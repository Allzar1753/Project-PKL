<?php

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'laporan.view');

$isAdmin    = is_admin();
$myBranchId = (int) (current_user_branch_id() ?? 0);
if (!$isAdmin && $myBranchId <= 0) {
    http_response_code(403);
    exit('Branch user belum ditentukan.');
}

// ==============================================================================
// HELPER FUNCTIONS (Diperbarui dengan Soft Badges Hexindo)
// ==============================================================================
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function build_url(array $params = []): string
{
    $base  = basename($_SERVER['PHP_SELF']);
    $clean = [];
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) continue;
        $clean[$key] = $value;
    }
    return empty($clean) ? $base : $base . '?' . http_build_query($clean);
}

function status_badge(string $status): string
{
    $s = strtolower(trim($status));
    if ($s === 'sudah diterima' || $s === 'sudah diterima ho' || $s === 'selesai')
        return '<span class="status-badge status-done">' . h($status) . '</span>';
    if ($s === 'sedang perjalanan')
        return '<span class="status-badge status-road">' . h($status) . '</span>';
    if ($s === 'sedang dikemas' || $s === 'menunggu persetujuan admin')
        return '<span class="status-badge status-pack">' . h($status) . '</span>';
    if ($s === 'ditolak')
        return '<span class="status-badge status-reject">' . h($status) . '</span>';
    return '<span class="status-badge status-wait">' . h($status ?: 'Belum dikirim') . '</span>';
}

function get_iso_week_default(): string
{
    return date('o') . '-W' . date('W');
}

function get_bulan_indo(int $num): string
{
    $bulan = [
        1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',
        5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',
        9=>'September',10=>'Oktober',11=>'November',12=>'Desember'
    ];
    return $bulan[$num] ?? '';
}

function resolve_period_range(string $periode, string $tahun, string $bulan, string $minggu, string $customAwal, string $customAkhir): array
{
    $now = new DateTime();
    if (!preg_match('/^\d{4}$/', $tahun)) $tahun = $now->format('Y');
    if (!preg_match('/^(0[1-9]|1[0-2])$/', $bulan)) $bulan = $now->format('m');

    if ($periode === 'tahun') {
        $startDate = new DateTime($tahun . '-01-01');
        $endDate   = new DateTime($tahun . '-12-31');
        $label     = 'Tahun ' . $tahun;
    } elseif ($periode === 'minggu') {
        if (!preg_match('/^(\d{4})-W(\d{2})$/', $minggu, $matches)) {
            $minggu = get_iso_week_default();
            preg_match('/^(\d{4})-W(\d{2})$/', $minggu, $matches);
        }
        $startDate = new DateTime();
        $startDate->setISODate((int)$matches[1], (int)$matches[2]);
        $endDate = clone $startDate;
        $endDate->modify('+6 days');
        $label = 'Minggu ' . (int)$matches[2] . ' - ' . (int)$matches[1];
    } elseif ($periode === 'custom') {
        if ($customAwal === '' && $customAkhir === '') {
            $startDate = new DateTime($now->format('Y-m-01'));
            $endDate   = new DateTime($now->format('Y-m-t'));
        } else {
            $startDate = new DateTime($customAwal !== '' ? $customAwal : $customAkhir);
            $endDate   = new DateTime($customAkhir !== '' ? $customAkhir : $customAwal);
        }
        if ($startDate > $endDate) { $tmp = $startDate; $startDate = $endDate; $endDate = $tmp; }
        $label = 'Custom: ' . $startDate->format('d') . ' ' . get_bulan_indo((int)$startDate->format('n')) . ' ' . $startDate->format('Y')
               . ' s/d ' . $endDate->format('d') . ' ' . get_bulan_indo((int)$endDate->format('n')) . ' ' . $endDate->format('Y');
    } else {
        $startDate = new DateTime($tahun . '-' . $bulan . '-01');
        $endDate   = new DateTime($tahun . '-' . $bulan . '-01');
        $endDate->modify('last day of this month');
        $label   = 'Bulan ' . get_bulan_indo((int)$startDate->format('n')) . ' ' . $startDate->format('Y');
        $periode = 'bulan';
    }
    return ['periode' => $periode, 'start' => $startDate->format('Y-m-d'), 'end' => $endDate->format('Y-m-d'), 'label' => $label];
}

// ==============================================================================
// FILTER INPUT
// ==============================================================================
$periode     = trim((string)($_GET['periode']      ?? 'bulan'));
$tahun       = trim((string)($_GET['tahun']        ?? date('Y')));
$bulan       = trim((string)($_GET['bulan']        ?? date('m')));
$minggu      = trim((string)($_GET['minggu']       ?? get_iso_week_default()));
$customAwal  = trim((string)($_GET['custom_awal']  ?? ''));
$customAkhir = trim((string)($_GET['custom_akhir'] ?? ''));
$cariInput   = trim((string)($_GET['cari']         ?? ''));

if (!in_array($periode, ['minggu','bulan','tahun','custom'], true)) $periode = 'bulan';

$range        = resolve_period_range($periode, $tahun, $bulan, $minggu, $customAwal, $customAkhir);
$periodeLabel = $range['label'];
$safeStart    = mysqli_real_escape_string($koneksi, $range['start']);
$safeEnd      = mysqli_real_escape_string($koneksi, $range['end']);
$safeCari     = mysqli_real_escape_string($koneksi, $cariInput);

