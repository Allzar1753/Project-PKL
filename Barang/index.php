<?php
include '../config/koneksi.php';
/** @var mysqli $koneksi */ //
require_once '../config/auth.php';
require_once '../config/warranty_helper.php';

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
 * (HANYA MENGUBAH NAMA CLASS CSS MENJADI SOFT BADGES, LOGIKA TETAP SAMA)
 */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function shippingBadge(string $status): string
{
    $class = 'badge-soft-secondary';
    $icon  = 'bi-dash-circle';
    $s = strtolower(trim($status));

    if ($s === 'menunggu persetujuan admin' || $s === 'sedang dikemas') {
        $class = 'badge-soft-warning';
        $icon  = 'bi-box-seam';
    } elseif ($s === 'sedang perjalanan') {
        $class = 'badge-soft-primary';
        $icon  = 'bi-truck';
    } elseif (in_array($s, ['sudah diterima', 'sudah diterima ho', 'selesai'], true)) {
        $class = 'badge-soft-success';
        $icon  = 'bi-check-circle';
    }
    return '<span class="badge rounded-pill ' . $class . '"><i class="bi ' . $icon . ' me-1"></i>' . h($status) . '</span>';
}

function barangBadge(string $bermasalah): string
{
    if ($bermasalah === 'Iya') {
        return '<span class="badge rounded-pill badge-soft-danger"><i class="bi bi-exclamation-triangle me-1"></i>Bermasalah</span>';
    }
    return '<span class="badge rounded-pill badge-soft-success"><i class="bi bi-check-circle me-1"></i>Normal</span>';
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
// PENGATURAN FILTER, PENCARIAN, DAN PAGINATION (TIDAK DIUBAH SAMA SEKALI)
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
    $searchSql_barang = " AND (tb_barang.nama_barang LIKE '%$s%' OR barang.kode_aset LIKE '%$s%' OR barang.no_asset LIKE '%$s%' OR barang.serial_number LIKE '%$s%') ";
    $searchSql_pengiriman_ho = " AND (tb_barang.nama_barang LIKE '%$s%' OR p.serial_number LIKE '%$s%' OR b.kode_aset LIKE '%$s%' OR b.no_asset LIKE '%$s%' OR p.pemilik_barang LIKE '%$s%') ";
    $searchSql_barang_pengiriman = " AND (tb_barang.nama_barang LIKE '%$s%' OR b.kode_aset LIKE '%$s%' OR b.no_asset LIKE '%$s%' OR b.serial_number LIKE '%$s%' OR p.nama_penerima LIKE '%$s%' OR b.user LIKE '%$s%') ";
}

// =========================================================================
// LOGIKA SUMMARY CARD & QUERY DATA (TIDAK DIUBAH SAMA SEKALI)
// =========================================================================

if ($isAdmin) {
    $excludeTransitSql = " AND barang.id NOT IN (SELECT id_barang FROM barang_pengiriman WHERE status_pengiriman IN ('Sedang perjalanan', 'Sudah diterima')) ";
} else {
    $excludeTransitSql = " AND barang.id NOT IN (SELECT id_barang FROM barang_pengiriman WHERE status_pengiriman = 'Sedang perjalanan') ";
    $excludeTransitSql .= " AND barang.serial_number NOT IN (SELECT serial_number FROM pengiriman_cabang_ho WHERE branch_asal = $myBranchId AND status_pengiriman NOT IN ('Ditolak', 'Selesai')) ";
}

$stokAktifSql = $isAdmin ? " AND (barang.status IN ('Tersedia','Diterima') OR barang.bermasalah = 'Iya') AND barang.status NOT IN (7, 8) " : " AND barang.status IN ('Tersedia','Diterima') AND barang.id_status NOT IN (7, 8) ";

// =========================================================================
// LOGIKA SINKRONISASI DATA (ADMIN HO = ID 40)
// =========================================================================

$idBranchHO = 40;

if ($isAdmin) {
    $whereLokasi = "barang.id_branch = $idBranchHO";
    $excludeTransitSql = " AND barang.id NOT IN (SELECT id_barang FROM barang_pengiriman WHERE status_pengiriman = 'Sedang perjalanan') ";

    $totalInventaris = fetchSingleValue($koneksi, "SELECT COUNT(id) AS total FROM barang WHERE $whereLokasi $excludeTransitSql AND status IN ('Tersedia','Diterima') AND id_status NOT IN (7, 8)");
    $totalMasuk      = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE status_pengiriman IN ('Sudah diterima HO', 'Selesai')");
    $totalKeluar     = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman");
} else {
    $whereLokasi = "barang.id_branch = $myBranchId";
    $excludeTransitSql = " AND barang.id NOT IN (SELECT id_barang FROM barang_pengiriman WHERE status_pengiriman = 'Sedang perjalanan') ";
    $excludeTransitSql .= " AND barang.serial_number NOT IN (SELECT serial_number FROM pengiriman_cabang_ho WHERE branch_asal = $myBranchId AND status_pengiriman NOT IN ('Ditolak', 'Selesai')) ";

    $totalInventaris = fetchSingleValue($koneksi, "SELECT COUNT(id) AS total FROM barang WHERE $whereLokasi $excludeTransitSql AND status IN ('Tersedia','Diterima') AND id_status NOT IN (7, 8)");
    $totalMasuk      = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman WHERE branch_tujuan = $myBranchId AND status_pengiriman = 'Sudah diterima'");
    $totalKeluar     = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE branch_asal = $myBranchId");
}

