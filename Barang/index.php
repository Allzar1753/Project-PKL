<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'barang.view');

$isAdmin = is_admin();
$myBranchId = current_user_branch_id();

if (!$isAdmin && (!$myBranchId || $myBranchId <= 0)) {
    http_response_code(403);
    exit('Branch user belum ditentukan.');
}

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
    } elseif ($s === 'sudah diterima' || $s === 'sudah diterima ho') {
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
    $row = mysqli_fetch_assoc($query) ?:[];
    return (int) ($row[$field] ?? 0);
}

function canEditMasterBarang(): bool { return is_admin() && can('barang.update'); }
function canDeleteMasterBarang(): bool { return is_admin() && can('barang.delete'); }
function canCreateBarang(): bool { return is_admin() && can('barang.create'); }
function canOpenPengirimanUser(): bool { return is_user_role() && can('barang.kirim'); }

// =========================================================================
// PENGATURAN FILTER, PENCARIAN, DAN PAGINATION
// =========================================================================
$searchInput = isset($_GET['cari']) ? trim((string) $_GET['cari']) : '';
$filter = isset($_GET['filter']) ? trim((string) $_GET['filter']) : '';

if (!in_array($filter, ['', 'masuk', 'keluar'], true)) {
    $filter = '';
}

$allowedLimits =[5, 25, 50, 100];
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
if (!in_array($limit, $allowedLimits, true)) {
    $limit = 25;
}

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$searchSql_barang = "";
$searchSql_pengiriman_ho = "";
$searchSql_barang_pengiriman = "";

if ($searchInput !== '') {
    $s = mysqli_real_escape_string($koneksi, $searchInput);
    $searchSql_barang = " AND (tb_barang.nama_barang LIKE '%$s%' OR barang.no_asset LIKE '%$s%' OR barang.serial_number LIKE '%$s%') ";
    $searchSql_pengiriman_ho = " AND (tb_barang.nama_barang LIKE '%$s%' OR p.serial_number LIKE '%$s%' OR p.nomor_resi_keluar LIKE '%$s%') ";
    $searchSql_barang_pengiriman = " AND (tb_barang.nama_barang LIKE '%$s%' OR b.no_asset LIKE '%$s%' OR b.serial_number LIKE '%$s%' OR p.nomor_resi_keluar LIKE '%$s%') ";
}

// =========================================================================
// LOGIKA SUMMARY CARD
// =========================================================================
$excludeTransitSql = " AND barang.id NOT IN (SELECT id_barang FROM barang_pengiriman WHERE status_pengiriman = 'Sedang perjalanan') ";

if ($isAdmin) {
    // ADMIN: Menghitung SEMUA barang di seluruh cabang (WHERE 1=1)
    $totalInventaris = fetchSingleValue($koneksi, "SELECT COUNT(id) AS total FROM barang WHERE 1=1 $excludeTransitSql");
    $totalMasuk      = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE status_pengiriman = 'Sudah diterima HO'");
    $totalKeluar     = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman");
} else {
    // CABANG (USER): Hanya menghitung barang yang fisiknya ada di Cabang mereka sendiri
    $totalInventaris = fetchSingleValue($koneksi, "SELECT COUNT(id) AS total FROM barang WHERE id_branch = $myBranchId $excludeTransitSql");
    $totalMasuk      = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman WHERE branch_tujuan = $myBranchId AND status_pengiriman = 'Sudah diterima'");
    $totalKeluar     = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE branch_asal = $myBranchId");
}

// =========================================================================
// LOGIKA QUERY TABEL
// =========================================================================
$querySql = "";

