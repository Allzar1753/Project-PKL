<?php
// Pastikan tidak ada output/spasi yang bocor sebelum file Excel di-generate
ob_start();

/** @var mysqli $koneksi */
require '../vendor/autoload.php';
include '../config/koneksi.php';
require_once '../config/auth.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

// Proteksi Hak Akses Cetak Excel
require_permission($koneksi, 'laporan.excel');

$isAdmin = is_admin();
$myBranchId = (int) (current_user_branch_id() ?? 0);
if (!$isAdmin && $myBranchId <= 0) {
    die('Akses Ditolak. Branch user belum ditentukan.');
}

function h($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }

// ==============================================================================
// LOGIKA FILTER TANGGAL (SAMA DENGAN PDF)
// ==============================================================================
function get_iso_week_default(): string { return date('o') . '-W' . date('W'); }

function resolve_period_range(string $periode, string $tahun, string $bulan, string $minggu, string $customAwal, string $customAkhir): array {
    $now = new DateTime();
    $label = '';
    if (!preg_match('/^\d{4}$/', $tahun)) { $tahun = $now->format('Y'); }
    if (!preg_match('/^(0[1-9]|1[0-2])$/', $bulan)) { $bulan = $now->format('m'); }

    if ($periode === 'tahun') {
        $start = "$tahun-01-01"; $end = "$tahun-12-31"; $label = "Tahun $tahun";
    } elseif ($periode === 'minggu') {
        if (!preg_match('/^(\d{4})-W(\d{2})$/', $minggu, $matches)) {
            $minggu = get_iso_week_default();
            preg_match('/^(\d{4})-W(\d{2})$/', $minggu, $matches);
        }
        $startDate = new DateTime(); $startDate->setISODate((int)$matches[1], (int)$matches[2]);
        $endDate = clone $startDate; $endDate->modify('+6 days');
        $start = $startDate->format('Y-m-d'); $end = $endDate->format('Y-m-d');
        $label = 'Minggu ' . $matches[2] . ' - ' . $matches[1];
    } elseif ($periode === 'custom') {
        $start = $customAwal ?: $now->format('Y-m-01'); $end = $customAkhir ?: $now->format('Y-m-t');
        if ($start > $end) { $t = $start; $start = $end; $end = $t; }
        $label = date('d M Y', strtotime($start)) . ' s/d ' . date('d M Y', strtotime($end));
    } else {
        $start = "$tahun-$bulan-01"; $end = date('Y-m-t', strtotime($start)); $label = date('F Y', strtotime($start));
    }
    return ['start' => $start, 'end' => $end, 'label' => $label];
}

$periode     = trim((string) ($_GET['periode'] ?? 'bulan'));
$tahun       = trim((string) ($_GET['tahun'] ?? date('Y')));
$bulan       = trim((string) ($_GET['bulan'] ?? date('m')));
$minggu      = trim((string) ($_GET['minggu'] ?? get_iso_week_default()));
$customAwal  = trim((string) ($_GET['custom_awal'] ?? ''));
$customAkhir = trim((string) ($_GET['custom_akhir'] ?? ''));

$range = resolve_period_range($periode, $tahun, $bulan, $minggu, $customAwal, $customAkhir);

// ==============================================================================
// KONDISI WHERE PER ROLE (ISOLASI DATA EXCEL)
// ==============================================================================
$idBranchHO = 40;
$activeBranchId = $isAdmin ? $idBranchHO : $myBranchId;

if ($isAdmin) {
    $condA = "1=1";
    $condB = "1=1";
} else {
    $condA = "(b.id_branch = $activeBranchId OR bp.branch_tujuan = $activeBranchId OR bp.branch_asal = $activeBranchId)";
    $condB = "pch.branch_asal = $activeBranchId";
}

$penerimaHO = 'Pak Deni';

