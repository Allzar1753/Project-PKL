<?php
/** @var mysqli $koneksi */ 
require_once __DIR__ . '/../config/auth.php';

// Koneksi Database
if (file_exists(__DIR__ . '/../config/database.php')) {
    require_once __DIR__ . '/../config/database.php';
} elseif (file_exists(__DIR__ . '/../config/koneksi.php')) {
    require_once __DIR__ . '/../config/koneksi.php';
} else {
    $koneksi = mysqli_connect("localhost", "root", "", "it_hexindo");
}

// Proteksi Admin
if (!is_admin()) {
    header('Location: ' . base_url('dashboard/index.php'));
    exit;
}

// Query Monitoring
$query = "SELECT 
            u.id AS user_id, u.username, u.email,
            b.nama_branch, 
            COUNT(brg.id) AS total_asset
          FROM users u
          JOIN tb_branch b ON u.id_branch = b.id_branch
          -- Relasi diubah menggunakan id_branch agar terhubung dengan cabang yang tepat
          LEFT JOIN barang brg ON b.id_branch = brg.id_branch
              -- Menghitung barang yang tersedia/diterima, DAN tetap menghitung yang bermasalah (rusak)
              AND (brg.status IN ('Tersedia', 'Diterima') OR brg.bermasalah = 'Iya')
              -- Mengecualikan barang yang sedang dikirim dari HO ke Cabang (transit)
              AND brg.id NOT IN (
                  SELECT id_barang FROM barang_pengiriman WHERE status_pengiriman = 'Sedang perjalanan'
              )
              -- Mengecualikan barang yang sedang dikirim dari Cabang ke HO (rusak/retur)
              AND brg.serial_number NOT IN (
                  SELECT serial_number FROM pengiriman_cabang_ho 
                  WHERE branch_asal = b.id_branch AND status_pengiriman NOT IN ('Ditolak', 'Selesai')
              )
          WHERE u.role = 'user'
          GROUP BY u.id, u.username, u.email, b.nama_branch
          ORDER BY total_asset DESC";

$result = mysqli_query($koneksi, $query);

