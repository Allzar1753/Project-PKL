<?php
include '../config/koneksi.php';
/** @var mysqli $koneksi */ //
require_once '../config/auth.php';

// Proteksi Halaman
require_permission($koneksi, 'barang.view');

$isAdmin = is_admin();
$myBranchId = current_user_branch_id();

if (!$isAdmin && (!$myBranchId || $myBranchId <= 0)) {
    http_response_code(403);
    exit('Branch user belum ditentukan. Hubungi Administrator.');
}

/**
 * HELPER FUNCTIONS
 */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function shippingBadge(string $status): string
{
    $class = 'bg-secondary';
    $icon  = 'bi-dash-circle';
    $s = strtolower(trim($status));

    if ($s === 'menunggu persetujuan admin' || $s === 'sedang dikemas') {
        $class = 'bg-warning text-dark';
        $icon  = 'bi-box-seam';
    } elseif ($s === 'sedang perjalanan') {
        $class = 'bg-primary';
        $icon  = 'bi-truck';
    } elseif (in_array($s, ['sudah diterima', 'sudah diterima ho', 'selesai'], true)) {
        $class = 'bg-success';
        $icon  = 'bi-check-circle';
    }
    return '<span class="badge rounded-pill ' . $class . '"><i class="bi ' . $icon . ' me-1"></i>' . h($status) . '</span>';
}

function barangBadge(string $bermasalah): string
{
    if ($bermasalah === 'Iya') {
        return '<span class="badge rounded-pill bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Bermasalah</span>';
    }
    return '<span class="badge rounded-pill bg-success"><i class="bi bi-check-circle me-1"></i>Normal</span>';
}

function fetchSingleValue(mysqli $koneksi, string $sql, string $field = 'total'): int
{
    $query = mysqli_query($koneksi, $sql);
    if (!$query) return 0;
    $row = mysqli_fetch_assoc($query) ?: [];
    return (int) ($row[$field] ?? 0);
}

function canCreateBarang(): bool
{
    return is_admin() && can('barang.create');
}

function canOpenPengirimanUser(): bool
{
    return is_user_role() && can('barang.kirim');
}

