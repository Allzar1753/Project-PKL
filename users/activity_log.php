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

function sendJson(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function getUserPresenceData(mysqli $koneksi, int $onlineThresholdMinutes): array
{
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

    $usersData = [];
    $totalUsers = 0;
    $onlineUsers = 0;
    $offlineUsers = 0;

    if ($qUsers && mysqli_num_rows($qUsers) > 0) {
        while ($u = mysqli_fetch_assoc($qUsers)) {
            $lastSeen = (string) ($u['last_seen_at'] ?? '');
            $isOnline = $lastSeen !== '' && strtotime($lastSeen) >= strtotime($cutoff);

            $totalUsers++;
            if ($isOnline) {
                $onlineUsers++;
            } else {
                $offlineUsers++;
            }

            $lastSeenFormatted = '-';
            if ($lastSeen !== '') {
                $timestamp = strtotime($lastSeen);
                if ($timestamp !== false) {
                    if (date('Y-m-d', $timestamp) === date('Y-m-d')) {
                        $lastSeenFormatted = 'Hari ini, ' . date('H:i', $timestamp) . ' WIB';
                    } else {
                        $lastSeenFormatted = date('d M Y, H:i', $timestamp) . ' WIB';
                    }
                }
            }

            $u['is_online'] = $isOnline;
            $u['last_seen_formatted'] = $lastSeenFormatted;
            $usersData[] = $u;
        }
    }

    return [
        'totalUsers' => $totalUsers,
        'onlineUsers' => $onlineUsers,
        'offlineUsers' => $offlineUsers,
        'usersData' => $usersData,
    ];
}

$onlineThresholdMinutes = 5;

if (isset($_GET['action']) && $_GET['action'] === 'status') {
    $payload = getUserPresenceData($koneksi, $onlineThresholdMinutes);
    sendJson($payload);
}

$data = getUserPresenceData($koneksi, $onlineThresholdMinutes);
$qUsers = null;
$usersData = $data['usersData'];
$totalUsers = $data['totalUsers'];
$onlineUsers = $data['onlineUsers'];
$offlineUsers = $data['offlineUsers'];

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

    <!-- SINKRONISASI TEMA HEXINDO (CLEAN & INDUSTRIAL) -->
    <style>
        :root {
            /* TEMA HEXINDO */
            --orange-1: #E64312; 
            --orange-2: #F25C05;
            --dark-1: #231F20;
            --text-main: #333333;
            --text-soft: #666666;
            --surface-bg: #F4F6F9;
            --border-soft: #E0E4E8;
            --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.04);
            --shadow-hover: 0 10px 25px rgba(0,0,0,0.08);
            --radius-box: 8px; /* Industrial Sharp Edges */
        }

        body {
            background-color: var(--surface-bg);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
            min-height: 100vh;
        }

        .page-shell { padding: 24px 32px; }

        /* Hero Card Hexindo Style */
        .page-hero {
            position: relative;
            background: var(--dark-1);
            border-top: 4px solid var(--orange-1);
            border-radius: var(--radius-box);
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-soft);
        }

        .page-title {
            color: #fff; font-size: 1.6rem; font-weight: 700; margin-bottom: 0.25rem;
        }

        .page-desc {
            color: #9ca3af; margin-bottom: 0; font-size: 0.95rem; max-width: 800px;
        }

        /* Buttons Hexindo */
        .btn-modern {
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.1);
            color: #fff; font-weight: 600;
            border-radius: var(--radius-box);
            padding: 0.6rem 1.2rem;
            text-decoration: none;
            display: inline-flex; align-items: center;
            transition: all 0.2s; font-size: 0.9rem;
        }
        .btn-modern:hover { background: rgba(255, 255, 255, 0.2); color: #fff; }

        /* Summary Cards Hexindo Style */
        .summary-card {
            background: #fff;
            border: 1px solid var(--border-soft);
            border-radius: var(--radius-box);
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.2rem;
            box-shadow: var(--shadow-soft);
            transition: 0.2s;
            height: 100%;
        }

        .summary-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-hover);}

        .sc-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0;
        }
        .sc-icon.blue  { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .sc-icon.green { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .sc-icon.gray  { background: rgba(107, 114, 128, 0.1); color: #6b7280; }

        .sc-label { font-size: 0.85rem; font-weight: 600; color: var(--text-soft); }
        .sc-value { font-size: 1.8rem; font-weight: 800; color: var(--dark-1); line-height: 1.1; margin-top: 0.2rem; }

        /* UI Card & Table Clean */
        .ui-card {
            background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-box); box-shadow: var(--shadow-soft); overflow: hidden;
        }

        .table-custom { margin-bottom: 0; }
        .table-custom thead th {
            background-color: #f9fafb !important; color: var(--text-soft);
            font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;
            font-weight: 700; padding: 1.2rem 1.5rem; border-bottom: 1px solid var(--border-soft);
        }
        .table-custom tbody td { padding: 1rem 1.5rem; vertical-align: middle; border-bottom: 1px solid var(--border-soft); }

        /* Avatar & Text */
        .user-avatar {
            width: 42px; height: 42px; border-radius: 8px; border: 1px solid rgba(230, 67, 18, 0.2);
            background-color: rgba(230, 67, 18, 0.1); color: var(--orange-1);
            display: inline-flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.1rem; flex-shrink: 0;
        }
        
        .user-info { display: flex; align-items: center; gap: 1rem; }
        .text-bold { font-weight: 700; color: var(--dark-1); font-size: 0.95rem; margin-bottom: 0.15rem;}
        .text-meta { font-size: 0.85rem; color: var(--text-soft); }

        /* Status Badges with Pulse Effect (Soft Hexindo Style) */
        .badge-status {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 0.35em 0.8em; border-radius: 6px;
            font-weight: 600; font-size: 0.75rem; text-transform: uppercase;
        }

        .badge-online { background-color: rgba(16, 185, 129, 0.15); color: #059669; border: 1px solid rgba(16, 185, 129, 0.2); }
        .badge-offline { background-color: rgba(107, 114, 128, 0.15); color: #4b5563; border: 1px solid rgba(107, 114, 128, 0.2); }

        .status-dot { width: 8px; height: 8px; border-radius: 50%; }
        .dot-offline { background-color: #6b7280; }

        /* Animasi Kedip untuk Online (Warna Hexindo/Hijau) */
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
    <div class="container-fluid p-0">
        <div class="d-flex flex-nowrap w-100 overflow-hidden">

            <?php include '../layout/sidebar.php'; ?>

            <div id="mainContent" class="flex-grow-1" style="transition: all 0.28s ease; min-width: 0;">

                <div class="page-shell">

                    <!-- Hero Section -->
                    <div class="page-hero">
                        <div class="hero-content d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div>
                                <h1 class="page-title"><i class="bi bi-activity me-2" style="color: var(--orange-1);"></i> Monitoring Kehadiran User Cabang</h1>
                                <p class="page-desc">Pantau status konektivitas dan keaktifan login pengguna cabang secara aktual (*Threshold batas online: <?= $onlineThresholdMinutes ?> menit).</p>
                                <div class="text-meta mt-2" style="color: #9ca3af;">
                                    <i class="bi bi-arrow-repeat me-1"></i> Sinkronisasi sistem terakhir: <strong class="text-white" id="presenceSyncTime">Memuat...</strong>
                                </div>
                            </div>
                            <div>
                                <button onclick="location.reload()" class="btn-modern">
                                    <i class="bi bi-arrow-clockwise me-2"></i> Muat Ulang Tampilan
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
                                    <div id="presenceTotalUsers" class="sc-value"><?= $totalUsers ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-card" style="border-left-color: #10b981;">
                                <div class="sc-icon green"><i class="bi bi-wifi"></i></div>
                                <div>
                                    <div class="sc-label">Sedang Online</div>
                                    <div id="presenceOnlineUsers" class="sc-value text-success"><?= $onlineUsers ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-card" style="border-left-color: #6b7280;">
                                <div class="sc-icon gray"><i class="bi bi-wifi-off"></i></div>
                                <div>
                                    <div class="sc-label">Sedang Offline</div>
                                    <div id="presenceOfflineUsers" class="sc-value text-muted"><?= $offlineUsers ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Data Table -->
                    <div class="ui-card">
                        <div class="table-responsive">
                            <table class="table table-custom align-middle table-hover">
                                <thead>
                                    <tr>
                                        <th width="5%" class="ps-4">No</th>
                                        <th width="35%">Informasi Pengguna</th>
                                        <th width="20%">Lokasi Cabang</th>
                                        <th width="20%">Status User</th>
                                        <th width="20%">Aktivitas Terakhir</th>
                                    </tr>
                                </thead>
                                <tbody id="presenceTableBody">
                                    <?php if (!empty($usersData)): ?>
                                        <?php $no = 1; ?>
                                        <?php foreach ($usersData as $u): ?>
                                            <tr>
                                                <td class="text-muted fw-semibold ps-4"><?= $no++ ?></td>

                                                <!-- User Info with Avatar -->
                                                <td>
                                                    <div class="user-info">
                                                        <div class="user-avatar">
                                                            <?= strtoupper(substr(h($u['username']), 0, 1)) ?>
                                                        </div>
                                                        <div>
                                                            <div class="text-bold"><?= h($u['username'] ?? '-') ?></div>
                                                            <div class="text-meta"><i class="bi bi-envelope text-muted me-1"></i><?= h($u['email'] ?? '-') ?></div>
                                                        </div>
                                                    </div>
                                                </td>

                                                <td>
                                                    <div class="text-bold text-dark"><i class="bi bi-geo-alt-fill text-muted me-2"></i><?= h($u['nama_branch'] ?? '-') ?></div>
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
                                                        <i class="bi bi-clock-history me-1 text-muted"></i><?= h($u['last_seen_formatted']) ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-5">
                                                <i class="bi bi-people text-muted fs-1 d-block mb-3"></i>
                                                <h5 class="fw-bold text-dark">Belum ada data user</h5>
                                                <p class="text-muted">Sistem tidak menemukan akun aktif dengan kewenangan 'user' cabang.</p>
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

    <!-- SCRIPT JAVASCRIPT AJAX 100% TIDAK DIUBAH (HANYA PENYESUAIAN CSS CLASS BUILD ROW SAJA) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const statusEndpoint = window.location.pathname + '?action=status';
            const presenceSyncTime = document.getElementById('presenceSyncTime');
            const presenceTotalUsers = document.getElementById('presenceTotalUsers');
            const presenceOnlineUsers = document.getElementById('presenceOnlineUsers');
            const presenceOfflineUsers = document.getElementById('presenceOfflineUsers');
            const presenceTableBody = document.getElementById('presenceTableBody');

            if (!presenceSyncTime || !presenceTotalUsers || !presenceOnlineUsers || !presenceOfflineUsers || !presenceTableBody) {
                console.warn('Element presence tidak ditemukan, polling dihentikan.');
                return;
            }

            function buildRow(index, user) {
                const statusBadge = user.is_online
                    ? '<span class="badge-status badge-online"><span class="status-dot dot-online"></span>Online</span>'
                    : '<span class="badge-status badge-offline"><span class="status-dot dot-offline"></span>Offline</span>';

                return `
                    <tr>
                        <td class="text-muted fw-semibold ps-4">${index}</td>
                        <td>
                            <div class="user-info">
                                <div class="user-avatar">${String(user.username || '').charAt(0).toUpperCase()}</div>
                                <div>
                                    <div class="text-bold">${user.username ? user.username : '-'}</div>
                                    <div class="text-meta"><i class="bi bi-envelope text-muted me-1"></i>${user.email ? user.email : '-'}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="text-bold text-dark"><i class="bi bi-geo-alt-fill text-muted me-2"></i>${user.nama_branch ? user.nama_branch : '-'}</div>
                        </td>
                        <td>${statusBadge}</td>
                        <td>
                            <div class="text-meta"><i class="bi bi-clock-history text-muted me-1"></i>${user.last_seen_formatted ? user.last_seen_formatted : '-'}</div>
                        </td>
                    </tr>
                `;
            }

            async function refreshPresence() {
                try {
                    const response = await fetch(statusEndpoint, { cache: 'no-store' });
                    if (!response.ok) {
                        throw new Error('Status: ' + response.status);
                    }
                    const data = await response.json();
                    presenceTotalUsers.textContent = data.totalUsers;
                    presenceOnlineUsers.textContent = data.onlineUsers;
                    presenceOfflineUsers.textContent = data.offlineUsers;
                    presenceSyncTime.textContent = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) + ' WIB';

                    if (Array.isArray(data.usersData)) {
                        presenceTableBody.innerHTML = data.usersData.map((user, index) => buildRow(index + 1, user)).join('');
                    }
                } catch (error) {
                    console.warn('Gagal memperbarui status presence:', error);
                    presenceSyncTime.textContent = 'Gagal sinkronisasi';
                }
            }

            setInterval(refreshPresence, 5000);
            refreshPresence(); // Panggil pertama kali saat halaman di-load
        });
    </script>
</body>

</html>