// ==============================================================================
// QUERY A & B (PERSIS SEPERTI PDF)
// ==============================================================================
$sqlA = "
    SELECT b.id, b.no_asset, b.serial_number, b.tanggal_terima, b.user, b.bermasalah, b.keterangan_masalah,
           tb.nama_barang, bm.nama_merk, st.nama_status, ba.nama_branch AS branch_aktif,
           bp.id_pengiriman, bp.tanggal_keluar, bp.tanggal_diterima, bp.status_pengiriman, 
           bp.nomor_resi_keluar, bp.nama_penerima, bp.jasa_pengiriman,
           bpasal.nama_branch AS branch_asal_pengiriman, bptujuan.nama_branch AS branch_tujuan,
           DATE(bp.tanggal_keluar) AS tgl_aktivitas
    FROM barang_pengiriman bp
    JOIN barang b ON bp.id_barang = b.id
    LEFT JOIN tb_barang tb ON tb.id_barang = b.id_barang
    LEFT JOIN tb_merk bm ON bm.id_merk = b.id_merk
    LEFT JOIN tb_status st ON st.id_status = b.id_status
    LEFT JOIN tb_branch ba ON ba.id_branch = b.id_branch
    LEFT JOIN tb_branch bpasal ON bpasal.id_branch = bp.branch_asal
    LEFT JOIN tb_branch bptujuan ON bptujuan.id_branch = bp.branch_tujuan
    WHERE DATE(bp.tanggal_keluar) BETWEEN ? AND ? AND ($condA)
";

$sqlB = "
    SELECT b.id, b.no_asset, pch.serial_number, b.tanggal_terima, b.user, b.bermasalah, b.keterangan_masalah,
           tb.nama_barang, bm.nama_merk, st.nama_status, ba.nama_branch AS branch_aktif,
           pch.id_pengiriman_ho AS id_pengiriman, pch.tanggal_pengajuan AS tanggal_keluar, 
           DATE(pch.disetujui_pada) AS tanggal_diterima,
           pch.status_pengiriman, pch.nomor_resi_keluar, '$penerimaHO' AS nama_penerima, 
           pch.jasa_pengiriman AS jasa_pengiriman,
           bpasal.nama_branch AS branch_asal_pengiriman, 'Kantor Pusat HO' AS branch_tujuan,
           DATE(pch.tanggal_pengajuan) AS tgl_aktivitas
    FROM pengiriman_cabang_ho pch
    LEFT JOIN barang b ON b.serial_number = pch.serial_number
    LEFT JOIN tb_barang tb ON tb.id_barang = pch.id_barang
    LEFT JOIN tb_merk bm ON bm.id_merk = b.id_merk
    LEFT JOIN tb_status st ON st.id_status = b.id_status
    LEFT JOIN tb_branch ba ON ba.id_branch = b.id_branch
    LEFT JOIN tb_branch bpasal ON bpasal.id_branch = pch.branch_asal
    WHERE DATE(pch.tanggal_pengajuan) BETWEEN ? AND ? AND ($condB) AND b.id IS NOT NULL
";

$stmtA = mysqli_prepare($koneksi, $sqlA);
mysqli_stmt_bind_param($stmtA, 'ss', $range['start'], $range['end']);
mysqli_stmt_execute($stmtA);
$resA = mysqli_stmt_get_result($stmtA);

$stmtB = mysqli_prepare($koneksi, $sqlB);
mysqli_stmt_bind_param($stmtB, 'ss', $range['start'], $range['end']);
mysqli_stmt_execute($stmtB);
$resB = mysqli_stmt_get_result($stmtB);

$groupedAssets = [];
while ($row = mysqli_fetch_assoc($resA)) {
    $id = (string)$row['id'];
    if (!isset($groupedAssets[$id])) { $groupedAssets[$id] = ['asset' => $row, 'timeline' => []]; }
    if (!empty($row['id_pengiriman'])) { $groupedAssets[$id]['timeline'][] = $row; }
}
while ($row = mysqli_fetch_assoc($resB)) {
    $id = (string)$row['id'];
    if (!isset($groupedAssets[$id])) { $groupedAssets[$id] = ['asset' => $row, 'timeline' => []]; }
    if (!empty($row['id_pengiriman'])) { $groupedAssets[$id]['timeline'][] = $row; }
}

