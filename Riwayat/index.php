<?php
include '../config/koneksi.php';

$filter = $_GET['filter'] ?? 'semua';
$tanggal_awal = $_GET['tanggal_awal'] ?? '';
$tanggal_akhir = $_GET['tanggal_akhir'] ?? '';

$where = "WHERE 1=1";

if ($filter == "masuk") {
    $where .= " AND barang.tanggal_masuk IS NOT NULL";
}if ($filter == "keluar") {
    $where .= " AND barang.tanggal_keluar IS NOT NULL";
}if (!empty($tanggal_awal) && !empty($tanggal_akhir)) {
    $where .= " AND DATE(barang.tanggal_masuk) BETWEEN '$tanggal_awal' AND '$tanggal_akhir'";
}

$q1 = mysqli_query($koneksi, "SELECT COUNT(*) as total FROM barang");
$total_semua = mysqli_fetch_assoc($q1)['total'];

$q2 = mysqli_query($koneksi, "SELECT COUNT(*) as total FROM barang WHERE tanggal_masuk IS NOT NULL");
$total_masuk = mysqli_fetch_assoc($q2)['total'];

$q3 = mysqli_query($koneksi, "SELECT COUNT(*) as total FROM barang WHERE tanggal_keluar IS NOT NULL");
$total_keluar = mysqli_fetch_assoc($q3)['total'];

$query = mysqli_query($koneksi, "
SELECT barang.*, 
tb_barang.nama_barang,
tb_merk.nama_merk,
tb_tipe.nama_tipe,
tb_branch.nama_branch,
tb_status.nama_status,
tb_jenis.nama_jenis

FROM barang

LEFT JOIN tb_barang ON barang.id_barang = tb_barang.id_barang
LEFT JOIN tb_merk ON barang.id_merk = tb_merk.id_merk
LEFT JOIN tb_tipe ON barang.id_tipe = tb_tipe.id_tipe
LEFT JOIN tb_branch ON barang.id_branch = tb_branch.id_branch
LEFT JOIN tb_status ON barang.id_status = tb_status.id_status
LEFT JOIN tb_jenis ON barang.id_jenis = tb_jenis.id_jenis

$where

ORDER BY barang.tanggal_masuk DESC
");

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Riwayat Asset</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <style>
        body {
            background: #f5f6fa;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
        }

        .header-page {
            font-weight: 600;
            color: #ffc107;
        }
    </style>

</head>

<body>

    <div class="container-fluid">

        <div class="row">

            <?php include '../layout/sidebar.php'; ?>

            <div class="col-md-10 p-4">

                <h3 class="header-page mb-4">
                    <i class="bi bi-clock-history"></i>
                    Riwayat Aktivitas Asset
                </h3>

                <div class="row mb-4">
                    <div class="col-md-4">
                        <a href="index.php" style="text-decoration:none">
                            <div class="card p-3">
                                <h6>Semua Aktivitas</h6>
                                <h3><?= $total_semua ?></h3>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-4">
                        <a href="index.php?filter=masuk" style="text-decoration:none">
                            <div class="card p-3">
                                <h6 class="text-primary">Barang Masuk</h6>
                                <h3 class="text-primary"><?= $total_masuk ?></h3>
                            </div>
                        </a>
                    </div>

                    <div class="col-md-4">
                        <a href="index.php?filter=keluar" style="text-decoration:none">
                            <div class="card p-3">
                                <h6 class="text-danger">Barang Keluar</h6>
                                <h3 class="text-danger"><?= $total_keluar ?></h3>
                            </div>
                        </a>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body">
                        <form method="GET">
                            <div class="row">
                                <div class="col-md-3">
                                    <label>Tanggal Awal</label>
                                    <input type="date" name="tanggal_awal" class="form-control">
                                </div>

                                <div class="col-md-3">
                                    <label>Tanggal Akhir</label>
                                    <input type="date" name="tanggal_akhir" class="form-control">
                                </div>

                                <div class="col-md-3">
                                    <label>Filter Aktivitas</label>
                                    <select name="filter" class="form-control">
                                        <option value="semua">Semua Aktivitas</option>
                                        <option value="masuk">Barang Masuk</option>
                                        <option value="keluar">Barang Keluar</option>
                                    </select>
                                </div>


                                <div class="col-md-3 d-flex align-items-end">
                                    <button class="btn btn-warning w-100">
                                        <i class="bi bi-search"></i>
                                        Filter
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-warning">
                        <b>Daftar Riwayat Asset</b>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-dark">
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
                                <?php
                                $no = 1;
                                while ($data = mysqli_fetch_assoc($query)) {
                                ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <b><?= $data['no_asset'] ?></b><br>
                                            <small><?= $data['serial_number'] ?></small>
                                        </td>

                                        <td>
                                            <b><?= $data['nama_barang'] ?></b><br>
                                            <span class="badge bg-light text-dark border">
                                                <?= $data['nama_merk'] ?>
                                            </span>
                                        </td>

                                        <td>
                                            <small>
                                                <b>Tipe :</b> <?= $data['nama_tipe'] ?><br>
                                                <b>Jenis :</b> <?= $data['nama_jenis'] ?>
                                            </small>

                                        </td>

                                        <td>
                                            <small>
                                                <b>Masuk :</b> <?= $data['tanggal_masuk'] ?><br>
                                                <b>Keluar :</b> <?= $data['tanggal_keluar'] ?: '-' ?>
                                            </small>
                                        </td>

                                        <td>
                                            <small>
                                                <i class="bi bi-geo-alt"></i>
                                                <?= $data['nama_branch'] ?><br>
                                                <i class="bi bi-person"></i>
                                                <?= $data['user'] ?: '-' ?>

                                            </small>
                                        </td>

                                        <td>
                                            <?php if ($data['bermasalah'] == "Iya") { ?>
                                                <span class="badge bg-danger">Bermasalah</span>
                                            <?php } else { ?>
                                                <span class="badge bg-success">Normal</span>
                                            <?php } ?>
                                            <br>
                                            <small><?= $data['nama_status'] ?></small>
                                        </td>

                                        <td>
                                            <?php
                                            if ($data['bermasalah'] == "Iya") {
                                                echo "<span class='text-danger'>" . $data['keterangan_masalah'] . "</span>";
                                            } else {
                                                echo "-";
                                            }
                                            ?>
                                        </td>

                                        <td>
                                            <?php
                                            if (!empty($data['foto'])) {
                                            ?>
                                                <img src="../assets/images/<?= $data['foto'] ?>" width="50">

                                            <?php
                                            } else {

                                                echo "-";
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div> 
</body>
</html>