$penerimaHO = 'Pak Deni';

// ==============================================================================
// KONDISI WHERE PER ROLE
// ==============================================================================
if ($isAdmin) {
    $condA = "1=1";
    $condB = "1=1";
} else {
    $condA = "bp.branch_tujuan = $myBranchId";
    $condB = "pch.branch_asal = $myBranchId";
}

$searchCondA = $safeCari !== '' ? "AND (tb.nama_barang LIKE '%$safeCari%' OR b.no_asset LIKE '%$safeCari%' OR b.serial_number LIKE '%$safeCari%' OR bp.nomor_resi_keluar LIKE '%$safeCari%' OR bp.nama_penerima LIKE '%$safeCari%')" : '';
$searchCondB = $safeCari !== '' ? "AND (tb.nama_barang LIKE '%$safeCari%' OR b.no_asset LIKE '%$safeCari%' OR b.serial_number LIKE '%$safeCari%' OR pch.nomor_resi_keluar LIKE '%$safeCari%' OR pch.pemilik_barang LIKE '%$safeCari%')" : '';

// ==============================================================================
// QUERY A & B
// ==============================================================================
$sqlA = "
    SELECT
        b.id AS id_barang, b.no_asset, b.serial_number, b.tanggal_terima, b.bermasalah, b.keterangan_masalah,
        CASE
            WHEN b.user IS NOT NULL AND b.user != '' AND b.user != '0' THEN b.user
            WHEN bp.nama_penerima IS NOT NULL AND bp.nama_penerima != '' THEN bp.nama_penerima
            ELSE '-'
        END AS nama_pemilik,
        tb.nama_barang, bm.nama_merk, st.nama_status, ba.nama_branch AS branch_aktif,
        bp.id_pengiriman AS id_aktivitas,
        'A' AS sumber_tabel,
        bp.tanggal_keluar AS tgl_keluar,
        bp.tanggal_diterima AS tgl_diterima,
        bp.status_pengiriman,
        bp.nomor_resi_keluar AS resi,
        bp.jasa_pengiriman,
        br_asal.nama_branch AS branch_asal_nama,
        br_tujuan.nama_branch AS branch_tujuan_nama,
        CASE
            WHEN bp.nama_penerima IS NOT NULL AND bp.nama_penerima != '' THEN bp.nama_penerima
            ELSE b.user
        END AS nama_penerima_aktual,
        DATE(COALESCE(bp.tanggal_keluar, b.tanggal_terima)) AS tgl_aktivitas
    FROM barang b
    JOIN tb_barang tb      ON b.id_barang   = tb.id_barang
    LEFT JOIN tb_merk bm   ON b.id_merk     = bm.id_merk
    LEFT JOIN tb_status st ON b.id_status   = st.id_status
    LEFT JOIN tb_branch ba ON b.id_branch   = ba.id_branch
    LEFT JOIN barang_pengiriman bp         ON bp.id_barang    = b.id
    LEFT JOIN tb_branch br_asal  ON br_asal.id_branch  = bp.branch_asal
    LEFT JOIN tb_branch br_tujuan ON br_tujuan.id_branch = bp.branch_tujuan
    WHERE DATE(COALESCE(bp.tanggal_keluar, b.tanggal_terima)) BETWEEN '$safeStart' AND '$safeEnd'
      AND ($condA)
      $searchCondA
";

$sqlB = "
    SELECT
        b.id AS id_barang, b.no_asset, b.serial_number, b.tanggal_terima, b.bermasalah, b.keterangan_masalah,
        CASE
            WHEN pch.pemilik_barang IS NOT NULL AND pch.pemilik_barang != '' THEN pch.pemilik_barang
            WHEN b.user IS NOT NULL AND b.user != '' AND b.user != '0' THEN b.user
            ELSE '-'
        END AS nama_pemilik,
        tb.nama_barang, bm.nama_merk, st.nama_status, ba.nama_branch AS branch_aktif,
        pch.id_pengiriman_ho AS id_aktivitas,
        'B' AS sumber_tabel,
        pch.tanggal_pengajuan AS tgl_keluar,
        DATE(COALESCE(pch.disetujui_pada, pch.updated_at)) AS tgl_diterima,
        pch.status_pengiriman,
        pch.nomor_resi_keluar AS resi,
        NULL AS jasa_pengiriman,
        br_asal.nama_branch AS branch_asal_nama,
        'Kantor Pusat HO' AS branch_tujuan_nama,
        '$penerimaHO' AS nama_penerima_aktual,
        DATE(pch.tanggal_pengajuan) AS tgl_aktivitas
    FROM pengiriman_cabang_ho pch
    JOIN tb_barang tb         ON pch.id_barang     = tb.id_barang
    LEFT JOIN barang b        ON pch.serial_number  = b.serial_number
    LEFT JOIN tb_merk bm      ON b.id_merk          = bm.id_merk
    LEFT JOIN tb_status st    ON b.id_status        = st.id_status
    LEFT JOIN tb_branch ba    ON b.id_branch        = ba.id_branch
    LEFT JOIN tb_branch br_asal ON pch.branch_asal  = br_asal.id_branch
    WHERE DATE(pch.tanggal_pengajuan) BETWEEN '$safeStart' AND '$safeEnd'
      AND ($condB)
      $searchCondB
