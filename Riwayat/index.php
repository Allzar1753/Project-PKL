<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'riwayat.view');

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$filter = isset($_GET['filter']) ? trim($_GET['filter']) : 'semua';
$tanggal_awal = isset($_GET['tanggal_awal']) ? trim($_GET['tanggal_awal']) : '';
$tanggal_akhir = isset($_GET['tanggal_akhir']) ? trim($_GET['tanggal_akhir']) : '';

if (!in_array($filter, ['semua', 'masuk', 'keluar'], true)) {
    $filter = 'semua';
}

$where = [];

if ($filter === 'masuk') {
    $where[] = "barang.tanggal_masuk IS NOT NULL";
} elseif ($filter === 'keluar') {
    $where[] = "barang.tanggal_keluar IS NOT NULL";
}

if ($tanggal_awal !== '' && $tanggal_akhir !== '') {
    if ($filter === 'keluar') {
        $where[] = "DATE(barang.tanggal_keluar) BETWEEN '" . mysqli_real_escape_string($koneksi, $tanggal_awal) . "' 
                    AND '" . mysqli_real_escape_string($koneksi, $tanggal_akhir) . "'";
    } else {
        $where[] = "DATE(barang.tanggal_masuk) BETWEEN '" . mysqli_real_escape_string($koneksi, $tanggal_awal) . "' 
                    AND '" . mysqli_real_escape_string($koneksi, $tanggal_akhir) . "'";
    }
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

$total_semua = (int) mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT COUNT(*) as total FROM barang"
))['total'];

$total_masuk = (int) mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT COUNT(*) as total FROM barang WHERE tanggal_masuk IS NOT NULL"
))['total'];

$total_keluar = (int) mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT COUNT(*) as total FROM barang WHERE tanggal_keluar IS NOT NULL"
))['total'];

