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
// HELPER FUNCTIONS (Diperbarui dengan Soft Badges Hexindo)
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
        return '<span class="badge rounded-pill badge-soft-danger"><i class="bi bi-box-arrow-up me-1"></i>Keluar</span>';
    }
    return '<span class="badge rounded-pill badge-soft-success"><i class="bi bi-box-arrow-in-down me-1"></i>Masuk</span>';
}

function shippingBadge(string $status): string
{
    $map = [
        'sedang dikemas'            => ['badge-soft-warning', 'bi-box-seam'],
        'menunggu persetujuan admin'=> ['badge-soft-warning', 'bi-hourglass-split'],
        'sedang perjalanan'         => ['badge-soft-primary', 'bi-truck'],
        'sudah diterima'            => ['badge-soft-success', 'bi-check-circle'],
        'sudah diterima ho'         => ['badge-soft-success', 'bi-check-circle'],
        'selesai'                   => ['badge-soft-success', 'bi-check-circle'],
        'ditolak'                   => ['badge-soft-danger',  'bi-x-circle'],
    ];
    $key = strtolower(trim($status));
    [$class, $icon] = $map[$key] ?? ['badge-soft-secondary', 'bi-clock-history'];
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
$penerimaHO = 'Pak Deni';

$search = mysqli_real_escape_string($koneksi, $search_input);
$safeAwal  = mysqli_real_escape_string($koneksi, $tanggalAwal);
$safeAkhir = mysqli_real_escape_string($koneksi, $tanggalAkhir);

// --------------------------------------------------------------------------
// PART A: barang_pengiriman (HO → Cabang)
// --------------------------------------------------------------------------
$partA_jenis = $isAdmin ? 'Keluar' : 'Masuk';

$partA_where = ["1=1"];

if (!$isAdmin) {
    $partA_where[] = "bp.branch_tujuan = $myBranchId";
}

if ($filter === 'masuk'  && !$isAdmin) $partA_where[] = "1=1"; 
if ($filter === 'masuk'  &&  $isAdmin) $partA_where[] = "1=0"; 
if ($filter === 'keluar' && !$isAdmin) $partA_where[] = "1=0"; 
if ($filter === 'keluar' &&  $isAdmin) $partA_where[] = "1=1"; 

if ($search_input !== '') {
    $partA_where[] = "(tb.nama_barang LIKE '%$search%' OR b.no_asset LIKE '%$search%' OR b.serial_number LIKE '%$search%'
                    OR b.user LIKE '%$search%' OR bp.nama_penerima LIKE '%$search%'
                    OR bp.nomor_resi_keluar LIKE '%$search%' OR bp.status_pengiriman LIKE '%$search%'
                    OR br_asal.nama_branch LIKE '%$search%' OR br_tujuan.nama_branch LIKE '%$search%')";
}
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
    $partB_where[] = "pch.branch_asal = $myBranchId";
}

if ($filter === 'masuk'  &&  $isAdmin) $partB_where[] = "1=1"; 
if ($filter === 'masuk'  && !$isAdmin) $partB_where[] = "1=0"; 
if ($filter === 'keluar' &&  $isAdmin) $partB_where[] = "1=0"; 
if ($filter === 'keluar' && !$isAdmin) $partB_where[] = "1=1"; 

if ($search_input !== '') {
    $partB_where[] = "(tb.nama_barang LIKE '%$search%' OR b.no_asset LIKE '%$search%' OR b.serial_number LIKE '%$search%'
                    OR pch.pemilik_barang LIKE '%$search%' OR pch.nomor_resi_keluar LIKE '%$search%'
                    OR pch.status_pengiriman LIKE '%$search%' OR br_asal.nama_branch LIKE '%$search%')";
}

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
// SUMMARY CARDS
// ==============================================================================
$masukA_jenis = !$isAdmin ? "'Masuk'" : "'1=0'"; 
$masukB_jenis = $isAdmin  ? "'Masuk'" : "'1=0'"; 

$partA_masuk_where = array_filter($partA_where, fn($w) => $w !== '1=0' && $w !== '1=1');
$partB_masuk_where = array_filter($partB_where, fn($w) => $w !== '1=0' && $w !== '1=1');