if ($filter === 'keluar') {
    if ($isAdmin) {
        $querySql = "SELECT p.id_pengiriman AS id_transaksi, p.tanggal_keluar AS tanggal, p.status_pengiriman, p.jasa_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar,
                            b.no_asset, b.serial_number, tb_barang.nama_barang, m.nama_merk, t.nama_tipe, j.nama_jenis, br.nama_branch AS info_branch
                     FROM barang_pengiriman p
                     JOIN barang b ON p.id_barang = b.id
                     JOIN tb_barang ON b.id_barang = tb_barang.id_barang
                     LEFT JOIN tb_merk m ON b.id_merk = m.id_merk
                     LEFT JOIN tb_tipe t ON b.id_tipe = t.id_tipe
                     LEFT JOIN tb_jenis j ON b.id_jenis = j.id_jenis
                     LEFT JOIN tb_branch br ON p.branch_tujuan = br.id_branch
                     WHERE 1=1 $searchSql_barang_pengiriman ORDER BY p.id_pengiriman DESC";
    } else {
        $querySql = "SELECT p.id_pengiriman_ho AS id_transaksi, p.tanggal_pengajuan AS tanggal, p.status_pengiriman, p.jasa_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar,
                            p.serial_number, p.pemilik_barang, tb_barang.nama_barang, NULL AS no_asset, NULL AS nama_merk, NULL AS nama_tipe, NULL AS nama_jenis, 'Pusat HO' AS info_branch
                     FROM pengiriman_cabang_ho p
                     JOIN tb_barang ON p.id_barang = tb_barang.id_barang
                     WHERE p.branch_asal = $myBranchId $searchSql_pengiriman_ho ORDER BY p.id_pengiriman_ho DESC";
    }
} elseif ($filter === 'masuk') {
    if ($isAdmin) {
        $querySql = "SELECT p.id_pengiriman_ho AS id_transaksi, p.tanggal_pengajuan AS tanggal, p.status_pengiriman, p.jasa_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar,
                            p.serial_number, p.pemilik_barang, tb_barang.nama_barang, NULL AS no_asset, NULL AS nama_merk, NULL AS nama_tipe, NULL AS nama_jenis, br.nama_branch AS info_branch
                     FROM pengiriman_cabang_ho p
                     JOIN tb_barang ON p.id_barang = tb_barang.id_barang
                     LEFT JOIN tb_branch br ON p.branch_asal = br.id_branch
                     WHERE 1=1 $searchSql_pengiriman_ho ORDER BY p.id_pengiriman_ho DESC";
    } else {
        $querySql = "SELECT p.id_pengiriman AS id_transaksi, p.tanggal_keluar AS tanggal, p.status_pengiriman, p.jasa_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar,
                            b.no_asset, b.serial_number, tb_barang.nama_barang, m.nama_merk, t.nama_tipe, j.nama_jenis, 'Pusat HO' AS info_branch
                     FROM barang_pengiriman p
                     JOIN barang b ON p.id_barang = b.id
                     JOIN tb_barang ON b.id_barang = tb_barang.id_barang
                     LEFT JOIN tb_merk m ON b.id_merk = m.id_merk
                     LEFT JOIN tb_tipe t ON b.id_tipe = t.id_tipe
                     LEFT JOIN tb_jenis j ON b.id_jenis = j.id_jenis
                     WHERE p.branch_tujuan = $myBranchId $searchSql_barang_pengiriman ORDER BY p.id_pengiriman DESC";
    }
} else {
    // Mode Tampilan Default (Inventaris)
    // Admin melihat SEMUA data (1=1), User Cabang HANYA melihat lokasinya sendiri
    $whereLokasi = $isAdmin ? "1=1" : "barang.id_branch = $myBranchId";

    $querySql = "SELECT barang.id, barang.no_asset, barang.serial_number, barang.bermasalah, barang.foto, barang.keterangan_masalah, barang.user,
                        tb_barang.nama_barang, m.nama_merk, t.nama_tipe, j.nama_jenis, br.nama_branch AS info_branch
                 FROM barang
                 JOIN tb_barang ON barang.id_barang = tb_barang.id_barang
                 LEFT JOIN tb_merk m ON barang.id_merk = m.id_merk
                 LEFT JOIN tb_tipe t ON barang.id_tipe = t.id_tipe
                 LEFT JOIN tb_jenis j ON barang.id_jenis = j.id_jenis
                 LEFT JOIN tb_branch br ON barang.id_branch = br.id_branch
                 WHERE $whereLokasi $excludeTransitSql $searchSql_barang 
                 ORDER BY barang.id DESC";
}

// -------------------------------------------------------------------------
// EKSEKUSI DATA & PAGINATION
// -------------------------------------------------------------------------
$countQuery = "SELECT COUNT(*) AS total FROM ($querySql) AS sub";
$totalRows = fetchSingleValue($koneksi, $countQuery);
$totalPages = max(1, (int) ceil($totalRows / $limit));

if ($page > $totalPages) {
    $page = $totalPages;
}

$querySql .= " LIMIT $offset, $limit";
$query = mysqli_query($koneksi, $querySql);
if (!$query) die(mysqli_error($koneksi));

// UI Data Tampilan
$searchValue = h($searchInput);
$filterValue = h($filter);

$tableTitle = 'Total Inventaris';
$tableIcon  = 'bi bi-box-seam';
$emptyColspan = 7;

if ($filter === 'masuk') {
    $tableTitle = 'Daftar Barang Masuk';
    $tableIcon  = 'bi bi-box-arrow-in-down';
    $emptyColspan = 6;
} elseif ($filter === 'keluar') {
    $tableTitle = 'Daftar Barang Keluar';
    $tableIcon  = 'bi bi-box-arrow-up';
    $emptyColspan = 6;
}

