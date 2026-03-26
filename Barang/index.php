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
    if (empty($row['tanggal_keluar'])) {
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

if ($search_input !== "") {
    $search = mysqli_real_escape_string($koneksi, $search_input);

    $where[] = "(
        tb_barang.nama_barang LIKE '%$search%'
        OR barang.no_asset LIKE '%$search%'
        OR barang.serial_number LIKE '%$search%'
        OR tb_merk.nama_merk LIKE '%$search%'
        OR tb_tipe.nama_tipe LIKE '%$search%'
        OR branch_asal.nama_branch LIKE '%$search%'
        OR branch_tujuan.nama_branch LIKE '%$search%'
        OR tb_status.nama_status LIKE '%$search%'
        OR tb_jenis.nama_jenis LIKE '%$search%'
        OR barang.keterangan_masalah LIKE '%$search%'
        OR barang.tanggal_masuk LIKE '%$search%'
        OR barang.tanggal_keluar LIKE '%$search%'
        OR barang.bermasalah LIKE '%$search%'
        OR barang.`user` LIKE '%$search%'
        OR barang.status_pengiriman LIKE '%$search%'
        OR barang.nomor_resi LIKE '%$search%'
        OR barang.estimasi_pengiriman LIKE '%$search%'
        OR barang.jasa_pengiriman LIKE '%$search%'
        OR barang.nama_penerima LIKE '%$search%'
    )";
}

if ($filter === "masuk") {
    $where[] = "barang.tanggal_keluar IS NULL";
} elseif ($filter === "keluar") {
    $where[] = "barang.tanggal_keluar IS NOT NULL";
}

$where_sql = "";
if (count($where) > 0) {
    $where_sql = "WHERE " . implode(" AND ", $where);
}

$base_from = "
FROM barang
LEFT JOIN tb_barang ON barang.id_barang = tb_barang.id_barang
LEFT JOIN tb_merk ON barang.id_merk = tb_merk.id_merk
LEFT JOIN tb_tipe ON barang.id_tipe = tb_tipe.id_tipe
LEFT JOIN tb_status ON barang.id_status = tb_status.id_status
LEFT JOIN tb_jenis ON barang.id_jenis = tb_jenis.id_jenis
LEFT JOIN tb_branch AS branch_asal ON barang.id_branch = branch_asal.id_branch
LEFT JOIN tb_branch AS branch_tujuan ON barang.tujuan = branch_tujuan.id_branch
";

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

$total = mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total FROM barang"
))['total'];

$masuk = mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total FROM barang WHERE tanggal_keluar IS NULL"
))['total'];

$keluar = mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total FROM barang WHERE tanggal_keluar IS NOT NULL"
))['total'];

