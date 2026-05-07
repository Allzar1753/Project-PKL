<?php
/** @var mysqli $koneksi */
require '../vendor/autoload.php';
include '../config/koneksi.php';
require_once '../config/auth.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Proteksi Hak Akses Cetak Excel
require_permission($koneksi, 'laporan.excel');

$isAdmin = is_admin();
$myBranchId = (int) (current_user_branch_id() ?? 0);
if (!$isAdmin && $myBranchId <= 0) {
    die('Akses Ditolak. Branch user belum ditentukan.');
}

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
        $startDate = new DateTime();
        $startDate->setISODate((int)$matches[1], (int)$matches[2]);
        $endDate = clone $startDate; $endDate->modify('+6 days');
        $label = 'Minggu ' . $matches[2] . ' - ' . $matches[1];
    } elseif ($periode === 'custom') {
        $startDate = new DateTime($customAwal !== '' ? $customAwal : $now->format('Y-m-01'));
        $endDate   = new DateTime($customAkhir !== '' ? $customAkhir : $now->format('Y-m-t'));
        if ($startDate > $endDate) { $t = $startDate; $startDate = $endDate; $endDate = $t; }
        $label = 'Custom: ' . $startDate->format('d M Y') . ' s/d ' . $endDate->format('d M Y');
    } else {
        $startDate = new DateTime($tahun . '-' . $bulan . '-01');
        $endDate   = clone $startDate; $endDate->modify('last day of this month');
        $label = 'Bulan ' . $startDate->format('F Y');
    }

    return ['start' => $startDate->format('Y-m-d'), 'end' => $endDate->format('Y-m-d'), 'label' => $label];
}

// Tangkap Parameter Filter
$periode     = trim((string) ($_GET['periode'] ?? 'bulan'));
$tahun       = trim((string) ($_GET['tahun'] ?? date('Y')));
$bulan       = trim((string) ($_GET['bulan'] ?? date('m')));
$minggu      = trim((string) ($_GET['minggu'] ?? get_iso_week_default()));
$customAwal  = trim((string) ($_GET['custom_awal'] ?? ''));
$customAkhir = trim((string) ($_GET['custom_akhir'] ?? ''));

$range = resolve_period_range($periode, $tahun, $bulan, $minggu, $customAwal, $customAkhir);

// Query Database
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

if (!$isAdmin) { $sql .= " AND b.id_branch = $myBranchId "; }
$sql .= " ORDER BY b.id DESC, bp.id_pengiriman ASC ";

$stmt = mysqli_prepare($koneksi, $sql);
mysqli_stmt_bind_param($stmt, 'ss', $range['start'], $range['end']);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Setup Sheet Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Laporan Asset');

$sheet->setCellValue('C1', 'PT HEXINDO ADIPEKARSA TBK');
$sheet->setCellValue('C2', 'Laporan Asset IT System');
$sheet->setCellValue('C3', 'Periode: ' . $range['label']);
$sheet->setCellValue('C4', 'Tanggal Cetak: ' . date('d F Y H:i'));

$sheet->getStyle('C1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('C2')->getFont()->setBold(true)->setSize(12);

$headers = [
    'A' => 'No', 'B' => 'Nama Asset', 'C' => 'Merk', 'D' => 'No Asset', 'E' => 'Serial Number',
    'F' => 'PIC/User', 'G' => 'Lokasi Terkini', 'H' => 'Kondisi', 'I' => 'Detail Masalah',
    'J' => 'Status Pengiriman', 'K' => 'Dari Cabang', 'L' => 'Ke Cabang', 'M' => 'Tgl Keluar',
    'N' => 'Tgl Diterima', 'O' => 'Jasa Kurir', 'P' => 'No Resi'
];

foreach ($headers as $col => $title) { $sheet->setCellValue($col . '6', $title); }

$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F59E0B']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
];
$sheet->getStyle('A6:P6')->applyFromArray($headerStyle);

$rowNum = 7; $no = 1;
while ($row = mysqli_fetch_assoc($result)) {
    $kondisi = ($row['bermasalah'] === 'Iya') ? 'Bermasalah' : 'Normal';
    $statusKirim = $row['status_pengiriman'] ?: 'Standby di Lokasi';

    $sheet->setCellValue('A' . $rowNum, $no++);
    $sheet->setCellValue('B' . $rowNum, $row['nama_barang'] ?? '-');
    $sheet->setCellValue('C' . $rowNum, $row['nama_merk'] ?? '-');
    $sheet->setCellValue('D' . $rowNum, $row['no_asset'] ?? '-');
    $sheet->setCellValueExplicit('E' . $rowNum, $row['serial_number'] ?? '-', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('F' . $rowNum, $row['user'] ?? '-');
    $sheet->setCellValue('G' . $rowNum, $row['branch_aktif'] ?? '-');
    $sheet->setCellValue('H' . $rowNum, $kondisi);
    $sheet->setCellValue('I' . $rowNum, $row['keterangan_masalah'] ?? '-');
    $sheet->setCellValue('J' . $rowNum, $statusKirim);
    $sheet->setCellValue('K' . $rowNum, $row['branch_asal_pengiriman'] ?? '-');
    $sheet->setCellValue('L' . $rowNum, $row['branch_tujuan'] ?? '-');
    $sheet->setCellValue('M' . $rowNum, $row['tanggal_keluar'] ?? '-');
    $sheet->setCellValue('N' . $rowNum, $row['tanggal_diterima'] ?? '-');
    $sheet->setCellValue('O' . $rowNum, $row['jasa_pengiriman'] ?? '-');
    $sheet->setCellValueExplicit('P' . $rowNum, $row['nomor_resi_keluar'] ?? '-', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $rowNum++;
}

if ($rowNum > 7) {
    $sheet->getStyle('A7:P' . ($rowNum - 1))->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDDD']]]
    ]);
}

foreach (range('A', 'P') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }

$fileName = 'Laporan_Asset_IT_' . date('Ymd_Hi') . '.xlsx';
ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'. urlencode($fileName).'"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;