$am_where = ["1=1"];
if (!$isAdmin) $am_where[] = "bp.branch_tujuan = $myBranchId";
if ($search_input !== '') $am_where[] = "(tb.nama_barang LIKE '%$search%' OR b.no_asset LIKE '%$search%' OR b.serial_number LIKE '%$search%' OR bp.nomor_resi_keluar LIKE '%$search%' OR bp.status_pengiriman LIKE '%$search%')";
if ($tanggalAwal !== '' && $tanggalAkhir !== '') $am_where[] = "DATE(bp.tanggal_keluar) BETWEEN '$safeAwal' AND '$safeAkhir'";
elseif ($tanggalAwal !== '') $am_where[] = "DATE(bp.tanggal_keluar) >= '$safeAwal'";
elseif ($tanggalAkhir !== '') $am_where[] = "DATE(bp.tanggal_keluar) <= '$safeAkhir'";
if ($isAdmin) $am_where[] = "1=0"; 

$bm_where = ["1=1"];
if (!$isAdmin) $bm_where[] = "1=0"; 
if (!$isAdmin || true) { 
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

$ak_where = ["1=1"];
if ($isAdmin) $ak_where[] = "1=1"; 
if (!$isAdmin) $ak_where[] = "1=0"; 

$bk_where = ["1=1"];
if (!$isAdmin) { 
    $bk_where[] = "pch.branch_asal = $myBranchId";
    if ($search_input !== '') $bk_where[] = "(tb.nama_barang LIKE '%$search%' OR b.no_asset LIKE '%$search%' OR b.serial_number LIKE '%$search%' OR pch.nomor_resi_keluar LIKE '%$search%' OR pch.status_pengiriman LIKE '%$search%')";
    if ($tanggalAwal !== '' && $tanggalAkhir !== '') $bk_where[] = "DATE(pch.tanggal_pengajuan) BETWEEN '$safeAwal' AND '$safeAkhir'";
    elseif ($tanggalAwal !== '') $bk_where[] = "DATE(pch.tanggal_pengajuan) >= '$safeAwal'";
    elseif ($tanggalAkhir !== '') $bk_where[] = "DATE(pch.tanggal_pengajuan) <= '$safeAkhir'";
} else {
    $bk_where[] = "1=0"; 
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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- SINKRONISASI TEMA HEXINDO -->
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
            --radius-xl: 8px; /* Sudut tegas/industri */
        }
        
        body { 
            background-color: var(--surface-bg); 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            color: var(--text-main); 
        }
        .page-shell { padding: 24px 32px; }

        /* Hero Banner Hexindo */
        .page-hero { 
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
        .page-desc  { color: #9ca3af; margin-bottom: 0; max-width: 800px; font-size: 0.95rem; }

        /* Toolbar & Card Dasar */
        .ui-card { 
            background: #ffffff; 
            border: 1px solid var(--border-soft); 
            border-radius: var(--radius-xl); 
            box-shadow: var(--shadow-soft); 
        }
        .toolbar-card { padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; }
        .toolbar-label { font-size: 0.85rem; font-weight: 600; color: var(--text-soft); margin-bottom: 0.5rem; }
        
        /* Input Form Custom */
        .form-control, .form-select { 
            border-radius: 6px; 
            border: 1px solid var(--border-soft); 
            box-shadow: none; 
            padding: 0.6rem 1rem; 
            font-size: 0.9rem; 
        }
        .form-control:focus, .form-select:focus { 
            border-color: var(--orange-1); 
            box-shadow: 0 0 0 0.25rem rgba(230, 67, 18, 0.1); 
        }
        
        /* Tombol Aksi */
        .filter-actions { display: flex; gap: 0.5rem; align-items: stretch; }
        .search-btn { 
            border: none; 
            background-color: var(--orange-1); 
            color: #fff; font-weight: 600; 
            padding: 0 1.2rem; 
            border-radius: 6px; 
            min-height: 42px; 
            transition: all 0.2s;
        }
        .search-btn:hover { background-color: var(--orange-2); color: #fff; }
        
        .reset-btn { 
            border: 1px solid var(--border-soft); 
            background: #fff; 
            color: var(--text-main); 
            font-weight: 600; 
            padding: 0 1rem; 
            border-radius: 6px; 
            display: inline-flex; 
            align-items: center; 
            justify-content: center; 
            text-decoration: none; 
            min-height: 42px; 
            transition: all 0.2s;
        }
        .reset-btn:hover { background: var(--surface-bg); color: var(--dark-1); }

        /* Mode Tampilan (Tabs) */
        .mode-switch { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .btn-mode { 
            display: inline-flex; align-items: center; gap: 0.4rem; 
            padding: 0.5rem 1rem; 
            border-radius: 6px; 
            border: 1px solid var(--border-soft); 
            background: #fff; color: var(--text-soft); 
            font-weight: 600; font-size: 0.85rem; 
            text-decoration: none; transition: all 0.2s ease; 
        }
        .btn-mode:hover { border-color: var(--orange-1); color: var(--orange-1); }
        .btn-mode.is-active { background: var(--dark-1); color: #fff; border-color: var(--dark-1); }

        /* Filter Aktif Bar */
        .active-filter-bar { 
            margin-top: 1rem; padding: 1rem; 
            border-radius: 6px; 
            background-color: #F9FAFB; 
            border: 1px dashed var(--border-soft); 
        }
        .active-filter-title { font-size: 0.8rem; font-weight: 700; color: var(--text-soft); margin-bottom: 0.5rem; text-transform: uppercase;}
        .filter-chip-wrap { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .filter-chip { 
            display: inline-flex; align-items: center; gap: 0.3rem; 
            padding: 0.3rem 0.8rem; 
            border-radius: 999px; 
            background: #fff; border: 1px solid var(--border-soft); 
            color: var(--text-main); font-size: 0.8rem; font-weight: 600; 
        }

        /* Summary Cards */
        .summary-card { 
            border-radius: var(--radius-xl); 
            background: #fff; 
            border: 1px solid var(--border-soft); 
            border-left: 4px solid var(--orange-1);
            box-shadow: var(--shadow-soft); 
            height: 100%; padding: 1.25rem 1.5rem; 
            transition: all 0.2s ease; 
        }
        .summary-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); }
        .summary-label { color: var(--text-soft); font-size: 0.85rem; font-weight: 600; }
        .summary-value { font-size: 1.8rem; font-weight: 800; color: var(--dark-1); margin: 0.2rem 0;}
        .summary-note  { color: #9ca3af; font-size: 0.8rem; }
        .summary-icon  { 
            width: 45px; height: 45px; border-radius: 12px; 
            display: flex; align-items: center; justify-content: center; 
            font-size: 1.25rem; 
            color: var(--orange-1); 
            background: rgba(230, 67, 18, 0.1); 
        }

        /* Table Design */
        .table-card .card-header { 
            border-bottom: 1px solid var(--border-soft); 
            padding: 1.2rem 1.5rem; 
            background: #fff; 
            border-radius: var(--radius-xl) var(--radius-xl) 0 0;
        }
        .table-title { font-size: 1.05rem; font-weight: 700; color: var(--dark-1); }
        .table-subinfo { font-size: 0.85rem; color: var(--text-soft); margin-top: 2px;}
        
        .limit-box { display: flex; align-items: center; gap: 0.5rem; background: #F9FAFB; border: 1px solid var(--border-soft); border-radius: 6px; padding: 0.3rem 0.5rem 0.3rem 0.8rem; }
        .limit-box .form-select { border: none; background: transparent; font-weight: 600; padding: 0.2rem 1.5rem 0.2rem 0.5rem; color: var(--dark-1); box-shadow: none;}
        .limit-box .section-label { color: var(--text-soft); font-size: 0.85rem; font-weight: 600; margin: 0; }
        
        .table > :not(caption) > * > * { padding: 1rem 1.2rem; border-bottom-color: var(--border-soft); }
        .table thead th { 
            background-color: #f9fafb !important; 
            color: var(--text-soft); 
            font-size: 0.85rem; 
            text-transform: uppercase; 
            letter-spacing: 0.5px; 
            font-weight: 700;
        }
        
        .asset-code { font-weight: 700; color: var(--dark-1); font-size: 0.9rem;}
        .meta-line  { display: block; font-size: 0.85rem; color: var(--text-main); margin-bottom: 2px;}
        .meta-muted { display: block; font-size: 0.8rem; color: var(--text-soft); }
        .meta-muted i, .meta-line i { color: #9ca3af; margin-right: 3px;}
        
        /* Soft Badges */
        .badge.rounded-pill { padding: 0.4em 0.8em; font-weight: 600; font-size: 0.75rem; letter-spacing: 0.3px; }
        .badge-soft-success { background-color: rgba(16, 185, 129, 0.15); color: #059669; }
        .badge-soft-warning { background-color: rgba(245, 158, 11, 0.15); color: #d97706; }
        .badge-soft-danger { background-color: rgba(239, 68, 68, 0.15); color: #b91c1c; }
        .badge-soft-primary { background-color: rgba(59, 130, 246, 0.15); color: #1d4ed8; }
        .badge-soft-secondary { background-color: rgba(107, 114, 128, 0.15); color: #4b5563; }

        /* Pagination */
        .pagination .page-link { border-radius: 6px; color: var(--text-main); border: 1px solid var(--border-soft); padding: 0.5rem 0.8rem; font-weight: 600; margin: 0 2px; }
        .pagination .page-item.active .page-link { background: var(--dark-1); border-color: var(--dark-1); color: #fff; }
        
        .empty-state { text-align: center; padding: 3rem 1rem !important; }
        .empty-state i { display: block; font-size: 2.5rem; margin-bottom: 0.5rem; color: #d1d5db; }
        .empty-state span { font-weight: 600; color: var(--text-soft);}
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
                        <h1 class="page-title">Riwayat Aktivitas Aset</h1>
                        <p class="page-desc">
                            Pantau seluruh rekam jejak transaksi barang masuk dan keluar, 
                            lengkap dengan detail pengiriman dan nomor resi.
                        </p>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="ui-card toolbar-card">
                    <div class="mb-4">
                        <label class="toolbar-label">Filter Kategori</label>
                        <div class="mode-switch">
                            <a href="<?= build_url(['filter'=>'semua','cari'=>$search_input,'tanggal_awal'=>$tanggalAwal,'tanggal_akhir'=>$tanggalAkhir,'limit'=>$limit]) ?>" class="<?= $btnSemua ?>">
                                <i class="bi bi-list-ul"></i> Semua Data
                            </a>
                            <a href="<?= build_url(['filter'=>'masuk','cari'=>$search_input,'tanggal_awal'=>$tanggalAwal,'tanggal_akhir'=>$tanggalAkhir,'limit'=>$limit]) ?>" class="<?= $btnMasuk ?>">
                                <i class="bi bi-box-arrow-in-down"></i> Riwayat Masuk
                            </a>
                            <a href="<?= build_url(['filter'=>'keluar','cari'=>$search_input,'tanggal_awal'=>$tanggalAwal,'tanggal_akhir'=>$tanggalAkhir,'limit'=>$limit]) ?>" class="<?= $btnKeluar ?>">
                                <i class="bi bi-box-arrow-up"></i> Riwayat Keluar
                            </a>
                        </div>
                    </div>

                    <form method="GET">
                        <input type="hidden" name="filter" value="<?= h($filter) ?>">
                        <input type="hidden" name="limit"  value="<?= h((string)$limit) ?>">
                        <div class="row g-3 align-items-end">
                            <div class="col-lg-5">
                                <label class="toolbar-label">Pencarian Kata Kunci</label>
                                <input type="text" name="cari" class="form-control" placeholder="Cari nama barang, serial number, resi..." value="<?= h($search_input) ?>">
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
                                    <button type="submit" class="search-btn w-100"><i class="bi bi-search me-1"></i> Cari</button>
                                    <a href="<?= h(build_url()) ?>" class="reset-btn w-100" title="Reset Pencarian"><i class="bi bi-arrow-clockwise me-1"></i> Reset</a>
                                </div>
                            </div>
                        </div>
                    </form>

                    <?php if ($hasFilter): ?>
                        <div class="active-filter-bar mt-3">
                            <div class="active-filter-title">Filter Yang Sedang Aktif:</div>
                            <div class="filter-chip-wrap">
                                <span class="filter-chip"><i class="bi bi-funnel text-muted"></i> Kategori: <?= $filter === 'masuk' ? 'Riwayat Masuk' : ($filter === 'keluar' ? 'Riwayat Keluar' : 'Semua Data') ?></span>
                                <?php if ($search_input !== ''): ?><span class="filter-chip"><i class="bi bi-search text-muted"></i> Keyword: <?= h($search_input) ?></span><?php endif; ?>
                                <?php if ($tanggalAwal  !== ''): ?><span class="filter-chip"><i class="bi bi-calendar text-muted"></i> Dari: <?= h($tanggalAwal) ?></span><?php endif; ?>
                                <?php if ($tanggalAkhir !== ''): ?><span class="filter-chip"><i class="bi bi-calendar-check text-muted"></i> Sampai: <?= h($tanggalAkhir) ?></span><?php endif; ?>
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
                                    <div class="summary-note">Total transaksi tercatat</div>
                                </div>
                                <div class="summary-icon"><i class="bi bi-clock-history"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-card">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="summary-label">Aset Masuk</div>
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
                                    <div class="summary-label">Aset Keluar</div>
                                    <div class="summary-value"><?= $totalKeluar ?></div>
                                    <div class="summary-note"><?= $isAdmin ? 'Dikirim ke Cabang' : 'Dikirim ke HO' ?></div>
                                </div>
                                <div class="summary-icon"><i class="bi bi-box-arrow-up"></i></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabel Riwayat -->
                <div class="ui-card table-card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div>
                                <div class="table-title"><i class="<?= h($tableIcon) ?> me-2" style="color: var(--orange-1);"></i><?= h($tableTitle) ?></div>
                                <div class="table-subinfo">Menampilkan <?= $from_row ?> - <?= $to_row ?> dari <?= $total_rows ?> data</div>
                            </div>
                            <form method="GET" class="mb-0">
                                <input type="hidden" name="cari"         value="<?= h($search_input) ?>">
                                <input type="hidden" name="filter"       value="<?= h($filter) ?>">
                                <input type="hidden" name="tanggal_awal" value="<?= h($tanggalAwal) ?>">
                                <input type="hidden" name="tanggal_akhir"value="<?= h($tanggalAkhir) ?>">
                                <div class="limit-box">
                                    <span class="section-label">Baris:</span>
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
                                    <th class="ps-4" width="50">No</th>
                                    <th>Identitas Aset</th>
                                    <th>Kategori & Merk</th>
                                    <th>Aktivitas</th>
                                    <th>Rute Pengiriman</th>
                                    <th>Waktu Logistik</th>
                                    <th>Status Terkini</th>
                                    <th>PIC & Resi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($mainQuery && mysqli_num_rows($mainQuery) > 0): ?>
                                    <?php $no = $offset + 1; ?>
                                    <?php while ($d = mysqli_fetch_assoc($mainQuery)): ?>
                                        <tr>
                                            <td class="ps-4 text-muted"><?= $no++ ?></td>

                                            <!-- Asset -->
                                            <td>
                                                <div class="asset-code"><?= h($d['no_asset'] ?? '-') ?></div>
                                                <span class="meta-muted mt-1">SN: <?= h($d['serial_number'] ?? '-') ?></span>
                                            </td>

                                            <!-- Nama Barang -->
                                            <td>
                                                <span class="meta-line fw-semibold"><?= h($d['nama_barang'] ?? '-') ?></span>
                                                <span class="meta-muted"><?= h($d['nama_merk'] ?? '-') ?></span>
                                            </td>

                                            <!-- Aktivitas -->
                                            <td><?= activityBadge($d['jenis']) ?></td>

                                            <!-- Asal → Tujuan -->
                                            <td>
                                                <span class="meta-muted mb-1"><i class="bi bi-arrow-up-right"></i> Asal: <?= h($d['nama_branch_asal'] ?? '-') ?></span>
                                                <span class="meta-muted"><i class="bi bi-arrow-down-right"></i> Tujuan: <?= h($d['nama_branch_tujuan'] ?? '-') ?></span>
                                            </td>

                                            <!-- Tanggal -->
                                            <td>
                                                <span class="meta-line"><i class="bi bi-calendar3"></i> Kirim: <?= h($d['tanggal_aktivitas'] ?? '-') ?></span>
                                                <?php if (!empty($d['tanggal_diterima'])): ?>
                                                    <span class="meta-muted"><i class="bi bi-check2"></i> Tiba: <?= h($d['tanggal_diterima']) ?></span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Status Pengiriman -->
                                            <td>
                                                <div class="mb-1"><?= shippingBadge($d['status_pengiriman'] ?? '-') ?></div>
                                                <?php if (!empty($d['jasa_pengiriman'])): ?>
                                                    <span class="meta-muted"><i class="bi bi-truck"></i> <?= h($d['jasa_pengiriman']) ?></span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- PIC & Resi (Digabung agar lebih rapi) -->
                                            <td>
                                                <span class="meta-line"><i class="bi bi-person"></i> Pemilik: <?= h($d['nama_pemilik'] ?? '-') ?></span>
                                                <span class="meta-line"><i class="bi bi-person-check"></i> Penerima: <?= h($d['nama_penerima'] ?? '-') ?></span>
                                                <div class="mt-1 pt-1 border-top" style="border-color: #E0E4E8 !important;">
                                                    <?php if (!empty($d['resi_keluar'])): ?>
                                                        <span class="meta-muted"><i class="bi bi-receipt"></i> Resi: <?= h($d['resi_keluar']) ?></span>
                                                    <?php elseif (!empty($d['resi_masuk'])): ?>
                                                        <span class="meta-muted"><i class="bi bi-receipt"></i> Resi: <?= h($d['resi_masuk']) ?></span>
                                                    <?php else: ?>
                                                        <span class="meta-muted">-</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="empty-state">
                                            <i class="bi bi-folder-x"></i>
                                            <span>Belum ada data riwayat yang dapat ditampilkan.</span>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="p-3 border-top bg-white rounded-bottom-4 d-flex justify-content-end">
                            <nav>
                                <ul class="pagination pagination-sm mb-0">
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= build_url(['page'=>$i,'limit'=>$limit,'cari'=>$search_input,'filter'=>$filter,'tanggal_awal'=>$tanggalAwal,'tanggal_akhir'=>$tanggalAkhir]) ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>