$total_all_assets = 0;
$cabang_kosong = 0;
$data_monitoring = [];
while ($row = mysqli_fetch_assoc($result)) {
    $data_monitoring[] = $row;
    $total_all_assets += $row['total_asset'];
    if($row['total_asset'] == 0) $cabang_kosong++;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring Asset HO | IT Asset Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        
        /* Banner Header Gradasi (Identik dengan Dashboard Anda) */
        .hero-banner {
            background: linear-gradient(90deg, #343940 0%, #3d434b 45%, #ff8f00 100%);
            border-radius: 24px;
            padding: 40px;
            color: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            position: relative;
        }
        .hero-banner .badge-ho {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 12px;
            padding: 8px 16px;
        }

        /* Stat Cards */
        .card-custom {
            border: none;
            border-radius: 20px;
            background: #fff;
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
            transition: transform 0.3s ease;
        }
        .card-custom:hover { transform: translateY(-5px); }
        .icon-shape {
            width: 48px; height: 48px;
            background: #ff8f00;
            color: white;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
        }

        /* Styling Tabel yang User Friendly */
        .table-container {
            background: #fff;
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.02);
        }
        .table thead th {
            background-color: transparent;
            border-bottom: 2px solid #f1f1f1;
            color: #adb5bd;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1px;
            padding: 15px;
        }
        .table tbody td { padding: 18px 15px; border-bottom: 1px solid #f8f9fa; }
        
        /* Badge Status */
        .status-pill {
            padding: 6px 16px;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .status-ready { background: #e8f5e9; color: #2e7d32; }
        .status-empty { background: #ffebee; color: #c62828; }

        .btn-view {
            background: #ff8f00;
            color: white;
            border: none;
            border-radius: 10px;
            padding: 8px 20px;
            font-weight: 600;
            transition: 0.3s;
        }
        .btn-view:hover { background: #e67e00; color: white; box-shadow: 0 4px 15px rgba(255, 143, 0, 0.3); }

        .search-box {
            background: #f1f3f5;
            border: none;
            border-radius: 12px;
            padding: 10px 20px;
        }
    </style>
</head>
<body class="d-flex">

    <!-- Sidebar -->
    <?php include __DIR__ . '/../layout/sidebar.php'; ?>

    <!-- Main Content -->
    <div id="mainContent" class="content-with-sidebar flex-grow-1 p-4">
        
        <!-- Header Banner -->
        <div class="hero-banner d-flex justify-content-between align-items-center">
            <div>
                <h1 class="fw-bold mb-2">Monitoring Pusat Asset</h1>
                <p class="mb-0 opacity-75">Ringkasan inventaris dan stok barang dari seluruh cabang dalam satu tampilan kontrol.</p>
            </div>
            <div class="d-none d-lg-block">
                <div class="badge-ho">
                    <i class="bi bi-geo-alt-fill text-warning me-1"></i> Administrator HO - Jakarta
                </div>
            </div>
        </div>

        <!-- Row Summary Cards -->
        <div class="row g-4 mb-5">
            <div class="col-md-3">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="text-muted small fw-bold mb-1">Total Inventaris</p>
                            <h2 class="fw-bold mb-0"><?= number_format($total_all_assets) ?></h2>
                            <small class="text-muted">Aset aktif </small>
                        </div>
                        <div class="icon-shape"><i class="bi bi-box-seam"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="text-muted small fw-bold mb-1">Cabang Aktif</p>
                            <h2 class="fw-bold mb-0"><?= count($data_monitoring) ?></h2>
                            <small class="text-muted">Lokasi terdaftar</small>
                        </div>
                        <div class="icon-shape" style="background: #0d6efd;"><i class="bi bi-building"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="text-muted small fw-bold mb-1">Belum Input</p>
                            <h2 class="fw-bold mb-0"><?= $cabang_kosong ?></h2>
                            <small class="text-muted">Cabang stok kosong</small>
                        </div>
                        <div class="icon-shape" style="background: #dc3545;"><i class="bi bi-exclamation-triangle"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabel Monitoring Section -->
        <div class="table-container">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
                <div>
                    <h4 class="fw-bold mb-1"><i class="bi bi-pcn-display-horizontal text-warning me-2"></i>Rekapitulasi Cabang</h4>
                    <p class="text-muted small mb-0">Klik "Detail" untuk melihat rincian spesifikasi barang per cabang.</p>
                </div>
                <div style="min-width: 300px;">
                    <div class="input-group">
                        <span class="input-group-text border-0 bg-light"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" id="filterInput" class="form-control search-box" placeholder="Cari Nama Cabang...">
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table align-middle" id="monitoringTable">
                    <thead>
                        <tr>
                            <th>Cabang</th>
                            <th>Admin Pengelola</th>
                            <th class="text-center">Kapasitas Asset</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data_monitoring as $row): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="p-2 bg-light rounded-3 me-3"><i class="bi bi-geo-alt-fill text-warning"></i></div>
                                    <span class="fw-bold text-dark fs-5"><?= h($row['nama_branch']) ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="fw-bold"><?= h($row['username']) ?></div>
                                <div class="text-muted small"><?= h($row['email']) ?></div>
                            </td>
                            <td class="text-center">
                                <span class="fw-bold fs-4 text-warning"><?= $row['total_asset'] ?></span>
                                <span class="text-muted small">Unit</span>
                            </td>
                            <td class="text-center">
                                <?php if($row['total_asset'] > 0): ?>
                                    <span class="status-pill status-ready">TERDATA</span>
                                <?php else: ?>
                                    <span class="status-pill status-empty">BELUM INPUT</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="detail.php?user_id=<?= $row['user_id'] ?>" class="btn btn-view">
                                    <i class="bi bi-arrow-right-short fs-5"></i> Detail
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Pencarian Tabel interaktif
        document.getElementById('filterInput').addEventListener('keyup', function() {
            let val = this.value.toLowerCase();
            document.querySelectorAll('#monitoringTable tbody tr').forEach(tr => {
                tr.style.display = (tr.innerText.toLowerCase().includes(val)) ? '' : 'none';
            });
        });
    </script>
</body>
</html> 