<?php
/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'riwayat.view');

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

function current_page_url(): string
{
    return basename($_SERVER['PHP_SELF']);
}

function build_url(array $params = []): string
{
    $base = current_page_url();
    if (empty($params)) return $base;
    return $base . '?' . http_build_query($params);
}

function activityBadge(string $jenis): string
{
    if ($jenis === 'Keluar') {
        return '<span class="badge rounded-pill bg-danger"><i class="bi bi-box-arrow-up me-1"></i>Keluar</span>';
    }
    return '<span class="badge rounded-pill bg-success"><i class="bi bi-box-arrow-in-down me-1"></i>Masuk</span>';
}

function shippingBadge(string $status): string
{
    $map = [
        'sedang dikemas'            => ['bg-warning text-dark', 'bi-box-seam'],
        'menunggu persetujuan admin'=> ['bg-warning text-dark', 'bi-hourglass-split'],
        'sedang perjalanan'         => ['bg-primary',           'bi-truck'],
        'sudah diterima'            => ['bg-success',           'bi-check-circle'],
        'sudah diterima ho'         => ['bg-success',           'bi-check-circle'],
        'selesai'                   => ['bg-success',           'bi-check-circle'],
        'ditolak'                   => ['bg-danger',            'bi-x-circle'],
    ];
    $key = strtolower(trim($status));
    [$class, $icon] = $map[$key] ?? ['bg-secondary', 'bi-clock-history'];
    return '<span class="badge rounded-pill ' . $class . '"><i class="bi ' . $icon . ' me-1"></i>' . h($status) . '</span>';
}

// ==============================================================================
// FILTER & PAGINATION PARAMS
// ==============================================================================
$search_input = isset($_GET['cari'])         ? trim((string) $_GET['cari'])          : '';
$filter       = isset($_GET['filter'])       ? trim((string) $_GET['filter'])        : 'semua';
$tanggalAwal  = isset($_GET['tanggal_awal']) ? trim((string) $_GET['tanggal_awal'])  : '';
$tanggalAkhir = isset($_GET['tanggal_akhir'])? trim((string) $_GET['tanggal_akhir']) : '';

if (!in_array($filter, ['semua', 'masuk', 'keluar'], true)) $filter = 'semua';

$allowed_limits = [5, 25, 50, 100];
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
if (!in_array($limit, $allowed_limits, true)) $limit = 25;

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

// ==============================================================================
// QUERY BUILDER — UNION kedua tabel pengiriman
// ==============================================================================
// Nama penerima HO selalu "Pak Deni" untuk pengiriman dari Cabang ke HO
$penerimaHO = 'Pak Deni';

/**
 * UNION terdiri dari 2 bagian:
 *
 * PART A — barang_pengiriman (HO → Cabang)
 *   Admin  : ini adalah KELUAR (HO yang kirim)
 *   User   : ini adalah MASUK  (Cabang yang terima)
 *
 * PART B — pengiriman_cabang_ho (Cabang → HO)
 *   Admin  : ini adalah MASUK  (HO yang terima)
 *   User   : ini adalah KELUAR (Cabang yang kirim)
 */

$search = mysqli_real_escape_string($koneksi, $search_input);
$safeAwal  = mysqli_real_escape_string($koneksi, $tanggalAwal);
$safeAkhir = mysqli_real_escape_string($koneksi, $tanggalAkhir);

// --------------------------------------------------------------------------
// PART A: barang_pengiriman (HO → Cabang)
// --------------------------------------------------------------------------
$partA_jenis = $isAdmin ? 'Keluar' : 'Masuk';

$partA_where = ["1=1"];

if (!$isAdmin) {
    // User hanya lihat kiriman yang ditujukan ke cabangnya
    $partA_where[] = "bp.branch_tujuan = $myBranchId";
}
// filter masuk/keluar
if ($filter === 'masuk'  && !$isAdmin) $partA_where[] = "1=1"; 
if ($filter === 'masuk'  &&  $isAdmin) $partA_where[] = "1=0"; 
if ($filter === 'keluar' && !$isAdmin) $partA_where[] = "1=0"; 
if ($filter === 'keluar' &&  $isAdmin) $partA_where[] = "1=1"; 

// pencarian teks
if ($search_input !== '') {
    $partA_where[] = "(tb.nama_barang LIKE '%$search%' OR b.no_asset LIKE '%$search%' OR b.serial_number LIKE '%$search%'
                    OR b.user LIKE '%$search%' OR bp.nama_penerima LIKE '%$search%'
                    OR bp.nomor_resi_keluar LIKE '%$search%' OR bp.status_pengiriman LIKE '%$search%'
                    OR br_asal.nama_branch LIKE '%$search%' OR br_tujuan.nama_branch LIKE '%$search%')";
}
// filter tanggal
if ($tanggalAwal !== '' && $tanggalAkhir !== '') {
    $partA_where[] = "DATE(bp.tanggal_keluar) BETWEEN '$safeAwal' AND '$safeAkhir'";
} elseif ($tanggalAwal !== '') {
    $partA_where[] = "DATE(bp.tanggal_keluar) >= '$safeAwal'";
} elseif ($tanggalAkhir !== '') {
    $partA_where[] = "DATE(bp.tanggal_keluar) <= '$safeAkhir'";
}