$fromRow = $totalRows > 0 ? $offset + 1 : 0;
$toRow   = min($offset + $limit, $totalRows);

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
            --dark-1: #111111;
            --text-main: #1e1e1e;
            --text-soft: #6b7280;
            --surface: #ffffff;
            --border-soft: rgba(255, 152, 0, 0.14);
            --shadow-soft: 0 12px 36px rgba(17, 17, 17, 0.07);
            --radius-xl: 28px;
        }

        * { box-sizing: border-box; }

        body {
            background:
                radial-gradient(circle at top left, rgba(255, 176, 0, 0.16), transparent 28%),
                radial-gradient(circle at bottom right, rgba(255, 122, 0, 0.10), transparent 20%),
                linear-gradient(180deg, #fff8f1 0%, #fffaf5 36%, #ffffff 100%);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
        }

        .page-shell { padding: 28px; }

        .page-hero {
            position: relative; overflow: hidden; border-radius: var(--radius-xl);
            background: linear-gradient(135deg, rgba(17, 17, 17, 0.94) 0%, rgba(42, 42, 42, 0.90) 30%, rgba(255, 122, 0, 0.96) 100%);
            box-shadow: 0 18px 45px rgba(255, 122, 0, 0.20);
            padding: 1.6rem; margin-bottom: 1.4rem;
        }
        .page-hero::before { content: ""; position: absolute; width: 240px; height: 240px; border-radius: 50%; background: rgba(255, 255, 255, 0.08); top: -90px; right: -60px; }
        .page-hero::after { content: ""; position: absolute; width: 180px; height: 180px; border-radius: 50%; background: rgba(255, 209, 102, 0.18); left: -60px; bottom: -70px; }
        .page-hero .hero-content { position: relative; z-index: 2; }
        .page-title { color: #fff; font-size: 1.8rem; font-weight: 800; margin-bottom: .35rem; letter-spacing: -0.02em; }
        .page-desc { color: rgba(255, 255, 255, 0.84); margin-bottom: 0; line-height: 1.7; max-width: 760px; font-size: .94rem; }

        .btn-add-item { border: none; background: #fff; color: #111; font-weight: 800; border-radius: 999px; padding: .82rem 1.25rem; box-shadow: 0 10px 24px rgba(0, 0, 0, 0.12); }
        .btn-add-item:hover { background: #fff7ea; color: #111; }

        .ui-card { background: var(--surface); border: 1px solid var(--border-soft); border-radius: 22px; box-shadow: var(--shadow-soft); }
        .toolbar-card { padding: 1.15rem; margin-bottom: 1.25rem; }
        .toolbar-label { font-size: .84rem; font-weight: 700; color: var(--dark-2); margin-bottom: .55rem; }

        .search-wrap .input-group { background: #fff; border: 1px solid rgba(255, 152, 0, 0.18); border-radius: 16px; overflow: hidden; }
        .search-wrap .form-control { border: none; box-shadow: none; padding: .88rem 1rem; font-size: .94rem; }
        .search-btn { border: none; background: linear-gradient(135deg, var(--orange-1), var(--orange-3)); color: #fff; font-weight: 700; padding: 0 1.1rem; }
        .reset-btn { border: none; background: #1f1f1f; color: #fff; font-weight: 700; padding: 0 1rem; display: flex; align-items: center; }

        .mode-switch { display: flex; flex-wrap: wrap; gap: .6rem; }
        .btn-mode { display: inline-flex; align-items: center; gap: .45rem; padding: .72rem 1rem; border-radius: 999px; border: 1px solid rgba(255, 152, 0, 0.18); background: #fff; color: #3d3d3d; font-weight: 700; text-decoration: none; transition: all .2s ease; box-shadow: 0 8px 20px rgba(17, 17, 17, 0.04); }
        .btn-mode.is-active { background: linear-gradient(135deg, #111111, #ff8f00); color: #fff; border-color: transparent; }

        .summary-card { position: relative; overflow: hidden; border-radius: 22px; background: linear-gradient(180deg, #ffffff 0%, #fffaf3 100%); border: 1px solid rgba(255, 176, 0, 0.15); box-shadow: var(--shadow-soft); height: 100%; padding: 1.15rem; transition: all .25s ease; }
        .summary-card::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 5px; background: linear-gradient(90deg, var(--orange-1), var(--orange-3)); }
        .summary-label { color: var(--text-soft); font-size: .84rem; font-weight: 700; margin-bottom: .4rem; }
        .summary-value { font-size: 2rem; line-height: 1; font-weight: 800; margin-bottom: .35rem; color: var(--dark-1); }
        .summary-note { color: var(--text-soft); font-size: .82rem; }
        .summary-icon { width: 52px; height: 52px; border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; color: #fff; background: linear-gradient(135deg, var(--orange-1), var(--orange-3)); box-shadow: 0 10px 24px rgba(255, 152, 0, 0.2); }

        .table-card .card-header { border: none; padding: 1.1rem 1.2rem; background: linear-gradient(135deg, #111111 0%, #2c2c2c 40%, #ff8f00 100%); color: #fff; }
        .table-title { font-size: 1rem; font-weight: 800; margin-bottom: .2rem; }
        .table-subinfo { font-size: .84rem; color: rgba(255, 255, 255, 0.82); }

        .limit-box { display: flex; align-items: center; gap: .6rem; background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.16); border-radius: 999px; padding: .45rem .55rem .45rem .85rem; }
        .limit-box .form-select { min-width: 86px; border-radius: 999px; border: none; box-shadow: none; font-weight: 700; }
        .limit-box .section-label { color: rgba(255, 255, 255, 0.88); font-size: .82rem; font-weight: 700; margin: 0; }

        .table thead th { background: #fffaf2; color: #2c2c2c; font-weight: 800; border-bottom: 1px solid #f0dfc3; white-space: nowrap; padding-top: 1rem; padding-bottom: 1rem; }
        .table tbody td { vertical-align: top; padding-top: 1rem; padding-bottom: 1rem; border-color: #f2ede5; }
        .asset-code { font-weight: 800; color: var(--dark-1); }
        .meta-line { display: block; font-size: .9rem; line-height: 1.5; color: #222; }
        .meta-muted { display: block; font-size: .88rem; color: var(--text-soft); line-height: 1.5; }
        .meta-muted i, .meta-line i { color: #d98710; }

        .thumb-img { width: 62px; height: 62px; object-fit: cover; border-radius: 14px; cursor: pointer; border: 1px solid #e8dcc9; box-shadow: 0 6px 18px rgba(17, 17, 17, 0.06); }
        .thumb-placeholder { width: 62px; height: 62px; border-radius: 14px; border: 1px dashed #d8c7aa; display: inline-flex; align-items: center; justify-content: center; color: #c6a56e; background: #fff9f1; }

        .action-group { display: flex; justify-content: center; gap: .45rem; flex-wrap: wrap; }
        .action-group .btn { width: 36px; height: 36px; padding: 0; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 8px 20px rgba(17, 17, 17, 0.06); }
        
        .mode-badge { font-size: .78rem; font-weight: 700; border-radius: 999px; padding: .38rem .65rem; }
        .badge.rounded-pill { padding: .5rem .78rem; font-size: .74rem; font-weight: 700; letter-spacing: .1px; }
        .badge.rounded-pill.bg-success { background: linear-gradient(135deg, #111111, #2e2e2e) !important; color: #fff !important; border: none !important; }
        .badge.rounded-pill.bg-danger { background: linear-gradient(135deg, #ff7a00, #ff9f1a) !important; color: #fff !important; border: none !important; }
        .badge.rounded-pill.bg-primary { background: linear-gradient(135deg, #ff8c00, #ffb000) !important; color: #fff !important; border: none !important; }
        .badge.rounded-pill.bg-secondary { background: #fff7ea !important; color: #6b4a00 !important; border: 1px solid rgba(255, 176, 0, 0.25) !important; }
        .badge.rounded-pill.bg-warning.text-dark { background: linear-gradient(135deg, #ffd166, #ffbf47) !important; color: #5b3a00 !important; border: none !important; }

        .btn-warning { background: linear-gradient(135deg, #ff9f1a, #ffb000); border: none; color: #fff; }
        .btn-danger { background: linear-gradient(135deg, #ff7a00, #ff9f1a); border: none; color: #fff; }
        .btn-success { background: linear-gradient(135deg, #28a745, #218838); border: none; color: #fff; }
        .btn-info { background: linear-gradient(135deg, #111111, #3a3a3a); border: none; color: #fff; }
        .btn-dark { background: linear-gradient(135deg, #444, #111); border: none; color: #fff; }

        .pagination .page-link { border-radius: 12px; color: #2a2a2a; border: 1px solid #ead9bf; padding: .6rem .85rem; font-weight: 700; margin: 0 2px;}
        .pagination .page-item.active .page-link { background: linear-gradient(135deg, #111111, #ff8f00); border-color: transparent; color: #fff; }

        .empty-state { text-align: center; color: var(--text-soft); padding: 2.2rem 1rem !important; }
        .empty-state i { display: block; font-size: 1.8rem; margin-bottom: .55rem; color: var(--orange-2); }

        .modal-header.bg-warning-custom { background: linear-gradient(135deg, #111111 0%, #2c2c2c 40%, #ff8f00 100%) !important; color: #fff; border: none; }
        .modal-header .btn-close { filter: invert(1); }
        .select2-container--default .select2-selection--single { border-radius: 12px !important; border: 1px solid #ddd !important; min-height: 42px; padding-top: 5px; }
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

                            <div class="d-flex gap-2 flex-wrap">
                                <?php if (canCreateBarang()): ?>
                                    <button class="btn btn-add-item" data-bs-toggle="modal" data-bs-target="#modalCreate">
                                        <i class="bi bi-plus-circle me-2"></i>Tambah Barang
                                    </button>
                                <?php endif; ?>

                                <?php if (canOpenPengirimanUser()): ?>
                                    <button class="btn btn-add-item" data-bs-toggle="modal" data-bs-target="#modalPengirimanUser">
                                        <i class="bi bi-truck me-2"></i>Kirim Barang Rusak ke HO Jakarta
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="ui-card toolbar-card">
                        <div class="row g-3 align-items-end">
                            <div class="col-lg-7">
                                <form method="GET" class="search-wrap">
                                    <input type="hidden" name="filter" value="<?= $filterValue ?>">
                                    <input type="hidden" name="limit" value="<?= $limit ?>">

                                    <label class="toolbar-label">Pencarian Barang</label>
                                    <div class="input-group">
                                        <input type="text" name="cari" class="form-control" placeholder="Cari asset, serial number..." value="<?= $searchValue ?>">
                                        <button class="btn search-btn" type="submit"><i class="bi bi-search me-2"></i>Cari</button>
                                        <a href="index.php" class="btn reset-btn">Reset</a>
                                    </div>
                                </form>
                            </div>

                            <div class="col-lg-5">
                                <label class="toolbar-label">Mode Tampilan</label>
                                <div class="mode-switch">
                                    <a href="index.php?cari=<?= urlencode($searchInput) ?>&limit=<?= $limit ?>" class="<?= $btnSemua ?>">
                                        <i class="bi bi-box-seam"></i>Total Inventaris
                                    </a>
                                    <a href="index.php?filter=masuk&cari=<?= urlencode($searchInput) ?>&limit=<?= $limit ?>" class="<?= $btnMasuk ?>">
                                        <i class="bi bi-box-arrow-in-down"></i>Barang Masuk
                                    </a>
                                    <a href="index.php?filter=keluar&cari=<?= urlencode($searchInput) ?>&limit=<?= $limit ?>" class="<?= $btnKeluar ?>">
                                        <i class="bi bi-box-arrow-up"></i>Barang Keluar
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 3 Kartu Ringkasan (Stok, Masuk, Keluar) -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Total Inventaris</div>
                                        <div class="summary-value"><?= $totalInventaris ?></div>
                                        <div class="summary-note">Aset aktif di lokasi saat ini</div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-box-seam"></i></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Barang Masuk</div>
                                        <div class="summary-value"><?= $totalMasuk ?></div>
                                        <div class="summary-note">Asset masuk ke gudang</div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-box-arrow-in-down"></i></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Barang Keluar</div>
                                        <div class="summary-value"><?= $totalKeluar ?></div>
                                        <div class="summary-note">Asset yang sudah dikirim</div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-box-arrow-up"></i></div>
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
                                        Menampilkan <?= $fromRow ?> - <?= $toRow ?> dari <?= $totalRows ?> data
                                    </div>
                                </div>

                                <form method="GET" class="mb-0">
                                    <input type="hidden" name="cari" value="<?= $searchValue ?>">
                                    <input type="hidden" name="filter" value="<?= $filterValue ?>">

                                    <div class="limit-box">
                                        <span class="section-label">Tampilkan</span>
                                        <select name="limit" onchange="this.form.submit()" class="form-select form-select-sm">
                                            <option value="5" <?= $limit === 5 ? 'selected' : '' ?>>5</option>
                                            <option value="25" <?= $limit === 25 ? 'selected' : '' ?>>25</option>
                                            <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
                                            <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
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
                                        <th>Asset & Identitas</th>

                                        <?php if ($filter === 'masuk'): ?>
                                            <th>Logistik Masuk</th>
                                            <th>Status Pengiriman</th>
                                            <th>Foto Resi</th>
                                            <th class="text-center">Aksi</th>
                                        <?php elseif ($filter === 'keluar'): ?>
                                            <th>Logistik Keluar</th>
                                            <th>Status Pengiriman</th>
                                            <th>Foto Resi</th>
                                            <th class="text-center">Aksi</th>
                                        <?php else: ?>
                                            <th>Detail Tipe</th>
                                            <th>Lokasi / User</th>
                                            <th>Status Barang</th>
                                            <th>Foto Aset</th>
                                            <th class="text-center">Aksi</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (mysqli_num_rows($query) > 0): ?>
                                        <?php $no = $offset + 1; ?>

                                        <?php while ($data = mysqli_fetch_assoc($query)): ?>
                                            <tr>
                                                <td><?= $no++ ?></td>

                                                <td>
                                                    <div class="fw-bold mb-1"><?= h($data['nama_barang'] ?? '-') ?></div>
                                                    <div class="asset-code"><?= h($data['no_asset'] ?? '-') ?></div>
                                                    <small class="meta-muted">SN: <?= h($data['serial_number'] ?? '-') ?></small>
                                                </td>

                                                <?php if ($filter === 'masuk'): ?>
                                                    <td>
                                                        <span class="badge bg-success mb-2"><i class="bi bi-box-arrow-in-down me-1"></i>Masuk</span>
                                                        <span class="meta-line"><i class="bi bi-calendar"></i> <?= h($data['tanggal'] ?? '-') ?></span>
                                                        <span class="meta-line"><i class="bi bi-geo-alt"></i> Asal: <?= h($data['info_branch'] ?? '-') ?></span>
                                                        <span class="meta-line"><i class="bi bi-person"></i> <?= h($data['pemilik_barang'] ?? 'Tidak ada') ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="mb-2"><?= shippingBadge($data['status_pengiriman'] ?? '-') ?></div>
                                                        <span class="meta-line"><i class="bi bi-receipt"></i> Resi: <?= h($data['nomor_resi_keluar'] ?? '-') ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($data['foto_resi_keluar'])): ?>
                                                            <img src="../assets/images/<?= h($data['foto_resi_keluar']) ?>" class="thumb-img previewFoto" data-foto="../assets/images/<?= h($data['foto_resi_keluar']) ?>">
                                                        <?php else: ?>
                                                            <span class="thumb-placeholder"><i class="bi bi-receipt"></i></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php
                                                        $status = strtolower(trim($data['status_pengiriman'] ?? ''));
                                                        $belumDiterima = ($status === 'sedang perjalanan' || $status === 'menunggu persetujuan admin');
                                                        ?>
                                                        <div class="action-group">
                                                            <?php if ($belumDiterima): ?>
                                                                <button class="btn btn-sm btn-info btnKonfirmasiTerima" data-id="<?= (int)$data['id_transaksi'] ?>" data-role="<?= $isAdmin ? 'admin' : 'user' ?>" title="Konfirmasi Terima Barang">
                                                                    <i class="bi bi-check2-circle text-white"></i>
                                                                </button>
                                                            <?php else: ?>
                                                                <button class="btn btn-sm btn-dark" disabled title="Selesai / Terkunci"><i class="bi bi-lock-fill"></i></button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>

                                                <?php elseif ($filter === 'keluar'): ?>
                                                    <td>
                                                        <span class="badge bg-danger mb-2"><i class="bi bi-box-arrow-up me-1"></i>Keluar</span>
                                                        <span class="meta-line"><i class="bi bi-calendar"></i> <?= h($data['tanggal'] ?? '-') ?></span>
                                                        <span class="meta-line"><i class="bi bi-geo-alt"></i> Tujuan: <?= h($data['info_branch'] ?? '-') ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="mb-2"><?= shippingBadge($data['status_pengiriman'] ?? '-') ?></div>
                                                        <span class="meta-line"><i class="bi bi-receipt"></i> Resi: <?= h($data['nomor_resi_keluar'] ?? '-') ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($data['foto_resi_keluar'])): ?>
                                                            <img src="../assets/images/<?= h($data['foto_resi_keluar']) ?>" class="thumb-img previewFoto" data-foto="../assets/images/<?= h($data['foto_resi_keluar']) ?>">
                                                        <?php else: ?>
                                                            <span class="thumb-placeholder"><i class="bi bi-receipt"></i></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="action-group">
                                                            <button class="btn btn-sm btn-dark" disabled title="Terkunci"><i class="bi bi-lock-fill"></i></button>
                                                        </div>
                                                    </td>

                                                <?php else: ?>
                                                    <!-- TAMPILAN DEFAULT -->
                                                    <td>
                                                        <span class="badge bg-light text-dark border mode-badge mb-1"><?= h($data['nama_merk'] ?? '-') ?></span>
                                                        <span class="meta-line"><b>Tipe:</b> <?= h($data['nama_tipe'] ?? '-') ?></span>
                                                        <span class="meta-line"><b>Jenis:</b> <?= h($data['nama_jenis'] ?? '-') ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if ($isAdmin): ?>
                                                            <span class="meta-line"><i class="bi bi-geo-alt"></i> <?= h($data['info_branch'] ?? '-') ?></span>
                                                        <?php endif; ?>
                                                        <span class="meta-line"><i class="bi bi-person"></i> <?= h($data['user'] ?? 'Tidak ada') ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="mb-2"><?= barangBadge($data['bermasalah'] ?? 'Tidak') ?></div>
                                                        <?php if (($data['bermasalah'] ?? 'Tidak') === 'Iya'): ?>
                                                            <span class="meta-line text-danger" style="font-size:0.8rem;"><?= h($data['keterangan_masalah'] ?? '') ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($data['foto'])): ?>
                                                            <img src="../assets/images/<?= h($data['foto']) ?>" class="thumb-img previewFoto" data-foto="../assets/images/<?= h($data['foto']) ?>">
                                                        <?php else: ?>
                                                            <span class="thumb-placeholder"><i class="bi bi-image"></i></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="action-group">
                                                            <?php if ($isAdmin): ?>
                                                                <button class="btn btn-sm btn-warning btnEditMaster" data-id="<?= (int) $data['id'] ?>" title="Edit Data Barang"><i class="bi bi-pencil-fill text-dark"></i></button>
                                                                <button class="btn btn-sm <?= (($data['bermasalah'] ?? '') === 'Iya') ? 'btn-danger' : 'btn-success' ?> btnLogistik" data-id="<?= (int) $data['id'] ?>" data-bermasalah="<?= (($data['bermasalah'] ?? '') === 'Iya') ? '1' : '0' ?>" title="Kirim ke Cabang"><i class="bi bi-truck"></i></button>
                                                                <button class="btn btn-sm btn-danger btnDelete" data-id="<?= (int) $data['id'] ?>" title="Hapus data"><i class="bi bi-trash"></i></button>
                                                            <?php else: ?>
                                                                <span class="text-muted small fw-bold">Aktif</span>
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
                                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                            <a class="page-link" href="?page=<?= $i ?>&limit=<?= $limit ?>&cari=<?= urlencode($searchInput) ?>&filter=<?= urlencode($filter) ?>">
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

    <!-- MODALS -->
    <div class="modal fade" id="modalCreate" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 rounded-4">
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
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header bg-warning-custom">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i><span id="modalUpdateTitle">Form Data</span></h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="contentUpdate">
                    <p class="text-center text-muted">Loading...</p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalPengirimanUser" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header bg-warning-custom">
                    <h5 class="modal-title"><i class="bi bi-truck me-2"></i>Kirim Barang Rusak</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="contentPengirimanUser">
                    <p class="text-center text-muted">Loading form...</p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalFoto" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-dark border-0">
                <div class="modal-body text-center">
                    <img id="fotoPreview" src="" class="img-fluid rounded">
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL BARU: TERIMA BARANG (KHUSUS CABANG) -->
    <div class="modal fade" id="modalTerimaCabang" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-box-seam me-2"></i>Form Konfirmasi Terima</h5>
                    <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="contentTerimaCabang">
                    <p class="text-center text-muted">Loading form...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPT JS -->
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

            $container.find('select.select2').each(function() {
                if ($(this).hasClass('select2-hidden-accessible')) {
                    $(this).select2('destroy');
                }
                $(this).select2({ dropdownParent: $modal, width: '100%', placeholder: placeholderText, allowClear: true });
            });
        }

        function initSelect2WhenModalReady(containerSelector, modalSelector, placeholderText = 'Pilih...') {
            const $modal = $(modalSelector);
            const runInit = function() { requestAnimationFrame(function() { initSelect2InContainer(containerSelector, modalSelector, placeholderText); }); };
            if ($modal.hasClass('show')) { runInit(); } else { $modal.one('shown.bs.modal', runInit); }
        }

        function loadModalContent(modalSelector, contentSelector, url, errorMessage) {
            $(contentSelector).html('<p class="text-center text-muted">Loading form...</p>');
            $.get(url, function(html) {
                $(contentSelector).html(html);
                initSelect2WhenModalReady(contentSelector, modalSelector, 'Pilih...');
            }).fail(function() {
                $(contentSelector).html('<p class="text-danger">' + errorMessage + '</p>');
            });
        }

        function resetModalContent(modalSelector, contentSelector, loadingText = 'Loading form...') {
            $(modalSelector).on('hidden.bs.modal', function() {
                destroySelect2InContainer(contentSelector);
                $(contentSelector).html('<p class="text-center text-muted">' + loadingText + '</p>');
            });
        }

        // =====================================
        // EVENT MODALS (CREATE, UPDATE, PREVIEW)
        // =====================================
        $('#modalCreate').on('show.bs.modal', function() {
            loadModalContent('#modalCreate', '#contentCreate', 'create.php', 'Gagal memuat form.');
        });
        resetModalContent('#modalCreate', '#contentCreate');

        $(document).on('click', '.previewFoto', function() {
            $('#fotoPreview').attr('src', $(this).data('foto'));
            new bootstrap.Modal(document.getElementById('modalFoto')).show();
        });

        $('#modalPengirimanUser').on('show.bs.modal', function() {
            loadModalContent('#modalPengirimanUser', '#contentPengirimanUser', 'pengiriman_user.php', 'Gagal memuat form pengiriman ke HO Jakarta.');
        });
        resetModalContent('#modalPengirimanUser', '#contentPengirimanUser');

        $(document).on('click', '.btnEditMaster', function() {
            const id = $(this).data('id');
            const modalEl = document.getElementById('modalUpdate');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            document.getElementById('modalUpdateTitle').innerHTML = 'Update Data Barang';
            destroySelect2InContainer('#contentUpdate');
            $('#contentUpdate').html('<p class="text-center text-muted">Loading form...</p>');
            modal.show();

            fetch('update.php?id=' + id + '&type=master').then(res => res.text()).then(html => {
                $('#contentUpdate').html(html);
                initSelect2WhenModalReady('#contentUpdate', '#modalUpdate', 'Pilih...');
                if ($('#bermasalahUpdate').length) { toggleKeteranganMasalah(); }
            }).catch(() => { $('#contentUpdate').html('<p class="text-danger">Gagal memuat data update.</p>'); });
        });

        $(document).on('click', '.btnLogistik', function() {
            const id = $(this).data('id');
            const isBermasalah = $(this).data('bermasalah') == '1';
            
            if (isBermasalah) {
                Swal.fire({ icon: 'error', title: 'Barang Bermasalah', text: 'Barang ini masih berstatus "Bermasalah" dan belum bisa melakukan transaksi pengiriman logistik.', confirmButtonColor: '#d33', });
                return;
            }

            const modalEl = document.getElementById('modalUpdate');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            document.getElementById('modalUpdateTitle').innerHTML = 'Logistik Pengiriman ke Cabang';
            destroySelect2InContainer('#contentUpdate');
            $('#contentUpdate').html('<p class="text-center text-muted">Loading form...</p>');
            modal.show();

            fetch('update.php?id=' + id + '&type=logistik').then(res => res.text()).then(html => {
                $('#contentUpdate').html(html);
                initSelect2WhenModalReady('#contentUpdate', '#modalUpdate', 'Pilih...');
            }).catch(() => { $('#contentUpdate').html('<p class="text-danger">Gagal memuat form logistik.</p>'); });
        });

        $('#modalUpdate').on('hidden.bs.modal', function() {
            destroySelect2InContainer('#contentUpdate');
            $('#contentUpdate').html('Loading...');
        });

        // =====================================
        // EVENT SUBMIT FORM
        // =====================================
        let isSubmittingCreate = false;
        $(document).on('submit', '#formCreate', function(e) {
            e.preventDefault();
            if (isSubmittingCreate) return;
            const $form = $(this);
            const $btn = $form.find('#btnSimpanBarang');

            $.ajax({
                url: 'create.php', type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
                beforeSend: function() {
                    isSubmittingCreate = true;
                    $btn.prop('disabled', true).addClass('disabled');
                    $btn.find('.btn-text').addClass('d-none');
                    $btn.find('.btn-loading').removeClass('d-none');
                    Swal.fire({ title: 'Menyimpan data...', allowOutsideClick: false, showConfirmButton: false, didOpen: () => { Swal.showLoading(); }});
                },
                success: function(response) {
                    if (response.success || response.status === 'success') {
                        Swal.fire({ icon: 'success', title: 'Berhasil', text: response.message || 'Data berhasil ditambahkan' }).then(() => location.reload());
                    } else { Swal.fire({ icon: 'error', title: 'Gagal', text: response.error || response.message || 'Terjadi kesalahan' }); }
                },
                error: function(xhr) { Swal.fire({ icon: 'error', title: 'Error', text: 'Terjadi kesalahan server' }); },
                complete: function() {
                    isSubmittingCreate = false;
                    $btn.prop('disabled', false).removeClass('disabled');
                    $btn.find('.btn-text').removeClass('d-none');
                    $btn.find('.btn-loading').addClass('d-none');
                }
            });
        });

        $(document).on('submit', '#formUpdate', function(e) {
            e.preventDefault();
            $.ajax({
                url: 'update.php', type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') { Swal.fire({ icon: 'success', title: 'Berhasil', text: response.message }).then(() => location.reload()); }
                    else { Swal.fire({ icon: 'error', title: 'Gagal', text: response.message || 'Proses gagal' }); }
                },
                error: function(xhr) { Swal.fire({ icon: 'error', title: 'Error', text: 'Terjadi error sistem backend.' }); }
            });
        });

        $(document).on('submit', '#formPengirimanUser', function(e) {
            e.preventDefault();
            const $btn = $(this).find('#btnSimpanPengirimanUser');

            $.ajax({
                url: 'pengiriman_user.php', type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
                beforeSend: function() { $btn.prop('disabled', true); $btn.find('.btn-text').addClass('d-none'); $btn.find('.btn-loading').removeClass('d-none'); },
                success: function(response) {
                    if (response.status === 'success') { Swal.fire({ icon: 'success', title: 'Berhasil', text: response.message }).then(() => location.reload()); }
                    else { Swal.fire({ icon: 'error', title: 'Gagal', text: response.message || 'Terjadi kesalahan' }); }
                },
                error: function(xhr) { Swal.fire({ icon: 'error', title: 'Error', text: 'Terjadi kesalahan server' }); },
                complete: function() { $btn.prop('disabled', false); $btn.find('.btn-text').removeClass('d-none'); $btn.find('.btn-loading').addClass('d-none'); }
            });
        });

        // =====================================
        // EVENT DELETE & TOGGLE MASALAH
        // =====================================
        $(document).on('click', '.btnDelete', function() {
            const id = this.dataset.id;
            Swal.fire({ title: 'Yakin hapus data?', text: 'Data tidak bisa dikembalikan', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Ya hapus' }).then(result => {
                if (!result.isConfirmed) return;
                $.getJSON('delete.php', { id: id }, function(response) {
                    if (response.status === 'success') { Swal.fire({ icon: 'success', title: 'Terhapus!', text: response.message }).then(() => location.reload()); }
                    else { Swal.fire({ icon: 'error', title: 'Gagal!', text: response.message }); }
                });
            });
        });

        function toggleKeteranganMasalah() {
            const val = $('#bermasalahUpdate').val();
            if (val === 'Iya') { $('#keteranganMasalahWrap').slideDown(); $('#keteranganMasalahUpdate').attr('required', true); }
            else { $('#keteranganMasalahWrap').slideUp(); $('#keteranganMasalahUpdate').removeAttr('required'); }
        }

        $(document).on('change', '#bermasalahUpdate', function() {
            const nilaiAwal = $('#bermasalahAwal').val();
            const nilaiSekarang = $(this).val();

            if (nilaiAwal === 'Iya' && nilaiSekarang === 'Tidak') {
                Swal.fire({ title: 'Konfirmasi', text: 'Barang ini sudah diperbaiki atau tidak bermasalah lagi?', showCancelButton: true, confirmButtonText: 'Ya, sudah', cancelButtonText: 'Batal'
                }).then((result) => {
                    if (result.isConfirmed) { $('#keteranganMasalahUpdate').val(''); toggleKeteranganMasalah(); }
                    else { $(this).val('Iya'); toggleKeteranganMasalah(); }
                });
            } else {
                if (nilaiSekarang === 'Tidak') { $('#keteranganMasalahUpdate').val(''); }
                toggleKeteranganMasalah();
            }
        });

        // =====================================================================
        // SCRIPT: KONFIRMASI TERIMA BARANG (ADMIN / CABANG)
        // =====================================================================
        $(document).on('click', '.btnKonfirmasiTerima', function() {
            const idTransaksi = $(this).data('id');
            const role = $(this).data('role');

            if (role === 'admin') {
                Swal.fire({
                    title: 'Konfirmasi Terima Barang',
                    text: "Apakah fisik barang beserta resi telah sesuai dan Anda terima dengan baik?",
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#111',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Ya, Terima!'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            url: 'pengiriman_approval.php',
                            type: 'POST',
                            data: { id_pengiriman: idTransaksi, nama_penerima: 'Admin HO Jakarta' },
                            dataType: 'json',
                            success: function(res) {
                                if (res.status === 'success') {
                                    Swal.fire('Berhasil!', res.message, 'success').then(() => location.reload());
                                } else {
                                    Swal.fire('Gagal!', res.message, 'error');
                                }
                            }
                        });
                    }
                });
            } else {
                // Tampilkan Modal Form Penerimaan untuk User Cabang
                $('#contentTerimaCabang').html('<p class="text-center text-muted">Loading form...</p>');
                $('#contentTerimaCabang').load('terima_barang_form.php?id=' + idTransaksi);
                new bootstrap.Modal(document.getElementById('modalTerimaCabang')).show();
            }
        });

        // =====================================================================
        // SCRIPT: SUBMIT FORM TERIMA BARANG OLEH CABANG
        // =====================================================================
        $(document).on('submit', '#formTerimaCabang', function(e) {
            e.preventDefault();
            const $btn = $('#btnSubmitTerima');
            
            $.ajax({
                url: 'terima_barang_proses.php',
                type: 'POST',
                data: new FormData(this),
                contentType: false,
                processData: false,
                dataType: 'json',
                beforeSend: function() { 
                    $btn.prop('disabled', true).text('Menyimpan...'); 
                },
                success: function(res) {
                    if (res.status === 'success') {
                        Swal.fire('Berhasil!', res.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Gagal!', res.message, 'error');
                    }
                },
                error: function() { 
                    Swal.fire('Error!', 'Gagal menghubungi server.', 'error'); 
                },
                complete: function() { 
                    $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Simpan Penerimaan'); 
                }
            });
        });

    </script>
</body>
</html>