$query = mysqli_query($koneksi, "
    SELECT 
        barang.*, 
        tb_barang.nama_barang, 
        tb_merk.nama_merk, 
        tb_tipe.nama_tipe,
        branch_asal.nama_branch AS branch_asal,
        branch_tujuan.nama_branch AS branch_tujuan,
        tb_status.nama_status, 
        tb_jenis.nama_jenis
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

$btnSemua  = $filter === '' ? 'btn-dark' : 'btn-outline-dark';
$btnMasuk  = $filter === 'masuk' ? 'btn-success' : 'btn-outline-success';
$btnKeluar = $filter === 'keluar' ? 'btn-danger' : 'btn-outline-danger';
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
    <style>
        :root {
            --primary: #ffc107;
            --dark: #212529;
            --muted: #6c757d;
        }

        body {
            background: #f8f9fa;
            font-family: 'Inter', sans-serif;
        }

        .text-warning-custom {
            color: var(--primary);
        }

        .bg-warning-custom {
            background: var(--primary);
        }

        .card-soft {
            border: none;
            border-radius: 14px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.05);
        }

        .btn-warning-custom {
            background: var(--primary);
            color: #000;
            font-weight: 700;
            border: none;
            border-radius: 10px;
        }

        .btn-warning-custom:hover {
            background: #e0a800;
        }

        .toolbar-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.05);
        }

        .summary-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.05);
            height: 100%;
        }

        .summary-card h3 {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 0;
        }

        .table-card {
            border: none;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.05);
        }

        .table-card .card-header {
            border-bottom: none;
            padding: 1rem 1.25rem;
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: #fff;
            color: #212529;
            font-weight: 700;
            border-bottom: 1px solid #dee2e6;
            white-space: nowrap;
        }

        .table tbody td {
            vertical-align: top;
            padding-top: 1rem;
            padding-bottom: 1rem;
        }

        .meta-line {
            display: block;
            font-size: 0.92rem;
            line-height: 1.45;
            color: #212529;
        }

        .meta-muted {
            display: block;
            font-size: 0.9rem;
            color: var(--muted);
            line-height: 1.45;
        }

        .thumb-img {
            width: 58px;
            height: 58px;
            object-fit: cover;
            border-radius: 10px;
            cursor: pointer;
            border: 1px solid #dee2e6;
        }

        .thumb-placeholder {
            width: 58px;
            height: 58px;
            border-radius: 10px;
            border: 1px dashed #ced4da;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #adb5bd;
            background: #fff;
        }

        .action-group {
            display: flex;
            justify-content: center;
            gap: .4rem;
            flex-wrap: wrap;
        }

        .section-label {
            font-size: 0.85rem;
            color: var(--muted);
        }

        .toolbar-actions .btn {
            border-radius: 10px;
            font-weight: 600;
        }

        .search-input {
            border-radius: 12px 0 0 12px !important;
        }

        .search-btn {
            border-radius: 0 !important;
        }

        .reset-btn {
            border-radius: 0 12px 12px 0 !important;
        }

        .limit-select {
            max-width: 90px;
        }

        .table-subinfo {
            font-size: 0.9rem;
            color: var(--muted);
        }

        .mode-badge {
            font-size: .82rem;
            font-weight: 600;
        }

        .pagination .page-link {
            border-radius: 8px;
            margin-right: 4px;
            color: #212529;
        }

        .pagination .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: #000;
            font-weight: 700;
        }

        @media (max-width: 992px) {
            .toolbar-actions {
                margin-top: 1rem;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">

            <?php include '../layout/sidebar.php'; ?>

            <div class="col-md-10 p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <h3 class="fw-bold text-warning-custom mb-1">Data Peralatan IT</h3>
                        <p class="text-muted mb-0">Manajemen Inventaris Aset Teknologi</p>
                    </div>
                    <?php if (can('barang.create')): ?>
                        <button class="btn btn-success px-4 py-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalCreate">
                            <i class="bi bi-plus-circle me-2"></i>Add Item
                        </button>
                    <?php endif; ?>
                </div>

                <div class="card toolbar-card p-3 mb-4">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-8">
                            <form method="GET">
                                <input type="hidden" name="filter" value="<?= $filter_value ?>">
                                <input type="hidden" name="limit" value="<?= $limit ?>">

                                <label class="form-label fw-semibold">Pencarian Barang</label>
                                <div class="input-group">
                                    <input type="text"
                                        name="cari"
                                        class="form-control search-input"
                                        placeholder="Cari asset / nama barang / serial number / merk..."
                                        value="<?= $search_value ?>">
                                    <button class="btn btn-warning-custom search-btn" type="submit">
                                        <i class="bi bi-search me-2"></i>Cari
                                    </button>
                                    <a href="index.php" class="btn btn-dark reset-btn">Reset</a>
                                </div>
                            </form>
                        </div>

                        <div class="col-lg-4">
                            <div class="toolbar-actions">
                                <label class="form-label fw-semibold">Mode Tampilan</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="index.php?cari=<?= urlencode($search_input) ?>&limit=<?= $limit ?>" class="btn <?= $btnSemua ?>">
                                        <i class="bi bi-grid-1x2-fill me-1"></i>Semua Barang
                                    </a>
                                    <a href="index.php?filter=masuk&cari=<?= urlencode($search_input) ?>&limit=<?= $limit ?>" class="btn <?= $btnMasuk ?>">
                                        <i class="bi bi-box-arrow-in-down me-1"></i>Barang Masuk
                                    </a>
                                    <a href="index.php?filter=keluar&cari=<?= urlencode($search_input) ?>&limit=<?= $limit ?>" class="btn <?= $btnKeluar ?>">
                                        <i class="bi bi-box-arrow-up me-1"></i>Barang Keluar
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="summary-card p-3">
                            <h6 class="text-muted">Total Inventaris</h6>
                            <h3 id="totalInventaris" class="fw-bold"><?= $total ?></h3>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="summary-card p-3">
                            <h6 class="text-muted">Barang Masuk</h6>
                            <h3 id="barangMasuk" class="fw-bold text-success"><?= $masuk ?></h3>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="summary-card p-3">
                            <h6 class="text-muted">Barang Keluar</h6>
                            <h3 id="barangKeluar" class="fw-bold text-danger"><?= $keluar ?></h3>
                        </div>
                    </div>
                </div>

                <div class="card table-card">
                    <div class="card-header bg-warning-custom">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h6 class="fw-bold mb-1">
                                    <i class="<?= $tableIcon ?> me-2"></i><?= h($tableTitle) ?>
                                </h6>
                                <div class="table-subinfo">
                                    Menampilkan <?= $from_row ?> - <?= $to_row ?> dari <?= $total_rows ?> data
                                </div>
                            </div>

                            <form method="GET" class="d-flex align-items-center gap-2 mb-0">
                                <input type="hidden" name="cari" value="<?= $search_value ?>">
                                <input type="hidden" name="filter" value="<?= $filter_value ?>">

                                <span class="section-label">Tampilkan</span>
                                <select name="limit" onchange="this.form.submit()" class="form-select form-select-sm limit-select">
                                    <option value="5" <?= $limit == 5 ? 'selected' : '' ?>>5</option>
                                    <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                                    <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                                    <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                                </select>
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
                                        $isKeluar = !empty($data['tanggal_keluar']);
                                        ?>
                                        <tr>
                                            <td><?= $no++ ?></td>

                                            <td>
                                                <div class="fw-bold"><?= h($data['no_asset'] ?? '-') ?></div>
                                                <small class="meta-muted"><?= h($data['serial_number'] ?? '-') ?></small>
                                            </td>

                                            <td>
                                                <div class="fw-semibold"><?= h($data['nama_barang'] ?? '-') ?></div>
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
                                                    <span class="meta-line"><i class="bi bi-geo-alt"></i> <?= !empty($data['branch_asal']) ? h($data['branch_asal']) : '-' ?></span>
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
                                                    <span class="meta-line"><i class="bi bi-geo-alt"></i> <?= !empty($data['branch_tujuan']) ? h($data['branch_tujuan']) : '-' ?></span>
                                                    <span class="meta-line"><i class="bi bi-truck"></i> <?= !empty($data['jasa_pengiriman']) ? h($data['jasa_pengiriman']) : '-' ?></span>
                                                    <span class="meta-line"><i class="bi bi-receipt"></i> Resi: <?= !empty($data['nomor_resi']) ? h($data['nomor_resi']) : '-' ?></span>
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

                                                    <?php if (!empty($data['catatan_pengiriman'])): ?>
                                                        <span class="meta-muted"><?= h($data['catatan_pengiriman']) ?></span>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php if (!empty($data['foto_resi'])): ?>
                                                        <img src="../assets/images/<?= h($data['foto_resi']) ?>"
                                                            class="thumb-img previewFoto"
                                                            data-foto="../assets/images/<?= h($data['foto_resi']) ?>">
                                                    <?php else: ?>
                                                        <span class="thumb-placeholder"><i class="bi bi-receipt"></i></span>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="text-center">
                                                    <div class="action-group">
                                                        <?php if (can('barang.kirim')): ?>
                                                            <button class="btn btn-sm btn-info btnEdit"
                                                                data-id="<?= $data['id'] ?>"
                                                                title="Update logistik / pengiriman">
                                                                <i class="bi bi-truck"></i>
                                                            </button>
                                                        <?php endif; ?>

                                                        <button class="btn btn-sm btn-dark" disabled title="Data barang terkunci, logistik masih bisa diupdate">
                                                            <i class="bi bi-lock-fill"></i>
                                                        </button>
                                                    </div>
                                                </td>

                                            <?php else: ?>
                                                <td>
                                                    <span class="meta-line"><i class="bi bi-geo-alt"></i> <?= !empty($data['branch_asal']) ? h($data['branch_asal']) : '-' ?></span>
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
                                                        <span class="meta-line"><i class="bi bi-calendar"></i> <?= h($data['tanggal_keluar']) ?></span>
                                                        <span class="meta-line"><i class="bi bi-geo-alt"></i> <?= !empty($data['branch_tujuan']) ? h($data['branch_tujuan']) : '-' ?></span>
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
                                                            <?php if (can('barang.kirim')): ?>
                                                                <button class="btn btn-sm btn-info btnEdit"
                                                                    data-id="<?= $data['id'] ?>"
                                                                    title="Update logistik / pengiriman">
                                                                    <i class="bi bi-truck"></i>
                                                                </button>
                                                            <?php endif; ?>

                                                            <button class="btn btn-sm btn-dark" disabled title="Data barang terkunci, logistik masih bisa diupdate">
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
                                        <td colspan="<?= $emptyColspan ?>" class="text-center text-muted py-5">
                                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                            Data tidak ditemukan.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="p-3">
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
        $('#modalCreate').on('show.bs.modal', function() {
            $.get('create.php', function(html) {
                $('#contentCreate').html(html);

                $('#contentCreate select').select2({
                    dropdownParent: $('#modalCreate'),
                    width: '100%',
                    placeholder: 'Pilih...'
                });
            }).fail(function() {
                $('#contentCreate').html('<p class="text-danger">Gagal memuat form.</p>');
            });
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
                    if (response.success || response.status === "success") {
                        Swal.fire({
                            icon: "success",
                            title: "Berhasil",
                            text: response.message || "Data berhasil ditambahkan"
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: "error",
                            title: "Gagal",
                            text: response.error || response.message || "Terjadi kesalahan"
                        });
                    }
                },
                error: function(xhr) {
                    console.log(xhr.responseText);
                    Swal.fire({
                        icon: "error",
                        title: "Error",
                        text: "Terjadi kesalahan server"
                    });
                }
            });
        });

        $(document).on("click", ".btnEdit", function() {
            let id = $(this).data("id");

            fetch("update.php?id=" + id)
                .then(res => res.text())
                .then(html => {
                    $("#contentUpdate").html(html);

                    let modal = new bootstrap.Modal(document.getElementById("modalUpdate"));
                    modal.show();

                    $('#contentUpdate select').select2({
                        dropdownParent: $('#modalUpdate'),
                        width: '100%'
                    });
                });
        });

        $(document).on("click", ".btnDelete", function() {
            let id = this.dataset.id;
            let row = this.closest('tr');

            Swal.fire({
                title: "Yakin hapus data?",
                text: "Data tidak bisa dikembalikan",
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#d33",
                confirmButtonText: "Ya hapus"
            }).then(result => {
                if (result.isConfirmed) {
                    $.getJSON('delete.php', {
                        id: id
                    }, function(response) {
                        if (response.status === "success") {
                            row.remove();

                            let total = document.getElementById("totalInventaris");
                            let masuk = document.getElementById("barangMasuk");
                            let keluar = document.getElementById("barangKeluar");

                            total.innerText = parseInt(total.innerText) - 1;

                            let keluarBadge = row.querySelector(".bg-danger");
                            if (keluarBadge) {
                                keluar.innerText = parseInt(keluar.innerText) - 1;
                            } else {
                                masuk.innerText = parseInt(masuk.innerText) - 1;
                            }

                            Swal.fire({
                                icon: "success",
                                title: "Terhapus!",
                                text: response.message
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: "error",
                                title: "Gagal!",
                                text: response.message
                            });
                        }
                    });
                }
            });
        });

        $(document).on("submit", "#formUpdate", function(e) {
            e.preventDefault();

            let formData = new FormData(this);

            $.ajax({
                url: "update.php",
                type: "POST",
                data: formData,
                contentType: false,
                processData: false,
                dataType: "json",
                success: function(response) {
                    if (response.status === "success") {
                        Swal.fire({
                            icon: "success",
                            title: "Berhasil",
                            text: response.message
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: "error",
                            title: "Gagal",
                            text: response.message || "Update gagal"
                        });
                    }
                },
                error: function(xhr) {
                    console.log(xhr.responseText);
                    Swal.fire({
                        icon: "error",
                        title: "Error",
                        text: "Terjadi error di update.php. Cek console browser."
                    });
                }
            });
        });

        $(document).on("click", ".previewFoto", function() {
            let foto = $(this).data("foto");
            $("#fotoPreview").attr("src", foto);

            let modal = new bootstrap.Modal(document.getElementById("modalFoto"));
            modal.show();
        });
    </script>
</body>

</html>