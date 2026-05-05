<?php
/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'users.view');

if (!is_admin()) {
    http_response_code(403);
    exit('Halaman ini khusus administrator.');
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$onlineThresholdMinutes = 5;
$cutoff = date('Y-m-d H:i:s', time() - ($onlineThresholdMinutes * 60));

$qUsers = mysqli_query($koneksi, "
    SELECT
        u.id,
        u.username,
        u.email,
        u.role,
        b.nama_branch,
        p.last_seen_at
    FROM users u
    LEFT JOIN tb_branch b ON b.id_branch = u.id_branch
    LEFT JOIN user_presence p ON p.user_id = u.id
    WHERE u.role = 'user'
    ORDER BY b.nama_branch ASC, u.username ASC
");

// --- PERSIAPAN DATA UNTUK SUMMARY CARDS ---
$usersData = [];
$totalUsers = 0;
$onlineUsers = 0;
$offlineUsers = 0;

if ($qUsers && mysqli_num_rows($qUsers) > 0) {
    while ($u = mysqli_fetch_assoc($qUsers)) {
        $lastSeen = (string) ($u['last_seen_at'] ?? '');
        $isOnline = $lastSeen !== '' && strtotime($lastSeen) >= strtotime($cutoff);

        // Hitung statistik
        $totalUsers++;
        if ($isOnline) {
            $onlineUsers++;
        } else {
            $offlineUsers++;
        }

        // Format waktu
        $lastSeenFormatted = '-';
        if ($lastSeen !== '') {
            $timestamp = strtotime($lastSeen);
            if ($timestamp !== false) {
                // Jika hari ini, tampilkan jam saja. Jika hari lain, tampilkan tanggal & jam.
                if (date('Y-m-d', $timestamp) === date('Y-m-d')) {
                    $lastSeenFormatted = 'Hari ini, ' . date('H:i', $timestamp) . ' WIB';
                } else {
                    $lastSeenFormatted = date('d M Y, H:i', $timestamp) . ' WIB';
                }
            }
        }

        // Simpan ke array untuk ditampilkan di tabel nanti
        $u['is_online'] = $isOnline;
        $u['last_seen_formatted'] = $lastSeenFormatted;
        $usersData[] = $u;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring User Online - IT Asset Management</title>
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <style>
        :root {
            --orange-1: #ff7a00; 
            --orange-2: #ff9800; 
            --orange-3: #ffb000;
            --dark-1: #111111; 
            --text-main: #1e1e1e; 
            --text-soft: #6b7280;
            --surface: #ffffff; 
            --border-soft: rgba(255, 152, 0, 0.14);
            --shadow-soft: 0 12px 36px rgba(17, 17, 17, 0.07); 
            --radius-xl: 28px;
        }

        body {
            background: radial-gradient(circle at top left, rgba(255, 176, 0, 0.16), transparent 28%),
                        linear-gradient(180deg, #fff8f1 0%, #ffffff 100%);
            font-family: 'Plus Jakarta Sans', sans-serif; 
            color: var(--text-main); 
            min-height: 100vh;
        }

        .page-shell { padding: 25px; }

        /* Hero Card */
        .page-hero {
            position: relative; 
            overflow: hidden; 
            border-radius: var(--radius-xl);
            background: linear-gradient(135deg, rgba(17, 17, 17, 0.94) 0%, rgba(255, 122, 0, 0.96) 100%);
            box-shadow: 0 18px 45px rgba(255, 122, 0, 0.20); 
            padding: 1.8rem 2rem; 
            margin-bottom: 1.5rem;
        }

        .page-title { 
            color: #fff; font-size: 1.8rem; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 0.3rem;
        }

        .page-desc { 
            color: rgba(255, 255, 255, 0.84); font-size: .95rem; max-width: 800px; margin-bottom: 0;
        }

        /* Buttons */
        .btn-modern {
            background: #ffffff;
            color: var(--dark-1);
            border: none;
            border-radius: 12px;
            padding: 0.6rem 1.2rem;
            font-weight: 800;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
            display: inline-flex;
            align-items: center;
            text-decoration: none;
        }

        .btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 25px rgba(0,0,0,0.25);
            background: #fff7ef;
            color: var(--dark-1);
        }

        /* Summary Cards */
        .summary-card {
            background: var(--surface);
            border: 1px solid var(--border-soft);
            border-radius: 20px;
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 1.2rem;
            box-shadow: var(--shadow-soft);
            transition: 0.3s;
            height: 100%;
        }
        .summary-card:hover { transform: translateY(-4px); }
        .sc-icon {
            width: 55px; height: 55px; border-radius: 16px;
            display: flex; align-items: center; justify-content: center; font-size: 1.6rem;
        }
        .sc-icon.blue { background: #eff6ff; color: #3b82f6; }
        .sc-icon.green { background: #ecfdf5; color: #10b981; }
        .sc-icon.gray { background: #f3f4f6; color: #6b7280; }
        .sc-label { font-size: 0.85rem; font-weight: 700; color: var(--text-soft); text-transform: uppercase; letter-spacing: 0.05em; }
        .sc-value { font-size: 1.8rem; font-weight: 800; color: var(--dark-1); line-height: 1.1; margin-top: 0.2rem; }

        /* UI Card & Table */
        .ui-card { 
            background: var(--surface); border: 1px solid var(--border-soft); border-radius: 22px; box-shadow: var(--shadow-soft); overflow: hidden;
        }
        
        .table-custom { margin-bottom: 0; }
        .table-custom thead th {
            background-color: #fcfcfc; color: #555; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; padding: 1rem 1.5rem; border-bottom: 2px solid #eee;
        }
        .table-custom tbody td { padding: 1.2rem 1.5rem; vertical-align: middle; border-bottom: 1px solid #f5f5f5; }

        /* Avatar & Text */
        .user-avatar {
            width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, var(--orange-1), var(--orange-3)); color: white;
            display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.1rem; flex-shrink: 0;
        }
        .user-info { display: flex; align-items: center; gap: 1rem; }
        .text-bold { font-weight: 700; color: var(--dark-1); font-size: 0.95rem; }
        .text-meta { font-size: 0.85rem; color: var(--text-soft); }

        /* Status Badges with Pulse Effect */
        .badge-status {
            display: inline-flex; align-items: center; padding: 0.45em 0.9em; border-radius: 999px; font-weight: 700; font-size: 0.8rem;
        }
        .badge-online { background-color: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .badge-offline { background-color: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; }

        .status-dot { width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
        .dot-offline { background-color: #9ca3af; }
        
        /* Animasi Dot Kedip untuk Online */
        .dot-online {
            background-color: #10b981;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: pulse-green 2s infinite;
        }
        @keyframes pulse-green {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php require_once '../layout/sidebar.php'; ?>
        
        <div class="col-md-10">
            <div class="page-shell">
                
                <!-- Hero Section -->
                <div class="page-hero">
                    <div class="hero-content d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <h1 class="page-title"><i class="bi bi-activity me-2 text-warning"></i>Monitoring User Cabang</h1>
                            <p class="page-desc">Pantau status koneksi dan keaktifan akun pengguna cabang secara aktual. (Threshold online: <?= $onlineThresholdMinutes ?> menit terakhir).</p>
                        </div>
                        <div>
                            <!-- Tombol Action -->
                            <button onclick="location.reload()" class="btn-modern">
                                <i class="bi bi-arrow-clockwise me-2 fs-5"></i> Muat Ulang Data
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards Row -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="summary-card">
                            <div class="sc-icon blue"><i class="bi bi-people-fill"></i></div>
                            <div>
                                <div class="sc-label">Total Akun Cabang</div>
                                <div class="sc-value"><?= $totalUsers ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-card">
                            <div class="sc-icon green"><i class="bi bi-wifi"></i></div>
                            <div>
                                <div class="sc-label">Sedang Online</div>
                                <div class="sc-value text-success"><?= $onlineUsers ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="summary-card">
                            <div class="sc-icon gray"><i class="bi bi-wifi-off"></i></div>
                            <div>
                                <div class="sc-label">Sedang Offline</div>
                                <div class="sc-value text-secondary"><?= $offlineUsers ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Data Table -->
                <div class="ui-card">
                    <div class="table-responsive">
                        <table class="table table-custom align-middle">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th width="35%">Informasi Pengguna</th>
                                    <th width="20%">Cabang Lokasi</th>
                                    <th width="20%">Status Sistem</th>
                                    <th width="20%">Aktivitas Terakhir</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($usersData)): ?>
                                    <?php $no = 1; ?>
                                    <?php foreach ($usersData as $u): ?>
                                        <tr>
                                            <td class="text-muted fw-bold"><?= $no++ ?></td>
                                            
                                            <!-- User Info with Avatar -->
                                            <td>
                                                <div class="user-info">
                                                    <!-- Ambil huruf pertama username untuk avatar -->
                                                    <div class="user-avatar">
                                                        <?= strtoupper(substr(h($u['username']), 0, 1)) ?>
                                                    </div>
                                                    <div>
                                                        <div class="text-bold"><?= h($u['username'] ?? '-') ?></div>
                                                        <div class="text-meta"><i class="bi bi-envelope me-1"></i><?= h($u['email'] ?? '-') ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            
                                            <td>
                                                <div class="text-bold"><i class="bi bi-geo-alt-fill text-warning me-1"></i><?= h($u['nama_branch'] ?? '-') ?></div>
                                            </td>

                                            <!-- Status Badge -->
                                            <td>
                                                <?php if ($u['is_online']): ?>
                                                    <span class="badge-status badge-online">
                                                        <span class="status-dot dot-online"></span>Online
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge-status badge-offline">
                                                        <span class="status-dot dot-offline"></span>Offline
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <div class="text-meta">
                                                    <i class="bi bi-clock-history me-1"></i><?= h($u['last_seen_formatted']) ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5">
                                            <i class="bi bi-people text-muted fs-1 d-block mb-3"></i>
                                            <h5 class="fw-bold text-dark">Belum ada data user</h5>
                                            <p class="text-muted">Sistem tidak menemukan akun dengan role 'user' cabang.</p>
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
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>