<?php
include '../config/koneksi.php';
require_once '../config/auth.php';
require_permission($koneksi, 'barang.view');

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function resolveShippingStatus($row)
{
    if (empty($row['id_pengiriman'])) {
        return 'Belum dikirim';
    }

    return !empty($row['status_pengiriman']) ? $row['status_pengiriman'] : 'Sedang perjalanan';
}

function shippingBadge($status)
{
    $class = 'bg-secondary';
    $icon  = 'bi-dash-circle';

    if ($status === 'Sedang dikemas') {
        $class = 'bg-warning text-dark';
        $icon  = 'bi-box-seam';
    } elseif ($status === 'Sedang perjalanan') {
        $class = 'bg-primary';
        $icon  = 'bi-truck';
    } elseif ($status === 'Sudah diterima') {
        $class = 'bg-success';
        $icon  = 'bi-check-circle';
    }

    return '<span class="badge rounded-pill ' . $class . '"><i class="bi ' . $icon . ' me-1"></i>' . h($status) . '</span>';
}

function barangBadge($bermasalah)
{
    if ($bermasalah === 'Iya') {
        return '<span class="badge rounded-pill bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Bermasalah</span>';
    }

    return '<span class="badge rounded-pill bg-success"><i class="bi bi-check-circle me-1"></i>Normal</span>';
}

function isBarangKeluar(array $row): bool
{
    return !empty($row['id_pengiriman']);
}

function isBarangLocked(array $row): bool
{
    return !empty($row['id_pengiriman']) &&
        (
            ($row['status_pengiriman'] ?? '') === 'Sudah diterima' ||
            !empty($row['tanggal_diterima'])
        );
}

$search_input = isset($_GET['cari']) ? trim($_GET['cari']) : "";
$filter       = isset($_GET['filter']) ? trim($_GET['filter']) : "";

if (!in_array($filter, ['', 'masuk', 'keluar'], true)) {
    $filter = '';
}

$allowed_limits = [5, 25, 50, 100];
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
if (!in_array($limit, $allowed_limits, true)) {
    $limit = 25;
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$where = [];

$base_from = "
FROM barang
LEFT JOIN tb_barang ON barang.id_barang = tb_barang.id_barang
LEFT JOIN tb_merk ON barang.id_merk = tb_merk.id_merk
LEFT JOIN tb_tipe ON barang.id_tipe = tb_tipe.id_tipe
LEFT JOIN tb_status ON barang.id_status = tb_status.id_status
LEFT JOIN tb_jenis ON barang.id_jenis = tb_jenis.id_jenis
LEFT JOIN tb_branch AS branch_aktif ON barang.id_branch = branch_aktif.id_branch

LEFT JOIN (
    SELECT bp1.*
    FROM barang_pengiriman bp1
    INNER JOIN (
        SELECT id_barang, MAX(id_pengiriman) AS id_pengiriman_terakhir
        FROM barang_pengiriman
        GROUP BY id_barang
    ) last_bp ON bp1.id_pengiriman = last_bp.id_pengiriman_terakhir
) AS pengiriman ON barang.id = pengiriman.id_barang

LEFT JOIN tb_branch AS branch_tujuan ON pengiriman.branch_tujuan = branch_tujuan.id_branch
LEFT JOIN tb_branch AS branch_asal_pengiriman ON pengiriman.branch_asal = branch_asal_pengiriman.id_branch
";

if ($search_input !== "") {
    $search = mysqli_real_escape_string($koneksi, $search_input);

    $where[] = "(
        tb_barang.nama_barang LIKE '%$search%'
        OR barang.no_asset LIKE '%$search%'
        OR barang.serial_number LIKE '%$search%'
        OR tb_merk.nama_merk LIKE '%$search%'
        OR tb_tipe.nama_tipe LIKE '%$search%'
        OR tb_status.nama_status LIKE '%$search%'
        OR tb_jenis.nama_jenis LIKE '%$search%'
        OR barang.keterangan_masalah LIKE '%$search%'
        OR barang.tanggal_masuk LIKE '%$search%'
        OR barang.bermasalah LIKE '%$search%'
        OR barang.`user` LIKE '%$search%'
        OR branch_aktif.nama_branch LIKE '%$search%'
        OR branch_tujuan.nama_branch LIKE '%$search%'
        OR branch_asal_pengiriman.nama_branch LIKE '%$search%'
        OR pengiriman.tanggal_keluar LIKE '%$search%'
        OR pengiriman.status_pengiriman LIKE '%$search%'
        OR pengiriman.nomor_resi_keluar LIKE '%$search%'
        OR pengiriman.estimasi_pengiriman LIKE '%$search%'
        OR pengiriman.jasa_pengiriman LIKE '%$search%'
        OR pengiriman.nama_penerima LIKE '%$search%'
        OR pengiriman.nomor_resi_masuk LIKE '%$search%'
    )";
}

/*
    LOGIKA FINAL:
    - MASUK  = belum pernah ada record pengiriman
    - KELUAR = sudah pernah ada record pengiriman
*/
if ($filter === "masuk") {
    $where[] = "pengiriman.id_pengiriman IS NULL";
} elseif ($filter === "keluar") {
    $where[] = "pengiriman.id_pengiriman IS NOT NULL";
}

