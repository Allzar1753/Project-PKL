<?php
/** @var mysqli $koneksi */
require '../vendor/autoload.php'; // Memanggil library PhpSpreadsheet
include '../config/koneksi.php';
require_once '../config/auth.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Proteksi
require_permission($koneksi, 'laporan.view');
$isAdmin = is_admin();
$myBranchId = (int) (current_user_branch_id() ?? 0);
if (!$isAdmin && $myBranchId <= 0) {
    die('Branch user belum ditentukan.');
}

// ---------------------------------------------------------
// 1. COPY FUNGSI HELPER DARI LAPORAN ASLI
// ---------------------------------------------------------
function get_iso_week_default(): string {
    return date('o') . '-W' . date('W');
}

function resolve_period_range(string $periode, string $tahun, string $bulan, string $minggu, string $customAwal, string $customAkhir): array {
    $now = new DateTime();
    $label = '';

    if (!preg_match('/^\d{4}$/', $tahun)) { $tahun = $now->format('Y'); }
    if (!preg_match('/^(0[1-9]|1[0-2])$/', $bulan)) { $bulan = $now->format('m'); }

    if ($periode === 'tahun') {
        $startDate = new DateTime($tahun . '-01-01');
        $endDate   = new DateTime($tahun . '-12-31');
        $label = 'Tahun ' . $tahun;
    } elseif ($periode === 'minggu') {
        if (!preg_match('/^(\d{4})-W(\d{2})$/', $minggu, $matches)) {
            $minggu = get_iso_week_default();
            preg_match('/^(\d{4})-W(\d{2})$/', $minggu, $matches);
        }
        $isoYear = (int) $matches[1];
        $isoWeek = (int) $matches[2];
        $startDate = new DateTime();
        $startDate->setISODate($isoYear, $isoWeek);
        $endDate = clone $startDate;
        $endDate->modify('+6 days');
        $label = 'Minggu ' . $isoWeek . ' - ' . $isoYear;
    } elseif ($periode === 'custom') {
        if ($customAwal === '' && $customAkhir === '') {
            $startDate = new DateTime($now->format('Y-m-01'));
            $endDate   = new DateTime($now->format('Y-m-t'));
        } else {
            $startDate = new DateTime($customAwal !== '' ? $customAwal : $customAkhir);
            $endDate   = new DateTime($customAkhir !== '' ? $customAkhir : $customAwal);
        }
        if ($startDate > $endDate) {
            $temp = $startDate; $startDate = $endDate; $endDate = $temp;
        }
        $label = 'Custom: ' . $startDate->format('d M Y') . ' s/d ' . $endDate->format('d M Y');
    } else {
        $startDate = new DateTime($tahun . '-' . $bulan . '-01');
        $endDate   = new DateTime($tahun . '-' . $bulan . '-01');
        $endDate->modify('last day of this month');
        $label = 'Bulan ' . $startDate->format('F Y');
        $periode = 'bulan';
    }

    return [
        'periode' => $periode,
        'start'   => $startDate->format('Y-m-d'),
        'end'     => $endDate->format('Y-m-d'),
        'label'   => $label,
    ];
}

// ---------------------------------------------------------
// 2. TANGKAP PARAMETER FILTER (Sama seperti cetak PDF)
// ---------------------------------------------------------
$periode     = trim((string) ($_GET['periode'] ?? 'bulan'));
$tahun       = trim((string) ($_GET['tahun'] ?? date('Y')));
$bulan       = trim((string) ($_GET['bulan'] ?? date('m')));
$minggu      = trim((string) ($_GET['minggu'] ?? get_iso_week_default()));
$customAwal  = trim((string) ($_GET['custom_awal'] ?? ''));
$customAkhir = trim((string) ($_GET['custom_akhir'] ?? ''));

if (!in_array($periode, ['minggu', 'bulan', 'tahun', 'custom'], true)) { $periode = 'bulan'; }
$range = resolve_period_range($periode, $tahun, $bulan, $minggu, $customAwal, $customAkhir);

// ---------------------------------------------------------
// 3. QUERY DATABASE
// ---------------------------------------------------------
$sql = "
    SELECT
        b.id, b.no_asset, b.serial_number, b.tanggal_kirim, b.user, b.bermasalah, b.keterangan_masalah,
        tb.nama_barang, bm.nama_merk, st.nama_status, ba.nama_branch AS branch_aktif,
        bp.id_pengiriman, bp.tanggal_keluar, bp.tanggal_diterima, bp.status_pengiriman,
        bp.nomor_resi_keluar, bp.nama_penerima, bp.jasa_pengiriman,
        bpasal.nama_branch AS branch_asal_pengiriman, bptujuan.nama_branch AS branch_tujuan
    FROM barang b
    LEFT JOIN tb_barang tb ON tb.id_barang = b.id_barang
    LEFT JOIN tb_merk bm ON bm.id_merk = b.id_merk
    LEFT JOIN tb_status st ON st.id_status = b.id_status
    LEFT JOIN tb_branch ba ON ba.id_branch = b.id_branch
    LEFT JOIN barang_pengiriman bp ON bp.id_barang = b.id
    LEFT JOIN tb_branch bpasal ON bpasal.id_branch = bp.branch_asal
    LEFT JOIN tb_branch bptujuan ON bptujuan.id_branch = bp.branch_tujuan
    WHERE DATE(COALESCE(bp.tanggal_keluar, b.tanggal_kirim)) BETWEEN ? AND ?
