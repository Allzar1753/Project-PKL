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
// HELPER FUNCTIONS
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

// Nama penerima HO selalu Pak Deni
$penerimaHO = 'Pak Deni';

// ==============================================================================
// KONDISI WHERE PER ROLE
// ==============================================================================
// Untuk barang_pengiriman (HO → Cabang)
if ($isAdmin) {
    // Admin HO: barang yang berasal dari HO atau tujuannya ke HO
    $condA = "b.id_branch = $myBranchId OR bp.branch_tujuan = $myBranchId";
} else {
    // User Cabang: hanya pengiriman masuk ke cabangnya
    $condA = "bp.branch_tujuan = $myBranchId";
}

// Untuk pengiriman_cabang_ho (Cabang → HO)
if ($isAdmin) {
    // Admin HO: semua pengiriman masuk ke HO
    $condB = "1=1";
} else {
    // User Cabang: hanya pengiriman keluar dari cabangnya
    $condB = "pch.branch_asal = $myBranchId";
}

// Filter pencarian teks
$searchCondA = $safeCari !== '' ? "AND (tb.nama_barang LIKE '%$safeCari%' OR b.no_asset LIKE '%$safeCari%' OR b.serial_number LIKE '%$safeCari%' OR bp.nomor_resi_keluar LIKE '%$safeCari%' OR bp.nama_penerima LIKE '%$safeCari%')" : '';
$searchCondB = $safeCari !== '' ? "AND (tb.nama_barang LIKE '%$safeCari%' OR b.no_asset LIKE '%$safeCari%' OR b.serial_number LIKE '%$safeCari%' OR pch.nomor_resi_keluar LIKE '%$safeCari%' OR pch.pemilik_barang LIKE '%$safeCari%')" : '';

// ==============================================================================
// QUERY A: barang_pengiriman (HO → Cabang)
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
        -- Timeline Pengiriman
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

// ==============================================================================
// QUERY B: pengiriman_cabang_ho (Cabang → HO)
// ==============================================================================
$sqlB = "
    SELECT
        b.id AS id_barang, b.no_asset, b.serial_number, b.tanggal_terima, b.bermasalah, b.keterangan_masalah,
        CASE
            WHEN pch.pemilik_barang IS NOT NULL AND pch.pemilik_barang != '' THEN pch.pemilik_barang
            WHEN b.user IS NOT NULL AND b.user != '' AND b.user != '0' THEN b.user
            ELSE '-'
        END AS nama_pemilik,
        tb.nama_barang, bm.nama_merk, st.nama_status, ba.nama_branch AS branch_aktif,
        -- Timeline Pengiriman
        pch.id_pengiriman_ho AS id_aktivitas,
        'B' AS sumber_tabel,
        pch.tanggal_pengajuan AS tgl_keluar,
        NULL AS tgl_diterima,
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

// Eksekusi kedua query
$resultA = mysqli_query($koneksi, $sqlA);
if (!$resultA) die('Query A Error: ' . mysqli_error($koneksi));

$resultB = mysqli_query($koneksi, $sqlB);
if (!$resultB) die('Query B Error: ' . mysqli_error($koneksi));

// ==============================================================================
// GROUPING — gabungkan A dan B ke dalam struktur per asset
// ==============================================================================
$groupedAssets = [];