$where_sql = "";
if (count($where) > 0) {
    $where_sql = "WHERE " . implode(" AND ", $where);
}

$count_query = mysqli_query($koneksi, "
    SELECT COUNT(DISTINCT barang.id) AS total
    $base_from
    $where_sql
");

if (!$count_query) {
    die(mysqli_error($koneksi));
}

$total_rows = (int) mysqli_fetch_assoc($count_query)['total'];
$total_pages = max(1, (int) ceil($total_rows / $limit));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $limit;

$total_query = mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM barang");
$total = (int) mysqli_fetch_assoc($total_query)['total'];

$masuk_query = mysqli_query($koneksi, "
    SELECT COUNT(DISTINCT barang.id) AS total
    $base_from
    WHERE pengiriman.id_pengiriman IS NULL
");
$masuk = (int) mysqli_fetch_assoc($masuk_query)['total'];

$keluar_query = mysqli_query($koneksi, "
    SELECT COUNT(DISTINCT barang.id) AS total
    $base_from
    WHERE pengiriman.id_pengiriman IS NOT NULL
");
$keluar = (int) mysqli_fetch_assoc($keluar_query)['total'];

$query = mysqli_query($koneksi, "
    SELECT
        barang.id,
        barang.no_asset,
        barang.serial_number,
        barang.tanggal_masuk,
        barang.bermasalah,
        barang.keterangan_masalah,
        barang.foto,
        barang.`user`,
        barang.id_status,
        barang.id_branch,

        tb_barang.nama_barang,
        tb_merk.nama_merk,
        tb_tipe.nama_tipe,
        tb_status.nama_status,
        tb_jenis.nama_jenis,

        branch_aktif.nama_branch AS nama_branch_aktif,
        branch_tujuan.nama_branch AS nama_branch_tujuan,
        branch_asal_pengiriman.nama_branch AS nama_branch_asal_pengiriman,

        pengiriman.id_pengiriman,
        pengiriman.branch_asal,
        pengiriman.branch_tujuan,
        pengiriman.tanggal_keluar,
        pengiriman.jasa_pengiriman,
        pengiriman.nomor_resi_keluar,
        pengiriman.foto_resi_keluar,
        pengiriman.estimasi_pengiriman,
        pengiriman.status_pengiriman,
        pengiriman.tanggal_diterima,
        pengiriman.nama_penerima,
        pengiriman.nomor_resi_masuk,
        pengiriman.foto_resi_masuk,
        pengiriman.catatan_pengiriman_keluar,
        pengiriman.catatan_penerimaan
    $base_from
    $where_sql
    ORDER BY barang.id DESC
    LIMIT $offset, $limit
");

if (!$query) {
    die(mysqli_error($koneksi));
}

$search_value = h($search_input);
$filter_value = h($filter);

$tableTitle = 'Daftar Semua Barang';
$tableIcon  = 'bi bi-grid-1x2-fill';
$emptyColspan = 9;

if ($filter === 'masuk') {
    $tableTitle = 'Daftar Barang Masuk';
    $tableIcon  = 'bi bi-box-arrow-in-down';
    $emptyColspan = 8;
} elseif ($filter === 'keluar') {
    $tableTitle = 'Daftar Barang Keluar';
    $tableIcon  = 'bi bi-box-arrow-up';
    $emptyColspan = 8;
}

$from_row = $total_rows > 0 ? $offset + 1 : 0;
$to_row   = min($offset + $limit, $total_rows);

$btnSemua  = $filter === '' ? 'btn-mode is-active' : 'btn-mode';
$btnMasuk  = $filter === 'masuk' ? 'btn-mode is-active' : 'btn-mode';
$btnKeluar = $filter === 'keluar' ? 'btn-mode is-active' : 'btn-mode';
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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --orange-1: #ff7a00;
            --orange-2: #ff9800;
            --orange-3: #ffb000;
            --orange-4: #ffd166;
            --orange-5: #fff3e0;

            --dark-1: #111111;
            --dark-2: #1f1f1f;
            --dark-3: #2a2a2a;
            --text-main: #1e1e1e;
            --text-soft: #6b7280;

            --white: #ffffff;
            --surface: #ffffff;
            --surface-soft: #fffaf3;
            --border-soft: rgba(255, 152, 0, 0.14);

            --shadow-soft: 0 12px 36px rgba(17, 17, 17, 0.07);
            --shadow-hover: 0 18px 42px rgba(255, 122, 0, 0.14);

            --radius-xl: 28px;
            --radius-lg: 22px;
            --radius-md: 16px;
            --radius-sm: 12px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background:
                radial-gradient(circle at top left, rgba(255, 176, 0, 0.16), transparent 28%),
                radial-gradient(circle at bottom right, rgba(255, 122, 0, 0.10), transparent 20%),
                linear-gradient(180deg, #fff8f1 0%, #fffaf5 36%, #ffffff 100%);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
        }

        .page-shell {
            padding: 28px;
        }

        .page-hero {
            position: relative;
            overflow: hidden;
            border-radius: var(--radius-xl);
            background:
                linear-gradient(135deg, rgba(17, 17, 17, 0.94) 0%, rgba(42, 42, 42, 0.90) 30%, rgba(255, 122, 0, 0.96) 100%);
            box-shadow: 0 18px 45px rgba(255, 122, 0, 0.20);
            padding: 1.6rem;
            margin-bottom: 1.4rem;
        }

        .page-hero::before {
            content: "";
            position: absolute;
            width: 240px;
            height: 240px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            top: -90px;
            right: -60px;
        }

        .page-hero::after {
            content: "";
            position: absolute;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: rgba(255, 209, 102, 0.18);
            left: -60px;
            bottom: -70px;
        }

        .page-hero .hero-content {
            position: relative;
            z-index: 2;
        }

        .page-title {
            color: #fff;
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: .35rem;
            letter-spacing: -0.02em;
        }

        .page-desc {
            color: rgba(255, 255, 255, 0.84);
            margin-bottom: 0;
            line-height: 1.7;
            max-width: 760px;
            font-size: .94rem;
        }

        .btn-add-item {
            border: none;
            background: #fff;
            color: #111;
            font-weight: 800;
            border-radius: 999px;
            padding: .82rem 1.25rem;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.12);
        }

        .btn-add-item:hover {
            background: #fff7ea;
            color: #111;
        }

        .ui-card {
            background: var(--surface);
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-soft);
        }

        .toolbar-card {
            padding: 1.15rem;
            margin-bottom: 1.25rem;
        }

        .toolbar-label {
            font-size: .84rem;
            font-weight: 700;
            color: var(--dark-2);
            margin-bottom: .55rem;
        }

        .search-wrap .input-group {
            background: #fff;
            border: 1px solid rgba(255, 152, 0, 0.18);
            border-radius: 16px;
            overflow: hidden;
        }

        .search-wrap .form-control {
            border: none;
            box-shadow: none;
            padding: .88rem 1rem;
            font-size: .94rem;
        }

        .search-wrap .form-control:focus {
            box-shadow: none;
        }

        .search-btn {
            border: none;
            background: linear-gradient(135deg, var(--orange-1), var(--orange-3));
            color: #fff;
            font-weight: 700;
            padding: 0 1.1rem;
        }

        .search-btn:hover {
            color: #fff;
            filter: brightness(.98);
        }

        .reset-btn {
            border: none;
            background: #1f1f1f;
            color: #fff;
            font-weight: 700;
            padding: 0 1rem;
            display: flex;
            align-items: center;
        }

        .reset-btn:hover {
            color: #fff;
            background: #111;
        }

        .mode-switch {
            display: flex;
            flex-wrap: wrap;
            gap: .6rem;
        }

        .btn-mode {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .72rem 1rem;
            border-radius: 999px;
            border: 1px solid rgba(255, 152, 0, 0.18);
            background: #fff;
            color: #3d3d3d;
            font-weight: 700;
            text-decoration: none;
            transition: all .2s ease;
            box-shadow: 0 8px 20px rgba(17, 17, 17, 0.04);
        }

        .btn-mode:hover {
            transform: translateY(-1px);
            color: #111;
            background: #fff7ea;
        }

        .btn-mode.is-active {
            background: linear-gradient(135deg, #111111, #ff8f00);
            color: #fff;
            border-color: transparent;
        }

        .summary-card {
            position: relative;
            overflow: hidden;
            border-radius: 22px;
            background: linear-gradient(180deg, #ffffff 0%, #fffaf3 100%);
            border: 1px solid rgba(255, 176, 0, 0.15);
            box-shadow: var(--shadow-soft);
            height: 100%;
            padding: 1.15rem;
            transition: all .25s ease;
        }

        .summary-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 5px;
            background: linear-gradient(90deg, var(--orange-1), var(--orange-3));
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
        }

        .summary-label {
            color: var(--text-soft);
            font-size: .84rem;
            font-weight: 700;
            margin-bottom: .4rem;
        }

        .summary-value {
            font-size: 2rem;
            line-height: 1;
            font-weight: 800;
            margin-bottom: .35rem;
            color: var(--dark-1);
        }

        .summary-note {
            color: var(--text-soft);
            font-size: .82rem;
        }

        .summary-icon {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
            color: #fff;
            background: linear-gradient(135deg, var(--orange-1), var(--orange-3));
            box-shadow: 0 10px 24px rgba(255, 152, 0, 0.2);
        }

        .table-card {
            overflow: hidden;
        }

        .table-card .card-header {
            border: none;
            padding: 1.1rem 1.2rem;
            background: linear-gradient(135deg, #111111 0%, #2c2c2c 40%, #ff8f00 100%);
            color: #fff;
        }

        .table-title {
            font-size: 1rem;
            font-weight: 800;
            margin-bottom: .2rem;
        }

        .table-subinfo {
            font-size: .84rem;
            color: rgba(255, 255, 255, 0.82);
        }

        .limit-box {
            display: flex;
            align-items: center;
            gap: .6rem;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 999px;
            padding: .45rem .55rem .45rem .85rem;
        }

        .limit-box .form-select {
            min-width: 86px;
            border-radius: 999px;
            border: none;
            box-shadow: none;
            font-weight: 700;
        }

        .limit-box .section-label {
            color: rgba(255, 255, 255, 0.88);
            font-size: .82rem;
            font-weight: 700;
            margin: 0;
        }

        .table-responsive {
            background: #fff;
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: #fffaf2;
            color: #2c2c2c;
            font-weight: 800;
            border-bottom: 1px solid #f0dfc3;
            white-space: nowrap;
            padding-top: 1rem;
            padding-bottom: 1rem;
        }

        .table tbody tr {
            transition: all .18s ease;
        }

        .table tbody tr:hover {
            background: #fffaf4;
        }

        .table tbody td {
            vertical-align: top;
            padding-top: 1rem;
            padding-bottom: 1rem;
            border-color: #f2ede5;
        }

        .asset-code {
            font-weight: 800;
            color: var(--dark-1);
        }

        .meta-line {
            display: block;
            font-size: .9rem;
            line-height: 1.5;
            color: #222;
        }

        .meta-muted {
            display: block;
            font-size: .88rem;
            color: var(--text-soft);
            line-height: 1.5;
        }

        .meta-muted i,
        .meta-line i {
            color: #d98710;
        }

        .thumb-img {
            width: 62px;
            height: 62px;
            object-fit: cover;
            border-radius: 14px;
            cursor: pointer;
            border: 1px solid #e8dcc9;
            box-shadow: 0 6px 18px rgba(17, 17, 17, 0.06);
        }

        .thumb-placeholder {
            width: 62px;
            height: 62px;
            border-radius: 14px;
            border: 1px dashed #d8c7aa;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #c6a56e;
            background: #fff9f1;
        }

        .action-group {
            display: flex;
            justify-content: center;
            gap: .45rem;
            flex-wrap: wrap;
        }

        .action-group .btn {
            width: 36px;
            height: 36px;
            padding: 0;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 20px rgba(17, 17, 17, 0.06);
        }

        .mode-badge {
            font-size: .78rem;
            font-weight: 700;
            border-radius: 999px;
            padding: .38rem .65rem;
        }

        .badge.rounded-pill {
            padding: .5rem .78rem;
            font-size: .74rem;
            font-weight: 700;
            letter-spacing: .1px;
        }

        .badge.rounded-pill.bg-success {
            background: linear-gradient(135deg, #111111, #2e2e2e) !important;
            color: #fff !important;
            border: none !important;
        }

        .badge.rounded-pill.bg-danger {
            background: linear-gradient(135deg, #ff7a00, #ff9f1a) !important;
            color: #fff !important;
            border: none !important;
        }

        .badge.rounded-pill.bg-primary {
            background: linear-gradient(135deg, #ff8c00, #ffb000) !important;
            color: #fff !important;
            border: none !important;
        }

        .badge.rounded-pill.bg-secondary {
            background: #fff7ea !important;
            color: #6b4a00 !important;
            border: 1px solid rgba(255, 176, 0, 0.25) !important;
        }

        .badge.rounded-pill.bg-warning.text-dark {
            background: linear-gradient(135deg, #ffd166, #ffbf47) !important;
            color: #5b3a00 !important;
            border: none !important;
        }

        .btn-warning {
            background: linear-gradient(135deg, #ff9f1a, #ffb000);
            border: none;
            color: #fff;
        }

        .btn-warning:hover {
            color: #fff;
            filter: brightness(.98);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ff7a00, #ff9f1a);
            border: none;
        }

        .btn-info {
            background: linear-gradient(135deg, #111111, #3a3a3a);
            border: none;
            color: #fff;
        }

        .btn-dark {
            background: linear-gradient(135deg, #444, #111);
            border: none;
            color: #fff;
        }

        .btn-info:hover,
        .btn-dark:hover,
        .btn-danger:hover {
            filter: brightness(.98);
        }

        .pagination {
            gap: .35rem;
        }

        .pagination .page-link {
            border-radius: 12px;
            color: #2a2a2a;
            border: 1px solid #ead9bf;
            padding: .6rem .85rem;
            font-weight: 700;
            box-shadow: none;
        }

        .pagination .page-link:hover {
            background: #fff4de;
            color: #111;
        }

        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, #111111, #ff8f00);
            border-color: transparent;
            color: #fff;
        }

        .empty-state {
            text-align: center;
            color: var(--text-soft);
            padding: 2.2rem 1rem !important;
        }

        .empty-state i {
            display: block;
            font-size: 1.8rem;
            margin-bottom: .55rem;
            color: var(--orange-2);
        }

        .modal-content {
            border: none;
            border-radius: 22px;
            overflow: hidden;
        }

        .modal-header.bg-warning-custom {
            background: linear-gradient(135deg, #111111 0%, #2c2c2c 40%, #ff8f00 100%) !important;
            color: #fff;
            border: none;
        }

        .modal-header .btn-close {
            filter: invert(1);
        }

        .select2-container--default .select2-selection--single,
        .select2-container--default .select2-selection--multiple {
            border-radius: 12px !important;
            border: 1px solid #ddd !important;
            min-height: 42px;
            padding-top: 5px;
        }

        @media (max-width: 991.98px) {
            .page-shell {
                padding: 18px;
            }

            .page-hero {
                padding: 1.3rem 1.2rem;
            }

            .page-title {
                font-size: 1.4rem;
            }

            .toolbar-card {
                padding: 1rem;
            }
        }

        @media (max-width: 575.98px) {
            .page-title {
                font-size: 1.2rem;
            }

            .page-desc {
                font-size: .9rem;
            }

            .btn-add-item {
                width: 100%;
                justify-content: center;
            }

            .limit-box {
                width: 100%;
                justify-content: space-between;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">

            <?php include '../layout/sidebar.php'; ?>

            <div class="col-md-10">
                <div class="page-shell">

                    <div class="page-hero">
                        <div class="hero-content d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div>
                                <h1 class="page-title">Data Peralatan IT</h1>
                                <p class="page-desc">
                                    Kelola inventaris aset teknologi dengan tampilan yang lebih rapi, konsisten, dan nyaman dipantau untuk aktivitas harian.
                                </p>
                            </div>

                            <?php if (can('barang.create')): ?>
                                <button class="btn btn-add-item" data-bs-toggle="modal" data-bs-target="#modalCreate">
                                    <i class="bi bi-plus-circle me-2"></i>Tambah Barang
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ui-card toolbar-card">
                        <div class="row g-3 align-items-end">
                            <div class="col-lg-7">
                                <form method="GET" class="search-wrap">
                                    <input type="hidden" name="filter" value="<?= $filter_value ?>">
                                    <input type="hidden" name="limit" value="<?= $limit ?>">

                                    <label class="toolbar-label">Pencarian Barang</label>
                                    <div class="input-group">
                                        <input
                                            type="text"
                                            name="cari"
                                            class="form-control"
                                            placeholder="Cari asset, nama barang, serial number, merk, cabang..."
                                            value="<?= $search_value ?>">
                                        <button class="btn search-btn" type="submit">
                                            <i class="bi bi-search me-2"></i>Cari
                                        </button>
                                        <a href="index.php" class="btn reset-btn">
                                            Reset
                                        </a>
                                    </div>
                                </form>
                            </div>

                            <div class="col-lg-5">
                                <label class="toolbar-label">Mode Tampilan</label>
                                <div class="mode-switch">
                                    <a href="index.php?cari=<?= urlencode($search_input) ?>&limit=<?= $limit ?>" class="<?= $btnSemua ?>">
                                        <i class="bi bi-grid-1x2-fill"></i>Semua Barang
                                    </a>
                                    <a href="index.php?filter=masuk&cari=<?= urlencode($search_input) ?>&limit=<?= $limit ?>" class="<?= $btnMasuk ?>">
                                        <i class="bi bi-box-arrow-in-down"></i>Barang Masuk
                                    </a>
                                    <a href="index.php?filter=keluar&cari=<?= urlencode($search_input) ?>&limit=<?= $limit ?>" class="<?= $btnKeluar ?>">
                                        <i class="bi bi-box-arrow-up"></i>Barang Keluar
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Total Inventaris</div>
                                        <div class="summary-value" id="totalInventaris"><?= $total ?></div>
                                        <div class="summary-note">Seluruh aset yang tercatat di sistem</div>
                                    </div>
                                    <div class="summary-icon">
                                        <i class="bi bi-box-seam"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Barang Masuk</div>
                                        <div class="summary-value" id="barangMasuk"><?= $masuk ?></div>
                                        <div class="summary-note">Item yang belum pernah dikirim</div>
                                    </div>
                                    <div class="summary-icon">
                                        <i class="bi bi-box-arrow-in-down"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Barang Keluar</div>
                                        <div class="summary-value" id="barangKeluar"><?= $keluar ?></div>
                                        <div class="summary-note">Item yang sudah pernah dikirim / keluar</div>
                                    </div>
                                    <div class="summary-icon">
                                        <i class="bi bi-box-arrow-up"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card table-card ui-card">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                                <div>
                                    <div class="table-title">
                                        <i class="<?= $tableIcon ?> me-2"></i><?= h($tableTitle) ?>
                                    </div>
                                    <div class="table-subinfo">
                                        Menampilkan <?= $from_row ?> - <?= $to_row ?> dari <?= $total_rows ?> data
                                    </div>
                                </div>

                                <form method="GET" class="mb-0">
                                    <input type="hidden" name="cari" value="<?= $search_value ?>">
                                    <input type="hidden" name="filter" value="<?= $filter_value ?>">

                                    <div class="limit-box">
                                        <span class="section-label">Tampilkan</span>
                                        <select name="limit" onchange="this.form.submit()" class="form-select form-select-sm">
                                            <option value="5" <?= $limit == 5 ? 'selected' : '' ?>>5</option>
                                            <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                                            <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                                            <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                                        </select>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Asset</th>
                                        <th>Nama Barang</th>
                                        <th>Detail</th>

                                        <?php if ($filter === 'masuk'): ?>
                                            <th>Logistik Masuk</th>
                                            <th>Masalah</th>
                                            <th>Foto</th>
                                            <th class="text-center">Aksi</th>
                                        <?php elseif ($filter === 'keluar'): ?>
                                            <th>Logistik Keluar</th>
                                            <th>Status Pengiriman</th>
                                            <th>Foto Resi</th>
                                            <th class="text-center">Aksi</th>
                                        <?php else: ?>
                                            <th>Lokasi / User</th>
                                            <th>Status Barang</th>
                                            <th>Status Pengiriman</th>
                                            <th>Foto</th>
                                            <th class="text-center">Aksi</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (mysqli_num_rows($query) > 0): ?>
                                        <?php $no = $offset + 1; ?>

                                        <?php while ($data = mysqli_fetch_assoc($query)): ?>
                                            <?php
                                            $shippingStatus = resolveShippingStatus($data);
                                            $isKeluar = isBarangKeluar($data);
                                            $isLocked = isBarangLocked($data);
                                            ?>
                                            <tr data-keluar="<?= $isKeluar ? '1' : '0' ?>" data-locked="<?= $isLocked ? '1' : '0' ?>">
                                                <td><?= $no++ ?></td>

                                                <td>
                                                    <div class="asset-code"><?= h($data['no_asset'] ?? '-') ?></div>
                                                    <small class="meta-muted"><?= h($data['serial_number'] ?? '-') ?></small>
                                                </td>

                                                <td>
                                                    <div class="fw-bold mb-1"><?= h($data['nama_barang'] ?? '-') ?></div>
                                                    <span class="badge bg-light text-dark border mode-badge"><?= h($data['nama_merk'] ?? '-') ?></span>
                                                </td>

                                                <td>
                                                    <span class="meta-line"><b>Tipe:</b> <?= h($data['nama_tipe'] ?? '-') ?></span>
                                                    <span class="meta-line"><b>Jenis:</b> <?= h($data['nama_jenis'] ?? '-') ?></span>
                                                </td>

                                                <?php if ($filter === 'masuk'): ?>
                                                    <td>
                                                        <span class="badge bg-success mb-2">
                                                            <i class="bi bi-box-arrow-in-down me-1"></i>Masuk
                                                        </span>
                                                        <span class="meta-line"><i class="bi bi-calendar"></i> <?= !empty($data['tanggal_masuk']) ? h($data['tanggal_masuk']) : '-' ?></span>
                                                        <span class="meta-line"><i class="bi bi-geo-alt"></i> <?= !empty($data['nama_branch_aktif']) ? h($data['nama_branch_aktif']) : '-' ?></span>
                                                        <span class="meta-muted"><i class="bi bi-person"></i> <?= !empty($data['user']) ? h($data['user']) : '-' ?></span>
                                                    </td>

                                                    <td>
                                                        <?php if (($data['bermasalah'] ?? '') === 'Iya'): ?>
                                                            <span class="badge bg-danger rounded-pill mb-2">
                                                                <i class="bi bi-exclamation-triangle me-1"></i>Bermasalah
                                                            </span>
                                                            <span class="meta-line text-danger"><?= h($data['keterangan_masalah'] ?? '-') ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>

                                                    <td>
                                                        <?php if (!empty($data['foto'])): ?>
                                                            <img src="../assets/images/<?= h($data['foto']) ?>"
                                                                class="thumb-img previewFoto"
                                                                data-foto="../assets/images/<?= h($data['foto']) ?>">
                                                        <?php else: ?>
                                                            <span class="thumb-placeholder"><i class="bi bi-image"></i></span>
                                                        <?php endif; ?>
                                                    </td>

                                                    <td class="text-center">
                                                        <div class="action-group">
                                                            <?php if (can('barang.update')): ?>
                                                                <button class="btn btn-sm btn-warning btnEdit"
                                                                    data-id="<?= $data['id'] ?>"
                                                                    title="Edit data barang">
                                                                    <i class="bi bi-pencil"></i>
                                                                </button>
                                                            <?php endif; ?>

                                                            <?php if (can('barang.delete')): ?>
                                                                <button class="btn btn-sm btn-danger btnDelete"
                                                                    data-id="<?= $data['id'] ?>"
                                                                    title="Hapus data">
                                                                    <i class="bi bi-trash"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>

                                                <?php elseif ($filter === 'keluar'): ?>
                                                    <td>
                                                        <span class="badge bg-danger mb-2">
                                                            <i class="bi bi-box-arrow-up me-1"></i>Keluar
                                                        </span>
                                                        <span class="meta-line"><i class="bi bi-calendar"></i> <?= !empty($data['tanggal_keluar']) ? h($data['tanggal_keluar']) : '-' ?></span>
                                                        <span class="meta-line"><i class="bi bi-geo-alt"></i> <?= !empty($data['nama_branch_tujuan']) ? h($data['nama_branch_tujuan']) : '-' ?></span>
                                                        <span class="meta-line"><i class="bi bi-truck"></i> <?= !empty($data['jasa_pengiriman']) ? h($data['jasa_pengiriman']) : '-' ?></span>
                                                        <span class="meta-line"><i class="bi bi-receipt"></i> Resi: <?= !empty($data['nomor_resi_keluar']) ? h($data['nomor_resi_keluar']) : '-' ?></span>
                                                        <span class="meta-muted"><i class="bi bi-hourglass-split"></i> Estimasi: <?= !empty($data['estimasi_pengiriman']) ? h($data['estimasi_pengiriman']) : '-' ?></span>
                                                    </td>

                                                    <td>
                                                        <div class="mb-2"><?= shippingBadge($shippingStatus) ?></div>

                                                        <?php if (!empty($data['tanggal_diterima'])): ?>
                                                            <span class="meta-line"><i class="bi bi-calendar-check"></i> Diterima: <?= h($data['tanggal_diterima']) ?></span>
                                                        <?php endif; ?>

                                                        <?php if (!empty($data['nama_penerima'])): ?>
                                                            <span class="meta-line"><i class="bi bi-person-check"></i> <?= h($data['nama_penerima']) ?></span>
                                                        <?php endif; ?>

                                                        <?php if ($isLocked): ?>
                                                            <span class="meta-muted text-danger">Barang sudah diterima branch tujuan dan dikunci.</span>
                                                        <?php endif; ?>
                                                    </td>

                                                    <td>
                                                        <?php if (!empty($data['foto_resi_keluar'])): ?>
                                                            <img src="../assets/images/<?= h($data['foto_resi_keluar']) ?>"
                                                                class="thumb-img previewFoto"
                                                                data-foto="../assets/images/<?= h($data['foto_resi_keluar']) ?>">
                                                        <?php else: ?>
                                                            <span class="thumb-placeholder"><i class="bi bi-receipt"></i></span>
                                                        <?php endif; ?>
                                                    </td>

                                                    <td class="text-center">
                                                        <div class="action-group">
                                                            <?php if (!$isLocked && can('barang.kirim')): ?>
                                                                <button class="btn btn-sm btn-info btnEdit"
                                                                    data-id="<?= $data['id'] ?>"
                                                                    title="Update logistik / pengiriman">
                                                                    <i class="bi bi-truck"></i>
                                                                </button>
                                                            <?php endif; ?>

                                                            <button class="btn btn-sm btn-dark" disabled title="<?= $isLocked ? 'Barang sudah dikunci' : 'Barang keluar' ?>">
                                                                <i class="bi bi-lock-fill"></i>
                                                            </button>
                                                        </div>
                                                    </td>

                                                <?php else: ?>
                                                    <td>
                                                        <span class="meta-line"><i class="bi bi-geo-alt"></i> <?= !empty($data['nama_branch_aktif']) ? h($data['nama_branch_aktif']) : '-' ?></span>
                                                        <span class="meta-line"><i class="bi bi-person"></i> <?= !empty($data['user']) ? h($data['user']) : '-' ?></span>
                                                        <span class="meta-muted"><i class="bi bi-calendar"></i> Masuk: <?= !empty($data['tanggal_masuk']) ? h($data['tanggal_masuk']) : '-' ?></span>
                                                    </td>

                                                    <td>
                                                        <div class="mb-2"><?= barangBadge($data['bermasalah'] ?? 'Tidak') ?></div>
                                                        <span class="meta-muted"><?= h($data['nama_status'] ?? '-') ?></span>
                                                    </td>

                                                    <td>
                                                        <div class="mb-2"><?= shippingBadge($shippingStatus) ?></div>

                                                        <?php if ($isKeluar): ?>
                                                            <span class="meta-line"><i class="bi bi-calendar"></i> <?= !empty($data['tanggal_keluar']) ? h($data['tanggal_keluar']) : '-' ?></span>
                                                            <span class="meta-line"><i class="bi bi-geo-alt"></i> <?= !empty($data['nama_branch_tujuan']) ? h($data['nama_branch_tujuan']) : '-' ?></span>

                                                            <?php if ($isLocked): ?>
                                                                <span class="meta-muted text-danger">Sudah diterima branch lain dan dikunci</span>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="meta-muted">Belum ada proses pengiriman</span>
                                                        <?php endif; ?>
                                                    </td>

                                                    <td>
                                                        <?php if (!empty($data['foto'])): ?>
                                                            <img src="../assets/images/<?= h($data['foto']) ?>"
                                                                class="thumb-img previewFoto"
                                                                data-foto="../assets/images/<?= h($data['foto']) ?>">
                                                        <?php else: ?>
                                                            <span class="thumb-placeholder"><i class="bi bi-image"></i></span>
                                                        <?php endif; ?>
                                                    </td>

                                                    <td class="text-center">
                                                        <div class="action-group">
                                                            <?php if ($isKeluar): ?>
                                                                <?php if (!$isLocked && can('barang.kirim')): ?>
                                                                    <button class="btn btn-sm btn-info btnEdit"
                                                                        data-id="<?= $data['id'] ?>"
                                                                        title="Update logistik / pengiriman">
                                                                        <i class="bi bi-truck"></i>
                                                                    </button>
                                                                <?php endif; ?>

                                                                <button class="btn btn-sm btn-dark" disabled title="<?= $isLocked ? 'Barang sudah dikunci' : 'Barang keluar' ?>">
                                                                    <i class="bi bi-lock-fill"></i>
                                                                </button>
                                                            <?php else: ?>
                                                                <?php if (can('barang.update')): ?>
                                                                    <button class="btn btn-sm btn-warning btnEdit"
                                                                        data-id="<?= $data['id'] ?>"
                                                                        title="Edit data barang">
                                                                        <i class="bi bi-pencil"></i>
                                                                    </button>
                                                                <?php endif; ?>

                                                                <?php if (can('barang.delete')): ?>
                                                                    <button class="btn btn-sm btn-danger btnDelete"
                                                                        data-id="<?= $data['id'] ?>"
                                                                        title="Hapus data">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endwhile; ?>

                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?= $emptyColspan ?>" class="empty-state">
                                                <i class="bi bi-inbox"></i>
                                                Data tidak ditemukan.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="p-3 p-md-4">
                            <nav>
                                <ul class="pagination mb-0 flex-wrap">
                                    <?php for ($i = 1; $i <= $total_pages; $i++) : ?>
                                        <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                            <a class="page-link" href="?page=<?= $i ?>&limit=<?= $limit ?>&cari=<?= urlencode($search_input) ?>&filter=<?= urlencode($filter) ?>">
                                                <?= $i ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalCreate" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning-custom">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Tambah Barang</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="contentCreate">
                    <p class="text-center text-muted">Loading form...</p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalUpdate" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning-custom">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Update Data Barang</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="contentUpdate">Loading...</div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalFoto" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-dark">
                <div class="modal-body text-center">
                    <img id="fotoPreview" src="" class="img-fluid rounded">
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        function destroySelect2InContainer(containerSelector) {
            const $container = $(containerSelector);
            $container.find('select').each(function() {
                if ($(this).hasClass('select2-hidden-accessible')) {
                    $(this).select2('destroy');
                }
            });
        }

        function initSelect2InContainer(containerSelector, modalSelector, placeholderText = 'Pilih...') {
            const $container = $(containerSelector);
            const $modal = $(modalSelector);

            $container.find('select').each(function() {
                if ($(this).hasClass('select2-hidden-accessible')) {
                    $(this).select2('destroy');
                }

                $(this).select2({
                    dropdownParent: $modal,
                    width: '100%',
                    placeholder: placeholderText,
                    allowClear: true
                });
            });
        }

        function initSelect2WhenModalReady(containerSelector, modalSelector, placeholderText = 'Pilih...') {
            const $modal = $(modalSelector);

            const runInit = function() {
                requestAnimationFrame(function() {
                    initSelect2InContainer(containerSelector, modalSelector, placeholderText);
                });
            };

            if ($modal.hasClass('show')) {
                runInit();
            } else {
                $modal.one('shown.bs.modal', runInit);
            }
        }

        $('#modalCreate').on('show.bs.modal', function() {
            $('#contentCreate').html('<p class="text-center text-muted">Loading form...</p>');

            $.get('create.php', function(html) {
                $('#contentCreate').html(html);
                initSelect2WhenModalReady('#contentCreate', '#modalCreate', 'Pilih...');
            }).fail(function() {
                $('#contentCreate').html('<p class="text-danger">Gagal memuat form.</p>');
            });
        });

        $('#modalCreate').on('hidden.bs.modal', function() {
            destroySelect2InContainer('#contentCreate');
            $('#contentCreate').html('<p class="text-center text-muted">Loading form...</p>');
        });

        $(document).on('submit', '#formCreate', function(e) {
            e.preventDefault();

            let formData = new FormData(this);

            $.ajax({
                url: 'create.php',
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success || response.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Berhasil',
                            text: response.message || 'Data berhasil ditambahkan'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: response.error || response.message || 'Terjadi kesalahan'
                        });
                    }
                },
                error: function(xhr) {
                    console.log(xhr.responseText);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Terjadi kesalahan server'
                    });
                }
            });
        });

        $(document).on('click', '.btnEdit', function() {
            let id = $(this).data('id');
            const modalEl = document.getElementById('modalUpdate');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

            destroySelect2InContainer('#contentUpdate');
            $('#contentUpdate').html('<p class="text-center text-muted">Loading form...</p>');
            modal.show();

            fetch('update.php?id=' + id)
                .then(res => res.text())
                .then(html => {
                    $('#contentUpdate').html(html);
                    initSelect2WhenModalReady('#contentUpdate', '#modalUpdate', 'Pilih...');
                })
                .catch(() => {
                    $('#contentUpdate').html('<p class="text-danger">Gagal memuat data update.</p>');
                });
        });

        $('#modalUpdate').on('hidden.bs.modal', function() {
            destroySelect2InContainer('#contentUpdate');
            $('#contentUpdate').html('Loading...');
        });

        $(document).on('click', '.btnDelete', function() {
            let id = this.dataset.id;
            let row = this.closest('tr');

            Swal.fire({
                title: 'Yakin hapus data?',
                text: 'Data tidak bisa dikembalikan',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Ya hapus'
            }).then(result => {
                if (result.isConfirmed) {
                    $.getJSON('delete.php', {
                        id: id
                    }, function(response) {
                        if (response.status === 'success') {
                            row.remove();

                            let total = document.getElementById('totalInventaris');
                            let masuk = document.getElementById('barangMasuk');
                            let keluar = document.getElementById('barangKeluar');

                            total.innerText = parseInt(total.innerText) - 1;

                            let isKeluar = row.dataset.keluar === '1';

                            if (isKeluar) {
                                keluar.innerText = parseInt(keluar.innerText) - 1;
                            } else {
                                masuk.innerText = parseInt(masuk.innerText) - 1;
                            }

                            Swal.fire({
                                icon: 'success',
                                title: 'Terhapus!',
                                text: response.message
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Gagal!',
                                text: response.message
                            });
                        }
                    });
                }
            });
        });

        $(document).on('submit', '#formUpdate', function(e) {
            e.preventDefault();

            let formData = new FormData(this);

            $.ajax({
                url: 'update.php',
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Berhasil',
                            text: response.message
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: response.message || 'Update gagal'
                        });
                    }
                },
                error: function(xhr) {
                    console.log(xhr.responseText);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Terjadi error di update.php. Cek console browser.'
                    });
                }
            });
        });

        $(document).on('click', '.previewFoto', function() {
            let foto = $(this).data('foto');
            $('#fotoPreview').attr('src', foto);

            let modal = new bootstrap.Modal(document.getElementById('modalFoto'));
            modal.show();
        });
    </script>
</body>

</html>