";

if (!$isAdmin) { $sql .= " AND b.id_branch = ? "; }
$sql .= " ORDER BY b.id DESC, bp.id_pengiriman ASC ";

$stmt = mysqli_prepare($koneksi, $sql);
if ($isAdmin) {
    mysqli_stmt_bind_param($stmt, 'ss', $range['start'], $range['end']);
} else {
    mysqli_stmt_bind_param($stmt, 'ssi', $range['start'], $range['end'], $myBranchId);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// ---------------------------------------------------------
// 4. SETUP EXCEL MENGGUNAKAN PHPSPREADSHEET
// ---------------------------------------------------------
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Laporan Asset');

// --- Header Informasi Dokumen ---
$sheet->setCellValue('C1', 'PT HEXINDO ADIPEKARSA TBK');
$sheet->setCellValue('C2', 'Laporan Asset IT System');
$sheet->setCellValue('C3', 'Periode: ' . $range['label']);
$sheet->setCellValue('C4', 'Tanggal Cetak: ' . date('d F Y H:i'));

$sheet->getStyle('C1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('C2')->getFont()->setBold(true)->setSize(12);

// --- Header Tabel Baris ke-6 ---
$headers = [
    'A' => 'No',
    'B' => 'Nama Asset',
    'C' => 'Merk',
    'D' => 'No Asset',
    'E' => 'Serial Number',
    'F' => 'PIC/User',
    'G' => 'Lokasi Terkini',
    'H' => 'Kondisi',
    'I' => 'Detail Masalah',
    'J' => 'Status Pengiriman',
    'K' => 'Dari Cabang',
    'L' => 'Ke Cabang',
    'M' => 'Tgl Keluar',
    'N' => 'Tgl Diterima',
    'O' => 'Jasa Kurir',
    'P' => 'No Resi'
];

foreach ($headers as $col => $title) {
    $sheet->setCellValue($col . '6', $title);
}

// Styling Header Tabel
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F59E0B']], // Warna Orange
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
];
$sheet->getStyle('A6:P6')->applyFromArray($headerStyle);

// ---------------------------------------------------------
// 5. MEMASUKKAN DATA KE EXCEL
// ---------------------------------------------------------
$rowNum = 7;
$no = 1; // <--- 1. PASTIKAN VARIABEL INI ADA DI LUAR LOOPING

while ($row = mysqli_fetch_assoc($result)) {
    // Menentukan Kondisi
    $kondisi = ($row['bermasalah'] === 'Iya') ? 'Bermasalah' : 'Normal';
    $statusKirim = $row['status_pengiriman'] ?: 'Standby di Lokasi';

    // 2. PASTIKAN KOLOM 'A' MENGGUNAKAN $no++ (BUKAN $row['id'])
    $sheet->setCellValue('A' . $rowNum, $no++); 
    
    $sheet->setCellValue('B' . $rowNum, $row['nama_barang'] ?? '-');
    $sheet->setCellValue('C' . $rowNum, $row['nama_merk'] ?? '-');
    $sheet->setCellValue('D' . $rowNum, $row['no_asset'] ?? '-');
    $sheet->setCellValueExplicit('E' . $rowNum, $row['serial_number'] ?? '-', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('F' . $rowNum, $row['user'] ?? '-');
    $sheet->setCellValue('G' . $rowNum, $row['branch_aktif'] ?? '-');
    $sheet->setCellValue('H' . $rowNum, $kondisi);
    $sheet->setCellValue('I' . $rowNum, $row['keterangan_masalah'] ?? '-');
    
    // Logistik
    $sheet->setCellValue('J' . $rowNum, $statusKirim);
    $sheet->setCellValue('K' . $rowNum, $row['branch_asal_pengiriman'] ?? '-');
    $sheet->setCellValue('L' . $rowNum, $row['branch_tujuan'] ?? '-');
    $sheet->setCellValue('M' . $rowNum, $row['tanggal_keluar'] ?? '-');
    $sheet->setCellValue('N' . $rowNum, $row['tanggal_diterima'] ?? '-');
    $sheet->setCellValue('O' . $rowNum, $row['jasa_pengiriman'] ?? '-');
    $sheet->setCellValueExplicit('P' . $rowNum, $row['nomor_resi_keluar'] ?? '-', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

    $rowNum++;
}

// Styling border untuk seluruh baris data
if ($rowNum > 7) {
    $dataStyle = [
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDDD']]
        ]
    ];
    $sheet->getStyle('A7:P' . ($rowNum - 1))->applyFromArray($dataStyle);
}

// Auto size kolom agar rapi
foreach (range('A', 'P') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ---------------------------------------------------------
// 6. RENDER & DOWNLOAD FILE EXCEL
// ---------------------------------------------------------
$fileName = 'Laporan_Asset_IT_' . date('Ymd_Hi') . '.xlsx';

ob_end_clean(); // Bersihkan output buffer jika ada spasi kosong terselip
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'. urlencode($fileName).'"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;