";

$resultA = mysqli_query($koneksi, $sqlA);
if (!$resultA) die('Query A Error: ' . mysqli_error($koneksi));

$resultB = mysqli_query($koneksi, $sqlB);
if (!$resultB) die('Query B Error: ' . mysqli_error($koneksi));

// ==============================================================================
// GROUPING
// ==============================================================================
$groupedAssets = [];

while ($row = mysqli_fetch_assoc($resultA)) {
    $id = (int)$row['id_barang'];
    if (!isset($groupedAssets[$id])) {
        $groupedAssets[$id] = ['asset' => $row, 'timeline' => []];
    }
    if (!empty($row['id_aktivitas'])) {
        $groupedAssets[$id]['timeline'][] = [
            'sumber'          => 'A',
            'id_aktivitas'    => $row['id_aktivitas'],
            'tgl_keluar'      => $row['tgl_keluar'],
            'tgl_diterima'    => $row['tgl_diterima'],
            'status'          => $row['status_pengiriman'],
            'resi'            => $row['resi'],
            'jasa'            => $row['jasa_pengiriman'],
            'branch_asal'     => $row['branch_asal_nama'],
            'branch_tujuan'   => $row['branch_tujuan_nama'],
            'nama_penerima'   => $row['nama_penerima_aktual'],
            'tgl_aktivitas'   => $row['tgl_aktivitas'],
        ];
    }
}

while ($row = mysqli_fetch_assoc($resultB)) {
    $id = (int)$row['id_barang'];
    if (!isset($groupedAssets[$id])) {
        $groupedAssets[$id] = ['asset' => $row, 'timeline' => []];
    }
    if (!empty($row['id_aktivitas'])) {
        $groupedAssets[$id]['timeline'][] = [
            'sumber'        => 'B',
            'id_aktivitas'  => $row['id_aktivitas'],
            'tgl_keluar'    => $row['tgl_keluar'],
            'tgl_diterima'  => $row['tgl_diterima'],
            'status'        => $row['status_pengiriman'],
            'resi'          => $row['resi'],
            'jasa'          => null,
            'branch_asal'   => $row['branch_asal_nama'],
            'branch_tujuan' => 'Kantor Pusat HO',
            'nama_penerima' => $penerimaHO,
            'tgl_aktivitas' => $row['tgl_aktivitas'],
        ];
    }
}

foreach ($groupedAssets as &$entry) {
    usort($entry['timeline'], fn($a, $b) => strcmp($a['tgl_aktivitas'], $b['tgl_aktivitas']));
}
unset($entry);

$totalAsset = count($groupedAssets);

// ==============================================================================
// SUMMARY CARDS
// ==============================================================================
$qA = mysqli_query($koneksi, "SELECT COUNT(DISTINCT bp.id_pengiriman) AS total FROM barang_pengiriman bp LEFT JOIN barang b ON bp.id_barang = b.id LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang WHERE DATE(bp.tanggal_keluar) BETWEEN '$safeStart' AND '$safeEnd' AND ($condA) $searchCondA AND bp.id_pengiriman IS NOT NULL");
$totalA = (int)(mysqli_fetch_assoc($qA)['total'] ?? 0);

$qA_done = mysqli_query($koneksi, "SELECT COUNT(DISTINCT bp.id_pengiriman) AS total FROM barang_pengiriman bp LEFT JOIN barang b ON bp.id_barang = b.id LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang WHERE DATE(bp.tanggal_keluar) BETWEEN '$safeStart' AND '$safeEnd' AND ($condA) $searchCondA AND bp.status_pengiriman = 'Sudah diterima'");
$totalA_done = (int)(mysqli_fetch_assoc($qA_done)['total'] ?? 0);

$qB = mysqli_query($koneksi, "SELECT COUNT(DISTINCT pch.id_pengiriman_ho) AS total FROM pengiriman_cabang_ho pch LEFT JOIN barang b ON pch.serial_number = b.serial_number LEFT JOIN tb_barang tb ON pch.id_barang = tb.id_barang WHERE DATE(pch.tanggal_pengajuan) BETWEEN '$safeStart' AND '$safeEnd' AND ($condB) $searchCondB");
$totalB = (int)(mysqli_fetch_assoc($qB)['total'] ?? 0);

$qB_done = mysqli_query($koneksi, "SELECT COUNT(DISTINCT pch.id_pengiriman_ho) AS total FROM pengiriman_cabang_ho pch LEFT JOIN barang b ON pch.serial_number = b.serial_number LEFT JOIN tb_barang tb ON pch.id_barang = tb.id_barang WHERE DATE(pch.tanggal_pengajuan) BETWEEN '$safeStart' AND '$safeEnd' AND ($condB) $searchCondB AND pch.status_pengiriman IN ('Sudah diterima HO', 'Selesai')");
$totalB_done = (int)(mysqli_fetch_assoc($qB_done)['total'] ?? 0);

$totalPengiriman = $totalA + $totalB;
$totalDiterima   = $totalA_done + $totalB_done;
$totalProses     = max(0, $totalPengiriman - $totalDiterima);