$partA_whereSql = implode(' AND ', $partA_where);

$partA = "
    SELECT
        'A'                          AS sumber,
        '$partA_jenis'               AS jenis,
        b.id                         AS id_barang,
        b.no_asset,
        b.serial_number,
        tb.nama_barang,
        bm.nama_merk,
        CASE 
            WHEN b.user IS NOT NULL AND b.user != '' AND b.user != '0' THEN b.user
            ELSE (SELECT pch2.pemilik_barang FROM pengiriman_cabang_ho pch2 WHERE pch2.serial_number = b.serial_number AND pch2.pemilik_barang IS NOT NULL AND pch2.pemilik_barang != '' AND pch2.pemilik_barang != '0' ORDER BY pch2.id_pengiriman_ho DESC LIMIT 1)
        END                          AS nama_pemilik,
        CASE
            WHEN bp.nama_penerima IS NOT NULL AND bp.nama_penerima != '' THEN bp.nama_penerima
            ELSE b.user
        END                          AS nama_penerima,
        bp.nomor_resi_keluar         AS resi_keluar,
        NULL                         AS resi_masuk,
        bp.status_pengiriman,
        bp.jasa_pengiriman,
        br_asal.nama_branch          AS nama_branch_asal,
        br_tujuan.nama_branch        AS nama_branch_tujuan,
        DATE(bp.tanggal_keluar)      AS tanggal_aktivitas,
        bp.tanggal_keluar,
        bp.tanggal_diterima
    FROM barang_pengiriman bp
    JOIN barang b          ON bp.id_barang    = b.id
    JOIN tb_barang tb      ON b.id_barang     = tb.id_barang
    LEFT JOIN tb_merk bm   ON b.id_merk       = bm.id_merk
    LEFT JOIN tb_branch br_asal  ON bp.branch_asal  = br_asal.id_branch
    LEFT JOIN tb_branch br_tujuan ON bp.branch_tujuan = br_tujuan.id_branch
    WHERE $partA_whereSql
";

// --------------------------------------------------------------------------
// PART B: pengiriman_cabang_ho (Cabang → HO)
// --------------------------------------------------------------------------
$partB_jenis = $isAdmin ? 'Masuk' : 'Keluar';

$partB_where = ["1=1"];

if (!$isAdmin) {
    // User hanya lihat kiriman dari cabangnya sendiri
    $partB_where[] = "pch.branch_asal = $myBranchId";
}
// filter masuk/keluar
if ($filter === 'masuk'  &&  $isAdmin) $partB_where[] = "1=1"; // admin: B = masuk, tampilkan
if ($filter === 'masuk'  && !$isAdmin) $partB_where[] = "1=0"; // user: B = keluar, sembunyikan
if ($filter === 'keluar' &&  $isAdmin) $partB_where[] = "1=0"; // admin: B = masuk, sembunyikan
if ($filter === 'keluar' && !$isAdmin) $partB_where[] = "1=1"; // user: B = keluar, tampilkan

// pencarian teks
if ($search_input !== '') {
    $partB_where[] = "(tb.nama_barang LIKE '%$search%' OR b.no_asset LIKE '%$search%' OR b.serial_number LIKE '%$search%'
                    OR pch.pemilik_barang LIKE '%$search%' OR pch.nomor_resi_keluar LIKE '%$search%'
                    OR pch.status_pengiriman LIKE '%$search%' OR br_asal.nama_branch LIKE '%$search%')";
}
// filter tanggal
if ($tanggalAwal !== '' && $tanggalAkhir !== '') {
    $partB_where[] = "DATE(pch.tanggal_pengajuan) BETWEEN '$safeAwal' AND '$safeAkhir'";
} elseif ($tanggalAwal !== '') {
    $partB_where[] = "DATE(pch.tanggal_pengajuan) >= '$safeAwal'";
} elseif ($tanggalAkhir !== '') {
    $partB_where[] = "DATE(pch.tanggal_pengajuan) <= '$safeAkhir'";
}

$partB_whereSql = implode(' AND ', $partB_where);