// Proses hasil Query A
while ($row = mysqli_fetch_assoc($resultA)) {
    $id = (int)$row['id_barang'];
    if (!isset($groupedAssets[$id])) {
        $groupedAssets[$id] = [
            'asset'    => $row,
            'timeline' => []
        ];
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

// Proses hasil Query B
while ($row = mysqli_fetch_assoc($resultB)) {
    $id = (int)$row['id_barang'];
    if (!isset($groupedAssets[$id])) {
        $groupedAssets[$id] = [
            'asset'    => $row,
            'timeline' => []
        ];
    }
    if (!empty($row['id_aktivitas'])) {
        $groupedAssets[$id]['timeline'][] = [
            'sumber'        => 'B',
            'id_aktivitas'  => $row['id_aktivitas'],
            'tgl_keluar'    => $row['tgl_keluar'],
            'tgl_diterima'  => null,
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

// Urutkan timeline setiap asset berdasarkan tanggal aktivitas
foreach ($groupedAssets as &$entry) {
    usort($entry['timeline'], fn($a, $b) => strcmp($a['tgl_aktivitas'], $b['tgl_aktivitas']));
}
unset($entry);

$totalAsset = count($groupedAssets);

// ==============================================================================
// SUMMARY CARDS — dari 2 tabel
// ==============================================================================

// Total pengiriman dari barang_pengiriman (A)
$qA = mysqli_query($koneksi, "SELECT COUNT(DISTINCT bp.id_pengiriman) AS total FROM barang_pengiriman bp LEFT JOIN barang b ON bp.id_barang = b.id LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang WHERE DATE(bp.tanggal_keluar) BETWEEN '$safeStart' AND '$safeEnd' AND ($condA) $searchCondA AND bp.id_pengiriman IS NOT NULL");
$totalA = (int)(mysqli_fetch_assoc($qA)['total'] ?? 0);

$qA_done = mysqli_query($koneksi, "SELECT COUNT(DISTINCT bp.id_pengiriman) AS total FROM barang_pengiriman bp LEFT JOIN barang b ON bp.id_barang = b.id LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang WHERE DATE(bp.tanggal_keluar) BETWEEN '$safeStart' AND '$safeEnd' AND ($condA) $searchCondA AND bp.status_pengiriman = 'Sudah diterima'");
$totalA_done = (int)(mysqli_fetch_assoc($qA_done)['total'] ?? 0);

// Total pengiriman dari pengiriman_cabang_ho (B)
$qB = mysqli_query($koneksi, "SELECT COUNT(DISTINCT pch.id_pengiriman_ho) AS total FROM pengiriman_cabang_ho pch LEFT JOIN barang b ON pch.serial_number = b.serial_number LEFT JOIN tb_barang tb ON pch.id_barang = tb.id_barang WHERE DATE(pch.tanggal_pengajuan) BETWEEN '$safeStart' AND '$safeEnd' AND ($condB) $searchCondB");
$totalB = (int)(mysqli_fetch_assoc($qB)['total'] ?? 0);

$qB_done = mysqli_query($koneksi, "SELECT COUNT(DISTINCT pch.id_pengiriman_ho) AS total FROM pengiriman_cabang_ho pch LEFT JOIN barang b ON pch.serial_number = b.serial_number LEFT JOIN tb_barang tb ON pch.id_barang = tb.id_barang WHERE DATE(pch.tanggal_pengajuan) BETWEEN '$safeStart' AND '$safeEnd' AND ($condB) $searchCondB AND pch.status_pengiriman IN ('Sudah diterima HO', 'Selesai')");
$totalB_done = (int)(mysqli_fetch_assoc($qB_done)['total'] ?? 0);

$totalPengiriman = $totalA + $totalB;
$totalDiterima   = $totalA_done + $totalB_done;
$totalProses     = max(0, $totalPengiriman - $totalDiterima);

// Dropdown bulan
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

    <style>
        :root {
            --accent-1: #f59e0b; --accent-2: #f97316; --accent-3: #ffb020;
            --accent-soft: #fff3e3; --accent-soft-2: #fff8ef;
            --dark-1: #171717; --dark-2: #262626; --dark-3: #3f3f46;
            --text-main: #202020; --text-soft: #6b7280; --text-faint: #9ca3af;
            --line-1: #eadfce; --bg-card: #ffffff;
            --shadow-soft: 0 16px 40px rgba(0,0,0,.07);
            --shadow-hover: 0 18px 46px rgba(245,158,11,.16);
            --radius-xl: 28px; --radius-lg: 22px; --radius-md: 18px;
        }
        *    { box-sizing: border-box; }
        body { margin:0; background: radial-gradient(circle at top left,rgba(245,158,11,.14),transparent 24%), radial-gradient(circle at bottom right,rgba(249,115,22,.10),transparent 18%), linear-gradient(180deg,#fff8f0 0%,#fffaf6 42%,#fff 100%); font-family:'Plus Jakarta Sans',sans-serif; color:var(--dark-1); }
        .page-shell { padding:28px; }
        @media(max-width:991.98px){ .page-shell{padding:18px;} }

        /* Hero */
        .hero-card { position:relative; overflow:hidden; border-radius:var(--radius-xl); background:linear-gradient(135deg,#1a1a1a 0%,#2c2c2c 34%,#8d5a20 62%,#f08a14 100%); box-shadow:0 18px 45px rgba(240,138,20,.18); padding:1.55rem 1.65rem; margin-bottom:1.35rem; }
        .hero-card::before { content:""; position:absolute; width:240px; height:240px; border-radius:50%; background:rgba(255,255,255,.08); top:-100px; right:-80px; }
        .hero-card::after  { content:""; position:absolute; width:180px; height:180px; border-radius:50%; background:rgba(255,255,255,.06); left:-50px; bottom:-60px; }
        .hero-content { position:relative; z-index:2; }
        .page-title    { color:#fff; font-size:1.85rem; font-weight:800; margin-bottom:.35rem; letter-spacing:-0.02em; }
        .page-subtitle { color:rgba(255,255,255,.84); margin-bottom:0; line-height:1.7; max-width:780px; font-size:.92rem; }
        .hero-action { border:none; background:#fff; color:#111; font-weight:800; border-radius:999px; padding:.82rem 1.2rem; box-shadow:0 10px 24px rgba(0,0,0,.12); text-decoration:none; display:inline-flex; align-items:center; cursor:pointer; transition:all .2s; }
        .hero-action:hover { background:#fff7ef; color:#111; }
        .hero-action.excel { background:#16a34a; color:#fff; }
        .hero-action.excel:hover { background:#15803d; color:#fff; }

        /* Panel / Card */
        .panel-card,.report-card,.asset-card,.summary-card { background:var(--bg-card); border:1px solid rgba(245,158,11,.12); border-radius:22px; box-shadow:var(--shadow-soft); }
        .summary-card { position:relative; overflow:hidden; height:100%; padding:1.1rem; transition:all .25s ease; }
        .summary-card::before { content:""; position:absolute; inset:0 0 auto 0; height:5px; background:linear-gradient(90deg,var(--accent-1),var(--accent-2)); }
        .summary-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-hover); }
        .summary-label { font-size:.84rem; color:var(--text-soft); font-weight:700; margin-bottom:.35rem; }
        .summary-value { font-size:1.9rem; font-weight:800; line-height:1; color:var(--dark-1); margin-bottom:.3rem; }
        .summary-note  { font-size:.82rem; color:var(--text-soft); line-height:1.5; }
        .summary-icon  { width:50px; height:50px; border-radius:16px; display:inline-flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; color:#fff; background:linear-gradient(135deg,var(--accent-1),var(--accent-2)); box-shadow:0 10px 24px rgba(245,158,11,.20); }

        /* Card head */
        .card-head { padding:1.05rem 1.2rem; background:linear-gradient(135deg,#171717 0%,#2d2d2d 42%,#f08a14 100%); color:#fff; border-radius:22px 22px 0 0; }
        .card-head-title    { font-size:1rem; font-weight:800; margin-bottom:.2rem; }
        .card-head-subtitle { font-size:.84rem; color:rgba(255,255,255,.82); }
        .card-body-custom   { padding:1.15rem; background:linear-gradient(180deg,#fffdf9 0%,#fff8ef 100%); border-radius:0 0 22px 22px; }

        /* Form */
        .form-label   { font-weight:700; color:var(--dark-1); margin-bottom:.45rem; font-size:.88rem; }
        .form-control,.form-select { border-radius:14px; min-height:46px; border:1px solid #e9dcc8; box-shadow:none; padding:.8rem .95rem; }
        .form-control:focus,.form-select:focus { border-color:#f5b55a; box-shadow:0 0 0 .2rem rgba(245,158,11,.12); }
        .btn-main { border:none; border-radius:14px; font-weight:800; padding:.82rem 1rem; background:linear-gradient(135deg,var(--accent-1),var(--accent-2)); color:#fff; box-shadow:0 12px 28px rgba(245,158,11,.22); transition:all .22s ease; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; text-decoration:none; }
        .btn-main:hover { color:#fff; transform:translateY(-1px); filter:brightness(.98); }
        .search-wrap { position:relative; }
        .search-wrap .form-control { padding-left:2.8rem; }
        .search-wrap .search-icon  { position:absolute; left:.95rem; top:50%; transform:translateY(-50%); color:#d98710; font-size:1rem; }
        .period-chip { display:inline-flex; align-items:center; gap:.45rem; border-radius:999px; background:var(--accent-soft); color:#9a5410; border:1px solid rgba(245,158,11,.18); padding:.38rem .75rem; font-size:.8rem; font-weight:800; }

        /* Asset Cards */
        .asset-card+.asset-card { margin-top:1rem; }
        .asset-top   { padding:1rem; background:linear-gradient(180deg,#fffbf6 0%,#fff4e8 100%); border-bottom:1px solid #efe1cf; border-radius:22px 22px 0 0; }
        .asset-title { font-size:1rem; font-weight:800; color:var(--dark-1); margin-bottom:.2rem; }
        .asset-meta  { color:var(--text-soft); font-size:.88rem; line-height:1.6; }
        .asset-body  { padding:1rem; }
        .info-box    { background:linear-gradient(180deg,#fffaf4 0%,#fff3e7 100%); border:1px solid rgba(245,158,11,.14); border-radius:16px; padding:.95rem 1rem; color:var(--text-soft); font-size:.88rem; line-height:1.7; margin-bottom:1rem; }

        /* Timeline */
        .timeline { position:relative; padding-left:1.2rem; }
        .timeline::before { content:""; position:absolute; top:0; bottom:0; left:.32rem; width:2px; background:linear-gradient(180deg,rgba(245,158,11,.30),rgba(249,115,22,.12)); }
        .timeline-item { position:relative; margin-bottom:1rem; padding-left:.9rem; }
        .timeline-item:last-child { margin-bottom:0; }
        .timeline-dot  { position:absolute; left:-1.2rem; top:.4rem; width:14px; height:14px; border-radius:50%; background:linear-gradient(135deg,var(--accent-1),var(--accent-2)); box-shadow:0 0 0 4px #fff1df; }
        .timeline-dot.dot-b { background:linear-gradient(135deg,#7c3aed,#a855f7); box-shadow:0 0 0 4px #f5f3ff; }
        .timeline-card { background:#fff; border:1px solid #f2e4d4; border-radius:18px; overflow:hidden; }
        .timeline-head { padding:.95rem 1rem; background:#fff8f0; border-bottom:1px solid #f0e3d6; }
        .timeline-title    { font-size:.96rem; font-weight:800; color:var(--dark-1); margin-bottom:.15rem; }
        .timeline-subtitle { font-size:.82rem; color:var(--text-soft); }
        .timeline-body { padding:1rem; }
        .detail-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.8rem; margin-bottom:1rem; }
        @media(max-width:575.98px){ .detail-grid { grid-template-columns:1fr; } .log-note-grid { grid-template-columns:1fr !important; } }
        .detail-box   { background:#fffdfb; border:1px solid #efe4d8; border-radius:14px; padding:.8rem .9rem; }
        .detail-label { font-size:.74rem; font-weight:800; color:#b45309; text-transform:uppercase; letter-spacing:.04em; margin-bottom:.25rem; }
        .detail-value { font-size:.92rem; font-weight:700; color:var(--dark-1); line-height:1.55; word-break:break-word; }
        .log-note-grid { display:grid; grid-template-columns:1fr 1fr; gap:.8rem; }
        .log-note-card  { background:linear-gradient(180deg,#fffaf4 0%,#fffdfb 100%); border:1px solid rgba(245,158,11,.14); border-radius:14px; padding:.85rem .9rem; }
        .log-note-title { font-size:.82rem; font-weight:800; color:#9a5410; margin-bottom:.35rem; }
        .log-note-text  { font-size:.88rem; line-height:1.65; color:#475569; }

        /* Source badge (A/B label di timeline) */
        .source-badge { display:inline-flex; align-items:center; gap:.35rem; border-radius:999px; padding:.28rem .6rem; font-size:.72rem; font-weight:800; }
        .source-badge.src-a { background:#fff3e3; color:#9a5410; border:1px solid rgba(245,158,11,.2); }
        .source-badge.src-b { background:#f5f3ff; color:#6d28d9; border:1px solid rgba(167,139,250,.3); }

        /* Status Badges */
        .status-badge  { display:inline-flex; align-items:center; justify-content:center; border-radius:999px; padding:.48rem .82rem; font-size:.76rem; font-weight:800; white-space:nowrap; }
        .status-done   { background:linear-gradient(135deg,#171717,#2f2f2f); color:#fff; }
        .status-road   { background:linear-gradient(135deg,var(--accent-1),var(--accent-2)); color:#fff; }
        .status-pack   { background:linear-gradient(135deg,#ffe2b8,#ffd089); color:#8a4a00; }
        .status-reject { background:linear-gradient(135deg,#dc2626,#ef4444); color:#fff; }
        .status-wait   { background:#fff7ed; color:#7c4a13; border:1px solid rgba(245,158,11,.24); }

        /* Empty */
        .empty-state   { text-align:center; padding:2rem 1rem; color:var(--text-soft); }
        .empty-state i { display:block; font-size:1.7rem; margin-bottom:.45rem; color:var(--accent-2); }
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
                            <h1 class="page-title">Laporan Asset</h1>
                            <p class="page-subtitle">Laporan lengkap seluruh aktivitas asset — pengiriman HO ke Cabang maupun sebaliknya — siap cetak PDF atau ekspor Excel.</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if (can('laporan.pdf')): ?>
                                <button type="button" class="hero-action" onclick="prosesCetak(this,'lengkap')">
                                    <i class="bi bi-printer me-2"></i>Cetak PDF
                                </button>
                            <?php endif; ?>
                            <?php if (can('laporan.excel')): ?>
                                <a href="export_excel.php?<?= http_build_query($_GET) ?>" class="hero-action excel">
                                    <i class="bi bi-file-excel me-2"></i>Cetak Excel
                                </a>
                            <?php endif; ?>
                            <a href="<?= h(build_url()) ?>" class="hero-action">
                                <i class="bi bi-arrow-counterclockwise me-2"></i>Reset
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Filter -->
                <div class="panel-card mb-4 no-print">
                    <div class="card-head">
                        <div class="card-head-title">Filter Laporan</div>
                        <div class="card-head-subtitle">Pilih periode dan cari data yang ingin ditampilkan.</div>
                    </div>
                    <div class="card-body-custom">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-lg-2">
                                <label class="form-label">Jenis Filter</label>
                                <select name="periode" id="periodeFilter" class="form-select">
                                    <option value="minggu" <?= $range['periode']==='minggu' ?'selected':'' ?>>Per Minggu</option>
                                    <option value="bulan"  <?= $range['periode']==='bulan'  ?'selected':'' ?>>Per Bulan</option>
                                    <option value="tahun"  <?= $range['periode']==='tahun'  ?'selected':'' ?>>Per Tahun</option>
                                    <option value="custom" <?= $range['periode']==='custom' ?'selected':'' ?>>Custom Tanggal</option>
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
                                <label class="form-label">Minggu</label>
                                <input type="week" name="minggu" class="form-control" value="<?= h($minggu) ?>">
                            </div>
                            <div class="col-lg-2 filter-custom" style="display:none;">
                                <label class="form-label">Tanggal Awal</label>
                                <input type="date" name="custom_awal" class="form-control" value="<?= h($customAwal) ?>">
                            </div>
                            <div class="col-lg-2 filter-custom" style="display:none;">
                                <label class="form-label">Tanggal Akhir</label>
                                <input type="date" name="custom_akhir" class="form-control" value="<?= h($customAkhir) ?>">
                            </div>
                            <div class="col-lg-3">
                                <label class="form-label">Cari Asset</label>
                                <div class="search-wrap">
                                    <i class="bi bi-search search-icon"></i>
                                    <input type="text" name="cari" class="form-control" placeholder="Nama barang, serial, resi..." value="<?= h($cariInput) ?>">
                                </div>
                            </div>
                            <div class="col-lg-1">
                                <div class="d-grid">
                                    <button type="submit" class="btn-main"><i class="bi bi-funnel me-2"></i>Tampilkan</button>
                                </div>
                            </div>
                        </form>
                        <div class="mt-3">
                            <span class="period-chip"><i class="bi bi-calendar3"></i> <?= h($periodeLabel) ?></span>
                            <?php if ($cariInput !== ''): ?>
                                <span class="period-chip ms-2"><i class="bi bi-search"></i> Cari: <?= h($cariInput) ?></span>
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
                                    <div class="summary-label">Total Asset</div>
                                    <div class="summary-value"><?= $totalAsset ?></div>
                                    <div class="summary-note">Asset pada periode ini</div>
                                </div>
                                <div class="summary-icon"><i class="bi bi-box-seam"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="summary-label">Total Pengiriman</div>
                                    <div class="summary-value"><?= $totalPengiriman ?></div>
                                    <div class="summary-note">HO↔Cabang gabungan</div>
                                </div>
                                <div class="summary-icon"><i class="bi bi-truck"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="summary-label">Sudah Diterima</div>
                                    <div class="summary-value"><?= $totalDiterima ?></div>
                                    <div class="summary-note">Pengiriman selesai</div>
                                </div>
                                <div class="summary-icon"><i class="bi bi-check2-circle"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="summary-label">Masih Proses</div>
                                    <div class="summary-value"><?= $totalProses ?></div>
                                    <div class="summary-note">Belum selesai diterima</div>
                                </div>
                                <div class="summary-icon"><i class="bi bi-hourglass-split"></i></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Report Content -->
                <div class="report-card">
                    <div class="card-body-custom">
                        <?php if (empty($groupedAssets)): ?>
                            <div class="empty-state">
                                <i class="bi bi-inbox"></i>Tidak ada data laporan pada periode dan filter ini.
                            </div>
                        <?php else: ?>
                            <?php foreach ($groupedAssets as $entry): ?>
                                <?php $asset = $entry['asset']; ?>
                                <div class="asset-card">

                                    <!-- Asset Header -->
                                    <div class="asset-top">
                                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                            <div>
                                                <div class="asset-title">
                                                    <?= h($asset['nama_barang'] ?? '-') ?> — <?= h($asset['nama_merk'] ?? '-') ?>
                                                </div>
                                                <div class="asset-meta">
                                                    Asset: <b><?= h($asset['no_asset'] ?? '-') ?></b> &nbsp;|&nbsp;
                                                    SN: <b><?= h($asset['serial_number'] ?? '-') ?></b> &nbsp;|&nbsp;
                                                    PIC: <b><?= h($asset['nama_pemilik'] ?? '-') ?></b>
                                                </div>
                                            </div>
                                            <?php if (can('laporan.pdf')): ?>
                                                <button type="button" class="btn-main no-print" onclick="prosesCetak(this,'tunggal',<?= (int)$asset['id_barang'] ?>)">
                                                    <i class="bi bi-printer me-2"></i>Cetak Asset Ini
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Asset Body -->
                                    <div class="asset-body">
                                        <!-- Info Box -->
                                        <div class="info-box">
                                            <div><b>Ringkasan Asset</b></div>
                                            <div>Cabang aktif: <b><?= h($asset['branch_aktif'] ?? '-') ?></b></div>
                                            <div>Tanggal masuk: <b><?= h($asset['tanggal_terima'] ?? '-') ?></b></div>
                                            <div>Status barang: <b><?= h($asset['nama_status'] ?? '-') ?></b></div>
                                            <?php if (($asset['bermasalah'] ?? '') === 'Iya'): ?>
                                                <div>Kondisi: <b class="text-danger">Bermasalah</b> — <?= h($asset['keterangan_masalah'] ?? '-') ?></div>
                                            <?php else: ?>
                                                <div>Kondisi: <b>Normal</b></div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Timeline -->
                                        <?php if (empty($entry['timeline'])): ?>
                                            <div class="timeline">
                                                <div class="timeline-item">
                                                    <div class="timeline-dot"></div>
                                                    <div class="timeline-card">
                                                        <div class="timeline-head">
                                                            <div class="timeline-title">Belum ada aktivitas pengiriman</div>
                                                            <div class="timeline-subtitle">Asset masih berada di cabang aktif saat ini.</div>
                                                        </div>
                                                        <div class="timeline-body">
                                                            <div class="detail-grid">
                                                                <div class="detail-box">
                                                                    <div class="detail-label">Cabang Aktif</div>
                                                                    <div class="detail-value"><?= h($asset['branch_aktif'] ?? '-') ?></div>
                                                                </div>
                                                                <div class="detail-box">
                                                                    <div class="detail-label">Tanggal Masuk</div>
                                                                    <div class="detail-value"><?= h($asset['tanggal_terima'] ?? '-') ?></div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="timeline">
                                                <?php foreach ($entry['timeline'] as $idx => $step): ?>
                                                    <?php $isB = ($step['sumber'] === 'B'); ?>
                                                    <div class="timeline-item">
                                                        <div class="timeline-dot <?= $isB ? 'dot-b' : '' ?>"></div>
                                                        <div class="timeline-card">
                                                            <div class="timeline-head d-flex justify-content-between align-items-start flex-wrap gap-2">
                                                                <div>
                                                                    <div class="timeline-title d-flex align-items-center gap-2">
                                                                        Aktivitas <?= $idx + 1 ?>
                                                                        <?php if ($isB): ?>
                                                                            <span class="source-badge src-b"><i class="bi bi-arrow-up-circle"></i> Cabang → HO</span>
                                                                        <?php else: ?>
                                                                            <span class="source-badge src-a"><i class="bi bi-arrow-down-circle"></i> HO → Cabang</span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <div class="timeline-subtitle">
                                                                        Dari <b><?= h($step['branch_asal'] ?? '-') ?></b> ke <b><?= h($step['branch_tujuan'] ?? '-') ?></b>
                                                                    </div>
                                                                </div>
                                                                <div><?= status_badge((string)($step['status'] ?? 'Belum dikirim')) ?></div>
                                                            </div>
                                                            <div class="timeline-body">
                                                                <div class="detail-grid">
                                                                    <div class="detail-box">
                                                                        <div class="detail-label">Dari</div>
                                                                        <div class="detail-value"><?= h($step['branch_asal'] ?? '-') ?></div>
                                                                    </div>
                                                                    <div class="detail-box">
                                                                        <div class="detail-label">Ke</div>
                                                                        <div class="detail-value"><?= h($step['branch_tujuan'] ?? '-') ?></div>
                                                                    </div>
                                                                    <div class="detail-box">
                                                                        <div class="detail-label">Tanggal Keluar</div>
                                                                        <div class="detail-value"><?= h($step['tgl_keluar'] ?? '-') ?></div>
                                                                    </div>
                                                                    <div class="detail-box">
                                                                        <div class="detail-label">Tanggal Diterima</div>
                                                                        <div class="detail-value"><?= h($step['tgl_diterima'] ?? ($isB ? 'Menunggu konfirmasi' : '-')) ?></div>
                                                                    </div>
                                                                    <?php if (!$isB): ?>
                                                                    <div class="detail-box">
                                                                        <div class="detail-label">Jasa Pengiriman</div>
                                                                        <div class="detail-value"><?= h($step['jasa'] ?? '-') ?></div>
                                                                    </div>
                                                                    <?php endif; ?>
                                                                    <div class="detail-box">
                                                                        <div class="detail-label">Nomor Resi</div>
                                                                        <div class="detail-value"><?= h($step['resi'] ?? '-') ?></div>
                                                                    </div>
                                                                </div>
                                                                <div class="log-note-grid">
                                                                    <div class="log-note-card">
                                                                        <div class="log-note-title">Catatan Pengiriman</div>
                                                                        <div class="log-note-text">
                                                                            <?php if (!empty($step['jasa'])): ?>
                                                                                Dikirim via <b><?= h($step['jasa']) ?></b>.
                                                                            <?php endif; ?>
                                                                            <?php if (!empty($step['resi'])): ?>
                                                                                Nomor resi <b><?= h($step['resi']) ?></b>.
                                                                            <?php else: ?>
                                                                                Nomor resi belum tersedia.
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                    <div class="log-note-card">
                                                                        <div class="log-note-title">Catatan Penerimaan</div>
                                                                        <div class="log-note-text">
                                                                            <?php
                                                                            $st = strtolower(trim($step['status'] ?? ''));
                                                                            $isDone = in_array($st, ['sudah diterima','sudah diterima ho','selesai']);
                                                                            if ($isDone): ?>
                                                                                Diterima oleh <b><?= h($step['nama_penerima'] ?? '-') ?></b>
                                                                                <?php if (!empty($step['tgl_diterima'])): ?>
                                                                                    pada <b><?= h($step['tgl_diterima']) ?></b>.
                                                                                <?php else: ?>
                                                                                    (tanggal belum tercatat).
                                                                                <?php endif; ?>
                                                                            <?php else: ?>
                                                                                Belum ada konfirmasi penerimaan barang.
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

            </div><!-- page-shell -->
        </div><!-- mainContent -->
    </div>
</div>

<script>
    // Filter periode toggle
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

    // Pop-up print sistem
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
</body>
</html>