$bulanOptions = [
    '01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April',
    '05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus',
    '09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Asset</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- CSS SINKRONISASI TEMA HEXINDO (CLEAN & MODERN) -->
    <style>
        :root {
            /* TEMA HEXINDO */
            --orange-1: #E64312; 
            --orange-2: #F25C05;
            --dark-1: #231F20;
            --text-main: #333333;
            --text-soft: #666666;
            --surface-bg: #F4F6F9;
            --border-soft: #E0E4E8;
            --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.04);
            --shadow-hover: 0 10px 25px rgba(0,0,0,0.08);
            --radius-xl: 8px; /* Industrial Sharp Edges */
        }
        
        body { 
            background-color: var(--surface-bg); 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            color: var(--text-main); 
        }
        .page-shell { padding: 24px 32px; }

        /* Hero Banner */
        .hero-card { 
            position: relative; 
            background: var(--dark-1); 
            border-top: 4px solid var(--orange-1); 
            border-radius: var(--radius-xl); 
            padding: 1.5rem 2rem; 
            margin-bottom: 1.5rem; 
            box-shadow: var(--shadow-soft); 
        }
        .hero-content { position: relative; z-index: 2; }
        .page-title { color: #fff; font-size: 1.6rem; font-weight: 700; margin-bottom: 0.25rem; }
        .page-subtitle { color: #9ca3af; margin-bottom: 0; max-width: 800px; font-size: 0.95rem; }
        
        .hero-action { 
            border: 1px solid rgba(255, 255, 255, 0.1); 
            background: rgba(255, 255, 255, 0.1); 
            color: #fff; font-weight: 600; 
            border-radius: var(--radius-xl); 
            padding: 0.6rem 1.2rem; 
            text-decoration: none; display: inline-flex; align-items: center; 
            transition: all 0.2s; font-size: 0.9rem;
        }
        .hero-action:hover { background: rgba(255, 255, 255, 0.2); color: #fff; }
        .hero-action.excel { background: #059669; border-color: #059669; }
        .hero-action.excel:hover { background: #047857; color: #fff; }

        /* Cards Panel (Filter & Konten) */
        .panel-card, .report-card, .asset-card, .summary-card { 
            background: #ffffff; 
            border: 1px solid var(--border-soft); 
            border-radius: var(--radius-xl); 
            box-shadow: var(--shadow-soft); 
        }
        
        /* Summary Card Spesifik */
        .summary-card { 
            position: relative; overflow: hidden; height: 100%; 
            padding: 1.25rem 1.5rem; transition: all 0.2s ease; 
            border-left: 4px solid var(--orange-1);
        }
        .summary-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-hover); }
        .summary-label { font-size: 0.85rem; color: var(--text-soft); font-weight: 600; margin-bottom: 0.35rem; }
        .summary-value { font-size: 1.8rem; font-weight: 800; color: var(--dark-1); margin-bottom: 0.2rem; }
        .summary-note  { font-size: 0.8rem; color: #9ca3af; }
        .summary-icon  { 
            width: 45px; height: 45px; border-radius: 12px; 
            display: flex; align-items: center; justify-content: center; 
            font-size: 1.25rem; color: var(--orange-1); 
            background: rgba(230, 67, 18, 0.1); 
        }

        /* Card Header Panel Filter */
        .card-head { 
            padding: 1.2rem 1.5rem; 
            background: #fff; color: var(--dark-1); 
            border-bottom: 1px solid var(--border-soft); 
            border-radius: var(--radius-xl) var(--radius-xl) 0 0; 
        }
        .card-head-title    { font-size: 1.05rem; font-weight: 700; margin-bottom: 0.1rem; }
        .card-head-subtitle { font-size: 0.85rem; color: var(--text-soft); }
        .card-body-custom   { padding: 1.5rem; }

        /* Form Filter */
        .form-label   { font-weight: 600; color: var(--dark-1); margin-bottom: 0.5rem; font-size: 0.85rem; }
        .form-control, .form-select { 
            border-radius: 6px; min-height: 42px; 
            border: 1px solid var(--border-soft); box-shadow: none; 
            padding: 0.5rem 0.8rem; font-size: 0.95rem;
        }
        .form-control:focus, .form-select:focus { 
            border-color: var(--orange-1); 
            box-shadow: 0 0 0 0.25rem rgba(230, 67, 18, 0.1); 
        }
        .btn-main { 
            border: none; border-radius: 6px; font-weight: 600; 
            padding: 0 1.2rem; min-height: 42px;
            background-color: var(--orange-1); color: #fff; 
            transition: all 0.2s ease; display: inline-flex; align-items: center; justify-content: center; 
        }
        .btn-main:hover { background-color: var(--orange-2); color: #fff; }
        
        .search-wrap { position: relative; }
        .search-wrap .form-control { padding-left: 2.2rem; }
        .search-wrap .search-icon  { position: absolute; left: 0.8rem; top: 50%; transform: translateY(-50%); color: var(--text-soft); font-size: 0.9rem; }
        
        .period-chip { 
            display: inline-flex; align-items: center; gap: 0.4rem; 
            border-radius: 999px; background: #F9FAFB; 
            color: var(--text-main); border: 1px solid var(--border-soft); 
            padding: 0.3rem 0.8rem; font-size: 0.8rem; font-weight: 600; 
        }

        /* Asset Cards (Laporan) */
        .report-card { background: transparent; border: none; box-shadow: none;} /* Hilangkan border parent */
        .asset-card { margin-bottom: 1.5rem; }
        
        .asset-top { 
            padding: 1.2rem 1.5rem; 
            background: #fff; border-bottom: 1px solid var(--border-soft); 
            border-radius: var(--radius-xl) var(--radius-xl) 0 0; 
        }
        .asset-title { font-size: 1rem; font-weight: 700; color: var(--dark-1); margin-bottom: 0.3rem; }
        .asset-meta  { color: var(--text-soft); font-size: 0.85rem; }
        .asset-body  { padding: 1.5rem; background: #fff; border-radius: 0 0 var(--radius-xl) var(--radius-xl);}
        
        /* Ringkasan Aset */
        .info-box { 
            background: #F9FAFB; border: 1px dashed var(--border-soft); 
            border-radius: 8px; padding: 1rem 1.2rem; color: var(--text-main); 
            font-size: 0.85rem; margin-bottom: 1.5rem; 
            display: flex; flex-wrap: wrap; gap: 1.5rem;
        }
        .info-box div { flex: 1; min-width: 200px;}

        /* Timeline Logistik */
        .timeline { position: relative; padding-left: 1.5rem; }
        .timeline::before { content: ""; position: absolute; top: 0; bottom: 0; left: 0.4rem; width: 2px; background: var(--border-soft); }
        .timeline-item { position: relative; margin-bottom: 1.5rem; padding-left: 1rem; }
        .timeline-item:last-child { margin-bottom: 0; }
        
        /* Titik Timeline (Oranye vs Biru/Ungu tergantung arah kiriman) */
        .timeline-dot  { position: absolute; left: -1.45rem; top: 0.4rem; width: 14px; height: 14px; border-radius: 50%; background-color: var(--orange-1); box-shadow: 0 0 0 4px #fff; }
        .timeline-dot.dot-b { background-color: #3b82f6; /* Warna biru membedakan Cabang ke HO */ }
        
        .timeline-card { background: #fff; border: 1px solid var(--border-soft); border-radius: 8px; overflow: hidden; }
        .timeline-head { padding: 1rem 1.2rem; background: #F9FAFB; border-bottom: 1px solid var(--border-soft); }
        .timeline-title    { font-size: 0.95rem; font-weight: 700; color: var(--dark-1); margin-bottom: 0.2rem; }
        .timeline-subtitle { font-size: 0.8rem; color: var(--text-soft); }
        .timeline-body { padding: 1.2rem; }
        
        .detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1rem; }
        @media(max-width:575.98px){ .detail-grid { grid-template-columns: 1fr; } .log-note-grid { grid-template-columns: 1fr !important; } }
        
        .detail-box   { padding: 0; }
        .detail-label { font-size: 0.75rem; font-weight: 600; color: var(--text-soft); text-transform: uppercase; margin-bottom: 0.2rem; }
        .detail-value { font-size: 0.9rem; font-weight: 600; color: var(--dark-1); word-break: break-word; }
        
        .log-note-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; border-top: 1px dashed var(--border-soft); padding-top: 1rem;}
        .log-note-title { font-size: 0.8rem; font-weight: 700; color: var(--dark-1); margin-bottom: 0.2rem; }
        .log-note-text  { font-size: 0.85rem; color: var(--text-soft); }

        /* Source badge (A/B label di timeline) */
        .source-badge { display: inline-flex; align-items: center; gap: 0.35rem; border-radius: 6px; padding: 0.2rem 0.5rem; font-size: 0.7rem; font-weight: 700; text-transform: uppercase;}
        .source-badge.src-a { background: rgba(230, 67, 18, 0.1); color: var(--orange-1); border: 1px solid rgba(230, 67, 18, 0.2); }
        .source-badge.src-b { background: rgba(59, 130, 246, 0.1); color: #1d4ed8; border: 1px solid rgba(59, 130, 246, 0.2); }

        /* Soft Badges Status */
        .status-badge  { display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; padding: 0.4em 0.8em; font-size: 0.75rem; font-weight: 600; white-space: nowrap; }
        .status-done   { background-color: rgba(16, 185, 129, 0.15); color: #059669; }
        .status-road   { background-color: rgba(59, 130, 246, 0.15); color: #1d4ed8; }
        .status-pack   { background-color: rgba(245, 158, 11, 0.15); color: #d97706; }
        .status-reject { background-color: rgba(239, 68, 68, 0.15); color: #b91c1c; }
        .status-wait   { background-color: rgba(107, 114, 128, 0.15); color: #4b5563; }

        /* Empty State */
        .empty-state   { text-align: center; padding: 3rem 1rem; color: var(--text-soft); background: #fff; border-radius: var(--radius-xl); border: 1px dashed var(--border-soft);}
        .empty-state i { display: block; font-size: 2.5rem; margin-bottom: 0.5rem; color: #d1d5db; }
        .empty-state span { font-weight: 600; }
        
        /* Utility */
        .no-print { /* Tetap biarkan untuk fungsi print out */ }
    </style>
</head>
<body>

<div class="container-fluid p-0">
    <div class="d-flex flex-nowrap w-100 overflow-hidden">

        <?php include '../layout/sidebar.php'; ?>

        <div id="mainContent" class="flex-grow-1" style="transition:all .28s ease; min-width:0;">
            <div class="page-shell">

                <!-- Hero -->
                <div class="hero-card no-print">
                    <div class="hero-content d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <h1 class="page-title">Laporan Aktivitas Aset</h1>
                            <p class="page-subtitle">Laporan per-periode seluruh rute pengiriman alat berat/aset (HO ↔ Cabang) yang siap cetak PDF atau ekspor Excel.</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if (can('laporan.pdf')): ?>
                                <button type="button" class="hero-action" onclick="prosesCetak(this,'lengkap')">
                                    <i class="bi bi-printer me-2"></i>Cetak Laporan PDF
                                </button>
                            <?php endif; ?>
                            <?php if (can('laporan.excel')): ?>
                                <a href="export_excel.php?<?= http_build_query($_GET) ?>" class="hero-action excel">
                                    <i class="bi bi-file-excel me-2"></i>Ekspor Excel
                                </a>
                            <?php endif; ?>
                            <a href="<?= h(build_url()) ?>" class="hero-action" title="Reset Filter">
                                <i class="bi bi-arrow-clockwise me-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Filter Panel -->
                <div class="panel-card mb-4 no-print">
                    <div class="card-head">
                        <div class="card-head-title">Filter Data Laporan</div>
                        <div class="card-head-subtitle">Atur periode laporan dan cari aset spesifik.</div>
                    </div>
                    <div class="card-body-custom">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-lg-2">
                                <label class="form-label">Jenis Periode</label>
                                <select name="periode" id="periodeFilter" class="form-select">
                                    <option value="minggu" <?= $range['periode']==='minggu' ?'selected':'' ?>>Per Minggu</option>
                                    <option value="bulan"  <?= $range['periode']==='bulan'  ?'selected':'' ?>>Per Bulan</option>
                                    <option value="tahun"  <?= $range['periode']==='tahun'  ?'selected':'' ?>>Per Tahun</option>
                                    <option value="custom" <?= $range['periode']==='custom' ?'selected':'' ?>>Tanggal Custom</option>
                                </select>
                            </div>
                            <div class="col-lg-2 filter-bulan-tahun">
                                <label class="form-label">Tahun</label>
                                <input type="number" name="tahun" class="form-control" value="<?= h($tahun) ?>" min="2000" max="2100">
                            </div>
                            <div class="col-lg-2 filter-bulan">
                                <label class="form-label">Bulan</label>
                                <select name="bulan" class="form-select">
                                    <?php foreach ($bulanOptions as $num => $lbl): ?>
                                        <option value="<?= h($num) ?>" <?= $bulan===$num?'selected':'' ?>><?= h($lbl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-2 filter-minggu" style="display:none;">
                                <label class="form-label">Minggu Ke-</label>
                                <input type="week" name="minggu" class="form-control" value="<?= h($minggu) ?>">
                            </div>
                            <div class="col-lg-2 filter-custom" style="display:none;">
                                <label class="form-label">Tanggal Mulai</label>
                                <input type="date" name="custom_awal" class="form-control" value="<?= h($customAwal) ?>">
                            </div>
                            <div class="col-lg-2 filter-custom" style="display:none;">
                                <label class="form-label">Tanggal Akhir</label>
                                <input type="date" name="custom_akhir" class="form-control" value="<?= h($customAkhir) ?>">
                            </div>
                            <div class="col-lg-3">
                                <label class="form-label">Cari Spesifik</label>
                                <div class="search-wrap">
                                    <i class="bi bi-search search-icon"></i>
                                    <input type="text" name="cari" class="form-control" placeholder="Nama, serial, resi..." value="<?= h($cariInput) ?>">
                                </div>
                            </div>
                            <div class="col-lg-2">
                                <div class="d-grid">
                                    <button type="submit" class="btn-main"><i class="bi bi-funnel-fill me-2"></i>Terapkan</button>
                                </div>
                            </div>
                        </form>
                        <div class="mt-3 border-top pt-3">
                            <span class="period-chip"><i class="bi bi-calendar3"></i> <?= h($periodeLabel) ?></span>
                            <?php if ($cariInput !== ''): ?>
                                <span class="period-chip ms-2"><i class="bi bi-search"></i> Keyword: <?= h($cariInput) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row g-3 mb-4 no-print">
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="summary-label">Total Aset</div>
                                    <div class="summary-value"><?= $totalAsset ?></div>
                                    <div class="summary-note">Aset dlm periode filter</div>
                                </div>
                                <div class="summary-icon"><i class="bi bi-pc-display"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="summary-label">Total Pengiriman</div>
                                    <div class="summary-value"><?= $totalPengiriman ?></div>
                                    <div class="summary-note">Aktivitas HO ↔ Cabang</div>
                                </div>
                                <div class="summary-icon"><i class="bi bi-arrow-left-right"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="summary-label">Tiba / Selesai</div>
                                    <div class="summary-value"><?= $totalDiterima ?></div>
                                    <div class="summary-note">Logistik telah diterima</div>
                                </div>
                                <div class="summary-icon"><i class="bi bi-check2-circle"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card" style="border-left-color: #f59e0b;">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="summary-label">Masih Proses</div>
                                    <div class="summary-value"><?= $totalProses ?></div>
                                    <div class="summary-note">Dalam tahap pengiriman</div>
                                </div>
                                <div class="summary-icon" style="background: rgba(245,158,11,.1); color: #f59e0b;"><i class="bi bi-truck"></i></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Report Content (Cards Aset) -->
                <div class="report-card">
                    <div>
                        <?php if (empty($groupedAssets)): ?>
                            <div class="empty-state">
                                <i class="bi bi-folder-x"></i>
                                <span>Tidak ada data laporan logistik pada rentang tanggal ini.</span>
                            </div>
                        <?php else: ?>
                            <?php foreach ($groupedAssets as $entry): ?>
                                <?php $asset = $entry['asset']; ?>
                                <div class="asset-card">

                                    <!-- Asset Header -->
                                    <div class="asset-top">
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                                            <div>
                                                <div class="asset-title">
                                                    <?= h($asset['nama_barang'] ?? '-') ?> <span class="fw-normal text-muted">— <?= h($asset['nama_merk'] ?? '-') ?></span>
                                                </div>
                                                <div class="asset-meta">
                                                    No Aset: <b class="text-dark"><?= h($asset['no_asset'] ?? '-') ?></b> &nbsp;|&nbsp;
                                                    SN: <b class="text-dark"><?= h($asset['serial_number'] ?? '-') ?></b> &nbsp;|&nbsp;
                                                    PIC/User: <b class="text-dark"><?= h($asset['nama_pemilik'] ?? '-') ?></b>
                                                </div>
                                            </div>
                                            <?php if (can('laporan.pdf')): ?>
                                                <button type="button" class="btn btn-light border btn-sm fw-bold no-print" onclick="prosesCetak(this,'tunggal',<?= (int)$asset['id_barang'] ?>)">
                                                    <i class="bi bi-printer me-1"></i> Cetak Aset
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Asset Body -->
                                    <div class="asset-body">
                                        <!-- Ringkasan Info Box -->
                                        <div class="info-box">
                                            <div>
                                                <span class="text-muted d-block mb-1">Lokasi Cabang Aktif</span>
                                                <b class="text-dark"><?= h($asset['branch_aktif'] ?? '-') ?></b>
                                            </div>
                                            <div>
                                                <span class="text-muted d-block mb-1">Tanggal Tiba di Cabang</span>
                                                <b class="text-dark"><?= h($asset['tanggal_terima'] ?? '-') ?></b>
                                            </div>
                                            <div>
                                                <span class="text-muted d-block mb-1">Status Ketersediaan</span>
                                                <b class="text-dark"><?= h($asset['nama_status'] ?? '-') ?></b>
                                            </div>
                                            <div>
                                                <span class="text-muted d-block mb-1">Kondisi Fisik</span>
                                                <?php if (($asset['bermasalah'] ?? '') === 'Iya'): ?>
                                                    <b class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i> Bermasalah</b> <br><span class="small text-muted"><?= h($asset['keterangan_masalah'] ?? '-') ?></span>
                                                <?php else: ?>
                                                    <b class="text-success"><i class="bi bi-check-circle me-1"></i> Normal</b>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Timeline Tracker -->
                                        <?php if (empty($entry['timeline'])): ?>
                                            <div class="timeline">
                                                <div class="timeline-item">
                                                    <div class="timeline-dot" style="background-color: #d1d5db;"></div>
                                                    <div class="timeline-card">
                                                        <div class="timeline-head">
                                                            <div class="timeline-title text-muted">Belum ada riwayat aktivitas logistik</div>
                                                            <div class="timeline-subtitle">Aset ini masih menetap di <?= h($asset['branch_aktif'] ?? 'lokasi') ?> tanpa pergerakan.</div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="timeline">
                                                <?php foreach ($entry['timeline'] as $idx => $step): ?>
                                                    <?php $isB = ($step['sumber'] === 'B'); ?>
                                                    <div class="timeline-item">
                                                        <!-- Dot Oranye (A) atau Biru (B) -->
                                                        <div class="timeline-dot <?= $isB ? 'dot-b' : '' ?>"></div>
                                                        <div class="timeline-card">
                                                            <div class="timeline-head d-flex justify-content-between align-items-start flex-wrap gap-2">
                                                                <div>
                                                                    <div class="timeline-title d-flex align-items-center gap-2">
                                                                        Aktivitas <?= $idx + 1 ?>
                                                                        <?php if ($isB): ?>
                                                                            <span class="source-badge src-b">Cabang → HO</span>
                                                                        <?php else: ?>
                                                                            <span class="source-badge src-a">HO → Cabang</span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <div class="timeline-subtitle">
                                                                        Rute: <b><?= h($step['branch_asal'] ?? '-') ?></b> <i class="bi bi-arrow-right mx-1"></i> <b><?= h($step['branch_tujuan'] ?? '-') ?></b>
                                                                    </div>
                                                                </div>
                                                                <div><?= status_badge((string)($step['status'] ?? 'Belum dikirim')) ?></div>
                                                            </div>
                                                            <div class="timeline-body">
                                                                <div class="detail-grid">
                                                                    <div class="detail-box">
                                                                        <div class="detail-label">Tanggal Dikirim</div>
                                                                        <div class="detail-value"><i class="bi bi-calendar-event text-muted me-1"></i> <?= h($step['tgl_keluar'] ?? '-') ?></div>
                                                                    </div>
                                                                    <div class="detail-box">
                                                                        <div class="detail-label">Tanggal Diterima</div>
                                                                        <div class="detail-value"><i class="bi bi-calendar-check text-muted me-1"></i> <?= h($step['tgl_diterima'] ?? ($isB ? 'Menunggu Konfirmasi' : '-')) ?></div>
                                                                    </div>
                                                                    <?php if (!$isB): ?>
                                                                    <div class="detail-box">
                                                                        <div class="detail-label">Ekspedisi Logistik</div>
                                                                        <div class="detail-value"><i class="bi bi-truck text-muted me-1"></i> <?= h($step['jasa'] ?? '-') ?></div>
                                                                    </div>
                                                                    <?php endif; ?>
                                                                    <div class="detail-box">
                                                                        <div class="detail-label">Nomor Resi / AWB</div>
                                                                        <div class="detail-value"><i class="bi bi-receipt text-muted me-1"></i> <?= h($step['resi'] ?? '-') ?></div>
                                                                    </div>
                                                                </div>
                                                                
                                                                <div class="log-note-grid">
                                                                    <div>
                                                                        <div class="log-note-title">Keterangan Pengiriman:</div>
                                                                        <div class="log-note-text">
                                                                            <?php if (!empty($step['jasa'])): ?>
                                                                                Dikirim melalui jasa <b><?= h($step['jasa']) ?></b>.
                                                                            <?php endif; ?>
                                                                            <?php if (!empty($step['resi'])): ?>
                                                                                No. Tracking: <b><?= h($step['resi']) ?></b>.
                                                                            <?php else: ?>
                                                                                Nomor tracking/resi belum diinput ke sistem.
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                    <div>
                                                                        <div class="log-note-title">Keterangan Penerimaan:</div>
                                                                        <div class="log-note-text">
                                                                            <?php
                                                                            $st = strtolower(trim($step['status'] ?? ''));
                                                                            $isDone = in_array($st, ['sudah diterima','sudah diterima ho','selesai']);
                                                                            if ($isDone): ?>
                                                                                Telah diserahterimakan kepada <b><?= h($step['nama_penerima'] ?? '-') ?></b>
                                                                                <?php if (!empty($step['tgl_diterima'])): ?>
                                                                                    pada <b><?= h($step['tgl_diterima']) ?></b>.
                                                                                <?php else: ?>
                                                                                    namun tidak ada rekam waktu.
                                                                                <?php endif; ?>
                                                                            <?php else: ?>
                                                                                Masih dalam perjalanan. Belum ada konfirmasi barang tiba di tujuan.
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
    // Filter periode toggle (Script JS Tidak Diubah)
    document.addEventListener('DOMContentLoaded', function () {
        const select           = document.getElementById('periodeFilter');
        const fieldsBulan      = document.querySelectorAll('.filter-bulan');
        const fieldsBulanTahun = document.querySelectorAll('.filter-bulan-tahun');
        const fieldsMinggu     = document.querySelectorAll('.filter-minggu');
        const fieldsCustom     = document.querySelectorAll('.filter-custom');

        function toggleFilterFields() {
            const value = select.value;
            fieldsBulan.forEach(el => el.style.display = 'none');
            fieldsBulanTahun.forEach(el => el.style.display = 'none');
            fieldsMinggu.forEach(el => el.style.display = 'none');
            fieldsCustom.forEach(el => el.style.display = 'none');

            if (value === 'bulan') {
                fieldsBulanTahun.forEach(el => el.style.display = '');
                fieldsBulan.forEach(el => el.style.display = '');
            } else if (value === 'tahun') {
                fieldsBulanTahun.forEach(el => el.style.display = '');
            } else if (value === 'minggu') {
                fieldsMinggu.forEach(el => el.style.display = '');
            } else if (value === 'custom') {
                fieldsCustom.forEach(el => el.style.display = '');
            }
        }
        toggleFilterFields();
        select.addEventListener('change', toggleFilterFields);
    });

    // Pop-up print sistem (Script JS Tidak Diubah)
    function prosesCetak(btnElement, tipe, idAsset = null) {
        let originalHtml  = btnElement.innerHTML;
        btnElement.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Menyiapkan...';
        btnElement.disabled  = true;

        let currentParams = new URLSearchParams(window.location.search);
        if (tipe === 'tunggal' && idAsset !== null) {
            currentParams.set('id', idAsset);
        }
        let targetUrl = 'cetak_pdf.php?' + currentParams.toString();

        let iframe = document.getElementById('rahasiaPrintFrame');
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.id = 'rahasiaPrintFrame';
            iframe.style.display = 'none';
            document.body.appendChild(iframe);
        }

        iframe.onload = function () {
            btnElement.innerHTML = originalHtml;
            btnElement.disabled  = false;
            iframe.contentWindow.print();
        };

        iframe.src = targetUrl;
    }
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>