$partB = "
    SELECT
        'B'                              AS sumber,
        '$partB_jenis'                   AS jenis,
        b.id                             AS id_barang,
        b.no_asset,
        b.serial_number,
        tb.nama_barang,
        bm.nama_merk,
        pch.pemilik_barang               AS nama_pemilik,
        '$penerimaHO'                    AS nama_penerima,
        NULL                             AS resi_keluar,
        pch.nomor_resi_keluar            AS resi_masuk,
        pch.status_pengiriman,
        NULL                             AS jasa_pengiriman,
        br_asal.nama_branch              AS nama_branch_asal,
        'Kantor Pusat HO'                AS nama_branch_tujuan,
        DATE(pch.tanggal_pengajuan)      AS tanggal_aktivitas,
        pch.tanggal_pengajuan            AS tanggal_keluar,
        NULL                             AS tanggal_diterima
    FROM pengiriman_cabang_ho pch
    JOIN tb_barang tb          ON pch.id_barang   = tb.id_barang
    LEFT JOIN barang b         ON pch.serial_number = b.serial_number
    LEFT JOIN tb_merk bm       ON b.id_merk        = bm.id_merk
    LEFT JOIN tb_branch br_asal ON pch.branch_asal = br_asal.id_branch
    WHERE $partB_whereSql
";

// ==============================================================================
// PAGINATION & COUNT
// ==============================================================================
$unionSql    = "($partA) UNION ALL ($partB)";
$countResult = mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM ($unionSql) AS u");
if (!$countResult) die(mysqli_error($koneksi));
$total_rows  = (int) (mysqli_fetch_assoc($countResult)['total'] ?? 0);
$total_pages = max(1, (int) ceil($total_rows / $limit));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;

// ==============================================================================
// SUMMARY CARDS — hitung terpisah untuk Masuk & Keluar
// ==============================================================================

// Masuk
$masukA_jenis = !$isAdmin ? "'Masuk'" : "'1=0'"; // Part A untuk user = masuk
$masukB_jenis = $isAdmin  ? "'Masuk'" : "'1=0'"; // Part B untuk admin = masuk

// Hitung total masuk: gabungkan filter aktif tapi paksa filter = masuk
$partA_masuk_where = array_filter($partA_where, fn($w) => $w !== '1=0' && $w !== '1=1');
$partB_masuk_where = array_filter($partB_where, fn($w) => $w !== '1=0' && $w !== '1=1');

// Re-build part A masuk
$am_where = ["1=1"];
if (!$isAdmin) $am_where[] = "bp.branch_tujuan = $myBranchId";
if ($search_input !== '') $am_where[] = "(tb.nama_barang LIKE '%$search%' OR b.no_asset LIKE '%$search%' OR b.serial_number LIKE '%$search%' OR bp.nomor_resi_keluar LIKE '%$search%' OR bp.status_pengiriman LIKE '%$search%')";
if ($tanggalAwal !== '' && $tanggalAkhir !== '') $am_where[] = "DATE(bp.tanggal_keluar) BETWEEN '$safeAwal' AND '$safeAkhir'";
elseif ($tanggalAwal !== '') $am_where[] = "DATE(bp.tanggal_keluar) >= '$safeAwal'";
elseif ($tanggalAkhir !== '') $am_where[] = "DATE(bp.tanggal_keluar) <= '$safeAkhir'";
if ($isAdmin) $am_where[] = "1=0"; // admin: A = keluar, jangan hitung di masuk

// Re-build part B masuk
$bm_where = ["1=1"];
if (!$isAdmin) $bm_where[] = "1=0"; // user: B = keluar, jangan hitung di masuk
if (!$isAdmin || true) { // admin: B = masuk
    if ($isAdmin) {
        if ($search_input !== '') $bm_where[] = "(tb.nama_barang LIKE '%$search%' OR b.no_asset LIKE '%$search%' OR b.serial_number LIKE '%$search%' OR pch.nomor_resi_keluar LIKE '%$search%' OR pch.status_pengiriman LIKE '%$search%')";
        if ($tanggalAwal !== '' && $tanggalAkhir !== '') $bm_where[] = "DATE(pch.tanggal_pengajuan) BETWEEN '$safeAwal' AND '$safeAkhir'";
        elseif ($tanggalAwal !== '') $bm_where[] = "DATE(pch.tanggal_pengajuan) >= '$safeAwal'";
        elseif ($tanggalAkhir !== '') $bm_where[] = "DATE(pch.tanggal_pengajuan) <= '$safeAkhir'";
    }
}

$countMasukA = "SELECT COUNT(*) AS total FROM barang_pengiriman bp JOIN barang b ON bp.id_barang = b.id JOIN tb_barang tb ON b.id_barang = tb.id_barang LEFT JOIN tb_branch br_asal ON bp.branch_asal = br_asal.id_branch LEFT JOIN tb_branch br_tujuan ON bp.branch_tujuan = br_tujuan.id_branch WHERE " . implode(' AND ', $am_where);
$countMasukB = "SELECT COUNT(*) AS total FROM pengiriman_cabang_ho pch JOIN tb_barang tb ON pch.id_barang = tb.id_barang LEFT JOIN barang b ON pch.serial_number = b.serial_number LEFT JOIN tb_branch br_asal ON pch.branch_asal = br_asal.id_branch WHERE " . implode(' AND ', $bm_where);