foreach ($groupedAssets as &$entry) {
    usort($entry['timeline'], function($a, $b) { return strcmp($a['tgl_aktivitas'], $b['tgl_aktivitas']); });
}
unset($entry);

// ==============================================================================
// GENERATE EXCEL MENGGUNAKAN PHPSPREADSHEET
// ==============================================================================
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Laporan_Asset');

// Kop Laporan
$sheet->setCellValue('B1', 'PT HEXINDO ADIPERKASA TBK');
$sheet->setCellValue('B2', 'Laporan Mutasi Asset & Logistik');
$sheet->setCellValue('B3', 'Periode Laporan : ' . $range['label']);
$sheet->setCellValue('B4', 'Tanggal Cetak   : ' . date('d M Y H:i') . ' WIB');

$sheet->getStyle('B1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('B2')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('B3:B4')->getFont()->setItalic(true)->getColor()->setARGB('666666');

// Header Tabel (Flat Design agar mudah di-filter/pivot oleh User)
$headers = [
    'A' => 'NO', 
    'B' => 'NAMA ASSET', 
    'C' => 'MERK', 
    'D' => 'NO ASSET', 
    'E' => 'SERIAL NUMBER',
    'F' => 'USER / PIC', 
    'G' => 'CABANG AKTIF', 
    'H' => 'KONDISI FISIK', 
    'I' => 'DETAIL KERUSAKAN',
    'J' => 'AKTIVITAS LOGISTIK', 
    'K' => 'DARI CABANG', 
    'L' => 'TUJUAN CABANG', 
    'M' => 'TGL DIKIRIM',
    'N' => 'TGL DITERIMA', 
    'O' => 'PENERIMA FISIK', 
    'P' => 'EKSPEDISI', 
    'Q' => 'NOMOR RESI', 
    'R' => 'STATUS PENGIRIMAN'
];

foreach ($headers as $col => $title) { 
    $sheet->setCellValue($col . '6', $title); 
}

// Styling Header Hexindo
$headerStyle = [
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E64312']], // Oranye Hexindo
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
];
$sheet->getStyle('A6:R6')->applyFromArray($headerStyle);
$sheet->getRowDimension('6')->setRowHeight(25);

// Isi Data ke Excel
$rowNum = 7; 
$no = 1;

foreach ($groupedAssets as $entry) {
    $asset = $entry['asset'];
    $timelines = $entry['timeline'];
    
    $kondisi = ($asset['bermasalah'] === 'Iya') ? 'Rusak/Bermasalah' : 'Normal';
    $ketMasalah = ($asset['bermasalah'] === 'Iya') ? ($asset['keterangan_masalah'] ?: '-') : '-';

    if (empty($timelines)) {
        // Jika tidak ada timeline logistik (hanya data aset standby)
        $sheet->setCellValue('A' . $rowNum, $no++);
        $sheet->setCellValue('B' . $rowNum, $asset['nama_barang'] ?: '-');
        $sheet->setCellValue('C' . $rowNum, $asset['nama_merk'] ?: '-');
        $sheet->setCellValueExplicit('D' . $rowNum, $asset['no_asset'] ?: '-', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('E' . $rowNum, $asset['serial_number'] ?: '-', DataType::TYPE_STRING);
        $sheet->setCellValue('F' . $rowNum, $asset['user'] ?: '-');
        $sheet->setCellValue('G' . $rowNum, $asset['branch_aktif'] ?: '-');
        $sheet->setCellValue('H' . $rowNum, $kondisi);
        $sheet->setCellValue('I' . $rowNum, $ketMasalah);
        
        $sheet->setCellValue('J' . $rowNum, 'Tidak ada pengiriman');
        $sheet->setCellValue('K' . $rowNum, '-');
        $sheet->setCellValue('L' . $rowNum, '-');
        $sheet->setCellValue('M' . $rowNum, '-');
        $sheet->setCellValue('N' . $rowNum, '-');
        $sheet->setCellValue('O' . $rowNum, '-');
        $sheet->setCellValue('P' . $rowNum, '-');
        $sheet->setCellValue('Q' . $rowNum, '-');
        $sheet->setCellValue('R' . $rowNum, 'Standby di Lokasi');
        $rowNum++;

    } else {
        // Jika ada timeline logistik (Bisa 1 aset punya 2 baris jika bolak-balik)
        foreach ($timelines as $idx => $step) {
            $sheet->setCellValue('A' . $rowNum, $no); // Nomor sama untuk aset yang sama
            $sheet->setCellValue('B' . $rowNum, $asset['nama_barang'] ?: '-');
            $sheet->setCellValue('C' . $rowNum, $asset['nama_merk'] ?: '-');
            $sheet->setCellValueExplicit('D' . $rowNum, $asset['no_asset'] ?: '-', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('E' . $rowNum, $asset['serial_number'] ?: '-', DataType::TYPE_STRING);
            $sheet->setCellValue('F' . $rowNum, $asset['user'] ?: '-');
            $sheet->setCellValue('G' . $rowNum, $asset['branch_aktif'] ?: '-');
            $sheet->setCellValue('H' . $rowNum, $kondisi);
            $sheet->setCellValue('I' . $rowNum, $ketMasalah);
            
            $sheet->setCellValue('J' . $rowNum, 'Aktivitas Ke-' . ($idx + 1));
            $sheet->setCellValue('K' . $rowNum, $step['branch_asal_pengiriman'] ?: '-');
            $sheet->setCellValue('L' . $rowNum, $step['branch_tujuan'] ?: '-');
            $sheet->setCellValue('M' . $rowNum, $step['tanggal_keluar'] ?: '-');
            $sheet->setCellValue('N' . $rowNum, $step['tanggal_diterima'] ?: '-');
            $sheet->setCellValue('O' . $rowNum, $step['nama_penerima'] ?: '-');
            $sheet->setCellValue('P' . $rowNum, $step['jasa_pengiriman'] ?: '-');
            $sheet->setCellValueExplicit('Q' . $rowNum, $step['nomor_resi_keluar'] ?: '-', DataType::TYPE_STRING);
            $sheet->setCellValue('R' . $rowNum, $step['status_pengiriman'] ?: '-');
            $rowNum++;
        }
        $no++; // Nomor bertambah setelah semua timeline aset selesai
    }
}

// Styling Border untuk Seluruh Data
if ($rowNum > 7) {
    $dataStyle = [
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '888888']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ];
    $sheet->getStyle('A7:R' . ($rowNum - 1))->applyFromArray($dataStyle);
    
    // Pewarnaan khusus untuk kolom Kondisi & Status
    for ($i = 7; $i < $rowNum; $i++) {
        // Mewarnai Kondisi Fisik
        $kondisiVal = $sheet->getCell('H'.$i)->getValue();
        if ($kondisiVal === 'Rusak/Bermasalah') {
            $sheet->getStyle('H'.$i)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('D32F2F')); // Merah
            $sheet->getStyle('H'.$i)->getFont()->setBold(true);
        }
        
        // Mewarnai Status Pengiriman
        $statusVal = strtolower(trim($sheet->getCell('R'.$i)->getValue()));
        if (in_array($statusVal, ['sudah diterima', 'sudah diterima ho', 'selesai'])) {
            $sheet->getStyle('R'.$i)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('059669')); // Hijau
            $sheet->getStyle('R'.$i)->getFont()->setBold(true);
        } elseif ($statusVal !== 'standby di lokasi' && $statusVal !== 'tidak ada pengiriman') {
            $sheet->getStyle('R'.$i)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('D97706')); // Oranye Warning
            $sheet->getStyle('R'.$i)->getFont()->setBold(true);
        }
    }
}

// Auto-Size Kolom agar rapi
foreach (range('A', 'R') as $col) { 
    $sheet->getColumnDimension($col)->setAutoSize(true); 
}

// Output File Excel
$fileName = 'Laporan_Mutasi_Aset_' . date('Ymd_Hi') . '.xlsx';
ob_end_clean(); // Bersihkan memori output agar file tidak korup
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'. urlencode($fileName).'"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;