$query = mysqli_query($koneksi, "
    SELECT 
        barang.*, 
        tb_barang.nama_barang,
        tb_merk.nama_merk,
        tb_tipe.nama_tipe,
        tb_status.nama_status,
        tb_jenis.nama_jenis,
        branch_asal.nama_branch AS branch_asal,
        branch_tujuan.nama_branch AS branch_tujuan
    FROM barang
    LEFT JOIN tb_barang ON barang.id_barang = tb_barang.id_barang
    LEFT JOIN tb_merk ON barang.id_merk = tb_merk.id_merk
    LEFT JOIN tb_tipe ON barang.id_tipe = tb_tipe.id_tipe
    LEFT JOIN tb_status ON barang.id_status = tb_status.id_status
    LEFT JOIN tb_jenis ON barang.id_jenis = tb_jenis.id_jenis
    LEFT JOIN tb_branch AS branch_asal ON barang.id_branch = branch_asal.id_branch
    LEFT JOIN tb_branch AS branch_tujuan ON barang.tujuan = branch_tujuan.id_branch
    $where_sql
    ORDER BY barang.id DESC
");

if (!$query) {
    die(mysqli_error($koneksi));
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Asset</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <style>
        :root {
            --primary: #ffc107;
            --dark: #212529;
            --muted: #6c757d;
        }

        body {
            background: #f5f7fb;
            font-family: 'Inter', sans-serif;
        }

        .text-warning-custom {
            color: var(--primary);
        }

        .toolbar-card,
        .summary-card,
        .table-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.05);
            background: #fff;
        }

        .summary-card {
            height: 100%;
        }

        .summary-card h3 {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 0;
        }

        .bg-warning-custom {
            background: var(--primary);
        }

        .table-card .card-header {
            border-bottom: none;
            padding: 1rem 1.25rem;
            border-radius: 16px 16px 0 0;
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: #212529;
            color: #fff;
            font-weight: 700;
            white-space: nowrap;
            border-bottom: none;
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
            font-size: 0.88rem;
            color: var(--muted);
            line-height: 1.45;
        }

        .thumb-img {
            width: 54px;
            height: 54px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid #dee2e6;
        }

        .thumb-placeholder {
            width: 54px;
            height: 54px;
            border-radius: 10px;
            border: 1px dashed #ced4da;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #adb5bd;
            background: #fff;
        }

        .filter-btn {
            background: var(--primary);
            border: none;
            color: #212529;
            font-weight: 700;
            border-radius: 10px;
            padding: 0.75rem 1rem;
        }

        .filter-btn:hover {
            background: #e0a800;
        }

        .section-subinfo {
            color: var(--muted);
            font-size: 0.9rem;
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <?php require_once '../layout/sidebar.php'; ?>

            <div class="col-md-10 p-4">
                <div class="mb-4">
                    <h3 class="fw-bold text-warning-custom mb-1">
                        <i class="bi bi-clock-history me-2"></i>Riwayat Aktivitas Asset
                    </h3>
                    <p class="text-muted mb-0">Pantau histori pergerakan inventaris barang masuk dan keluar</p>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <a href="index.php" class="text-decoration-none">
                            <div class="summary-card p-3">
                                <h6 class="text-dark">Semua Aktivitas</h6>
                                <h3><?= $total_semua ?></h3>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-4">
                        <a href="index.php?filter=masuk" class="text-decoration-none">
                            <div class="summary-card p-3">
                                <h6 class="text-primary">Barang Masuk</h6>
                                <h3 class="text-primary"><?= $total_masuk ?></h3>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-4">
                        <a href="index.php?filter=keluar" class="text-decoration-none">
                            <div class="summary-card p-3">
                                <h6 class="text-danger">Barang Keluar</h6>
                                <h3 class="text-danger"><?= $total_keluar ?></h3>
                            </div>
                        </a>
                    </div>
                </div>

                <div class="toolbar-card p-3 mb-4">
                    <form method="GET">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Tanggal Awal</label>
                                <input type="date" name="tanggal_awal" class="form-control" value="<?= h($tanggal_awal) ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Tanggal Akhir</label>
                                <input type="date" name="tanggal_akhir" class="form-control" value="<?= h($tanggal_akhir) ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Filter Aktivitas</label>
                                <select name="filter" class="form-select">
                                    <option value="semua" <?= $filter === 'semua' ? 'selected' : '' ?>>Semua Aktivitas</option>
                                    <option value="masuk" <?= $filter === 'masuk' ? 'selected' : '' ?>>Barang Masuk</option>
                                    <option value="keluar" <?= $filter === 'keluar' ? 'selected' : '' ?>>Barang Keluar</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <button class="filter-btn w-100">
                                    <i class="bi bi-search me-1"></i>Filter
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="table-card">
                    <div class="card-header bg-warning-custom">
                        <div>
                            <h6 class="fw-bold mb-1">Daftar Riwayat Asset</h6>
                            <div class="section-subinfo">Menampilkan histori aktivitas inventaris berdasarkan filter yang dipilih</div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Asset & SN</th>
                                    <th>Nama Barang</th>
                                    <th>Detail</th>
                                    <th>Tanggal</th>
                                    <th>Logistik</th>
                                    <th>Status</th>
                                    <th>Masalah</th>
                                    <th>Foto</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php $no = 1; ?>
                                <?php if (mysqli_num_rows($query) > 0): ?>
                                    <?php while ($data = mysqli_fetch_assoc($query)): ?>
                                        <tr>
                                            <td><?= $no++ ?></td>

                                            <td>
                                                <div class="fw-bold"><?= h($data['no_asset'] ?? '-') ?></div>
                                                <small class="meta-muted"><?= h($data['serial_number'] ?? '-') ?></small>
                                            </td>

                                            <td>
                                                <div class="fw-semibold"><?= h($data['nama_barang'] ?? '-') ?></div>
                                                <span class="badge bg-light text-dark border"><?= h($data['nama_merk'] ?? '-') ?></span>
                                            </td>

                                            <td>
                                                <span class="meta-line"><b>Tipe:</b> <?= h($data['nama_tipe'] ?? '-') ?></span>
                                                <span class="meta-line"><b>Jenis:</b> <?= h($data['nama_jenis'] ?? '-') ?></span>
                                            </td>

                                            <td>
                                                <span class="meta-line"><b>Masuk:</b> <?= h($data['tanggal_masuk'] ?? '-') ?></span>
                                                <span class="meta-line"><b>Keluar:</b> <?= !empty($data['tanggal_keluar']) ? h($data['tanggal_keluar']) : '-' ?></span>
                                            </td>

                                            <td>
                                                <span class="meta-line">
                                                    <i class="bi bi-geo-alt me-1"></i>
                                                    <?= !empty($data['tanggal_keluar']) ? h($data['branch_tujuan'] ?? '-') : h($data['branch_asal'] ?? '-') ?>
                                                </span>
                                                <span class="meta-muted">
                                                    <i class="bi bi-person me-1"></i><?= h($data['user'] ?? '-') ?>
                                                </span>
                                            </td>

                                            <td>
                                                <div class="mb-2">
                                                    <?php if (($data['bermasalah'] ?? '') === 'Iya'): ?>
                                                        <span class="badge bg-danger">Bermasalah</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">Normal</span>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="meta-muted"><?= h($data['nama_status'] ?? '-') ?></span>
                                            </td>

                                            <td>
                                                <?php if (($data['bermasalah'] ?? '') === 'Iya'): ?>
                                                    <span class="text-danger"><?= h($data['keterangan_masalah'] ?? '-') ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <?php if (!empty($data['foto'])): ?>
                                                    <img src="../assets/images/<?= h($data['foto']) ?>" class="thumb-img" alt="Foto Barang">
                                                <?php else: ?>
                                                    <span class="thumb-placeholder"><i class="bi bi-image"></i></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-5 text-muted">
                                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                            Data riwayat tidak ditemukan.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</body>
</html>