// =========================================================================
// PENGATURAN FILTER, PENCARIAN, DAN PAGINATION
// =========================================================================
$searchInput = isset($_GET['cari']) ? trim((string) $_GET['cari']) : '';
$filter = isset($_GET['filter']) ? trim((string) $_GET['filter']) : '';
if (!in_array($filter, ['', 'masuk', 'keluar'], true)) {
    $filter = '';
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// SQL Search Fragments
$searchSql_barang = "";
$searchSql_pengiriman_ho = "";
$searchSql_barang_pengiriman = "";

if ($searchInput !== '') {
    $s = mysqli_real_escape_string($koneksi, $searchInput);
    $searchSql_barang = " AND (tb_barang.nama_barang LIKE '%$s%' OR barang.no_asset LIKE '%$s%' OR barang.serial_number LIKE '%$s%') ";
    $searchSql_pengiriman_ho = " AND (tb_barang.nama_barang LIKE '%$s%' OR p.serial_number LIKE '%$s%') ";
    $searchSql_barang_pengiriman = " AND (tb_barang.nama_barang LIKE '%$s%' OR b.no_asset LIKE '%$s%' OR b.serial_number LIKE '%$s%') ";
}


// =========================================================================
// LOGIKA SUMMARY CARD & QUERY DATA
// =========================================================================

if ($isAdmin) {
    $excludeTransitSql = " AND barang.id NOT IN (SELECT id_barang FROM barang_pengiriman WHERE status_pengiriman IN ('Sedang perjalanan', 'Sudah diterima')) ";
} else {
    $excludeTransitSql = " AND barang.id NOT IN (SELECT id_barang FROM barang_pengiriman WHERE status_pengiriman = 'Sedang perjalanan') ";
    $excludeTransitSql .= " AND barang.serial_number NOT IN (SELECT serial_number FROM pengiriman_cabang_ho WHERE branch_asal = $myBranchId AND status_pengiriman NOT IN ('Ditolak', 'Selesai')) ";
}

$stokAktifSql = $isAdmin ? " AND (barang.status IN ('Tersedia','Diterima') OR barang.bermasalah = 'Iya') " : " AND barang.status IN ('Tersedia','Diterima') ";

// =========================================================================
// LOGIKA SINKRONISASI DATA (ADMIN HO = ID 40)
// =========================================================================

// Tentukan ID Branch HO kamu di sini agar mudah diubah nanti
$idBranchHO = 40;

if ($isAdmin) {
    /** 
     * ADMIN HO: Hanya melihat barang yang fisiknya ada di HO (id_branch = 40)
     * Data akan hilang dari sini jika sedang dikirim (transit) ke cabang.
     */
    $whereLokasi = "barang.id_branch = $idBranchHO";

    // Barang disembunyikan jika sedang dalam perjalanan keluar dari HO
    $excludeTransitSql = " AND barang.id NOT IN (SELECT id_barang FROM barang_pengiriman WHERE status_pengiriman = 'Sedang perjalanan') ";

    // Summary Card Admin HO
    $totalInventaris = fetchSingleValue($koneksi, "SELECT COUNT(id) AS total FROM barang WHERE $whereLokasi $excludeTransitSql AND status IN ('Tersedia','Diterima')");
    $totalMasuk      = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE status_pengiriman IN ('Sudah diterima HO', 'Selesai')");
    $totalKeluar     = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman");
} else {
    /**
     * USER CABANG: Hanya melihat barang di cabangnya sendiri.
     */
    $whereLokasi = "barang.id_branch = $myBranchId";

    // Sembunyikan barang jika sedang dikirim ke HO (masuk pengiriman_cabang_ho) 
    // atau sedang dikirim dari HO ke Cabang (barang_pengiriman)
    $excludeTransitSql = " AND barang.id NOT IN (SELECT id_barang FROM barang_pengiriman WHERE status_pengiriman = 'Sedang perjalanan') ";
    $excludeTransitSql .= " AND barang.serial_number NOT IN (SELECT serial_number FROM pengiriman_cabang_ho WHERE branch_asal = $myBranchId AND status_pengiriman NOT IN ('Ditolak', 'Selesai')) ";

    // Summary Card User Cabang
    $totalInventaris = fetchSingleValue($koneksi, "SELECT COUNT(id) AS total FROM barang WHERE $whereLokasi $excludeTransitSql AND status IN ('Tersedia','Diterima')");
    $totalMasuk      = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman WHERE branch_tujuan = $myBranchId AND status_pengiriman = 'Sudah diterima'");
    $totalKeluar     = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE branch_asal = $myBranchId");
}

if ($filter === 'keluar') {
    if ($isAdmin) {
        $querySql = "SELECT p.id_pengiriman AS id_transaksi, p.tanggal_keluar AS tanggal, p.status_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar,
                            b.id AS id_barang, b.bermasalah, b.no_asset, b.serial_number, tb_barang.nama_barang, br.nama_branch AS info_branch, p.nama_penerima as pemilik_barang
                     FROM barang_pengiriman p
                     JOIN barang b ON p.id_barang = b.id
                     JOIN tb_barang ON b.id_barang = tb_barang.id_barang
                     LEFT JOIN tb_branch br ON p.branch_tujuan = br.id_branch
                     WHERE 1=1 $searchSql_barang_pengiriman ORDER BY p.id_pengiriman DESC";
    } else {
        $querySql = "SELECT p.id_pengiriman_ho AS id_transaksi, p.tanggal_pengajuan AS tanggal, p.status_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar,
                            p.serial_number, p.pemilik_barang, tb_barang.nama_barang, 'Pusat HO' AS info_branch, b.no_asset
                     FROM pengiriman_cabang_ho p
                     JOIN tb_barang ON p.id_barang = tb_barang.id_barang
                     LEFT JOIN barang b ON p.serial_number = b.serial_number
                     WHERE p.branch_asal = $myBranchId $searchSql_pengiriman_ho ORDER BY p.id_pengiriman_ho DESC";
    }
} elseif ($filter === 'masuk') {
    if ($isAdmin) {
        $querySql = "SELECT p.id_pengiriman_ho AS id_transaksi, p.tanggal_pengajuan AS tanggal, p.status_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar,
                            p.serial_number, p.pemilik_barang, tb_barang.nama_barang, br.nama_branch AS info_branch, b.no_asset
                     FROM pengiriman_cabang_ho p
                     JOIN tb_barang ON p.id_barang = tb_barang.id_barang
                     LEFT JOIN barang b ON p.serial_number = b.serial_number
                     LEFT JOIN tb_branch br ON p.branch_asal = br.id_branch
                     WHERE 1=1 $searchSql_pengiriman_ho ORDER BY p.id_pengiriman_ho DESC";
    } else {
        // SISI USER - LOGISTIK MASUK
        $querySql = "SELECT p.id_pengiriman AS id_transaksi, p.tanggal_keluar AS tanggal, p.status_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar,
                        b.no_asset, b.serial_number, tb_barang.nama_barang, 'Pusat HO' AS info_branch,
                        CASE
                            WHEN b.`user` IS NOT NULL AND b.`user` != '' AND b.`user` != '0' 
                                THEN b.`user`
                            ELSE (
                                SELECT pch.pemilik_barang 
                                FROM pengiriman_cabang_ho pch 
                                WHERE pch.serial_number = b.serial_number
                                  AND pch.pemilik_barang IS NOT NULL
                                  AND pch.pemilik_barang != ''
                                  AND pch.pemilik_barang != '0'
                                ORDER BY pch.id_pengiriman_ho DESC 
                                LIMIT 1
                            )
                        END AS pemilik_barang
                 FROM barang_pengiriman p
                 JOIN barang b ON p.id_barang = b.id
                 JOIN tb_barang ON b.id_barang = tb_barang.id_barang
                 WHERE p.branch_tujuan = $myBranchId $searchSql_barang_pengiriman 
                 ORDER BY p.id_pengiriman DESC";
    }
} else {
    // --- PERBAIKAN QUERY ASSET TERSEDIA ---
    // Subquery untuk mengambil nama user dari riwayat logistik terakhir
    if ($isAdmin) {
        // Admin HO: Ambil pengirim terakhir dari Cabang
        $subqueryLastUser = "(SELECT p.pemilik_barang FROM pengiriman_cabang_ho p WHERE p.serial_number = barang.serial_number AND p.status_pengiriman IN ('Sudah diterima HO', 'Selesai') AND p.pemilik_barang IS NOT NULL AND p.pemilik_barang != '' AND p.pemilik_barang != '0' ORDER BY p.id_pengiriman_ho DESC LIMIT 1)";
    } else {
        // User Cabang: Disamakan dengan logic Logistik Masuk (Ambil dari histori HO)
        $subqueryLastUser = "(SELECT pch.pemilik_barang FROM pengiriman_cabang_ho pch WHERE pch.serial_number = barang.serial_number AND pch.pemilik_barang IS NOT NULL AND pch.pemilik_barang != '' AND pch.pemilik_barang != '0' ORDER BY pch.id_pengiriman_ho DESC LIMIT 1)";
    }

    $querySql = "SELECT barang.id, barang.no_asset, barang.serial_number, barang.bermasalah, barang.foto, 
                        barang.user AS master_user, 
                        $subqueryLastUser AS last_logistic_user,
                        barang.keterangan_masalah, barang.status,
                        tb_barang.nama_barang, m.nama_merk, t.nama_tipe, j.nama_jenis, br.nama_branch AS info_branch
                 FROM barang
                 JOIN tb_barang ON barang.id_barang = tb_barang.id_barang
                 LEFT JOIN tb_merk m ON barang.id_merk = m.id_merk
                 LEFT JOIN tb_tipe t ON barang.id_tipe = t.id_tipe
                 LEFT JOIN tb_jenis j ON barang.id_jenis = j.id_jenis
                 LEFT JOIN tb_branch br ON barang.id_branch = br.id_branch
                 WHERE $whereLokasi $excludeTransitSql $stokAktifSql $searchSql_barang 
                 ORDER BY barang.id DESC";
}


// Pagination Logic
$countQuery = "SELECT COUNT(*) AS total FROM ($querySql) AS sub";
$totalRows = fetchSingleValue($koneksi, $countQuery);
$totalPages = max(1, (int) ceil($totalRows / $limit));
$fromRow = ($totalRows > 0) ? $offset + 1 : 0;
$toRow   = min($offset + $limit, $totalRows);

$querySql .= " LIMIT $offset, $limit";
$query = mysqli_query($koneksi, $querySql);
if (!$query) die(mysqli_error($koneksi));

// UI States
$searchValue = h($searchInput);
$filterValue = h($filter);
$btnSemua = ($filter === '' ? 'btn-mode is-active' : 'btn-mode');
$btnMasuk = ($filter === 'masuk' ? 'btn-mode is-active' : 'btn-mode');
$btnKeluar = ($filter === 'keluar' ? 'btn-mode is-active' : 'btn-mode');
$tableTitle = $filter === 'masuk' ? 'Daftar Barang Masuk' : ($filter === 'keluar' ? 'Daftar Barang Keluar' : 'Total Inventaris');
$tableIcon = $filter === 'masuk' ? 'bi-box-arrow-in-down' : ($filter === 'keluar' ? 'bi-box-arrow-up' : 'bi-box-seam');
$emptyColspan = ($filter === '' ? 7 : 6);
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Barang - IT Asset Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        .container {
            width: 100%;
            padding: 0 15px;
            margin: 0 auto;
            max-width: 1200px;
        }

        :root {
            --orange-1: #ff7a00;
            --orange-2: #ff9800;
            --orange-3: #ffb000;
            --dark-1: #111111;
            --text-main: #1e1e1e;
            --text-soft: #6b7280;
            --surface: #ffffff;
            --border-soft: rgba(255, 152, 0, 0.14);
            --shadow-soft: 0 12px 36px rgba(17, 17, 17, 0.07);
            --radius-xl: 28px;
        }

        body {
            background: radial-gradient(circle at top left, rgba(255, 176, 0, 0.16), transparent 28%),
                linear-gradient(180deg, #fff8f1 0%, #ffffff 100%);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
            min-height: 100vh;
        }

        .page-shell {
            padding: 25px;
        }

        .page-hero {
            position: relative;
            overflow: hidden;
            border-radius: var(--radius-xl);
            background: linear-gradient(135deg, #111111 0%, #ff7a00 100%);
            /* Gradien Gelap ke Oranye */
            box-shadow: 0 18px 45px rgba(255, 122, 0, 0.25);
            padding: 2.5rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-title {
            color: #fff;
            font-size: 2.2rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            margin-bottom: 0.5rem;
        }

        .page-desc {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1rem;
            max-width: 600px;
            margin-bottom: 0;
        }

        .btn-header-light {
            background: #fff;
            color: #111;
            font-weight: 700;
            border-radius: 999px;
            padding: 0.75rem 1.5rem;
            border: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .btn-header-light:hover {
            transform: translateY(-3px);
            background: #f8f9fa;
            color: #000;
        }

        .btn-header-dark {
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(10px);
            color: #fff;
            font-weight: 700;
            border-radius: 999px;
            padding: 0.75rem 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
        }

        .btn-header-dark:hover {
            transform: translateY(-3px);
            background: #000;
            color: #fff;
        }

        .btn-add-item {
            border: none;
            background: #fff;
            color: #111;
            font-weight: 800;
            border-radius: 999px;
            padding: .75rem 1.4rem;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.12);
        }

        .ui-card {
            background: var(--surface);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-soft);
        }

        .toolbar-card {
            padding: 1.25rem;
            margin-bottom: 1.25rem;
        }

        .toolbar-label {
            font-size: .84rem;
            font-weight: 700;
            color: #444;
            margin-bottom: .5rem;
            display: block;
        }

        .search-wrap .input-group {
            border: 1px solid rgba(255, 152, 0, 0.2);
            border-radius: 16px;
            overflow: hidden;
            background: #fff;
        }

        .search-wrap .form-control {
            border: none;
            padding: .8rem 1rem;
        }

        .search-btn {
            background: linear-gradient(135deg, var(--orange-1), var(--orange-3));
            color: #fff;
            border: none;
            padding: 0 1.2rem;
            font-weight: 700;
        }

        .reset-btn {
            background: #222;
            color: #fff;
            border: none;
            padding: 0 1rem;
            display: flex;
            align-items: center;
        }

        .mode-switch {
            display: flex;
            gap: .6rem;
            flex-wrap: wrap;
        }

        .btn-mode {
            padding: .7rem 1.1rem;
            border-radius: 999px;
            border: 1px solid #eee;
            background: #fff;
            color: #444;
            font-weight: 700;
            text-decoration: none;
            transition: .2s;
        }

        .btn-mode.is-active {
            background: #111;
            color: #fff;
            border-color: #111;
        }

        .summary-card {
            padding: 1.25rem;
            border-radius: 20px;
            background: #fff;
            border: 1px solid rgba(255, 152, 0, 0.1);
            position: relative;
            overflow: hidden;
            height: 100%;
        }

        .summary-card::after {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--orange-1);
        }

        .summary-label {
            font-size: .85rem;
            font-weight: 700;
            color: var(--text-soft);
        }

        .summary-value {
            font-size: 2rem;
            font-weight: 800;
            color: #111;
            margin: .2rem 0;
        }

        .summary-icon {
            width: 45px;
            height: 45px;
            background: #fff4e6;
            color: var(--orange-1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }

        .table-card .card-header {
            background: #111;
            color: #fff;
            border: none;
            padding: 1.2rem;
            border-radius: 22px 22px 0 0;
        }

        .limit-box {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 999px;
            padding: 5px 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #fff;
            font-size: .85rem;
        }

        .limit-box select {
            background: transparent;
            border: none;
            color: #fff;
            font-weight: 700;
            outline: none;
        }

        .limit-box select option {
            color: #000;
        }

        .thumb-img {
            width: 55px;
            height: 55px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid #eee;
            cursor: pointer;
        }

        .thumb-placeholder {
            width: 55px;
            height: 55px;
            background: #f9f9f9;
            border: 1px dashed #ccc;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #aaa;
        }

        .asset-code {
            font-weight: 800;
            color: #111;
            font-size: 0.95rem;
        }

        .meta-line {
            display: block;
            font-size: 0.88rem;
            margin-bottom: 2px;
        }

        .meta-muted {
            color: #666;
            font-size: 0.82rem;
        }

        .badge.rounded-pill {
            padding: 0.5em 0.9em;
            font-weight: 700;
        }

        .pagination .page-link {
            border-radius: 10px;
            margin: 0 3px;
            color: #111;
            font-weight: 700;
            border: 1px solid #eee;
        }

        .pagination .page-item.active .page-link {
            background: #111;
            border-color: #111;
            color: #fff;
        }

        .action-group .btn {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            margin: 2px;
        }

        .modal-header.bg-warning-custom {
            background: #111;
            color: #fff;
        }

        .modal-header .btn-close {
            filter: invert(1);
        }
    </style>
</head>

<body>

    <div class="container-fluid p-0">
        <div class="d-flex flex-nowrap w-100 overflow-hidden">

            <?php include '../layout/sidebar.php'; ?>

            <div id="mainContent" class="flex-grow-1" style="transition: all 0.28s ease; min-width: 0;">

                <div class="page-shell">

                    <!-- Header Hero Modern -->
                    <div class="page-hero">
                        <div class="hero-text">
                            <?php if ($isAdmin): ?>
                                <h1 class="page-title">Peralatan & Asset IT</h1>
                                <p class="page-desc">Kelola inventaris pusat, distribusi barang ke cabang, dan pantau status aset secara real-time dari Dashboard HO.</p>
                            <?php else: ?>
                                <h1 class="page-title">Aset & Inventaris Cabang</h1>
                                <p class="page-desc">Kelola stok barang di unit Anda, laporkan kerusakan, dan pantau pengiriman logistik dari pusat dengan mudah.</p>
                            <?php endif; ?>
                        </div>

                        <div class="hero-actions d-flex gap-3">
                            <?php if ($isAdmin): ?>
                                <!-- Tombol Khusus Admin HO -->
                                <button class="btn btn-header-light" data-bs-toggle="modal" data-bs-target="#modalCreate">
                                    <i class="bi bi-plus-circle me-2"></i>Tambah Barang Master
                                </button>
                            <?php else: ?>
                                <!-- Tombol Khusus User Cabang -->
                                <button class="btn btn-header-light" data-bs-toggle="modal" data-bs-target="#modalCreateCabang">
                                    <i class="bi bi-plus-circle me-2"></i>Tambah Aset
                                </button>
                                <button class="btn btn-header-dark" data-bs-toggle="modal" data-bs-target="#modalPengirimanUser">
                                    <i class="bi bi-truck me-2"></i>Kirim Barang Rusak
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Stats Summary -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="summary-label">Total Inventaris</div>
                                        <div class="summary-value"><?= $totalInventaris ?></div>
                                        <div class="text-muted small">Aktif di lokasi saat ini</div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-box-seam"></i></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="summary-label">Aset Masuk</div>
                                        <div class="summary-value"><?= $totalMasuk ?></div>
                                        <div class="text-muted small">Diterima di gudang</div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-box-arrow-in-down"></i></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="summary-label">Aset Keluar</div>
                                        <div class="summary-value"><?= $totalKeluar ?></div>
                                        <div class="text-muted small">Dikirim ke tujuan</div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-box-arrow-up"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Toolbar & Filter -->
                    <div class="ui-card toolbar-card">
                        <div class="row g-3 align-items-end">
                            <div class="col-lg-6">
                                <form method="GET" class="search-wrap">
                                    <input type="hidden" name="filter" value="<?= $filterValue ?>">
                                    <input type="hidden" name="limit" value="<?= $limit ?>">
                                    <label class="toolbar-label">Pencarian Cepat</label>
                                    <div class="input-group">
                                        <input type="text" name="cari" class="form-control" placeholder="Nama, No Asset, atau Serial Number..." value="<?= $searchValue ?>">
                                        <button class="btn search-btn" type="submit"><i class="bi bi-search me-2"></i>Cari</button>
                                        <a href="index.php" class="btn reset-btn">Reset</a>
                                    </div>
                                </form>
                            </div>
                            <div class="col-lg-6">
                                <label class="toolbar-label">Filter Tampilan</label>
                                <div class="mode-switch">
                                    <a href="index.php?cari=<?= urlencode($searchInput) ?>&limit=<?= $limit ?>" class="<?= $btnSemua ?>">
                                        <i class="bi bi-grid-fill me-2"></i>Asset Tersedia
                                    </a>
                                    <a href="index.php?filter=masuk&cari=<?= urlencode($searchInput) ?>&limit=<?= $limit ?>" class="<?= $btnMasuk ?>">
                                        <i class="bi bi-box-arrow-in-down me-2"></i>Logistik Masuk
                                    </a>
                                    <a href="index.php?filter=keluar&cari=<?= urlencode($searchInput) ?>&limit=<?= $limit ?>" class="<?= $btnKeluar ?>">
                                        <i class="bi bi-box-arrow-up me-2"></i>Logistik Keluar
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Data Table -->
                    <div class="ui-card table-card">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div>
                                <div class="fw-bold fs-5"><i class="<?= $tableIcon ?> me-2 text-warning"></i><?= h($tableTitle) ?></div>
                                <div class="small opacity-75">Menampilkan <?= $fromRow ?> - <?= $toRow ?> dari <?= $totalRows ?> data</div>
                            </div>
                            <form method="GET" class="mb-0">
                                <input type="hidden" name="cari" value="<?= $searchValue ?>">
                                <input type="hidden" name="filter" value="<?= $filterValue ?>">
                                <div class="limit-box">
                                    <span>Baris:</span>
                                    <select name="limit" onchange="this.form.submit()">
                                        <option value="10" <?= $limit === 10 ? 'selected' : '' ?>>10</option>
                                        <option value="25" <?= $limit === 25 ? 'selected' : '' ?>>25</option>
                                        <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
                                        <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
                                    </select>
                                </div>
                            </form>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th width="50" class="ps-4">No</th>
                                        <th>Asset & Identitas</th>
                                        <?php if ($filter === 'masuk' || $filter === 'keluar'): ?>
                                            <th>Informasi Logistik</th>
                                            <th>Status & Resi</th>
                                            <th>Bukti</th>
                                        <?php else: ?>
                                            <th>Spesifikasi</th>
                                            <th>Lokasi / User</th>
                                            <th>Kondisi</th>
                                            <th>Foto</th>
                                        <?php endif; ?>
                                        <th class="text-center pe-4">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (mysqli_num_rows($query) > 0): $no = $offset + 1;
                                        while ($data = mysqli_fetch_assoc($query)): ?>
                                            <tr>
                                                <td class="ps-4 text-muted"><?= $no++ ?></td>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= h($data['nama_barang']) ?></div>
                                                    <div class="asset-code"><?= h($data['no_asset'] ?? '-') ?></div>
                                                    <div class="meta-muted">SN: <?= h($data['serial_number'] ?? '-') ?></div>
                                                </td>

                                                <?php if ($filter === 'masuk'): ?>
                                                    <td>
                                                        <span class="meta-line"><i class="bi bi-calendar-check me-2"></i><?= h($data['tanggal']) ?></span>
                                                        <span class="meta-line"><i class="bi bi-geo-alt me-2 text-danger"></i>Asal: <?= h($data['info_branch']) ?></span>
                                                        <span class="meta-line"><i class="bi bi-person me-2"></i><?= h($data['pemilik_barang']) ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="mb-2"><?= shippingBadge($data['status_pengiriman']) ?></div>
                                                        <div class="meta-muted"><i class="bi bi-receipt me-1"></i><?= h($data['nomor_resi_keluar'] ?: 'Belum ada') ?></div>
                                                    </td>
                                                    <td>
                                                        <?php if ($data['foto_resi_keluar']): ?>
                                                            <img src="../assets/images/<?= h($data['foto_resi_keluar']) ?>" class="thumb-img previewFoto" data-foto="../assets/images/<?= h($data['foto_resi_keluar']) ?>">
                                                        <?php else: ?><div class="thumb-placeholder"><i class="bi bi-receipt"></i></div><?php endif; ?>
                                                    </td>
                                                    <td class="text-center pe-4">
                                                        <?php if (in_array(strtolower($data['status_pengiriman']), ['sedang perjalanan', 'menunggu persetujuan admin'])): ?>
                                                            <button class="btn btn-primary btn-sm btnKonfirmasiTerima" data-id="<?= $data['id_transaksi'] ?>" data-role="<?= $isAdmin ? 'admin' : 'user' ?>"><i class="bi bi-check2-circle"></i></button>
                                                        <?php else: ?>
                                                            <i class="bi bi-lock-fill text-muted"></i>
                                                        <?php endif; ?>
                                                    </td>

                                                <?php elseif ($filter === 'keluar'): ?>
                                                    <td>
                                                        <span class="meta-line"><i class="bi bi-calendar-plus me-2"></i><?= h($data['tanggal']) ?></span>
                                                        <span class="meta-line"><i class="bi bi-geo-alt me-2 text-primary"></i>Tujuan: <?= h($data['info_branch']) ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="mb-2"><?= shippingBadge($data['status_pengiriman']) ?></div>
                                                        <div class="meta-muted"><i class="bi bi-receipt me-1"></i><?= h($data['nomor_resi_keluar'] ?: '-') ?></div>
                                                    </td>
                                                    <td>
                                                        <?php if ($data['foto_resi_keluar']): ?>
                                                            <img src="../assets/images/<?= h($data['foto_resi_keluar']) ?>" class="thumb-img previewFoto" data-foto="../assets/images/<?= h($data['foto_resi_keluar']) ?>">
                                                        <?php else: ?><div class="thumb-placeholder"><i class="bi bi-receipt"></i></div><?php endif; ?>
                                                    </td>
                                                    <td class="text-center pe-4">
                                                        <?php if ($isAdmin): ?>
                                                            <?php $logisticStatus = strtolower(trim($data['status_pengiriman'] ?? '')); ?>
                                                            <?php if (in_array($logisticStatus, ['sedang perjalanan', 'menunggu persetujuan admin'], true)): ?>
                                                                <button type="button" class="btn btn-info btn-sm text-white btnLogistikStatus" data-id="<?= $data['id_transaksi'] ?>" data-status="<?= h($logisticStatus) ?>" title="Informasi status logistik"><i class="bi bi-truck"></i></button>
                                                            <?php else: ?>
                                                                <button type="button" class="btn btn-success btn-sm text-white" disabled title="Pengiriman selesai"><i class="bi bi-lock-fill"></i></button>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <i class="bi bi-lock-fill text-muted"></i>
                                                        <?php endif; ?>
                                                    </td>

                                                <?php else: ?>
                                                    <td>
                                                        <span class="badge bg-light text-dark border mb-1"><?= h($data['nama_merk'] ?? '-') ?></span>
                                                        <div class="meta-line text-muted">Tipe: <?= h($data['nama_tipe'] ?? '-') ?></div>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $userMaster = trim((string)($data['master_user'] ?? ''));
                                                        $userLogistic = trim((string)($data['last_logistic_user'] ?? ''));

                                                        if ($userMaster !== '' && $userMaster !== '0') {
                                                            $namaTampil = $userMaster;
                                                        } elseif ($userLogistic !== '' && $userLogistic !== '0') {
                                                            $namaTampil = $userLogistic;
                                                        } else {
                                                            $namaTampil = 'No User';
                                                        }
                                                        ?>
                                                        <div class="meta-line"><i class="bi bi-person me-1"></i><?= h($namaTampil) ?></div>
                                                    </td>
                                                    <td>
                                                        <?= barangBadge($data['bermasalah']) ?>
                                                        <?php if ($data['bermasalah'] === 'Iya'): ?><div class="small text-danger mt-1" style="max-width:150px"><?= h($data['keterangan_masalah']) ?></div><?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($data['foto']): ?>
                                                            <img src="../assets/images/<?= h($data['foto']) ?>" class="thumb-img previewFoto" data-foto="../assets/images/<?= h($data['foto']) ?>">
                                                        <?php else: ?><div class="thumb-placeholder"><i class="bi bi-image"></i></div><?php endif; ?>
                                                    </td>
                                                    <td class="text-center pe-4">
                                                        <div class="action-group">
                                                            <?php if ($isAdmin): ?>
                                                                <!-- Tombol Admin: Edit Master, Hapus, dan KIRIM KE CABANG -->
                                                                <button class="btn btn-warning btn-sm btnEditMaster" data-id="<?= $data['id'] ?>" title="Edit Master"><i class="bi bi-pencil-fill"></i></button>
                                                                <button class="btn btn-danger btn-sm btnDelete" data-id="<?= $data['id'] ?>" title="Hapus"><i class="bi bi-trash"></i></button>

                                                                <!-- Tombol Truk Biru (Logistik ke Cabang) -->
                                                                <button class="btn btn-info btn-sm text-white btnLogistik" data-id="<?= $data['id'] ?>" data-bermasalah="<?= ($data['bermasalah'] === 'Iya' ? '1' : '0') ?>" title="Kirim ke Cabang"><i class="bi bi-truck"></i></button>

                                                            <?php else: ?>
                                                                <!-- Tombol User: Edit Aset Cabang & Hapus -->
                                                                <button class="btn btn-warning btn-sm btnEditMaster" data-id="<?= $data['id'] ?>" title="Edit Aset"><i class="bi bi-pencil-fill"></i></button>
                                                                <button class="btn btn-danger btn-sm btnDelete" data-id="<?= $data['id'] ?>" title="Hapus"><i class="bi bi-trash"></i></button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endwhile;
                                    else: ?>
                                        <tr>
                                            <td colspan="<?= $emptyColspan ?>" class="text-center py-5 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                                Belum ada data yang dapat ditampilkan.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="p-4 border-top">
                                <nav>
                                    <ul class="pagination pagination-sm mb-0">
                                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                <a class="page-link" href="?page=<?= $i ?>&limit=<?= $limit ?>&cari=<?= urlencode($searchInput) ?>&filter=<?= urlencode($filter) ?>"><?= $i ?></a>
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

    <!-- =========================================================================
     MODALS SECTION
     ========================================================================= -->

    <!-- Modal Create -->
    <div class="modal fade" id="modalCreate" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 rounded-4 overflow-hidden">
                <div class="modal-header bg-warning-custom">
                    <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Tambah Aset Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="contentCreate">
                    <div class="text-center p-4">
                        <div class="spinner-border text-warning" role="status"></div>
                        <p class="mt-2 text-muted">Memuat form...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Update (Master & Logistik) -->
    <div class="modal fade" id="modalUpdate" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 rounded-4 overflow-hidden">
                <div class="modal-header bg-warning-custom">
                    <h5 class="modal-title fw-bold" id="modalUpdateTitle">Update Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="contentUpdate">
                    <div class="text-center p-4">
                        <div class="spinner-border text-warning" role="status"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Pengiriman Cabang ke HO (User Only) -->
    <div class="modal fade" id="modalPengirimanUser" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 rounded-4 overflow-hidden">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-truck me-2"></i>Kirim Barang Rusak ke HO</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="contentPengirimanUser">
                    <div class="text-center p-4">
                        <div class="spinner-border text-dark"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Preview Foto -->
    <div class="modal fade" id="modalFoto" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-dark border-0">
                <div class="modal-body p-1 text-center">
                    <img id="fotoPreview" src="" class="img-fluid rounded shadow-lg">
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Konfirmasi Terima (User Cabang) -->
    <div class="modal fade" id="modalTerimaCabang" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content border-0 rounded-4 overflow-hidden">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, #ff7a00, #ffb000); border-bottom: none;">
                    <h5 class="modal-title fw-bold"><i class="bi bi-box-seam me-2"></i>Konfirmasi Penerimaan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="contentTerimaCabang"></div>
            </div>
        </div>
    </div>

    <!-- Modal Create Aset (Khusus User Cabang) -->
    <div class="modal fade" id="modalCreateCabang" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 rounded-4 overflow-hidden">
                <div class="modal-header bg-warning-custom">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pc-display me-2"></i>Tambah Aset Cabang</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="contentCreateCabang">
                    <div class="text-center p-4">
                        <div class="spinner-border text-warning" role="status"></div>
                        <p class="mt-2 text-muted">Memuat form...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        /**
         * SELECT2 & MODAL HELPERS
         */
        function initSelect2(container, modal) {
            $(container).find('select.select2').each(function() {
                $(this).select2({
                    dropdownParent: $(modal),
                    width: '100%',
                    placeholder: 'Pilih...',
                    allowClear: true
                });
            });
        }

        /**
         * MODAL LOADERS (CREATE)
         */
        // Modal Create Master (Admin)
        $('#modalCreate').on('show.bs.modal', function() {
            $('#contentCreate').load('create.php', function() {
                initSelect2('#contentCreate', '#modalCreate');
            });
        });

        // Modal Create Aset (User Cabang)
        $('#modalCreateCabang').on('show.bs.modal', function() {
            $('#contentCreateCabang').load('create_cabang.php', function() {
                initSelect2('#contentCreateCabang', '#modalCreateCabang');
            });
        });

        // Modal Pengiriman Cabang ke HO (User Only)
        $('#modalPengirimanUser').on('show.bs.modal', function() {
            $('#contentPengirimanUser').load('pengiriman_user.php', function() {
                initSelect2('#contentPengirimanUser', '#modalPengirimanUser');
            });
        });

        /**
         * ACTION BUTTONS (EDIT & LOGISTIK)
         */
        // Edit Data (Berbeda URL antara Admin dan User)
        $(document).on('click', '.btnEditMaster', function() {
            const id = $(this).data('id');
            const isAdmin = <?= is_admin() ? 'true' : 'false' ?>;

            // Penentuan target file
            const targetUrl = isAdmin ? 'update.php' : 'update_cabang.php';
            const modalTitle = isAdmin ? 'Update Data Barang Master' : 'Edit Data Aset Cabang';

            $('#modalUpdateTitle').text(modalTitle);
            $('#contentUpdate').html('<div class="text-center p-4"><div class="spinner-border text-warning"></div></div>');
            bootstrap.Modal.getOrCreateInstance('#modalUpdate').show();

            $.get(targetUrl, {
                id: id,
                type: 'master'
            }, function(html) {
                $('#contentUpdate').html(html);
                initSelect2('#contentUpdate', '#modalUpdate');
            });
        });

        // Pengiriman Logistik ke Cabang (Hanya Admin)
        $(document).on('click', '.btnLogistik', function() {
            const id = $(this).data('id');
            const isBermasalah = $(this).data('bermasalah');

            if (isBermasalah == '1' || isBermasalah == 'Iya') {
                Swal.fire('Perhatian', 'Barang berstatus bermasalah tidak dapat dikirim ke cabang.', 'error');
                return;
            }

            $('#modalUpdateTitle').text('Logistik Pengiriman ke Cabang');
            $('#contentUpdate').html('<div class="text-center p-4"><div class="spinner-border text-info"></div></div>');
            bootstrap.Modal.getOrCreateInstance('#modalUpdate').show();

            $.get('update.php', {
                id: id,
                type: 'logistik'
            }, function(html) {
                $('#contentUpdate').html(html);
                initSelect2('#contentUpdate', '#modalUpdate');
            });
        });

        $(document).on('click', '.btnLogistikStatus', function() {
            const status = $(this).data('status');
            if (status === 'sedang perjalanan' || status === 'menunggu persetujuan admin') {
                Swal.fire({
                    icon: 'info',
                    title: 'Pengiriman belum selesai',
                    text: 'Barang sedang dalam perjalanan. User belum melakukan konfirmasi penerimaan.',
                    confirmButtonColor: '#ff7a00',
                    confirmButtonText: 'Tutup'
                });
            } else {
                Swal.fire({
                    icon: 'success',
                    title: 'Pengiriman selesai',
                    text: 'Barang sudah diterima dan proses logistik sudah selesai.',
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'Tutup'
                });
            }
        });

        /**
         * KONFIRMASI PENERIMAAN (LOGISTIK MASUK)
         */
        $(document).on('click', '.btnKonfirmasiTerima', function() {
            const id = $(this).data('id');
            const role = $(this).data('role');

            if (role === 'admin') {
                // Admin Konfirmasi Terima Barang dari Cabang
                Swal.fire({
                    title: 'Konfirmasi Terima?',
                    text: "Pastikan fisik barang sudah diterima di HO Jakarta.",
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#ff7a00',
                    confirmButtonText: 'Ya, Sudah Terima'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.post('pengiriman_approval.php', {
                            id_pengiriman: id,
                            nama_penerima: 'Admin HO'
                        }, function(res) {
                            if (res.status === 'success') {
                                Swal.fire('Berhasil', res.message, 'success').then(() => location.reload());
                            } else {
                                Swal.fire('Error', res.message, 'error');
                            }
                        }, 'json');
                    }
                });
            } else {
                // User Cabang Membuka Form Terima Barang dari HO
                $('#contentTerimaCabang').html('<div class="text-center p-4"><div class="spinner-border text-warning"></div></div>');
                $('#contentTerimaCabang').load('terima_barang_form.php?id=' + id);
                bootstrap.Modal.getOrCreateInstance('#modalTerimaCabang').show();
            }
        });

        /**
         * AJAX SUBMIT FORMS
         */
        $(document).on('submit', '#formCreate, #formUpdate, #formUpdateCabang, #formPengirimanUser, #formTerimaCabang, #formCreateCabang', function(e) {
            e.preventDefault();
            const $form = $(this);
            const $btn = $form.find('button[type="submit"]');
            const originalBtnHtml = $btn.html();
            const formId = $form.attr('id');

            // Mapping URL berdasarkan ID Form agar tidak salah alamat
            const urlMap = {
                'formCreate': 'create.php',
                'formUpdate': 'update.php',
                'formUpdateCabang': 'update_cabang.php',
                'formPengirimanUser': 'pengiriman_user.php',
                'formTerimaCabang': 'terima_barang_proses.php',
                'formCreateCabang': 'create_cabang.php'
            };

            const targetUrl = $form.attr('action') || urlMap[formId];

            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Proses...');

            $.ajax({
                url: targetUrl,
                type: 'POST',
                data: new FormData(this),
                contentType: false,
                processData: false,
                dataType: 'json',
                success: function(res) {
                    if (res.status === 'success' || res.success) {
                        Swal.fire('Berhasil', res.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Gagal', res.message || 'Terjadi kesalahan sistem', 'error');
                        $btn.prop('disabled', false).html(originalBtnHtml);
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Gagal menghubungi server', 'error');
                    $btn.prop('disabled', false).html(originalBtnHtml);
                }
            });
        });

        /**
         * DELETE ACTION
         */
        $(document).on('click', '.btnDelete', function() {
            const id = $(this).data('id');
            Swal.fire({
                title: 'Hapus data ini?',
                text: "Data yang dihapus tidak dapat dikembalikan!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Ya, Hapus!'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.getJSON('delete.php', {
                        id: id
                    }, function(res) {
                        if (res.status === 'success') {
                            Swal.fire('Dihapus!', res.message, 'success').then(() => location.reload());
                        } else {
                            Swal.fire('Gagal', res.message, 'error');
                        }
                    });
                }
            });
        });

        /**
         * UI INTERACTION & PREVIEW
         */
        // Preview Foto
        $(document).on('click', '.previewFoto', function() {
            $('#fotoPreview').attr('src', $(this).data('foto'));
            new bootstrap.Modal('#modalFoto').show();
        });

        // Toggle Keterangan Masalah di Form Update
        $(document).on('change', '#bermasalahUpdate', function() {
            if ($(this).val() === 'Iya') {
                $('#keteranganMasalahWrap').slideDown();
                $('#keteranganMasalahUpdate').attr('required', true);
            } else {
                $('#keteranganMasalahWrap').slideUp();
                $('#keteranganMasalahUpdate').removeAttr('required').val('');
            }
        });
    </script>

</body>

</html>