$rA = mysqli_query($koneksi, $countMasukA);
$rB = mysqli_query($koneksi, $countMasukB);
$totalMasuk = ((int)(mysqli_fetch_assoc($rA)['total'] ?? 0)) + ((int)(mysqli_fetch_assoc($rB)['total'] ?? 0));

// Hitung total keluar (kebalikan masuk)
$ak_where = ["1=1"];
if ($isAdmin) $ak_where[] = "1=1"; // admin: A = keluar
if (!$isAdmin) $ak_where[] = "1=0"; // user: A = masuk, jangan hitung di keluar

$bk_where = ["1=1"];
if (!$isAdmin) { // user: B = keluar
    $bk_where[] = "pch.branch_asal = $myBranchId";
    if ($search_input !== '') $bk_where[] = "(tb.nama_barang LIKE '%$search%' OR b.no_asset LIKE '%$search%' OR b.serial_number LIKE '%$search%' OR pch.nomor_resi_keluar LIKE '%$search%' OR pch.status_pengiriman LIKE '%$search%')";
    if ($tanggalAwal !== '' && $tanggalAkhir !== '') $bk_where[] = "DATE(pch.tanggal_pengajuan) BETWEEN '$safeAwal' AND '$safeAkhir'";
    elseif ($tanggalAwal !== '') $bk_where[] = "DATE(pch.tanggal_pengajuan) >= '$safeAwal'";
    elseif ($tanggalAkhir !== '') $bk_where[] = "DATE(pch.tanggal_pengajuan) <= '$safeAkhir'";
} else {
    $bk_where[] = "1=0"; // admin: B = masuk, jangan hitung di keluar
}

if ($isAdmin && $search_input !== '') $ak_where[] = "(tb.nama_barang LIKE '%$search%' OR b.no_asset LIKE '%$search%' OR b.serial_number LIKE '%$search%' OR bp.nomor_resi_keluar LIKE '%$search%' OR bp.status_pengiriman LIKE '%$search%')";
if ($isAdmin && $tanggalAwal !== '' && $tanggalAkhir !== '') $ak_where[] = "DATE(bp.tanggal_keluar) BETWEEN '$safeAwal' AND '$safeAkhir'";
elseif ($isAdmin && $tanggalAwal !== '') $ak_where[] = "DATE(bp.tanggal_keluar) >= '$safeAwal'";
elseif ($isAdmin && $tanggalAkhir !== '') $ak_where[] = "DATE(bp.tanggal_keluar) <= '$safeAkhir'";

$countKeluarA = "SELECT COUNT(*) AS total FROM barang_pengiriman bp JOIN barang b ON bp.id_barang = b.id JOIN tb_barang tb ON b.id_barang = tb.id_barang WHERE " . implode(' AND ', $ak_where);
$countKeluarB = "SELECT COUNT(*) AS total FROM pengiriman_cabang_ho pch JOIN tb_barang tb ON pch.id_barang = tb.id_barang LEFT JOIN barang b ON pch.serial_number = b.serial_number LEFT JOIN tb_branch br_asal ON pch.branch_asal = br_asal.id_branch WHERE " . implode(' AND ', $bk_where);

$rKA = mysqli_query($koneksi, $countKeluarA);
$rKB = mysqli_query($koneksi, $countKeluarB);
$totalKeluar = ((int)(mysqli_fetch_assoc($rKA)['total'] ?? 0)) + ((int)(mysqli_fetch_assoc($rKB)['total'] ?? 0));
$totalSemua  = $total_rows;

// ==============================================================================
// EKSEKUSI DATA UTAMA
// ==============================================================================
$mainQuery = mysqli_query($koneksi,
    "SELECT * FROM ($unionSql) AS gabungan
     ORDER BY tanggal_aktivitas DESC, id_barang DESC
     LIMIT $offset, $limit"
);
if (!$mainQuery) die(mysqli_error($koneksi));

$from_row = $total_rows > 0 ? $offset + 1 : 0;
$to_row   = min($offset + $limit, $total_rows);

// ==============================================================================
// UI STATE
// ==============================================================================
$btnSemua  = $filter === 'semua'  ? 'btn-mode is-active' : 'btn-mode';
$btnMasuk  = $filter === 'masuk'  ? 'btn-mode is-active' : 'btn-mode';
$btnKeluar = $filter === 'keluar' ? 'btn-mode is-active' : 'btn-mode';

$tableTitle = 'Riwayat Semua Asset';
$tableIcon  = 'bi bi-clock-history';
if ($filter === 'masuk')  { $tableTitle = 'Riwayat Asset Masuk';  $tableIcon = 'bi bi-box-arrow-in-down'; }
if ($filter === 'keluar') { $tableTitle = 'Riwayat Asset Keluar'; $tableIcon = 'bi bi-box-arrow-up'; }