if ($filter === 'keluar') {
    if ($isAdmin) {
        $querySql = "SELECT p.id_pengiriman AS id_transaksi, p.tanggal_keluar AS tanggal, p.status_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar, p.foto_barang_diterima,
                            b.id AS id_barang, b.bermasalah, b.kode_aset, b.no_asset, b.serial_number, tb_barang.nama_barang, br.nama_branch AS info_branch, p.nama_penerima as pemilik_barang
                     FROM barang_pengiriman p
                     JOIN barang b ON p.id_barang = b.id
                     JOIN tb_barang ON b.id_barang = tb_barang.id_barang
                     LEFT JOIN tb_branch br ON p.branch_tujuan = br.id_branch
                     WHERE 1=1 $searchSql_barang_pengiriman ORDER BY p.id_pengiriman DESC";
    } else {
        $querySql = "SELECT p.id_pengiriman_ho AS id_transaksi, p.tanggal_pengajuan AS tanggal, p.status_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar, p.foto_barang_diterima_ho AS foto_barang_diterima,
                            p.serial_number, p.pemilik_barang, tb_barang.nama_barang, 'Pusat HO' AS info_branch, b.kode_aset, b.no_asset
                     FROM pengiriman_cabang_ho p
                     JOIN tb_barang ON p.id_barang = tb_barang.id_barang
                     LEFT JOIN barang b ON p.serial_number = b.serial_number
                     WHERE p.branch_asal = $myBranchId $searchSql_pengiriman_ho ORDER BY p.id_pengiriman_ho DESC";
    }
} elseif ($filter === 'masuk') {
    if ($isAdmin) {
        $querySql = "SELECT p.id_pengiriman_ho AS id_transaksi, p.tanggal_pengajuan AS tanggal, p.status_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar, p.foto_barang_diterima_ho AS foto_barang_diterima,
                            p.serial_number, p.pemilik_barang, tb_barang.nama_barang, br.nama_branch AS info_branch, b.kode_aset, b.no_asset
                     FROM pengiriman_cabang_ho p
                     JOIN tb_barang ON p.id_barang = tb_barang.id_barang
                     LEFT JOIN barang b ON p.serial_number = b.serial_number
                     LEFT JOIN tb_branch br ON p.branch_asal = br.id_branch
                     WHERE 1=1 $searchSql_pengiriman_ho ORDER BY p.id_pengiriman_ho DESC";
    } else {
        $querySql = "
            SELECT * FROM (
                SELECT p.id_pengiriman AS id_transaksi, p.tanggal_keluar AS tanggal, p.status_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar, p.foto_barang_diterima,
                        b.kode_aset, b.no_asset, b.serial_number, tb_barang.nama_barang, 'Pusat HO' AS info_branch, 'ho' AS sumber_transaksi,
                        CASE
                            WHEN b.`user` IS NOT NULL AND b.`user` != '' AND b.`user` != '0' THEN b.`user`
                            ELSE (
                                SELECT pch.pemilik_barang FROM pengiriman_cabang_ho pch
                                WHERE pch.serial_number = b.serial_number AND pch.pemilik_barang IS NOT NULL AND pch.pemilik_barang != '' AND pch.pemilik_barang != '0'
                                ORDER BY pch.id_pengiriman_ho DESC LIMIT 1
                            )
                        END AS pemilik_barang
                FROM barang_pengiriman p
                JOIN barang b ON p.id_barang = b.id
                JOIN tb_barang ON b.id_barang = tb_barang.id_barang
                WHERE p.branch_tujuan = $myBranchId

                UNION ALL

                SELECT p.id_pengiriman_ho AS id_transaksi, p.tanggal_pengajuan AS tanggal, p.status_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar,
                        p.foto_barang_diterima_ho AS foto_barang_diterima,
                        b.kode_aset, b.no_asset, p.serial_number, tb_barang.nama_barang, br.nama_branch AS info_branch, 'antar_cabang' AS sumber_transaksi,
                        p.pemilik_barang
                FROM pengiriman_cabang_ho p
                JOIN tb_barang ON p.id_barang = tb_barang.id_barang
                LEFT JOIN barang b ON p.serial_number = b.serial_number
                LEFT JOIN tb_branch br ON p.branch_asal = br.id_branch
                WHERE p.branch_tujuan = $myBranchId
                  AND COALESCE(p.jenis_pengiriman, 'ke_ho') = 'ke_cabang'
            ) AS masuk_cabang
            WHERE 1=1
            ORDER BY id_transaksi DESC";
    }
} else {
    // ... (Bagian else yang bawah ini biarkan saja, jangan diubah)
    if ($isAdmin) {
        $subqueryLastUser = "(SELECT p.pemilik_barang FROM pengiriman_cabang_ho p WHERE p.serial_number = barang.serial_number AND p.status_pengiriman IN ('Sudah diterima HO', 'Selesai') AND p.pemilik_barang IS NOT NULL AND p.pemilik_barang != '' AND p.pemilik_barang != '0' ORDER BY p.id_pengiriman_ho DESC LIMIT 1)";
    } else {
        $subqueryLastUser = "(SELECT pch.pemilik_barang FROM pengiriman_cabang_ho pch WHERE pch.serial_number = barang.serial_number AND pch.pemilik_barang IS NOT NULL AND pch.pemilik_barang != '' AND pch.pemilik_barang != '0' ORDER BY pch.id_pengiriman_ho DESC LIMIT 1)";
    }

    if ($searchInput !== '') {
        $s = mysqli_real_escape_string($koneksi, $searchInput);
        $searchSql_barang = " AND (tb_barang.nama_barang LIKE '%$s%' OR barang.kode_aset LIKE '%$s%' OR barang.no_asset LIKE '%$s%' OR barang.serial_number LIKE '%$s%' OR barang.user LIKE '%$s%' OR $subqueryLastUser LIKE '%$s%') ";
    }

    $querySql = "SELECT barang.id, barang.kode_aset, barang.no_asset, barang.serial_number, barang.bermasalah, barang.foto,
                        barang.tanggal_garansi_berakhir, barang.masa_garansi_bulan, 
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

    <!-- CSS SINKRONISASI HEXINDO THEME -->
    <style>
        :root {
            /* TEMA HEXINDO / HITACHI */
            --orange-1: #E64312;
            --orange-2: #F25C05;
            --dark-1: #231F20;
            --text-main: #333333;
            --text-soft: #666666;
            --surface-bg: #F4F6F9;
            --border-soft: #E0E4E8;
            --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.04);
            --radius-xl: 8px;
            /* Lebih kotak / industrial */
        }

        body {
            background-color: var(--surface-bg);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
            min-height: 100vh;
        }

        .page-shell {
            padding: 24px 32px;
        }

        /* Hero Banner */
        .page-hero {
            position: relative;
            background: var(--dark-1);
            border-top: 4px solid var(--orange-1);
            border-radius: var(--radius-xl);
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: var(--shadow-soft);
        }

        .page-title {
            color: #fff;
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .page-desc {
            color: #9ca3af;
            font-size: 0.95rem;
            margin-bottom: 0;
        }

        /* Hero Buttons */
        .btn-header-light {
            background: #fff;
            color: var(--dark-1);
            font-weight: 600;
            border-radius: var(--radius-xl);
            padding: 0.6rem 1.2rem;
            border: none;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }

        .btn-header-light:hover {
            background: var(--orange-1);
            color: #fff;
        }

        .btn-header-dark {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            font-weight: 600;
            border-radius: var(--radius-xl);
            padding: 0.6rem 1.2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }

        .btn-header-dark:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Summary Cards */
        .ui-card {
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-soft);
        }

        .summary-card {
            padding: 1.25rem 1.5rem;
            border-radius: var(--radius-xl);
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-left: 4px solid var(--orange-1);
            height: 100%;
            box-shadow: var(--shadow-soft);
            transition: all .2s ease;
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
        }

        .summary-label {
            font-size: .85rem;
            font-weight: 600;
            color: var(--text-soft);
        }

        .summary-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--dark-1);
            margin: .2rem 0;
        }

        .summary-icon {
            width: 45px;
            height: 45px;
            background: rgba(230, 67, 18, 0.1);
            /* Transparan orange hexindo */
            color: var(--orange-1);
            border-radius: var(--radius-xl);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        /* Toolbar & Search */
        .toolbar-card {
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
        }

        .toolbar-label {
            font-size: .85rem;
            font-weight: 600;
            color: var(--text-soft);
            margin-bottom: .5rem;
            display: block;
        }

        .search-wrap .input-group {
            border: 1px solid var(--border-soft);
            border-radius: var(--radius-xl);
            overflow: hidden;
            background: #fff;
        }

        .search-wrap .form-control {
            border: none;
            padding: .6rem 1rem;
            font-size: 0.9rem;
            box-shadow: none;
        }

        .search-btn {
            background: var(--orange-1);
            color: #fff;
            border: none;
            padding: 0 1.2rem;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .search-btn:hover {
            background: var(--orange-2);
            color: #fff;
        }

        .reset-btn {
            background: #f3f4f6;
            color: var(--text-main);
            border: none;
            padding: 0 1rem;
            display: flex;
            align-items: center;
            font-size: 0.9rem;
        }

        .reset-btn:hover {
            background: #e5e7eb;
        }

        .mode-switch {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
        }

        .btn-mode {
            padding: .5rem 1rem;
            border-radius: var(--radius-xl);
            border: 1px solid var(--border-soft);
            background: #fff;
            color: var(--text-soft);
            font-weight: 600;
            font-size: 0.85rem;
            text-decoration: none;
            transition: .2s;
        }

        .btn-mode:hover {
            border-color: var(--orange-1);
            color: var(--orange-1);
        }

        .btn-mode.is-active {
            background: var(--dark-1);
            color: #fff;
            border-color: var(--dark-1);
        }

        /* Table Design */
        .table-card .card-header {
            background: #fff;
            color: var(--dark-1);
            border-bottom: 1px solid var(--border-soft);
            padding: 1.2rem 1.5rem;
            border-radius: var(--radius-xl) var(--radius-xl) 0 0;
        }

        .table> :not(caption)>*>* {
            padding: 1rem 1.5rem;
            border-bottom-color: var(--border-soft);
        }

        .table-light {
            background-color: #f9fafb !important;
            color: var(--text-soft);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Soft Badges */
        .badge.rounded-pill {
            padding: 0.4em 0.8em;
            font-weight: 600;
            font-size: 0.75rem;
            letter-spacing: 0.3px;
        }

        .badge-soft-success {
            background-color: rgba(16, 185, 129, 0.15);
            color: #059669;
        }

        .badge-soft-warning {
            background-color: rgba(245, 158, 11, 0.15);
            color: #d97706;
        }

        .badge-soft-danger {
            background-color: rgba(239, 68, 68, 0.15);
            color: #b91c1c;
        }

        .badge-soft-primary {
            background-color: rgba(59, 130, 246, 0.15);
            color: #1d4ed8;
        }

        .badge-soft-secondary {
            background-color: rgba(107, 114, 128, 0.15);
            color: #4b5563;
        }

        .limit-box {
            background: #f3f4f6;
            border-radius: var(--radius-xl);
            padding: 5px 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-soft);
            font-size: .85rem;
            border: 1px solid var(--border-soft);
        }

        .limit-box select {
            background: transparent;
            border: none;
            color: var(--dark-1);
            font-weight: 600;
            outline: none;
        }

        .thumb-img {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: var(--radius-xl);
            border: 1px solid var(--border-soft);
            cursor: pointer;
        }

        .thumb-placeholder {
            width: 50px;
            height: 50px;
            background: #f9fafb;
            border: 1px dashed #d1d5db;
            border-radius: var(--radius-xl);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
        }

        .asset-code {
            font-weight: 700;
            color: var(--dark-1);
            font-size: 0.9rem;
        }

        .kode-aset-badge {
            display: inline-block;
            font-family: 'Consolas', 'Courier New', monospace;
            font-weight: 700;
            font-size: 0.82rem;
            letter-spacing: 0.03em;
            color: #fff;
            background: linear-gradient(135deg, var(--orange-1), #F25C05);
            padding: 0.2rem 0.55rem;
            border-radius: 6px;
            margin-bottom: 0.25rem;
        }

        .meta-line {
            display: block;
            font-size: 0.85rem;
            margin-bottom: 2px;
            color: var(--text-main);
        }

        .meta-muted {
            color: var(--text-soft);
            font-size: 0.8rem;
        }

        .pagination .page-link {
            border-radius: var(--radius-xl);
            margin: 0 2px;
            color: var(--text-main);
            font-weight: 600;
            border: 1px solid var(--border-soft);
        }

        .pagination .page-item.active .page-link {
            background: var(--dark-1);
            border-color: var(--dark-1);
            color: #fff;
        }

        .action-group .btn {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-xl);
            margin: 2px;
        }

        .modal-header.bg-warning-custom {
            background: var(--dark-1);
            color: #fff;
        }

        .modal-header .btn-close {
            filter: invert(1);
        }

        .select2-container--open {
            z-index: 999999 !important;
        }
    </style>
</head>

<body>

    <div class="container-fluid p-0">
        <div class="d-flex flex-nowrap w-100 overflow-hidden">

            <?php include '../layout/sidebar.php'; ?>

            <div id="mainContent" class="flex-grow-1" style="transition: all 0.28s ease; min-width: 0;">
                <?php include '../layout/notification_bell.php'; ?>

                <div class="page-shell">

                    <!-- Header Hero Modern -->
                    <div class="page-hero">
                        <div class="hero-text">
                            <?php if ($isAdmin): ?>
                                <h1 class="page-title">Peralatan & Asset IT</h1>
                                <p class="page-desc">Kelola inventaris pusat, distribusi barang ke cabang, dan pantau status aset secara real-time.</p>
                            <?php else: ?>
                                <h1 class="page-title">Aset & Inventaris Cabang</h1>
                                <p class="page-desc">Kelola stok barang di unit Anda, laporkan kerusakan, dan pantau pengiriman logistik dari pusat.</p>
                            <?php endif; ?>
                        </div>

                        <div class="hero-actions d-flex gap-3">
                            <?php if ($isAdmin): ?>
                                <!-- Tombol Kelola Master Data -->
                                <a href="master_data.php" class="btn btn-header-dark" title="Kelola Kategori, Merk, dan Tipe">
                                    <i class="bi bi-database-gear me-2"></i>Kelola Master Data
                                </a>

                                <!-- Tombol Khusus Admin HO -->
                                <button class="btn btn-header-light" data-bs-toggle="modal" data-bs-target="#modalCreate">
                                    <i class="bi bi-plus-circle me-2"></i>Tambah Aset Baru
                                </button>
                            <?php else: ?>
                                <!-- Tombol Khusus User Cabang -->
                                <?php if (can('barang.create')): ?>
                                    <button class="btn btn-header-light" data-bs-toggle="modal" data-bs-target="#modalCreateCabang">
                                        <i class="bi bi-plus-circle me-2"></i>Tambah Aset
                                    </button>
                                <?php endif; ?>
                                <?php if (can('barang.pengiriman')): ?>
    <!-- UBAH data-bs-target MENJADI #modalPengirimanUser -->
    <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalPengirimanUser">
        <i class="bi bi-truck me-1"></i> Kirim Barang Rusak
    </button> 
<?php endif; ?>
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
                                        <input type="text" name="cari" class="form-control" placeholder="Kode Asset (HXI-...), nama, no asset, serial number..." value="<?= $searchValue ?>">
                                        <button class="btn search-btn" type="submit"><i class="bi bi-search me-1"></i></button>
                                        <a href="index.php?filter=<?= $filterValue  ?>&limit=<?= $limit ?>" class="btn reset-btn" title="Reset"><i class="bi bi-x-lg"></i></a>
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
                                <div class="fw-bold fs-5"><i class="<?= $tableIcon ?> me-2" style="color: var(--orange-1);"></i><?= h($tableTitle) ?></div>
                                <div class="small text-muted mt-1">Menampilkan <?= $fromRow ?> - <?= $toRow ?> dari <?= $totalRows ?> data</div>
                            </div>
                            <form method="GET" class="mb-0">
                                <input type="hidden" name="cari" value="<?= $searchValue ?>">
                                <input type="hidden" name="filter" value="<?= $filterValue ?>">
                                <div class="limit-box">
                                    <span>Tampilkan:</span>
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
                                            <th>Bukti Kirim</th>
                                        <?php else: ?>
                                            <th>Spesifikasi</th>
                                            <th>Lokasi / User</th>
                                            <th>Garansi</th>
                                            <th>Kondisi</th>
                                            <th>Foto</th>
                                        <?php endif; ?>
<th class="text-center">
    <?php 
        // Cek parameter URL. Jika kosong, berarti sedang di tab default (Asset Tersedia)
        $tabAktif = $_GET['filter'] ?? 'tersedia'; 
        
        if ($tabAktif === 'tersedia') {
            echo "AKSI";
        } else {
            echo "BUKTI TERIMA";
        }
    ?>
</th>                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (mysqli_num_rows($query) > 0): $no = $offset + 1;
                                        while ($data = mysqli_fetch_assoc($query)): ?>
                                            <tr>
                                                <td class="ps-4 text-muted"><?= $no++ ?></td>
                                                <td>
                                                    <div class="fw-bold text-dark mb-1"><?= h($data['nama_barang']) ?></div>
                                                    <?php if (!empty($data['kode_aset'])): ?>
                                                        <div class="kode-aset-badge"><?= h($data['kode_aset']) ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($data['no_asset'])): ?>
                                                        <div class="asset-code">No Asset: <?= h($data['no_asset']) ?></div>
                                                    <?php endif; ?>
                                                    <div class="meta-muted mt-1">SN: <?= h($data['serial_number'] ?? '-') ?></div>
                                                </td>

                                                <?php if ($filter === 'masuk'): ?>
                                                    <td>
                                                        <span class="meta-line"><i class="bi bi-calendar-check me-2 text-muted"></i><?= h($data['tanggal']) ?></span>
                                                        <span class="meta-line"><i class="bi bi-geo-alt me-2 text-danger"></i>Asal: <?= h($data['info_branch']) ?></span>
                                                        <span class="meta-line"><i class="bi bi-person me-2 text-primary"></i><?= h($data['pemilik_barang']) ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="mb-2"><?= shippingBadge($data['status_pengiriman']) ?></div>
                                                        <div class="meta-muted"><i class="bi bi-receipt me-1"></i><?= h($data['nomor_resi_keluar'] ?: 'Belum ada') ?></div>
                                                    </td>
                                                    <td>
                                                        <?php if ($data['foto_resi_keluar']): ?>
                                                            <img src="../assets/images/<?= h($data['foto_resi_keluar']) ?>" class="thumb-img previewFoto" data-foto="../assets/images/<?= h($data['foto_resi_keluar']) ?>">
                                                        <?php else: ?><div class="thumb-placeholder"><i class="bi bi-image text-muted"></i></div><?php endif; ?>
                                                    </td>
                                                    <td class="text-center pe-4">
                                                        <?php
                                                        $sumberTransaksi = $data['sumber_transaksi'] ?? 'ho';
                                                        $canTerima = in_array(strtolower(trim($data['status_pengiriman'] ?? '')), ['sedang perjalanan', 'menunggu persetujuan admin'], true);
                                                        if ($sumberTransaksi === 'antar_cabang') {
                                                            $canTerima = strtolower(trim($data['status_pengiriman'] ?? '')) === 'sedang perjalanan';
                                                        }
                                                        ?>
                                                        <?php if ($canTerima): ?>
                                                            <button class="btn btn-primary btn-sm btnKonfirmasiTerima" data-id="<?= $data['id_transaksi'] ?>" data-sumber="<?= h($sumberTransaksi) ?>" data-role="<?= $isAdmin ? 'admin' : 'user' ?>" title="Konfirmasi Terima"><i class="bi bi-check2-all"></i></button>
                                                        <?php else: ?>
                                                            <!-- MENAMPILKAN THUMBNAIL FOTO BUKTI TERIMA -->
                                                            <?php if (!empty($data['foto_barang_diterima'])): ?>
                                                                <img src="../assets/images/<?= h($data['foto_barang_diterima']) ?>" class="thumb-img previewFoto border-success" style="border-width: 2px;" data-foto="../assets/images/<?= h($data['foto_barang_diterima']) ?>" title="Bukti Terima (Klik untuk perbesar)" alt="Bukti">
                                                            <?php else: ?>
                                                                <button class="btn btn-light btn-sm text-muted" disabled title="Selesai (Tidak ada foto)"><i class="bi bi-lock-fill"></i></button>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </td>

                                                <?php elseif ($filter === 'keluar'): ?>
                                                    <td>
                                                        <span class="meta-line"><i class="bi bi-calendar-plus me-2 text-muted"></i><?= h($data['tanggal']) ?></span>
                                                        <span class="meta-line"><i class="bi bi-geo-alt me-2 text-primary"></i>Tujuan: <?= h($data['info_branch']) ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="mb-2"><?= shippingBadge($data['status_pengiriman']) ?></div>
                                                        <div class="meta-muted"><i class="bi bi-receipt me-1"></i><?= h($data['nomor_resi_keluar'] ?: '-') ?></div>
                                                    </td>
                                                    <td>
                                                        <?php if ($data['foto_resi_keluar']): ?>
                                                            <img src="../assets/images/<?= h($data['foto_resi_keluar']) ?>" class="thumb-img previewFoto" data-foto="../assets/images/<?= h($data['foto_resi_keluar']) ?>">
                                                        <?php else: ?><div class="thumb-placeholder"><i class="bi bi-image text-muted"></i></div><?php endif; ?>
                                                    </td>
                                                    <td class="text-center pe-4">
                                                        <?php if ($isAdmin): ?>
                                                            <?php $logisticStatus = strtolower(trim($data['status_pengiriman'] ?? '')); ?>
                                                            <?php if (in_array($logisticStatus, ['sedang perjalanan', 'menunggu persetujuan admin'], true)): ?>
                                                                <button type="button" class="btn btn-light border btn-sm text-info btnLogistikStatus" data-id="<?= $data['id_transaksi'] ?>" data-status="<?= h($logisticStatus) ?>" title="Informasi status logistik"><i class="bi bi-truck"></i></button>
                                                            <?php else: ?>
                                                                <!-- ADMIN MELIHAT THUMBNAIL CABANG -->
                                                                <?php if (!empty($data['foto_barang_diterima'])): ?>
                                                                    <img src="../assets/images/<?= h($data['foto_barang_diterima']) ?>" class="thumb-img previewFoto border-success" style="border-width: 2px;" data-foto="../assets/images/<?= h($data['foto_barang_diterima']) ?>" title="Bukti Terima Cabang (Klik perbesar)" alt="Bukti">
                                                                <?php else: ?>
                                                                    <button type="button" class="btn btn-light btn-sm text-success" disabled title="Pengiriman selesai (Tidak ada foto)"><i class="bi bi-check-circle-fill"></i></button>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <!-- USER CABANG MELIHAT THUMBNAIL HO -->
                                                            <?php $logisticStatus = strtolower(trim($data['status_pengiriman'] ?? '')); ?>
                                                            <?php if (in_array($logisticStatus, ['sudah diterima ho', 'selesai'], true)): ?>
                                                                <?php if (!empty($data['foto_barang_diterima'])): ?>
                                                                    <img src="../assets/images/<?= h($data['foto_barang_diterima']) ?>" class="thumb-img previewFoto border-success" style="border-width: 2px;" data-foto="../assets/images/<?= h($data['foto_barang_diterima']) ?>" title="Bukti Terima HO (Klik perbesar)" alt="Bukti">
                                                                <?php else: ?>
                                                                    <button class="btn btn-light btn-sm text-muted" disabled title="Selesai (Tidak ada foto)"><i class="bi bi-lock-fill"></i></button>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <button class="btn btn-light btn-sm text-muted" disabled><i class="bi bi-lock-fill"></i></button>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </td>

                                                <?php else: ?>
                                                    <td>
                                                        <span class="badge badge-soft-secondary mb-1"><?= h($data['nama_merk'] ?? '-') ?></span>
                                                        <div class="meta-muted mt-1">Tipe: <?= h($data['nama_tipe'] ?? '-') ?></div>
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
                                                        <div class="meta-line"><i class="bi bi-person me-2 text-muted"></i><?= h($namaTampil) ?></div>
                                                    </td>
                                                    <td>
                                                        <?= warranty_badge_html($data['tanggal_garansi_berakhir'] ?? null) ?>
                                                        <?php if (!empty($data['tanggal_garansi_berakhir'])): ?>
                                                            <div class="meta-muted mt-1"><i class="bi bi-calendar-event me-1"></i><?= date('d M Y', strtotime($data['tanggal_garansi_berakhir'])) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?= barangBadge($data['bermasalah']) ?>
                                                        <?php if ($data['bermasalah'] === 'Iya'): ?>
                                                            <div class="small text-danger mt-2 fw-semibold" style="max-width:180px; font-size: 0.8rem;"><i class="bi bi-info-circle me-1"></i><?= h($data['keterangan_masalah']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($data['foto']): ?>
                                                            <img src="../assets/images/<?= h($data['foto']) ?>" class="thumb-img previewFoto" data-foto="../assets/images/<?= h($data['foto']) ?>">
                                                        <?php else: ?><div class="thumb-placeholder"><i class="bi bi-image text-muted"></i></div><?php endif; ?>
                                                    </td>
                                                    <td class="text-center pe-4">
                                                        <div class="action-group">
                                                            <?php if ($isAdmin): ?>
                                                                <button class="btn btn-light border btn-sm btnEditMaster text-primary" data-id="<?= $data['id'] ?>" title="Edit Master"><i class="bi bi-pencil-fill"></i></button>
                                                                <button class="btn btn-light border btn-sm text-info btnLogistik" data-id="<?= $data['id'] ?>" data-bermasalah="<?= ($data['bermasalah'] === 'Iya' ? '1' : '0') ?>" title="Kirim ke Cabang"><i class="bi bi-truck"></i></button>
                                                                <button class="btn btn-light border btn-sm btnDelete text-danger" data-id="<?= $data['id'] ?>" title="Hapus"><i class="bi bi-trash"></i></button>
                                                                <?php if (can('barang.scrap')): ?>
        <button class="btn btn-light border btn-sm text-secondary btnScrap" data-id="<?= $data['id'] ?>" title="Ajukan Scrap (Pemusnahan)"><i class="bi bi-archive-fill"></i></button>
    <?php endif; ?>
                                                            <?php else: ?>
                                                                <?php if (can('barang.update')): ?>
                <button class="btn btn-light border btn-sm btnEditMaster text-primary" data-id="<?= $data['id'] ?>" title="Edit Aset"><i class="bi bi-pencil-fill"></i></button>
            <?php endif; ?>

            <?php if (can('barang.delete')): ?>
                <button class="btn btn-light border btn-sm btnDelete text-danger" data-id="<?= $data['id'] ?>" title="Hapus"><i class="bi bi-trash"></i></button>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endwhile;
                                    else: ?>
                                        <tr>
                                            <td colspan="<?= $emptyColspan ?>" class="text-center py-5 text-muted">
                                                <div class="d-flex flex-column align-items-center justify-content-center">
                                                    <i class="bi bi-inbox fs-1 mb-3" style="color: var(--border-soft);"></i>
                                                    <span class="fw-semibold">Tidak ada data yang ditemukan.</span>
                                                    <span class="small mt-1">Coba sesuaikan filter atau kata kunci pencarian Anda.</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="p-3 border-top bg-white rounded-bottom-4 d-flex justify-content-end">
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
     MODALS SECTION (TIDAK DIUBAH SAMA SEKALI)
     ========================================================================= -->

    <!-- Modal Create -->
    <div class="modal fade" id="modalCreate" aria-hidden="true">
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
    <div class="modal fade" id="modalUpdate" aria-hidden="true">
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
    <div class="modal fade" id="modalPengirimanUser" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 rounded-4 overflow-hidden">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-truck me-2"></i>Kirim Barang Rusak ke HO</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="contentPengirimanUser">
                    <div class="text-center p-4">
                        <div class="spinner-border text-dark"></div>
                        <p class="mt-2 text-muted">Memuat form pengiriman...</p>
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
                <div class="modal-header text-white" style="background: linear-gradient(135deg, #E64312, #F25C05); border-bottom: none;">
                    <h5 class="modal-title fw-bold"><i class="bi bi-box-seam me-2"></i>Konfirmasi Penerimaan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="contentTerimaCabang"></div>
            </div>
        </div>
    </div>

    <!-- Modal Create Aset (Khusus User Cabang) -->
    <div class="modal fade" id="modalCreateCabang" aria-hidden="true">
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

    <!-- SCRIPTS (TIDAK DIUBAH SAMA SEKALI) -->
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
                    width: '100%',
                    placeholder: 'Pilih...',
                    allowClear: true,
                    dropdownParent: $(this).parent() // <--- UBAH JADI INI
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
                    confirmButtonColor: '#E64312',
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
            const sumber = $(this).data('sumber') || 'ho';
            const sumberParam = sumber === 'antar_cabang' ? '&sumber=antar_cabang' : '';
            $('#contentTerimaCabang').html('<div class="text-center p-4"><div class="spinner-border text-warning"></div></div>');
            $('#contentTerimaCabang').load('terima_barang_form.php?id=' + id + sumberParam);
            bootstrap.Modal.getOrCreateInstance('#modalTerimaCabang').show();
        });

        /**
         * AJAX SUBMIT FORMS (DENGAN ANIMASI DELAY 2 DETIK)
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

            // Tampilkan animasi Loading di tombol
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...');

            $.ajax({
                url: targetUrl,
                type: 'POST',
                data: new FormData(this),
                contentType: false,
                processData: false,
                dataType: 'json',
                success: function(res) {
                    // Jeda animasi 2 detik (2000 ms) sebelum menampilkan hasil
                    setTimeout(function() {
                        if (res.status === 'success' || res.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Berhasil!',
                                text: res.message,
                                showConfirmButton: false,
                                timer: 1500
                            }).then(() => location.reload());
                        } else {
                            Swal.fire('Gagal', res.message || 'Terjadi kesalahan sistem', 'error');
                            $btn.prop('disabled', false).html(originalBtnHtml);
                        }
                    }, 2000); // <-- Delay 2 detik
                },
                error: function() {
                    // Jeda animasi 2 detik jika terjadi error jaringan
                    setTimeout(function() {
                        Swal.fire('Error', 'Gagal menghubungi server', 'error');
                        $btn.prop('disabled', false).html(originalBtnHtml);
                    }, 2000); // <-- Delay 2 detik
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
         * SCRAP ACTION (PENGAJUAN PEMUSNAHAN OLEH ADMIN)
         */
        $(document).on('click', '.btnScrap', function() {
            const idBarang = $(this).data('id');

            Swal.fire({
                title: 'Ajukan Scrap Aset?',
                text: 'Aset ini akan dikunci dan dikirimkan permohonan persetujuan ke User Cabang untuk dimusnahkan. Masukkan alasan Scrap:',
                icon: 'warning',
                input: 'textarea',
                inputPlaceholder: 'Cth: Motherboard terbakar, tidak bisa diperbaiki...',
                inputAttributes: {
                    'aria-label': 'Alasan Scrap'
                },
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-send"></i> Ajukan Scrap',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#E64312',
                preConfirm: (alasan) => {
                    if (!alasan) {
                        Swal.showValidationMessage('Alasan scrap wajib diisi!');
                    }
                    return alasan;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // Tampilkan loading spinner bawaan SweetAlert
                    Swal.fire({
                        title: 'Memproses...',
                        text: 'Mohon tunggu sebentar',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading() }
                    });

                    // Siapkan FormData untuk dikirim
                    const fd = new FormData();
                    fd.append('id_barang', idBarang);
                    fd.append('alasan', result.value);

                    // Gunakan fetch / ajax ke backend
                    fetch('pengajuan_scrap_proses.php', {
                        method: 'POST',
                        body: fd
                    })
                    .then(response => response.json())
                    .then(data => {
                        // Tambahkan Jeda Animasi 2 Detik agar seragam dengan form Anda yang lain
                        setTimeout(function() {
                            if (data.status === 'success') {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Berhasil!',
                                    text: data.message,
                                    showConfirmButton: false,
                                    timer: 1500
                                }).then(() => location.reload());
                            } else {
                                Swal.fire('Gagal!', data.message, 'error');
                            }
                        }, 2000); // <-- Delay 2 detik
                    })
                    .catch(error => {
                        setTimeout(function() {
                            Swal.fire('Error!', 'Terjadi kesalahan sistem (Gagal menghubungi server)', 'error');
                        }, 2000);
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