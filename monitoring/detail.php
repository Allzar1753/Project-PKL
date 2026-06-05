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

function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Asset Cabang | IT Asset Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- SINKRONISASI TEMA HEXINDO -->
    <style>
        :root {
            --orange-1: #E64312; 
            --orange-2: #F25C05;
            --dark-1: #231F20;
            --text-main: #333333;
            --text-soft: #666666;
            --surface-bg: #F4F6F9;
            --border-soft: #E0E4E8;
            --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.04);
            --radius-xl: 8px; /* Industrial Sharp Edges */
        }

        body { 
            background-color: var(--surface-bg); 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            color: var(--text-main); 
            min-height: 100vh;
        }

        .page-shell { padding: 24px 32px; }

        /* Navigasi Breadcrumb Modern */
        .breadcrumb-item a { color: var(--text-soft); font-weight: 600; transition: 0.2s; }
        .breadcrumb-item a:hover { color: var(--orange-1); }
        .breadcrumb-item.active { color: var(--dark-1); font-weight: 700; }
        .breadcrumb-item + .breadcrumb-item::before { content: "›"; color: var(--border-soft); }
        
        .btn-back {
            background-color: #fff;
            border: 1px solid var(--border-soft);
            color: var(--text-main);
            font-weight: 600;
            padding: 0.4rem 1rem;
            border-radius: 6px;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        .btn-back:hover { background-color: var(--surface-bg); border-color: #d1d5db; color: var(--dark-1); }

        /* Hero Mini Info (Hexindo Style) */
        .info-cabang {
            background: #fff; 
            border-radius: var(--radius-xl); 
            padding: 1.5rem 2rem; 
            margin-bottom: 1.5rem;
            border: 1px solid var(--border-soft);
            border-left: 4px solid var(--orange-1); 
            box-shadow: var(--shadow-soft);
        }
        
        /* Tabel Container */
        .ui-card {
            background: #fff;
            border: 1px solid var(--border-soft);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-soft);
            overflow: hidden;
        }
        
        /* Card Header Tabel Bersih */
        .inventory-header {
            background-color: #fff;
            color: var(--dark-1);
            border-bottom: 1px solid var(--border-soft);
            padding: 1.2rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Tabel Styling */
        .table { margin-bottom: 0; }
        .table > :not(caption) > * > * { padding: 1rem 1.5rem; border-bottom-color: var(--border-soft); }
        .table thead th {
            background-color: #f9fafb !important;
            color: var(--text-soft);
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }
        
        /* Teks dalam tabel */
        .asset-code { font-weight: 700; color: var(--dark-1); font-size: 0.95rem; margin-top: 2px;}
        .meta-line  { display: block; font-size: 0.85rem; color: var(--text-main); margin-bottom: 2px;}
        .meta-muted { display: block; font-size: 0.8rem; color: var(--text-soft); }
        .meta-muted i, .meta-line i { color: #9ca3af; margin-right: 4px;}

        /* Badge Merk & Status (Soft Badges Hexindo) */
        .badge-merk { 
            background: #f3f4f6; border: 1px solid #d1d5db; color: #4b5563; 
            padding: 0.3rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;
        }
        .badge.rounded-pill { padding: 0.4em 0.8em; font-weight: 600; font-size: 0.75rem; letter-spacing: 0.3px; }
        .badge-soft-success { background-color: rgba(16, 185, 129, 0.15); color: #059669; }
        .badge-soft-danger { background-color: rgba(239, 68, 68, 0.15); color: #b91c1c; }
        
        /* Thumbnail Foto */
        .img-preview {
            width: 55px; height: 55px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-soft);
        }
        .thumb-placeholder {
            width: 55px; height: 55px; background: #f9fafb; border: 1px dashed #d1d5db; border-radius: 6px;
            display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 1.2rem;
            margin: 0 auto;
        }
    </style>
</head>
<body class="d-flex">

    <!-- Sidebar -->
    <?php include __DIR__ . '/../layout/sidebar.php'; ?>

    <!-- Main Content -->
    <div id="mainContent" class="content-with-sidebar flex-grow-1" style="transition: all 0.28s ease; min-width: 0;">
        <div class="page-shell">
            
            <!-- Header Navigasi -->
            <div class="mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Monitoring</a></li>
                        <li class="breadcrumb-item active">Detail Aset Cabang</li>
                    </ol>
                </nav>
                <a href="index.php" class="btn btn-back">
                    <i class="bi bi-arrow-left me-1"></i> Kembali ke Rekapitulasi
                </a>
            </div>

            <!-- Info Cabang -->
            <div class="info-cabang">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h4 class="fw-bold mb-2 text-dark"><i class="bi bi-geo-alt-fill me-2" style="color: var(--orange-1);"></i><?= h($data_user['nama_branch']) ?></h4>
                        <p class="text-muted small mb-0 fs-6">Admin Pengelola: <strong class="text-dark"><?= h($data_user['username']) ?></strong></p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <span class="text-muted small fw-semibold text-uppercase letter-spacing-1">Total Unit di Cabang Ini</span>
                        <h2 class="fw-bold text-dark mb-0 mt-1"><?= mysqli_num_rows($result_barang) ?> <span class="fs-5 text-muted fw-normal">Unit</span></h2>
                    </div>
                </div>
            </div>

            <!-- Tabel Inventaris -->
            <div class="ui-card">
                <div class="inventory-header">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-box-seam me-2 fs-5" style="color: var(--orange-1);"></i>
                        <h5 class="mb-0 fw-bold">Daftar Inventaris Terdaftar</h5>
                    </div>
                    <div class="small text-muted fw-semibold">Menampilkan <?= mysqli_num_rows($result_barang) ?> data aset</div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th width="60" class="ps-4">No</th>
                                <th>Identitas Aset</th>
                                <th>Kategori & Spesifikasi</th>
                                <th>Lokasi / Pengguna</th>
                                <th>Kondisi Fisik</th>
                                <th class="text-center pe-4">Foto Aset</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = 1;
                            if(mysqli_num_rows($result_barang) == 0): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-1 d-block mb-3" style="color: var(--border-soft);"></i>
                                        <span class="fw-semibold">Cabang ini belum memiliki data aset terdaftar.</span>
                                    </td>
                                </tr>
                            <?php else:
                                while($row = mysqli_fetch_assoc($result_barang)): 
                            ?>
                            <tr>
                                <td class="ps-4 text-muted fw-semibold"><?= $no++ ?></td>
                                <td>
                                    <!-- Identitas -->
                                    <div class="fw-bold text-uppercase" style="font-size: 0.85rem; color: var(--text-soft);"><?= h($row['nama_barang'] ?? 'UNKNOWN') ?></div>
                                    <div class="asset-code"><?= h($row['no_asset'] ?? '-') ?></div>
                                    <div class="meta-muted mt-1"><i class="bi bi-upc-scan"></i> SN: <?= h($row['serial_number'] ?? '-') ?></div>
                                </td>
                                <td>
                                    <!-- Spesifikasi -->
                                    <span class="badge-merk mb-1 d-inline-block"><?= h($row['nama_merk'] ?? 'TANPA MERK') ?></span>
                                    <div class="meta-line mt-1">Tipe: <span class="fw-semibold text-dark"><?= h($row['nama_tipe'] ?? '-') ?></span></div>
                                </td>
                                <td>
                                    <!-- Lokasi / User -->
                                    <div class="meta-line mb-1"><i class="bi bi-geo-alt-fill text-muted"></i> <?= h($data_user['nama_branch']) ?></div>
                                    <div class="meta-line fw-bold"><i class="bi bi-person-fill text-muted"></i> <?= h($row['user'] ?? 'User') ?></div>
                                </td>
                                <td>
                                    <!-- Kondisi -->
                                    <?php if($row['bermasalah'] == 'Iya'): ?>
                                        <span class="badge rounded-pill badge-soft-danger mb-2"><i class="bi bi-exclamation-triangle me-1"></i> Bermasalah</span>
                                        <div class="text-danger small fw-semibold" style="font-size: 0.75rem; max-width: 200px;"><i class="bi bi-info-circle me-1"></i><?= h($row['keterangan_masalah']) ?></div>
                                    <?php else: ?>
                                        <span class="badge rounded-pill badge-soft-success"><i class="bi bi-check-circle me-1"></i> Normal</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center pe-4">
                                    <!-- Foto -->
                                    <?php if(!empty($row['foto'])): ?>
                                        <img src="<?= base_url('assets/images/' . $row['foto']) ?>" class="img-preview shadow-sm" alt="foto aset">
                                    <?php else: ?>
                                        <div class="thumb-placeholder">
                                            <i class="bi bi-image"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div> <!-- End Page Shell -->
    </div> <!-- End Main Content -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>