$hasFilter = ($search_input !== '' || $tanggalAwal !== '' || $tanggalAkhir !== '' || $filter !== 'semua');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Asset - IT Asset Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --orange-1: #ff7a00; --orange-2: #ff9800; --orange-3: #ffb000;
            --dark-1: #111111; --dark-2: #1f1f1f;
            --text-main: #1e1e1e; --text-soft: #6b7280;
            --surface: #ffffff;
            --border-soft: rgba(255,152,0,0.14);
            --shadow-soft: 0 12px 36px rgba(17,17,17,0.07);
            --shadow-hover: 0 18px 42px rgba(255,122,0,0.14);
            --radius-xl: 28px;
        }
        * { box-sizing: border-box; }
        body { background: radial-gradient(circle at top left,rgba(255,176,0,.16),transparent 28%),radial-gradient(circle at bottom right,rgba(255,122,0,.10),transparent 20%),linear-gradient(180deg,#fff8f1 0%,#fffaf5 36%,#fff 100%); font-family:'Plus Jakarta Sans',sans-serif; color:var(--text-main); }
        .page-shell { padding:28px; }

        /* Hero */
        .page-hero { position:relative; overflow:hidden; border-radius:var(--radius-xl); background:linear-gradient(135deg,rgba(17,17,17,.94) 0%,rgba(42,42,42,.90) 30%,rgba(255,122,0,.96) 100%); box-shadow:0 18px 45px rgba(255,122,0,.20); padding:1.6rem; margin-bottom:1.4rem; }
        .page-hero::before { content:""; position:absolute; width:240px; height:240px; border-radius:50%; background:rgba(255,255,255,.08); top:-90px; right:-60px; }
        .page-hero::after  { content:""; position:absolute; width:180px; height:180px; border-radius:50%; background:rgba(255,209,102,.18); left:-60px; bottom:-70px; }
        .hero-content { position:relative; z-index:2; }
        .page-title { color:#fff; font-size:1.8rem; font-weight:800; margin-bottom:.35rem; letter-spacing:-0.02em; }
        .page-desc  { color:rgba(255,255,255,.84); margin-bottom:0; line-height:1.7; max-width:780px; font-size:.94rem; }

        /* Toolbar */
        .ui-card { background:var(--surface); border:1px solid var(--border-soft); border-radius:22px; box-shadow:var(--shadow-soft); }
        .toolbar-card { padding:1.15rem; margin-bottom:1.25rem; }
        .toolbar-label { font-size:.84rem; font-weight:700; color:var(--dark-2); margin-bottom:.55rem; }
        .form-control,.form-select { border-radius:16px; border:1px solid #e6dfd2; box-shadow:none; padding:.9rem 1rem; font-size:.94rem; }
        .form-control:focus,.form-select:focus { border-color:#f0c63d; box-shadow:0 0 0 .2rem rgba(255,193,7,.14); }
        .filter-actions { display:flex; gap:.65rem; align-items:stretch; }
        .search-btn { border:none; background:linear-gradient(135deg,var(--orange-1),var(--orange-3)); color:#fff; font-weight:700; padding:0 1.15rem; border-radius:16px; min-height:50px; }
        .search-btn:hover { color:#fff; filter:brightness(.98); }
        .reset-btn { border:none; background:#1f1f1f; color:#fff; font-weight:700; padding:0 1rem; border-radius:16px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none; min-height:50px; }
        .reset-btn:hover { color:#fff; background:#111; }
        .mode-switch { display:flex; flex-wrap:wrap; gap:.6rem; }
        .btn-mode { display:inline-flex; align-items:center; gap:.45rem; padding:.72rem 1rem; border-radius:999px; border:1px solid rgba(255,152,0,.18); background:#fff; color:#3d3d3d; font-weight:700; text-decoration:none; transition:all .2s ease; box-shadow:0 8px 20px rgba(17,17,17,.04); }
        .btn-mode:hover { transform:translateY(-1px); color:#111; background:#fff7ea; }
        .btn-mode.is-active { background:linear-gradient(135deg,#111111,#ff8f00); color:#fff; border-color:transparent; }

        /* Filter Bar */
        .active-filter-bar { margin-top:1rem; padding:.95rem 1rem; border-radius:18px; background:linear-gradient(180deg,#fffaf3 0%,#fff6ea 100%); border:1px solid rgba(255,152,0,.12); }
        .active-filter-title { font-size:.82rem; font-weight:800; color:#8b4f00; margin-bottom:.65rem; }
        .filter-chip-wrap { display:flex; flex-wrap:wrap; gap:.55rem; }
        .filter-chip { display:inline-flex; align-items:center; gap:.4rem; padding:.45rem .8rem; border-radius:999px; background:#fff; border:1px solid rgba(255,152,0,.16); color:#6b4a00; font-size:.82rem; font-weight:700; }

        /* Summary Cards */
        .summary-card { position:relative; overflow:hidden; border-radius:22px; background:linear-gradient(180deg,#fff 0%,#fffaf3 100%); border:1px solid rgba(255,176,0,.15); box-shadow:var(--shadow-soft); height:100%; padding:1.15rem; transition:all .25s ease; }
        .summary-card::before { content:""; position:absolute; inset:0 0 auto 0; height:5px; background:linear-gradient(90deg,var(--orange-1),var(--orange-3)); }
        .summary-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-hover); }
        .summary-label { color:var(--text-soft); font-size:.84rem; font-weight:700; margin-bottom:.4rem; }
        .summary-value { font-size:2rem; line-height:1; font-weight:800; margin-bottom:.35rem; color:var(--dark-1); }
        .summary-note  { color:var(--text-soft); font-size:.82rem; }
        .summary-icon  { width:52px; height:52px; border-radius:16px; display:inline-flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; color:#fff; background:linear-gradient(135deg,var(--orange-1),var(--orange-3)); box-shadow:0 10px 24px rgba(255,152,0,.2); }

        /* Table */
        .table-card { overflow:hidden; }
        .table-card .card-header { border:none; padding:1.1rem 1.2rem; background:linear-gradient(135deg,#111 0%,#2c2c2c 40%,#ff8f00 100%); color:#fff; }
        .table-title   { font-size:1rem; font-weight:800; margin-bottom:.2rem; }
        .table-subinfo { font-size:.84rem; color:rgba(255,255,255,.82); }
        .limit-box { display:flex; align-items:center; gap:.6rem; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.16); border-radius:999px; padding:.45rem .55rem .45rem .85rem; }
        .limit-box .form-select { min-width:86px; border-radius:999px; border:none; box-shadow:none; font-weight:700; padding:.3rem .7rem; }
        .limit-box .section-label { color:rgba(255,255,255,.88); font-size:.82rem; font-weight:700; margin:0; }
        .table-responsive { background:#fff; }
        .table { margin-bottom:0; }
        .table thead th { background:#fffaf2; color:#2c2c2c; font-weight:800; border-bottom:1px solid #f0dfc3; white-space:nowrap; padding-top:1rem; padding-bottom:1rem; }
        .table tbody tr { transition:all .18s ease; }
        .table tbody tr:hover { background:#fffaf4; }
        .table tbody td { vertical-align:top; padding-top:1rem; padding-bottom:1rem; border-color:#f2ede5; }
        .asset-code { font-weight:800; color:var(--dark-1); }
        .meta-line  { display:block; font-size:.9rem; line-height:1.5; color:#222; }
        .meta-muted { display:block; font-size:.88rem; color:var(--text-soft); line-height:1.5; }
        .meta-muted i,.meta-line i { color:#d98710; }
        .badge.rounded-pill { padding:.5rem .78rem; font-size:.74rem; font-weight:700; }

        /* Pagination */
        .pagination { gap:.35rem; }
        .pagination .page-link { border-radius:12px; color:#2a2a2a; border:1px solid #ead9bf; padding:.6rem .85rem; font-weight:700; box-shadow:none; margin:0 2px; }
        .pagination .page-link:hover { background:#fff4de; color:#111; }
        .pagination .page-item.active .page-link { background:linear-gradient(135deg,#111,#ff8f00); border-color:transparent; color:#fff; }
        .empty-state { text-align:center; color:var(--text-soft); padding:2.2rem 1rem !important; }
        .empty-state i { display:block; font-size:1.8rem; margin-bottom:.55rem; color:var(--orange-2); }

        @media(max-width:991.98px){.page-shell{padding:18px;}.page-hero{padding:1.3rem 1.2rem;}.page-title{font-size:1.4rem;}.toolbar-card{padding:1rem;}}
        @media(max-width:767.98px){.filter-actions{flex-direction:column;}}
        @media(max-width:575.98px){.page-title{font-size:1.2rem;}.limit-box,.btn-mode{width:100%;justify-content:center;}}
    </style>
</head>
<body>

<div class="container-fluid p-0">
    <div class="d-flex flex-nowrap w-100 overflow-hidden">

        <?php include '../layout/sidebar.php'; ?>

        <div id="mainContent" class="flex-grow-1" style="transition:all .28s ease; min-width:0;">
            <div class="page-shell">

                <!-- Hero -->
                <div class="page-hero">
                    <div class="hero-content">
                        <h1 class="page-title">Riwayat Aktivitas Asset</h1>
                        <p class="page-desc">
                            Pantau seluruh riwayat transaksi barang masuk dan keluar secara lengkap,
                            lengkap dengan informasi pemilik, penerima, dan nomor resi.
                        </p>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="ui-card toolbar-card">
                    <div class="mb-4">
                        <label class="toolbar-label">Mode Tampilan</label>
                        <div class="mode-switch">
                            <a href="<?= build_url(['filter'=>'semua','cari'=>$search_input,'tanggal_awal'=>$tanggalAwal,'tanggal_akhir'=>$tanggalAkhir,'limit'=>$limit]) ?>" class="<?= $btnSemua ?>">
                                <i class="bi bi-clock-history"></i>Semua Riwayat
                            </a>
                            <a href="<?= build_url(['filter'=>'masuk','cari'=>$search_input,'tanggal_awal'=>$tanggalAwal,'tanggal_akhir'=>$tanggalAkhir,'limit'=>$limit]) ?>" class="<?= $btnMasuk ?>">
                                <i class="bi bi-box-arrow-in-down"></i>Riwayat Masuk
                            </a>
                            <a href="<?= build_url(['filter'=>'keluar','cari'=>$search_input,'tanggal_awal'=>$tanggalAwal,'tanggal_akhir'=>$tanggalAkhir,'limit'=>$limit]) ?>" class="<?= $btnKeluar ?>">
                                <i class="bi bi-box-arrow-up"></i>Riwayat Keluar
                            </a>
                        </div>
                    </div>

                    <form method="GET">
                        <input type="hidden" name="filter" value="<?= h($filter) ?>">
                        <input type="hidden" name="limit"  value="<?= h((string)$limit) ?>">
                        <div class="row g-3 align-items-end">
                            <div class="col-lg-5">
                                <label class="toolbar-label">Pencarian Riwayat</label>
                                <input type="text" name="cari" class="form-control" placeholder="Cari nama barang, serial number, resi, status..." value="<?= h($search_input) ?>">
                            </div>
                            <div class="col-lg-2">
                                <label class="toolbar-label">Tanggal Awal</label>
                                <input type="date" name="tanggal_awal" class="form-control" value="<?= h($tanggalAwal) ?>">
                            </div>
                            <div class="col-lg-2">
                                <label class="toolbar-label">Tanggal Akhir</label>
                                <input type="date" name="tanggal_akhir" class="form-control" value="<?= h($tanggalAkhir) ?>">
                            </div>
                            <div class="col-lg-3">
                                <div class="filter-actions">
                                    <button type="submit" class="btn search-btn w-100"><i class="bi bi-funnel me-2"></i>Terapkan</button>
                                    <a href="<?= h(build_url()) ?>" class="reset-btn w-100"><i class="bi bi-arrow-counterclockwise me-2"></i>Reset</a>
                                </div>
                            </div>
                        </div>
                    </form>

                    <?php if ($hasFilter): ?>
                        <div class="active-filter-bar">
                            <div class="active-filter-title">Filter Aktif</div>
                            <div class="filter-chip-wrap">
                                <span class="filter-chip"><i class="bi bi-ui-checks-grid"></i>Mode: <?= $filter === 'masuk' ? 'Riwayat Masuk' : ($filter === 'keluar' ? 'Riwayat Keluar' : 'Semua Riwayat') ?></span>
                                <?php if ($search_input !== ''): ?><span class="filter-chip"><i class="bi bi-search"></i>Cari: <?= h($search_input) ?></span><?php endif; ?>
                                <?php if ($tanggalAwal  !== ''): ?><span class="filter-chip"><i class="bi bi-calendar-event"></i>Dari: <?= h($tanggalAwal) ?></span><?php endif; ?>
                                <?php if ($tanggalAkhir !== ''): ?><span class="filter-chip"><i class="bi bi-calendar-check"></i>Sampai: <?= h($tanggalAkhir) ?></span><?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Summary Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="summary-card">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="summary-label">Total Riwayat</div>
                                    <div class="summary-value"><?= $totalSemua ?></div>
                                    <div class="summary-note">Semua transaksi tercatat</div>
                                </div>
                                <div class="summary-icon"><i class="bi bi-clock-history"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-card">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="summary-label">Asset Masuk</div>
                                    <div class="summary-value"><?= $totalMasuk ?></div>
                                    <div class="summary-note"><?= $isAdmin ? 'Diterima dari Cabang' : 'Diterima dari HO' ?></div>
                                </div>
                                <div class="summary-icon"><i class="bi bi-box-arrow-in-down"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-card">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="summary-label">Asset Keluar</div>
                                    <div class="summary-value"><?= $totalKeluar ?></div>
                                    <div class="summary-note"><?= $isAdmin ? 'Dikirim ke Cabang' : 'Dikirim ke HO' ?></div>
                                </div>
                                <div class="summary-icon"><i class="bi bi-box-arrow-up"></i></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabel Riwayat -->
                <div class="card table-card ui-card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div>
                                <div class="table-title"><i class="<?= h($tableIcon) ?> me-2"></i><?= h($tableTitle) ?></div>
                                <div class="table-subinfo">Menampilkan <?= $from_row ?> - <?= $to_row ?> dari <?= $total_rows ?> data</div>
                            </div>
                            <form method="GET" class="mb-0">
                                <input type="hidden" name="cari"         value="<?= h($search_input) ?>">
                                <input type="hidden" name="filter"       value="<?= h($filter) ?>">
                                <input type="hidden" name="tanggal_awal" value="<?= h($tanggalAwal) ?>">
                                <input type="hidden" name="tanggal_akhir"value="<?= h($tanggalAkhir) ?>">
                                <div class="limit-box">
                                    <span class="section-label">Tampilkan</span>
                                    <select name="limit" onchange="this.form.submit()" class="form-select form-select-sm">
                                        <option value="5"   <?= $limit==5   ? 'selected':'' ?>>5</option>
                                        <option value="25"  <?= $limit==25  ? 'selected':'' ?>>25</option>
                                        <option value="50"  <?= $limit==50  ? 'selected':'' ?>>50</option>
                                        <option value="100" <?= $limit==100 ? 'selected':'' ?>>100</option>
                                    </select>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-3">No</th>
                                    <th>Asset</th>
                                    <th>Nama Barang</th>
                                    <th>Aktivitas</th>
                                    <th>Asal → Tujuan</th>
                                    <th>Tanggal</th>
                                    <th>Status</th>
                                    <th>Pemilik</th>
                                    <th>Penerima</th>
                                    <th>Resi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($mainQuery && mysqli_num_rows($mainQuery) > 0): ?>
                                    <?php $no = $offset + 1; ?>
                                    <?php while ($d = mysqli_fetch_assoc($mainQuery)): ?>
                                        <tr>
                                            <td class="ps-3 text-muted"><?= $no++ ?></td>

                                            <!-- Asset -->
                                            <td>
                                                <div class="asset-code"><?= h($d['no_asset'] ?? '-') ?></div>
                                                <span class="meta-muted">SN: <?= h($d['serial_number'] ?? '-') ?></span>
                                            </td>

                                            <!-- Nama Barang -->
                                            <td>
                                                <span class="meta-line"><b><?= h($d['nama_barang'] ?? '-') ?></b></span>
                                                <span class="meta-muted"><?= h($d['nama_merk'] ?? '-') ?></span>
                                            </td>

                                            <!-- Aktivitas -->
                                            <td><?= activityBadge($d['jenis']) ?></td>

                                            <!-- Asal → Tujuan -->
                                            <td>
                                                <span class="meta-muted"><i class="bi bi-geo-alt"></i> <?= h($d['nama_branch_asal'] ?? '-') ?></span>
                                                <span class="meta-muted"><i class="bi bi-arrow-right"></i> <?= h($d['nama_branch_tujuan'] ?? '-') ?></span>
                                            </td>

                                            <!-- Tanggal -->
                                            <td>
                                                <span class="meta-line"><i class="bi bi-calendar-event"></i> <?= h($d['tanggal_aktivitas'] ?? '-') ?></span>
                                                <?php if (!empty($d['tanggal_diterima'])): ?>
                                                    <span class="meta-muted">Diterima: <?= h($d['tanggal_diterima']) ?></span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Status Pengiriman -->
                                            <td>
                                                <?= shippingBadge($d['status_pengiriman'] ?? '-') ?>
                                                <?php if (!empty($d['jasa_pengiriman'])): ?>
                                                    <span class="meta-muted"><?= h($d['jasa_pengiriman']) ?></span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Pemilik -->
                                            <td>
                                                <span class="meta-line"><i class="bi bi-person"></i> <?= h($d['nama_pemilik'] ?? '-') ?></span>
                                            </td>

                                            <!-- Penerima -->
                                            <td>
                                                <span class="meta-line"><i class="bi bi-person-check"></i> <?= h($d['nama_penerima'] ?? '-') ?></span>
                                            </td>

                                            <!-- Resi -->
                                            <td>
                                                <?php if (!empty($d['resi_keluar'])): ?>
                                                    <span class="meta-muted"><i class="bi bi-receipt"></i> Keluar: <?= h($d['resi_keluar']) ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($d['resi_masuk'])): ?>
                                                    <span class="meta-muted"><i class="bi bi-receipt"></i> Masuk: <?= h($d['resi_masuk']) ?></span>
                                                <?php endif; ?>
                                                <?php if (empty($d['resi_keluar']) && empty($d['resi_masuk'])): ?>
                                                    <span class="meta-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10" class="empty-state">
                                            <i class="bi bi-inbox"></i>Data riwayat tidak ditemukan.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="p-3 p-md-4">
                        <nav>
                            <ul class="pagination mb-0 flex-wrap">
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= build_url(['page'=>$i,'limit'=>$limit,'cari'=>$search_input,'filter'=>$filter,'tanggal_awal'=>$tanggalAwal,'tanggal_akhir'=>$tanggalAkhir]) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    </div>
                </div>

            </div><!-- page-shell -->
        </div><!-- mainContent -->
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>