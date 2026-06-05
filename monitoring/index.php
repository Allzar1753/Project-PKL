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

// Fungsi bantu untuk mencegah error HTML spesial karakter
function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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

        /* Banner Header Gradasi Hexindo Style */
        .hero-banner {
            position: relative; 
            background: var(--dark-1); 
            border-top: 4px solid var(--orange-1); 
            border-radius: var(--radius-xl); 
            padding: 1.5rem 2rem; 
            margin-bottom: 1.5rem; 
            color: white;
            box-shadow: var(--shadow-soft);
        }
        .hero-banner h1 {
            color: #fff; font-size: 1.6rem; font-weight: 700; margin-bottom: 0.25rem;
        }
        .hero-banner p {
            color: #9ca3af; margin-bottom: 0; font-size: 0.95rem;
        }
        .hero-banner .badge-ho {
            background: rgba(255, 255, 255, 0.1);
            color: #fff; font-weight: 600;
            border-radius: 999px;
            padding: 0.6rem 1.2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 0.85rem;
        }

        /* Stat Cards Hexindo Style */
        .card-custom {
            border: 1px solid var(--border-soft);
            border-radius: var(--radius-xl);
            background: #fff;
            box-shadow: var(--shadow-soft);
            transition: all 0.2s ease;
            height: 100%;
        }
        .card-custom:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); }
        .icon-shape {
            width: 45px; height: 45px;
            background: rgba(230, 67, 18, 0.1);
            color: var(--orange-1);
            border-radius: var(--radius-xl);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem;
        }

        /* Styling Tabel Clean */
        .table-container {
            background: #fff;
            border-radius: var(--radius-xl);
            padding: 1.5rem;
            border: 1px solid var(--border-soft);
            box-shadow: var(--shadow-soft);
        }
        
        .table > :not(caption) > * > * { padding: 1rem 1.5rem; border-bottom-color: var(--border-soft); }
        
        .table thead th {
            background-color: #f9fafb !important;
            color: var(--text-soft);
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }
        
        /* Soft Badges */
        .status-pill {
            padding: 0.4em 0.8em; font-weight: 600; font-size: 0.75rem; letter-spacing: 0.3px; border-radius: 999px; display: inline-flex; align-items: center; gap: 4px;
        }
        .status-ready { background-color: rgba(16, 185, 129, 0.15); color: #059669; }
        .status-empty { background-color: rgba(239, 68, 68, 0.15); color: #b91c1c; }

        /* Tombol View Hexindo */
        .btn-view {
            background-color: var(--orange-1);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 0.5rem 1.2rem;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.2s ease;
        }
        .btn-view:hover { background-color: var(--orange-2); color: white; }

        /* Search Box Clean */
        .search-box {
            border: 1px solid var(--border-soft);
            border-radius: 6px;
            padding: 0.5rem 0.8rem;
            font-size: 0.9rem;
            box-shadow: none;
        }
        .search-box:focus {
            border-color: var(--orange-1);
            box-shadow: 0 0 0 0.25rem rgba(230, 67, 18, 0.1);
            outline: none;
        }
        .input-group-text { background: transparent; border: 1px solid var(--border-soft); border-radius: 6px;}

        /* Icon Wrapper Cabang */
        .icon-cabang {
            width: 35px; height: 35px;
            background-color: #F4F6F9;
            border: 1px solid #E0E4E8;
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
        }
    </style>
</head>
<body class="d-flex">

    <!-- Sidebar -->
    <?php include __DIR__ . '/../layout/sidebar.php'; ?>

    <!-- Main Content -->
    <div id="mainContent" class="content-with-sidebar flex-grow-1" style="transition: all 0.28s ease; min-width: 0;">
        <div class="page-shell">
            
            <!-- Header Banner -->
            <div class="hero-banner d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="fw-bold mb-1">Monitoring Pusat Asset</h1>
                    <p class="mb-0">Ringkasan inventaris dan stok barang dari seluruh cabang dalam satu tampilan kontrol.</p>
                </div>
                <div class="d-none d-lg-block">
                    <div class="badge-ho">
                        <i class="bi bi-geo-alt-fill me-2" style="color: var(--orange-1);"></i> Administrator HO - Jakarta
                    </div>
                </div>
            </div>

            <!-- Row Summary Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="card-custom p-4">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <p class="text-muted small fw-bold mb-1 text-uppercase">Total Inventaris</p>
                                <h2 class="fw-bold mb-0 text-dark"><?= number_format($total_all_assets) ?></h2>
                                <small class="text-muted">Semua aset aktif</small>
                            </div>
                            <div class="icon-shape"><i class="bi bi-box-seam"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card-custom p-4 border-start border-4" style="border-left-color: #3b82f6 !important;">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <p class="text-muted small fw-bold mb-1 text-uppercase">Cabang Aktif</p>
                                <h2 class="fw-bold mb-0 text-dark"><?= count($data_monitoring) ?></h2>
                                <small class="text-muted">Total lokasi terdaftar</small>
                            </div>
                            <div class="icon-shape" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6;"><i class="bi bi-building"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card-custom p-4 border-start border-4" style="border-left-color: #ef4444 !important;">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <p class="text-muted small fw-bold mb-1 text-uppercase">Belum Input</p>
                                <h2 class="fw-bold mb-0 text-dark"><?= $cabang_kosong ?></h2>
                                <small class="text-muted">Cabang dengan stok kosong</small>
                            </div>
                            <div class="icon-shape" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;"><i class="bi bi-exclamation-triangle"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabel Monitoring Section -->
            <div class="table-container">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3 border-bottom pb-3">
                    <div>
                        <h5 class="fw-bold mb-1 text-dark"><i class="bi bi-pc-display-horizontal me-2" style="color: var(--orange-1);"></i>Rekapitulasi Cabang</h5>
                        <p class="text-muted small mb-0">Klik "Detail Data" untuk melihat rincian spesifikasi barang per cabang.</p>
                    </div>
                    <div style="min-width: 250px;">
                        <div class="input-group">
                            <span class="input-group-text border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" id="filterInput" class="form-control search-box border-start-0 ps-0" placeholder="Cari Nama Cabang...">
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="monitoringTable">
                        <thead>
                            <tr>
                                <th>Lokasi Cabang</th>
                                <th>Admin Pengelola</th>
                                <th class="text-center">Kapasitas Aset</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data_monitoring as $row): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="icon-cabang me-3"><i class="bi bi-geo-alt-fill text-muted"></i></div>
                                        <span class="fw-bold text-dark fs-6"><?= h($row['nama_branch']) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark fs-6"><?= h($row['username']) ?></div>
                                    <div class="text-muted small"><i class="bi bi-envelope me-1"></i><?= h($row['email']) ?></div>
                                </td>
                                <td class="text-center">
                                    <span class="fw-bold fs-5 text-dark"><?= $row['total_asset'] ?></span>
                                    <span class="text-muted small ms-1">Unit Aktif</span>
                                </td>
                                <td class="text-center">
                                    <?php if($row['total_asset'] > 0): ?>
                                        <span class="status-pill status-ready"><i class="bi bi-check-circle"></i> Terdata</span>
                                    <?php else: ?>
                                        <span class="status-pill status-empty"><i class="bi bi-x-circle"></i> Belum Input</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="detail.php?user_id=<?= $row['user_id'] ?>" class="btn btn-view">
                                        Detail Data <i class="bi bi-arrow-right-short"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if (count($data_monitoring) === 0): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="bi bi-building-x fs-1 d-block mb-3" style="color: var(--border-soft);"></i>
                                    <span class="fw-semibold">Tidak ada data cabang yang ditemukan.</span>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div> <!-- End Page Shell -->
    </div> <!-- End Main Content -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Pencarian Tabel interaktif (TIDAK DIUBAH)
        document.getElementById('filterInput').addEventListener('keyup', function() {
            let val = this.value.toLowerCase();
            document.querySelectorAll('#monitoringTable tbody tr').forEach(tr => {
                // Jangan sembunyikan baris "tidak ada data" jika ada
                if(tr.cells.length > 1) {
                    tr.style.display = (tr.innerText.toLowerCase().includes(val)) ? '' : 'none';
                }
            });
        });
    </script>
</body>
</html>