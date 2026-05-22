<?php
/** @var mysqli $koneksi */ 
require_once __DIR__ . '/../config/auth.php';

if (file_exists(__DIR__ . '/../config/database.php')) {
    require_once __DIR__ . '/../config/database.php';
} else {
    $koneksi = mysqli_connect("localhost", "root", "" , "it_hexindo");
}

require_admin();

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

// Query untuk mengambil info cabang/user
$query_user = mysqli_query($koneksi, "SELECT u.username, u.id_branch, b.nama_branch FROM users u JOIN tb_branch b ON u.id_branch = b.id_branch WHERE u.id = '$user_id'");
$data_user = mysqli_fetch_assoc($query_user);

if (!$data_user) {
    echo "Data tidak ditemukan.";
    exit;
}

$branch_id = (int) $data_user['id_branch'];

// Query mengambil detail barang milik cabang tersebut
// Kita JOIN ke tabel master (barang, merk, tipe, status) agar tampil nama, bukan ID
$query_barang = "SELECT 
                    brg.*, 
                    tb.nama_barang,
                    m.nama_merk, 
                    t.nama_tipe,
                    s.nama_status
                 FROM barang brg
                 LEFT JOIN tb_barang tb ON brg.id_barang = tb.id_barang
                 LEFT JOIN tb_merk m ON brg.id_merk = m.id_merk
                 LEFT JOIN tb_tipe t ON brg.id_tipe = t.id_tipe
                 LEFT JOIN tb_status s ON brg.id_status = s.id_status
                 WHERE brg.id_branch = '$branch_id'";
$result_barang = mysqli_query($koneksi, $query_barang);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Detail Asset Cabang | IT Asset</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; }
        
        /* Gaya Tabel sesuai Refrensi Anda */
        .inventory-header {
            background-color: #111;
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 15px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .table-custom {
            background: white;
            border-radius: 0 0 15px 15px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .table thead th {
            background: white;
            color: #333;
            font-weight: 700;
            border-bottom: 2px solid #eee;
            padding: 15px;
        }
        .table tbody td { padding: 15px; vertical-align: middle; border-bottom: 1px solid #f1f1f1; }

        /* Badge Styling */
        .badge-merk { 
            background: transparent; border: 1px solid #ddd; color: #666; 
            padding: 4px 10px; border-radius: 8px; font-size: 0.75rem; font-weight: 600;
        }
        .badge-kondisi {
            border-radius: 20px; padding: 5px 15px; font-size: 0.75rem; font-weight: 700;
        }
        .sn-text { color: #999; font-size: 0.85rem; }
        .asset-id { font-weight: 800; font-size: 1rem; color: #111; }
        
        .img-preview {
            width: 60px; height: 60px; object-fit: cover; border-radius: 10px; border: 1px solid #eee;
        }

        /* Hero Mini Info */
        .info-cabang {
            background: white; border-radius: 15px; padding: 20px; margin-bottom: 20px;
            border-left: 5px solid #ff8f00; box-shadow: 0 4px 10px rgba(0,0,0,0.03);
        }
    </style>
</head>
<body class="d-flex">

    <?php include __DIR__ . '/../layout/sidebar.php'; ?>

    <div id="mainContent" class="content-with-sidebar flex-grow-1 p-4">
        
        <!-- Header Navigasi -->
        <div class="mb-4 d-flex align-items-center justify-content-between">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-warning">Monitoring</a></li>
                    <li class="breadcrumb-item active">Detail Asset Cabang</li>
                </ol>
            </nav>
            <a href="index.php" class="btn btn-sm btn-outline-secondary rounded-pill">
                <i class="bi bi-arrow-left me-1"></i> Kembali
            </a>
        </div>

        <!-- Info Cabang -->
        <div class="info-cabang">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h5 class="fw-bold mb-1"><i class="bi bi-geo-alt-fill text-warning me-2"></i><?= h($data_user['nama_branch']) ?></h5>
                    <p class="text-muted small mb-0">Admin Pengelola: <strong><?= h($data_user['username']) ?></strong></p>
                </div>
                <div class="col-md-6 text-md-end">
                    <span class="text-muted small">Total Unit di Cabang ini:</span>
                    <h4 class="fw-bold text-dark"><?= mysqli_num_rows($result_barang) ?> Unit</h4>
                </div>
            </div>
        </div>

        <!-- Tabel Inventaris (Sesuai Referensi) -->
        <div class="inventory-header">
            <div class="d-flex align-items-center">
                <i class="bi bi-box-seam text-warning me-2 fs-5"></i>
                <h5 class="mb-0 fw-bold">Daftar Inventaris Cabang</h5>
            </div>
            <div class="small opacity-75">Menampilkan <?= mysqli_num_rows($result_barang) ?> data asset</div>
        </div>

        <div class="table-custom">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th width="50">No</th>
                            <th>Asset & Identitas</th>
                            <th>Spesifikasi</th>
                            <th>Lokasi / User</th>
                            <th>Kondisi</th>
                            <th class="text-center">Foto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        if(mysqli_num_rows($result_barang) == 0): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">Cabang ini belum memiliki data asset.</td></tr>
                        <?php else:
                            while($row = mysqli_fetch_assoc($result_barang)): 
                        ?>
                        <tr>
                            <td class="text-muted"><?= $no++ ?></td>
                            <td>
                                <!-- Nama Barang & No Asset (Identitas) -->
                                <div class="fw-bold text-uppercase" style="font-size: 0.85rem; color: #555;"><?= h($row['nama_barang'] ?? 'UNKNOWN') ?></div>
                                <div class="asset-id"><?= h($row['no_asset']) ?></div>
                                <div class="sn-text">SN: <?= h($row['serial_number']) ?></div>
                            </td>
                            <td>
                                <!-- Spesifikasi -->
                                <span class="badge-merk mb-2 d-inline-block"><?= h($row['nama_merk'] ?? 'NO BRAND') ?></span>
                                <div class="small text-muted">Tipe: <?= h($row['nama_tipe'] ?? '-') ?></div>
                            </td>
                            <td>
                                <!-- Lokasi / User -->
                                <div class="small mb-1"><i class="bi bi-geo-alt text-danger me-1"></i><?= h($data_user['nama_branch']) ?></div>
                                <div class="small fw-bold"><i class="bi bi-person text-muted me-1"></i><?= h($row['user'] ?? 'User') ?></div>
                            </td>
                            <td>
                                <!-- Kondisi -->
                                <?php if($row['bermasalah'] == 'Iya'): ?>
                                    <span class="badge bg-danger badge-kondisi mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Bermasalah</span>
                                    <div class="text-danger small fw-bold" style="font-size: 0.7rem;"><?= h($row['keterangan_masalah']) ?></div>
                                <?php else: ?>
                                    <span class="badge bg-success badge-kondisi"><i class="bi bi-check-circle me-1"></i> Normal</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <!-- Foto -->
                                <?php if(!empty($row['foto'])): ?>
                                    <img src="<?= base_url('assets/images/' . $row['foto']) ?>" class="img-preview" alt="asset">
                                <?php else: ?>
                                    <div class="bg-light rounded d-flex align-items-center justify-content-center" style="width: 60px; height: 60px; margin: 0 auto;">
                                        <i class="bi bi-image text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>