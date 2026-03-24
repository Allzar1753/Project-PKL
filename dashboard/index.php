<?php
include '../config/koneksi.php';
require_permission($koneksi, 'dashboard.view');
$search = isset($_GET['cari']) ? $_GET['cari'] : "";

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

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

FROM barang

LEFT JOIN tb_barang ON barang.id_barang = tb_barang.id_barang
LEFT JOIN tb_merk ON barang.id_merk = tb_merk.id_merk
LEFT JOIN tb_tipe ON barang.id_tipe = tb_tipe.id_tipe
LEFT JOIN tb_status ON barang.id_status = tb_status.id_status
LEFT JOIN tb_jenis ON barang.id_jenis = tb_jenis.id_jenis

LEFT JOIN tb_branch AS branch_asal 
ON barang.id_branch = branch_asal.id_branch

LEFT JOIN tb_branch AS branch_tujuan 
ON barang.tujuan = branch_tujuan.id_branch

$where_sql
ORDER BY barang.id DESC
LIMIT $offset, $limit
");

if (!$query) {
    die(mysqli_error($koneksi));
}

$count_query = mysqli_query($koneksi, "
    SELECT COUNT(*) as total
    FROM barang
    LEFT JOIN tb_barang ON barang.id_barang = tb_barang.id_barang
    LEFT JOIN tb_merk ON barang.id_merk = tb_merk.id_merk
    LEFT JOIN tb_tipe ON barang.id_tipe = tb_tipe.id_tipe
    LEFT JOIN tb_branch ON barang.id_branch = tb_branch.id_branch
    LEFT JOIN tb_status ON barang.id_status = tb_status.id_status
    LEFT JOIN tb_jenis ON barang.id_jenis = tb_jenis.id_jenis
    WHERE 
    tb_barang.nama_barang LIKE '%$search%'
    OR barang.no_asset LIKE '%$search%'
    OR barang.serial_number LIKE '%$search%'
    OR tb_merk.nama_merk LIKE '%$search%'
    OR tb_tipe.nama_tipe LIKE '%$search%'
    OR tb_branch.nama_branch LIKE '%$search%'
    OR tb_status.nama_status LIKE '%$search%'
    OR tb_jenis.nama_jenis LIKE '%$search%'
    OR barang.keterangan_masalah LIKE '%$search%'
    OR barang.tanggal_masuk LIKE '%$search%'
    OR barang.tanggal_keluar LIKE '%$search%'
    OR barang.bermasalah LIKE '%$search%'
    OR barang.user LIKE '%$search%'
");

$total_rows = mysqli_fetch_assoc($count_query)['total'];
$total_pages = ceil($total_rows / $limit);
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Peralatan IT - Asset Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        .bg-warning-custom {
            background-color: #ffc107 !important;
        }

        .text-warning-custom {
            color: #ffc107 !important;
        }

        .card-header-custom {
            background-color: #ffc107;
            color: #212529;
            font-weight: bold;
            border: none;
        }

        .btn-warning-custom {
            background-color: #ffc107;
            border: none;
            color: #212529;
            font-weight: 600;
        }

        .btn-warning-custom:hover {
            background-color: #e0a800;
        }

        .table thead {
            background-color: #212529;
            color: white;
        }

        .badge-normal {
            background-color: #198754;
        }

        .badge-masalah {
            background-color: #dc3545;
        }

        .img-thumbnail-custom {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
        }
    </style>
</head>

<body class="bg-light">
    <div class="container-fluid">
        <div class="row">

            <?php include '../layout/sidebar.php'; ?>

            <div class="col-md-10 p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h3 class="fw-bold text-warning-custom mb-0">Data Peralatan IT</h3>
                        <p class="text-muted mb-0">Manajemen Inventaris Aset Teknologi</p>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <form method="GET">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0">
                                    <i class="bi bi-search text-muted"></i>
                                </span>
                                <input type="text" name="cari" class="form-control border-start-0"
                                    placeholder="Cari asset / nama barang / serial number / merk..."
                                    value="<?= $search ?>">
                                <button class="btn btn-warning-custom px-4" type="submit">Cari</button>
                                <a href="index.php" class="btn btn-dark px-4">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header card-header-custom py-3">
                        <i class="bi bi-list-ul me-2"></i> Daftar Inventaris Barang
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <form method="GET" class="mb-3">
                                <input type="hidden" name="cari" value="<?= $search ?>">

                                <select name="limit" onchange="this.form.submit()" class="form-select w-auto">
                                    <option value="5" <?= $limit == 5 ? 'selected' : '' ?>>5</option>
                                    <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                                    <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                                    <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                                </select>
                            </form>
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-3">No</th>
                                        <th>Info Asset</th>
                                        <th>Spesifikasi</th>
                                        <th>Logistik</th>
                                        <th>Kondisi</th>
                                        <th>Status & Lokasi</th>
                                        <th>User</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $no = $offset + 1;
                                    if (mysqli_num_rows($query) > 0) :
                                        while ($data = mysqli_fetch_assoc($query)) :
                                    ?>
                                            <tr>
                                                <td class="ps-3 text-muted"><?= $no++; ?></td>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= $data['no_asset']; ?></div>
                                                    <small class="text-muted font-monospace"><?= $data['serial_number']; ?></small>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?= $data['nama_barang']; ?></div>
                                                    <div class="d-flex gap-1 mt-1">
                                                        <span class="badge bg-light text-dark border"><?= $data['nama_merk']; ?></span>
                                                        <small class="text-muted italic"><?= $data['nama_tipe']; ?></small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (!empty($data['tanggal_masuk']) && $data['tanggal_masuk'] != "0000-00-00") : ?>
                                                        <small class="d-block text-muted">
                                                            <i class="bi bi-calendar-check text-success me-1"></i>
                                                            <?= date('d/m/Y', strtotime($data['tanggal_masuk'])); ?>
                                                        </small>
                                                    <?php endif; ?>

                                                    <?php if (!empty($data['tanggal_keluar']) && $data['tanggal_keluar'] != "0000-00-00") : ?>
                                                        <small class="d-block text-muted">
                                                            <i class="bi bi-calendar-x text-danger me-1"></i>
                                                            <?= date('d/m/Y', strtotime($data['tanggal_keluar'])); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($data['bermasalah'] == "Iya") : ?>
                                                        <span class="badge badge-masalah">Bermasalah</span>
                                                        <div class="small text-danger mt-1 italic"><?= $data['keterangan_masalah']; ?></div>
                                                    <?php else : ?>
                                                        <span class="badge badge-normal">Normal</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="small fw-bold"><?= $data['nama_status']; ?></div>
                                                    <div class="small text-muted"><i class="bi bi-geo-alt me-1"></i><?php
                                                         if (!empty($data['tanggal_keluar'])) {
                                                             echo $data['branch_tujuan'];
                                                            } else {
                                                                echo $data['branch_asal'];
                                                                    }
                                                            ?></div>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <i class="bi bi-person-circle me-2 text-secondary"></i>
                                                        <span class="small"><?= $data['user'] ? $data['user'] : '-'; ?></span>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php
                                        endwhile;
                                    else:
                                        ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-5 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                Data tidak ditemukan
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <nav class="mt-3">
                                <ul class="pagination">

                                    <?php for ($i = 1; $i <= $total_pages; $i++) : ?>

                                        <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                            <a class="page-link" href="?page=<?= $i ?>&limit=<?= $limit ?>&cari=<?= $search ?>">
                                                <?= $i ?>
                                            </a>
                                        </li>

                                    <?php endfor; ?>

                                </ul>
                            </nav>
                        </div>
                    </div>
                    <div class="card-footer bg-white border-0 py-3">
                        <small class="text-muted">Menampilkan total <?= $total_rows ?> aset IT.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>