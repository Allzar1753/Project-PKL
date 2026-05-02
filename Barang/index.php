<?php
include '../config/koneksi.php';
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
function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function shippingBadge(string $status): string {
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

function barangBadge(string $bermasalah): string {
    if ($bermasalah === 'Iya') {
        return '<span class="badge rounded-pill bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Bermasalah</span>';
    }
    return '<span class="badge rounded-pill bg-success"><i class="bi bi-check-circle me-1"></i>Normal</span>';
}

function fetchSingleValue(mysqli $koneksi, string $sql, string $field = 'total'): int {
    $query = mysqli_query($koneksi, $sql);
    if (!$query) return 0;
    $row = mysqli_fetch_assoc($query) ?: [];
    return (int) ($row[$field] ?? 0);
}

function canCreateBarang(): bool {
    return is_admin() && can('barang.create');
}

function canOpenPengirimanUser(): bool {
    return is_user_role() && can('barang.kirim');
}

// =========================================================================
// PENGATURAN FILTER, PENCARIAN, DAN PAGINATION
// =========================================================================
$searchInput = isset($_GET['cari']) ? trim((string) $_GET['cari']) : '';
$filter = isset($_GET['filter']) ? trim((string) $_GET['filter']) : '';
if (!in_array($filter, ['', 'masuk', 'keluar'], true)) { $filter = ''; }

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
$excludeTransitSql = " AND barang.id NOT IN (SELECT id_barang FROM barang_pengiriman WHERE status_pengiriman = 'Sedang perjalanan') ";
$stokAktifSql = " AND barang.status = 'Tersedia' ";

if ($isAdmin) {
    $totalInventaris = fetchSingleValue($koneksi, "SELECT COUNT(id) AS total FROM barang WHERE 1=1 $excludeTransitSql $stokAktifSql");
    $totalMasuk      = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE status_pengiriman = 'Sudah diterima HO'");
    $totalKeluar     = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman");
} else {
    $totalInventaris = fetchSingleValue($koneksi, "SELECT COUNT(id) AS total FROM barang WHERE id_branch = $myBranchId $excludeTransitSql $stokAktifSql");
    $totalMasuk      = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman WHERE branch_tujuan = $myBranchId AND status_pengiriman = 'Sudah diterima'");
    $totalKeluar     = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE branch_asal = $myBranchId");
}

// Menentukan Query Berdasarkan Filter
if ($filter === 'keluar') {
    if ($isAdmin) {
        $querySql = "SELECT p.id_pengiriman AS id_transaksi, p.tanggal_keluar AS tanggal, p.status_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar,
                            b.no_asset, b.serial_number, tb_barang.nama_barang, br.nama_branch AS info_branch, p.nama_penerima as pemilik_barang
                     FROM barang_pengiriman p
                     JOIN barang b ON p.id_barang = b.id
                     JOIN tb_barang ON b.id_barang = tb_barang.id_barang
                     LEFT JOIN tb_branch br ON p.branch_tujuan = br.id_branch
                     WHERE 1=1 $searchSql_barang_pengiriman ORDER BY p.id_pengiriman DESC";
    } else {
        $querySql = "SELECT p.id_pengiriman_ho AS id_transaksi, p.tanggal_pengajuan AS tanggal, p.status_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar,
                            p.serial_number, p.pemilik_barang, tb_barang.nama_barang, 'Pusat HO' AS info_branch
                     FROM pengiriman_cabang_ho p
                     JOIN tb_barang ON p.id_barang = tb_barang.id_barang
                     WHERE p.branch_asal = $myBranchId $searchSql_pengiriman_ho ORDER BY p.id_pengiriman_ho DESC";
    }
} elseif ($filter === 'masuk') {
    if ($isAdmin) {
        $querySql = "SELECT p.id_pengiriman_ho AS id_transaksi, p.tanggal_pengajuan AS tanggal, p.status_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar,
                            p.serial_number, p.pemilik_barang, tb_barang.nama_barang, br.nama_branch AS info_branch
                     FROM pengiriman_cabang_ho p
                     JOIN tb_barang ON p.id_barang = tb_barang.id_barang
                     LEFT JOIN tb_branch br ON p.branch_asal = br.id_branch
                     WHERE 1=1 $searchSql_pengiriman_ho ORDER BY p.id_pengiriman_ho DESC";
    } else {
        $querySql = "SELECT p.id_pengiriman AS id_transaksi, p.tanggal_keluar AS tanggal, p.status_pengiriman, p.nomor_resi_keluar, p.foto_resi_keluar,
                            b.no_asset, b.serial_number, tb_barang.nama_barang, 'Pusat HO' AS info_branch, p.nama_penerima as pemilik_barang
                     FROM barang_pengiriman p
                     JOIN barang b ON p.id_barang = b.id
                     JOIN tb_barang ON b.id_barang = tb_barang.id_barang
                     WHERE p.branch_tujuan = $myBranchId $searchSql_barang_pengiriman ORDER BY p.id_pengiriman DESC";
    }
} else {
    $whereLokasi = $isAdmin ? "1=1" : "barang.id_branch = $myBranchId";
    $querySql = "SELECT barang.id, barang.no_asset, barang.serial_number, barang.bermasalah, barang.foto, barang.user, barang.keterangan_masalah,
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
        :root {
            --orange-1: #ff7a00; --orange-2: #ff9800; --orange-3: #ffb000;
            --dark-1: #111111; --text-main: #1e1e1e; --text-soft: #6b7280;
            --surface: #ffffff; --border-soft: rgba(255, 152, 0, 0.14);
            --shadow-soft: 0 12px 36px rgba(17, 17, 17, 0.07); --radius-xl: 28px;
        }
        body {
            background: radial-gradient(circle at top left, rgba(255, 176, 0, 0.16), transparent 28%),
                        linear-gradient(180deg, #fff8f1 0%, #ffffff 100%);
            font-family: 'Plus Jakarta Sans', sans-serif; color: var(--text-main); min-height: 100vh;
        }
        .page-shell { padding: 25px; }
        .page-hero {
            position: relative; overflow: hidden; border-radius: var(--radius-xl);
            background: linear-gradient(135deg, rgba(17, 17, 17, 0.94) 0%, rgba(255, 122, 0, 0.96) 100%);
            box-shadow: 0 18px 45px rgba(255, 122, 0, 0.20); padding: 1.8rem; margin-bottom: 1.5rem;
        }
        .page-title { color: #fff; font-size: 1.8rem; font-weight: 800; letter-spacing: -0.02em; }
        .page-desc { color: rgba(255, 255, 255, 0.84); font-size: .94rem; max-width: 700px; }
        .btn-add-item {
            border: none; background: #fff; color: #111; font-weight: 800;
            border-radius: 999px; padding: .75rem 1.4rem; box-shadow: 0 10px 24px rgba(0, 0, 0, 0.12);
        }
        .ui-card { background: var(--surface); border: 1px solid var(--border-soft); border-radius: 22px; box-shadow: var(--shadow-soft); }
        .toolbar-card { padding: 1.25rem; margin-bottom: 1.25rem; }
        .toolbar-label { font-size: .84rem; font-weight: 700; color: #444; margin-bottom: .5rem; display: block;}
        .search-wrap .input-group { border: 1px solid rgba(255, 152, 0, 0.2); border-radius: 16px; overflow: hidden; background: #fff; }
        .search-wrap .form-control { border: none; padding: .8rem 1rem; }
        .search-btn { background: linear-gradient(135deg, var(--orange-1), var(--orange-3)); color: #fff; border: none; padding: 0 1.2rem; font-weight: 700; }
        .reset-btn { background: #222; color: #fff; border: none; padding: 0 1rem; display: flex; align-items: center; }
        .mode-switch { display: flex; gap: .6rem; flex-wrap: wrap; }
        .btn-mode {
            padding: .7rem 1.1rem; border-radius: 999px; border: 1px solid #eee;
            background: #fff; color: #444; font-weight: 700; text-decoration: none; transition: .2s;
        }
        .btn-mode.is-active { background: #111; color: #fff; border-color: #111; }
        .summary-card {
            padding: 1.25rem; border-radius: 20px; background: #fff; border: 1px solid rgba(255,152,0,0.1);
            position: relative; overflow: hidden; height: 100%;
        }
        .summary-card::after { content: ""; position: absolute; top:0; left:0; width:4px; height:100%; background: var(--orange-1); }
        .summary-label { font-size: .85rem; font-weight: 700; color: var(--text-soft); }
        .summary-value { font-size: 2rem; font-weight: 800; color: #111; margin: .2rem 0; }
        .summary-icon { width: 45px; height: 45px; background: #fff4e6; color: var(--orange-1); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
        .table-card .card-header { background: #111; color: #fff; border: none; padding: 1.2rem; border-radius: 22px 22px 0 0; }
        .limit-box { background: rgba(255,255,255,0.1); border-radius: 999px; padding: 5px 15px; display: flex; align-items: center; gap: 8px; color: #fff; font-size: .85rem; }
        .limit-box select { background: transparent; border: none; color: #fff; font-weight: 700; outline: none; }
        .limit-box select option { color: #000; }
        .thumb-img { width: 55px; height: 55px; object-fit: cover; border-radius: 12px; border: 1px solid #eee; cursor: pointer; }
        .thumb-placeholder { width: 55px; height: 55px; background: #f9f9f9; border: 1px dashed #ccc; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #aaa; }
        .asset-code { font-weight: 800; color: #111; font-size: 0.95rem; }
        .meta-line { display: block; font-size: 0.88rem; margin-bottom: 2px; }
        .meta-muted { color: #666; font-size: 0.82rem; }
        .badge.rounded-pill { padding: 0.5em 0.9em; font-weight: 700; }
        .pagination .page-link { border-radius: 10px; margin: 0 3px; color: #111; font-weight: 700; border: 1px solid #eee; }
        .pagination .page-item.active .page-link { background: #111; border-color: #111; color: #fff; }
        .action-group .btn { width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; margin: 2px; }
        .modal-header.bg-warning-custom { background: #111; color: #fff; }
        .modal-header .btn-close { filter: invert(1); }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <?php include '../layout/sidebar.php'; ?>

        <div class="col-md-10">
            <div class="page-shell">

                <!-- Header Hero -->
                <div class="page-hero">
                    <div class="hero-content d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <h1 class="page-title">Peralatan & Asset IT</h1>
                            <p class="page-desc">Kelola inventaris, lacak pengiriman antar cabang, dan pantau status kondisi perangkat secara real-time.</p>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if (canCreateBarang()): ?>
                                <button class="btn btn-add-item" data-bs-toggle="modal" data-bs-target="#modalCreate">
                                    <i class="bi bi-plus-circle me-2"></i>Tambah Barang
                                </button>
                            <?php endif; ?>
                            <?php if (canOpenPengirimanUser()): ?>
                                <button class="btn btn-add-item bg-dark text-white" data-bs-toggle="modal" data-bs-target="#modalPengirimanUser">
                                    <i class="bi bi-truck me-2"></i>Kirim Rusak ke HO
                                </button>
                            <?php endif; ?>
                        </div>
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
                                    <i class="bi bi-grid-fill me-2"></i>Stok Tersedia
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
                                <?php if (mysqli_num_rows($query) > 0): $no = $offset + 1; while ($data = mysqli_fetch_assoc($query)): ?>
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
                                            <?php if (in_array(strtolower($data['status_pengiriman']), ['sedang perjalanan','menunggu persetujuan admin'])): ?>
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
                                        <td class="text-center pe-4"><i class="bi bi-lock-fill text-muted"></i></td>

                                    <?php else: ?>
                                        <td>
                                            <span class="badge bg-light text-dark border mb-1"><?= h($data['nama_merk'] ?? '-') ?></span>
                                            <div class="meta-line text-muted">Tipe: <?= h($data['nama_tipe'] ?? '-') ?></div>
                                        </td>
                                        <td>
                                            <?php if ($isAdmin): ?><div class="meta-line fw-bold"><i class="bi bi-geo-alt me-1 text-danger"></i><?= h($data['info_branch']) ?></div><?php endif; ?>
                                            <div class="meta-line"><i class="bi bi-person me-1"></i><?= h($data['user'] ?: 'No User') ?></div>
                                        </td>
                                        <td>
                                            <?= barangBadge($data['bermasalah']) ?>
                                            <?php if($data['bermasalah'] === 'Iya'): ?><div class="small text-danger mt-1" style="max-width:150px"><?= h($data['keterangan_masalah']) ?></div><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($data['foto']): ?>
                                                <img src="../assets/images/<?= h($data['foto']) ?>" class="thumb-img previewFoto" data-foto="../assets/images/<?= h($data['foto']) ?>">
                                            <?php else: ?><div class="thumb-placeholder"><i class="bi bi-image"></i></div><?php endif; ?>
                                        </td>
                                        <td class="text-center pe-4">
                                            <div class="action-group">
                                                <?php if ($isAdmin): ?>
                                                    <button class="btn btn-warning btn-sm btnEditMaster" data-id="<?= $data['id'] ?>"><i class="bi bi-pencil-fill"></i></button>
                                                    <button class="btn btn-info btn-sm text-white btnLogistik" data-id="<?= $data['id'] ?>" data-bermasalah="<?= ($data['bermasalah'] === 'Iya' ? '1' : '0') ?>"><i class="bi bi-truck"></i></button>
                                                    <button class="btn btn-danger btn-sm btnDelete" data-id="<?= $data['id'] ?>"><i class="bi bi-trash"></i></button>
                                                <?php else: ?>
                                                    <span class="badge bg-success-subtle text-success">Aktif</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endwhile; else: ?>
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
                <div class="text-center p-4"><div class="spinner-border text-warning" role="status"></div><p class="mt-2 text-muted">Memuat form...</p></div>
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
                <div class="text-center p-4"><div class="spinner-border text-warning" role="status"></div></div>
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
                <div class="text-center p-4"><div class="spinner-border text-dark"></div></div>
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
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-box-seam me-2"></i>Konfirmasi Penerimaan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="contentTerimaCabang"></div>
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

    // Modal Create
    $('#modalCreate').on('show.bs.modal', function() {
        $('#contentCreate').load('create.php', function() { initSelect2('#contentCreate', '#modalCreate'); });
    });

    // Preview Foto
    $(document).on('click', '.previewFoto', function() {
        $('#fotoPreview').attr('src', $(this).data('foto'));
        new bootstrap.Modal('#modalFoto').show();
    });

    // Edit Master Data
    $(document).on('click', '.btnEditMaster', function() {
        const id = $(this).data('id');
        $('#modalUpdateTitle').text('Update Data Barang Master');
        $('#contentUpdate').html('<div class="text-center p-4"><div class="spinner-border text-warning"></div></div>');
        bootstrap.Modal.getOrCreateInstance('#modalUpdate').show();
        $.get('update.php', {id: id, type: 'master'}, function(html) {
            $('#contentUpdate').html(html);
            initSelect2('#contentUpdate', '#modalUpdate');
        });
    });

    // Pengiriman Logistik (Admin Only)
    $(document).on('click', '.btnLogistik', function() {
        if ($(this).data('bermasalah') == '1') {
            Swal.fire('Perhatian', 'Barang berstatus bermasalah tidak dapat dikirim ke cabang.', 'error');
            return;
        }
        const id = $(this).data('id');
        $('#modalUpdateTitle').text('Logistik Pengiriman ke Cabang');
        $('#contentUpdate').html('<div class="text-center p-4"><div class="spinner-border text-info"></div></div>');
        bootstrap.Modal.getOrCreateInstance('#modalUpdate').show();
        $.get('update.php', {id: id, type: 'logistik'}, function(html) {
            $('#contentUpdate').html(html);
            initSelect2('#contentUpdate', '#modalUpdate');
        });
    });

    // Pengiriman User (Cabang ke HO)
    $('#modalPengirimanUser').on('show.bs.modal', function() {
        $('#contentPengirimanUser').load('pengiriman_user.php', function() { initSelect2('#contentPengirimanUser', '#modalPengirimanUser'); });
    });

    // Submit Forms via AJAX
    $(document).on('submit', '#formCreate, #formUpdate, #formPengirimanUser, #formTerimaCabang', function(e) {
        e.preventDefault();
        const $form = $(this);
        const $btn = $form.find('button[type="submit"]');
        const originalBtnHtml = $btn.html();
        const url = $form.attr('action') || ($form.attr('id') === 'formCreate' ? 'create.php' : ($form.attr('id') === 'formUpdate' ? 'update.php' : ($form.attr('id') === 'formPengirimanUser' ? 'pengiriman_user.php' : 'terima_barang_proses.php')));

        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Proses...');

        $.ajax({
            url: url,
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
                }
            },
            error: () => Swal.fire('Error', 'Gagal menghubungi server', 'error'),
            complete: () => $btn.prop('disabled', false).html(originalBtnHtml)
        });
    });

    // Delete Item
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
                $.getJSON('delete.php', {id: id}, function(res) {
                    if (res.status === 'success') Swal.fire('Dihapus!', res.message, 'success').then(() => location.reload());
                    else Swal.fire('Gagal', res.message, 'error');
                });
            }
        });
    });

    // Konfirmasi Terima Barang (Logistik Masuk)
    $(document).on('click', '.btnKonfirmasiTerima', function() {
        const id = $(this).data('id');
        const role = $(this).data('role');

        if (role === 'admin') {
            Swal.fire({
                title: 'Konfirmasi Terima?',
                text: "Pastikan fisik barang sudah diterima di HO Jakarta.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Sudah Terima'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('pengiriman_approval.php', {id_pengiriman: id, nama_penerima: 'Admin HO'}, function(res) {
                        if (res.status === 'success') location.reload(); else Swal.fire('Error', res.message, 'error');
                    }, 'json');
                }
            });
        } else {
            $('#contentTerimaCabang').load('terima_barang_form.php?id=' + id);
            bootstrap.Modal.getOrCreateInstance('#modalTerimaCabang').show();
        }
    });

    // Logic Toggle Kondisi Masalah di Form Edit
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