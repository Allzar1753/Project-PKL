<?php
include "../config/koneksi.php";
require_permission($koneksi, 'riwayat.view');



$periode = $_GET['periode'] ?? "";

$query = "
SELECT 
barang.*,
tb_barang.nama_barang,
tb_merk.nama_merk,
tb_tipe.nama_tipe,
tb_jenis.nama_jenis,
tb_status.nama_status,
tb_branch.nama_branch

FROM barang

LEFT JOIN tb_barang ON barang.id_barang = tb_barang.id_barang
LEFT JOIN tb_merk ON barang.id_merk = tb_merk.id_merk
LEFT JOIN tb_tipe ON barang.id_tipe = tb_tipe.id_tipe
LEFT JOIN tb_jenis ON barang.id_jenis = tb_jenis.id_jenis
LEFT JOIN tb_status ON barang.id_status = tb_status.id_status
LEFT JOIN tb_branch ON barang.id_branch = tb_branch.id_branch
";

if ($periode == "harian") {

    $query .= " WHERE DATE(barang.tanggal_masuk)=CURDATE()";
} elseif ($periode == "mingguan") {

    $query .= " WHERE YEARWEEK(barang.tanggal_masuk,1)=YEARWEEK(CURDATE(),1)";
} elseif ($periode == "bulanan") {

    $query .= " WHERE MONTH(barang.tanggal_masuk)=MONTH(CURDATE())
AND YEAR(barang.tanggal_masuk)=YEAR(CURDATE())";
} elseif ($periode == "tahunan") {

    $query .= " WHERE YEAR(barang.tanggal_masuk)=YEAR(CURDATE())";
}

$data = mysqli_query($koneksi, $query);
?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">
    <title>Riwayat Barang Masuk</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <style>
        :root {
            --primary: #ffc107;
            --dark: #212529;
        }

        body {
            background: #f4f6f9;
            font-family: 'Segoe UI', sans-serif;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .bg-warning-custom {
            background: var(--primary);
        }
    </style>

</head>

<body>

    <div class="container py-5">

        <div class="card">

            <div class="card-header bg-warning-custom fw-bold">

                <i class="bi bi-clock-history me-2"></i>
                Riwayat Barang Masuk

            </div>

            <div class="card-body">

                <form method="GET" class="row mb-4">

                    <div class="col-md-3">

                        <select name="periode" class="form-control">

                            <option value="">Semua Data</option>

                            <option value="harian" <?= $periode == "harian" ? "selected" : "" ?>>Harian</option>

                            <option value="mingguan" <?= $periode == "mingguan" ? "selected" : "" ?>>Mingguan</option>

                            <option value="bulanan" <?= $periode == "bulanan" ? "selected" : "" ?>>Bulanan</option>

                            <option value="tahunan" <?= $periode == "tahunan" ? "selected" : "" ?>>Tahunan</option>

                        </select>

                    </div>

                    <div class="col-md-2">

                        <button class="btn btn-warning">
                            <i class="bi bi-search"></i>
                            Tampilkan
                        </button>

                    </div>

                </form>

                <div class="table-responsive">

                    <table class="table table-bordered table-striped">

                        <thead class="table-dark">

                            <tr>

                                <th>No</th>
                                <th>No Asset</th>
                                <th>Nama Barang</th>
                                <th>Merk</th>
                                <th>Serial Number</th>
                                <th>Tipe</th>
                                <th>Jenis</th>
                                <th>Branch</th>
                                <th>Status</th>
                                <th>Tanggal Masuk</th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php
                            $no = 1;

                            while ($row = mysqli_fetch_assoc($data)) {
                            ?>

                                <tr>

                                    <td><?= $no++ ?></td>

                                    <td><?= $row['no_asset'] ?></td>

                                    <td><?= $row['nama_barang'] ?></td>

                                    <td><?= $row['nama_merk'] ?></td>

                                    <td><?= $row['serial_number'] ?></td>

                                    <td><?= $row['nama_tipe'] ?></td>

                                    <td><?= $row['nama_jenis'] ?></td>

                                    <td><?= $row['nama_branch'] ?></td>

                                    <td><?= $row['nama_status'] ?></td>

                                    <td><?= $row['tanggal_masuk'] ?></td>

                                </tr>

                            <?php } ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

</body>

</html>