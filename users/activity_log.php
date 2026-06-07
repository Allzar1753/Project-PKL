<?php

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';
require_once '../config/activity_logger.php';

require_permission($koneksi, 'users.view');

if (!is_admin()) {
    http_response_code(403);
    exit('Halaman ini khusus administrator.');
}

$onlineThresholdMinutes = 5;

if (isset($_GET['action']) && $_GET['action'] === 'status') {
    header('Content-Type: application/json');
    echo json_encode(get_user_presence_snapshot($koneksi, $onlineThresholdMinutes));
    exit;
}

$tab = $_GET['tab'] ?? 'activity';
if (!in_array($tab, ['activity', 'presence'], true)) {
    $tab = 'activity';
}

$presenceData = get_user_presence_snapshot($koneksi, $onlineThresholdMinutes);
$totalUsers = $presenceData['totalUsers'];
$onlineUsers = $presenceData['onlineUsers'];
$offlineUsers = $presenceData['offlineUsers'];
$inactiveUsers = $presenceData['inactiveUsers'] ?? 0;
$usersData = $presenceData['usersData'];

$categories = get_activity_categories();
$category = $_GET['category'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$user_id_filter = $_GET['user_id'] ?? '';
$branch_id_filter = $_GET['branch_id'] ?? '';
$search = trim($_GET['search'] ?? '');
$view_mode = $_GET['view'] ?? 'timeline';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 40;
$offset = ($page - 1) * $limit;

$total_records = 0;
$total_pages = 1;
$activityRows = [];
$branchSummary = false;
$userSummary = false;

if ($tab === 'activity') {
$where_clauses = [];
$params = [];
$param_types = '';

$categoryActions = get_activity_actions_by_category($category);
if ($categoryActions !== null) {
    $placeholders = implode(',', array_fill(0, count($categoryActions), '?'));
    $where_clauses[] = "action IN ($placeholders)";
    foreach ($categoryActions as $act) {
        $params[] = $act;
        $param_types .= 's';
    }
}

if ($date_from !== '') {
    $where_clauses[] = "DATE(created_at) >= ?";
    $params[] = $date_from;
    $param_types .= 's';
}

if ($date_to !== '') {
    $where_clauses[] = "DATE(created_at) <= ?";
    $params[] = $date_to;
    $param_types .= 's';
}

if ($user_id_filter !== '') {
    $where_clauses[] = "user_id = ?";
    $params[] = (int)$user_id_filter;
    $param_types .= 'i';
}

if ($branch_id_filter !== '') {
    $where_clauses[] = "branch_id = ?";
    $params[] = (int)$branch_id_filter;
    $param_types .= 'i';
}

if ($search !== '') {
    $where_clauses[] = "(description LIKE ? OR action LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $param_types .= 'ss';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$count_query = "SELECT COUNT(id) as total FROM user_activity_logs $where_sql";
$count_stmt = mysqli_prepare($koneksi, $count_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $param_types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$count_row = mysqli_fetch_assoc(mysqli_stmt_get_result($count_stmt));
$total_records = (int)($count_row['total'] ?? 0);
$total_pages = max(1, (int)ceil($total_records / $limit));
mysqli_stmt_close($count_stmt);

$query = "
    SELECT id, user_id, role, branch_id, action, description, path, ip_address, created_at
    FROM user_activity_logs
    $where_sql
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = mysqli_prepare($koneksi, $query);
$listParams = $params;
$listTypes = $param_types . 'ii';
$listParams[] = $limit;
$listParams[] = $offset;
mysqli_stmt_bind_param($stmt, $listTypes, ...$listParams);
mysqli_stmt_execute($stmt);
$activities = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);

$activityRows = [];
while ($row = mysqli_fetch_assoc($activities)) {
    $activityRows[] = $row;
}

$branchSummary = mysqli_query($koneksi, "
    SELECT branch_id, COUNT(*) as total
    FROM user_activity_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY branch_id
    ORDER BY total DESC
    LIMIT 8
");

$userSummary = mysqli_query($koneksi, "
    SELECT user_id, COUNT(*) as total, MAX(created_at) as last_activity
    FROM user_activity_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY user_id
    ORDER BY last_activity DESC
    LIMIT 8
");
}

$users_list = mysqli_query($koneksi, "
    SELECT id, username, id_branch, role FROM users ORDER BY role ASC, username ASC
");

$branches_list = mysqli_query($koneksi, "
    SELECT id_branch, nama_branch FROM tb_branch ORDER BY nama_branch ASC
");

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function build_query(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    unset($query['page'], $query['action']);
    return '?' . http_build_query($query);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Aktivitas - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        :root {
            --orange-1: #E64312;
            --orange-2: #F25C05;
            --dark-1: #231F20;
            --text-main: #333333;
            --text-soft: #666666;
            --surface-bg: #F4F6F9;
            --border-soft: #E0E4E8;
            --radius-box: 8px;
        }

        body { background: var(--surface-bg); font-family: 'Plus Jakarta Sans', sans-serif; color: var(--text-main); min-height: 100vh; }
        .page-shell { padding: 24px 28px; }

        .page-hero {
            background: var(--dark-1);
            border-top: 4px solid var(--orange-1);
            border-radius: var(--radius-box);
            padding: 1.4rem 1.8rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
        }
        .page-title { color: #fff; font-size: 1.55rem; font-weight: 700; margin-bottom: .25rem; }
        .page-desc { color: #9ca3af; margin: 0; font-size: .92rem; }

        .stat-card, .panel-card, .filter-card {
            background: #fff;
            border: 1px solid var(--border-soft);
            border-radius: var(--radius-box);
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
        }
        .stat-card { padding: 1rem 1.1rem; height: 100%; }
        .stat-num { font-size: 1.75rem; font-weight: 800; color: var(--dark-1); line-height: 1; }
        .stat-label { font-size: .78rem; color: var(--text-soft); font-weight: 600; margin-top: .35rem; text-transform: uppercase; letter-spacing: .4px; }

        .filter-card { padding: 1rem 1.1rem; margin-bottom: 1rem; }
        .filter-title { font-weight: 700; color: var(--dark-1); margin-bottom: .75rem; font-size: .92rem; }

        .cat-btn {
            display: inline-flex; align-items: center; gap: .35rem;
            padding: .45rem .85rem; margin: 0 .35rem .35rem 0;
            border-radius: 999px; border: 1px solid var(--border-soft);
            background: #fff; color: var(--text-main); text-decoration: none;
            font-size: .82rem; font-weight: 600; transition: .2s;
        }
        .cat-btn:hover { border-color: var(--orange-1); color: var(--orange-1); }
        .cat-btn.active { background: var(--orange-1); border-color: var(--orange-1); color: #fff; }

        .view-btn {
            border: 1px solid var(--border-soft); background: #fff; color: var(--text-soft);
            font-weight: 600; font-size: .82rem; padding: .45rem .9rem; border-radius: 999px;
        }
        .view-btn.active { background: var(--dark-1); color: #fff; border-color: var(--dark-1); }

        .panel-card { overflow: hidden; margin-bottom: 1rem; }
        .panel-head {
            background: #f9fafb; border-bottom: 1px solid var(--border-soft);
            padding: .85rem 1.1rem; font-weight: 700; color: var(--dark-1); font-size: .92rem;
        }

        .activity-table { margin: 0; font-size: .88rem; }
        .activity-table thead th {
            background: #f9fafb; color: var(--text-soft); font-size: .75rem;
            text-transform: uppercase; letter-spacing: .4px; font-weight: 700;
            padding: .85rem 1rem; border-bottom: 1px solid var(--border-soft);
        }
        .activity-table tbody td { padding: .85rem 1rem; vertical-align: middle; border-bottom: 1px solid var(--border-soft); }
        .activity-table tbody tr:hover { background: #fafbfc; }

        .act-badge {
            display: inline-flex; align-items: center; gap: .35rem;
            padding: .28rem .65rem; border-radius: 999px; font-size: .72rem; font-weight: 700;
            white-space: nowrap;
        }
        .act-badge.create { background: rgba(16,185,129,.12); color: #059669; }
        .act-badge.update { background: rgba(59,130,246,.12); color: #2563eb; }
        .act-badge.delete { background: rgba(239,68,68,.12); color: #dc2626; }
        .act-badge.send { background: rgba(245,158,11,.15); color: #d97706; }
        .act-badge.receive { background: rgba(20,184,166,.12); color: #0d9488; }

        .user-chip {
            display: inline-flex; align-items: center; gap: .45rem;
            padding: .35rem .55rem; border-radius: 8px; background: rgba(230,67,18,.08);
            color: var(--dark-1); font-weight: 700; font-size: .82rem;
        }
        .user-chip .initial {
            width: 28px; height: 28px; border-radius: 6px; background: rgba(230,67,18,.15);
            color: var(--orange-1); display: inline-flex; align-items: center; justify-content: center; font-size: .75rem;
        }

        .summary-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: .65rem .85rem; border-bottom: 1px solid var(--border-soft); font-size: .84rem;
        }
        .summary-item:last-child { border-bottom: none; }

        .group-block { border: 1px solid var(--border-soft); border-radius: var(--radius-box); margin-bottom: .85rem; overflow: hidden; background: #fff; }
        .group-head {
            background: rgba(230,67,18,.06); padding: .7rem 1rem;
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid var(--border-soft);
        }
        .group-item { padding: .75rem 1rem; border-bottom: 1px solid var(--border-soft); font-size: .84rem; }
        .group-item:last-child { border-bottom: none; }

        .desc-text { color: var(--text-main); font-weight: 500; }
        .meta-text { color: var(--text-soft); font-size: .78rem; }

        .btn-filter { background: var(--orange-1); border: none; color: #fff; font-weight: 700; border-radius: var(--radius-box); }
        .btn-filter:hover { background: var(--orange-2); color: #fff; }
        .btn-reset { background: #6b7280; border: none; color: #fff; font-weight: 700; border-radius: var(--radius-box); }
        .btn-reset:hover { background: #4b5563; color: #fff; }

        .empty-box { text-align: center; padding: 2.5rem 1rem; color: var(--text-soft); }
        .empty-box i { font-size: 2.2rem; opacity: .45; display: block; margin-bottom: .5rem; }

        .main-tab {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .55rem 1.1rem; border-radius: 999px; text-decoration: none;
            font-weight: 700; font-size: .88rem; border: 2px solid var(--border-soft);
            background: #fff; color: var(--text-main); transition: .2s;
        }
        .main-tab:hover { border-color: var(--orange-1); color: var(--orange-1); }
        .main-tab.active { background: var(--orange-1); border-color: var(--orange-1); color: #fff; }

        .user-avatar {
            width: 42px; height: 42px; border-radius: 8px; border: 1px solid rgba(230, 67, 18, 0.2);
            background: rgba(230, 67, 18, 0.1); color: var(--orange-1);
            display: inline-flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1rem; flex-shrink: 0;
        }
        .user-info { display: flex; align-items: center; gap: 1rem; }
        .text-bold { font-weight: 700; color: var(--dark-1); font-size: .95rem; }

        .badge-status {
            display: inline-flex; align-items: center; gap: 6px;
            padding: .35em .8em; border-radius: 6px;
            font-weight: 600; font-size: .75rem; text-transform: uppercase;
        }
        .badge-online { background: rgba(16, 185, 129, 0.15); color: #059669; border: 1px solid rgba(16, 185, 129, 0.2); }
        .badge-offline { background: rgba(107, 114, 128, 0.15); color: #4b5563; border: 1px solid rgba(107, 114, 128, 0.2); }
        .badge-inactive { background: rgba(239, 68, 68, 0.12); color: #b91c1c; border: 1px solid rgba(239, 68, 68, 0.2); }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; }
        .dot-offline { background-color: #6b7280; }
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

        <div id="mainContent" class="flex-grow-1" style="min-width:0;">
            <div class="page-shell">

                <div class="page-hero">
                    <?php if ($tab === 'presence'): ?>
                        <h1 class="page-title"><i class="bi bi-activity me-2" style="color:var(--orange-1)"></i>Monitoring User Online / Offline</h1>
                        <p class="page-desc">Pantau status konektivitas user cabang secara real-time. User dianggap <strong style="color:#fff">online</strong> jika aktif dalam <?= $onlineThresholdMinutes ?> menit terakhir.</p>
                        <div class="meta-text mt-2" style="color:#9ca3af;">
                            <i class="bi bi-arrow-repeat me-1"></i> Sinkronisasi terakhir: <strong class="text-white" id="presenceSyncTime">-</strong>
                        </div>
                    <?php else: ?>
                        <h1 class="page-title"><i class="bi bi-clock-history me-2" style="color:var(--orange-1)"></i>Riwayat Aktivitas User Cabang</h1>
                        <p class="page-desc">Pantau siapa yang sedang CRUD barang, pengiriman, penerimaan, dan kelola user — terpisah per kategori, user, dan cabang.</p>
                    <?php endif; ?>
                </div>

                <div class="filter-card mb-3">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <a href="<?= h(build_query(['tab' => 'activity', 'page' => 1])) ?>" class="main-tab <?= $tab === 'activity' ? 'active' : '' ?>">
                            <i class="bi bi-clock-history"></i> Riwayat Aktivitas
                        </a>
                        <a href="<?= h(build_query(['tab' => 'presence'])) ?>" class="main-tab <?= $tab === 'presence' ? 'active' : '' ?>">
                            <i class="bi bi-wifi"></i> User Online / Offline
                            <span class="badge bg-success rounded-pill ms-1" id="tab-online-badge"><?= $onlineUsers ?></span>
                        </a>
                    </div>
                </div>

                <?php if ($tab === 'presence'): ?>

                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <div class="stat-card d-flex align-items-center gap-3">
                            <div class="user-avatar" style="width:48px;height:48px;"><i class="bi bi-people-fill"></i></div>
                            <div>
                                <div class="stat-num" id="presenceTotalUsers"><?= $totalUsers ?></div>
                                <div class="stat-label">Total Akun Cabang</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card d-flex align-items-center gap-3">
                            <div class="user-avatar" style="width:48px;height:48px;background:rgba(16,185,129,.12);color:#059669;border-color:rgba(16,185,129,.2);"><i class="bi bi-wifi"></i></div>
                            <div>
                                <div class="stat-num text-success" id="presenceOnlineUsers"><?= $onlineUsers ?></div>
                                <div class="stat-label">Sedang Online</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card d-flex align-items-center gap-3">
                            <div class="user-avatar" style="width:48px;height:48px;background:rgba(107,114,128,.12);color:#6b7280;border-color:rgba(107,114,128,.2);"><i class="bi bi-wifi-off"></i></div>
                            <div>
                                <div class="stat-num text-muted" id="presenceOfflineUsers"><?= $offlineUsers ?></div>
                                <div class="stat-label">Offline (Masih Bekerja)</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card d-flex align-items-center gap-3">
                            <div class="user-avatar" style="width:48px;height:48px;background:rgba(239,68,68,.12);color:#b91c1c;border-color:rgba(239,68,68,.2);"><i class="bi bi-person-x"></i></div>
                            <div>
                                <div class="stat-num text-danger" id="presenceInactiveUsers"><?= $inactiveUsers ?></div>
                                <div class="stat-label">Tidak Bekerja Lagi</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span><i class="bi bi-person-lines-fill me-1"></i> Daftar User Cabang</span>
                        <button type="button" class="btn btn-sm btn-filter" onclick="refreshPresence()"><i class="bi bi-arrow-clockwise me-1"></i> Muat Ulang</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table activity-table">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th width="30%">Pengguna</th>
                                    <th width="20%">Cabang</th>
                                    <th width="15%">Status Kerja</th>
                                    <th width="12%">Koneksi</th>
                                    <th width="18%">Aktivitas Terakhir</th>
                                    <th width="10%">IP</th>
                                </tr>
                            </thead>
                            <tbody id="presenceTableBody">
                                <?php if (!empty($usersData)): ?>
                                    <?php $no = 1; foreach ($usersData as $u): ?>
                                        <tr>
                                            <td class="meta-text"><?= $no++ ?></td>
                                            <td>
                                                <div class="user-info">
                                                    <div class="user-avatar"><?= strtoupper(substr(h($u['username']), 0, 1)) ?></div>
                                                    <div>
                                                        <div class="text-bold"><?= h($u['username']) ?></div>
                                                        <div class="meta-text"><i class="bi bi-envelope me-1"></i><?= h($u['email']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><i class="bi bi-geo-alt-fill text-danger me-1"></i><?= h($u['nama_branch']) ?></td>
                                            <td>
                                                <?php if (($u['employment_status'] ?? 'active') === 'inactive'): ?>
                                                    <span class="badge-status badge-inactive"><span class="status-dot dot-offline"></span>Tidak Bekerja</span>
                                                <?php else: ?>
                                                    <span class="badge-status badge-online" style="background:rgba(16,185,129,.12);color:#059669;border-color:rgba(16,185,129,.2);"><i class="bi bi-briefcase-fill"></i> Masih Bekerja</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (($u['employment_status'] ?? 'active') === 'inactive'): ?>
                                                    <span class="meta-text">Akses diblokir</span>
                                                <?php elseif ($u['is_online']): ?>
                                                    <span class="badge-status badge-online"><span class="status-dot dot-online"></span>Online</span>
                                                <?php else: ?>
                                                    <span class="badge-status badge-offline"><span class="status-dot dot-offline"></span>Offline</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="meta-text"><i class="bi bi-clock-history me-1"></i><?= h($u['last_seen_formatted']) ?></td>
                                            <td class="meta-text font-monospace"><?= h($u['ip_address']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="empty-box">
                                            <i class="bi bi-people"></i>
                                            Belum ada akun user cabang terdaftar.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php else: ?>

                <div class="row g-3 mb-3">
                    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-num"><?= $total_records ?></div><div class="stat-label">Total (filter aktif)</div></div></div>
                    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-num" id="today-count">-</div><div class="stat-label">Hari Ini</div></div></div>
                    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-num" id="week-count">-</div><div class="stat-label">Minggu Ini</div></div></div>
                    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-num" id="month-count">-</div><div class="stat-label">Bulan Ini</div></div></div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-lg-8">
                        <div class="filter-card">
                            <div class="filter-title"><i class="bi bi-funnel me-1"></i> Kategori Aktivitas</div>
                            <?php foreach ($categories as $key => $label): ?>
                                <a href="<?= h(build_query(['category' => $key, 'page' => 1])) ?>" class="cat-btn <?= $category === $key ? 'active' : '' ?>">
                                    <?= h($label) ?>
                                </a>
                            <?php endforeach; ?>
                            <?php
                            $quickFilters = [
                                'create_barang' => 'Tambah Barang',
                                'update_barang' => 'Edit Barang',
                                'delete_barang' => 'Hapus Barang',
                                'send_barang' => 'Kirim HO→Cabang',
                                'send_pengiriman_cabang' => 'Kirim Cabang→HO',
                                'receive_barang' => 'Terima Cabang',
                                'approve_pengiriman_ho' => 'Approval HO',
                            ];
                            foreach ($quickFilters as $key => $label):
                            ?>
                                <a href="<?= h(build_query(['category' => $key, 'page' => 1])) ?>" class="cat-btn <?= $category === $key ? 'active' : '' ?>" style="font-size:.76rem;">
                                    <?= h($label) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="filter-card h-100">
                            <div class="filter-title"><i class="bi bi-layout-split me-1"></i> Tampilan</div>
                            <div class="d-flex gap-2 flex-wrap">
                                <a href="<?= h(build_query(['view' => 'timeline', 'page' => 1])) ?>" class="view-btn <?= $view_mode === 'timeline' ? 'active' : '' ?>">Timeline</a>
                                <a href="<?= h(build_query(['view' => 'user', 'page' => 1])) ?>" class="view-btn <?= $view_mode === 'user' ? 'active' : '' ?>">Per User</a>
                                <a href="<?= h(build_query(['view' => 'branch', 'page' => 1])) ?>" class="view-btn <?= $view_mode === 'branch' ? 'active' : '' ?>">Per Cabang</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="filter-card">
                    <div class="filter-title"><i class="bi bi-sliders me-1"></i> Filter Lanjutan</div>
                    <form method="GET" class="row g-2 align-items-end">
                        <input type="hidden" name="category" value="<?= h($category) ?>">
                        <input type="hidden" name="view" value="<?= h($view_mode) ?>">
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">User</label>
                            <select class="form-select form-select-sm" name="user_id">
                                <option value="">Semua User</option>
                                <?php while ($user = mysqli_fetch_assoc($users_list)): ?>
                                    <option value="<?= (int)$user['id'] ?>" <?= (string)$user_id_filter === (string)$user['id'] ? 'selected' : '' ?>>
                                        <?= h($user['username']) ?> (<?= h($user['role']) ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Cabang</label>
                            <select class="form-select form-select-sm" name="branch_id">
                                <option value="">Semua Cabang</option>
                                <option value="0" <?= (string)$branch_id_filter === '0' ? 'selected' : '' ?>>Admin HO</option>
                                <?php while ($branch = mysqli_fetch_assoc($branches_list)): ?>
                                    <option value="<?= (int)$branch['id_branch'] ?>" <?= (string)$branch_id_filter === (string)$branch['id_branch'] ? 'selected' : '' ?>>
                                        <?= h($branch['nama_branch']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Dari</label>
                            <input type="date" class="form-control form-control-sm" name="date_from" value="<?= h($date_from) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Sampai</label>
                            <input type="date" class="form-control form-control-sm" name="date_to" value="<?= h($date_to) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Cari</label>
                            <input type="text" class="form-control form-control-sm" name="search" placeholder="Deskripsi / aksi..." value="<?= h($search) ?>">
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-filter btn-sm w-100"><i class="bi bi-search"></i></button>
                        </div>
                        <div class="col-md-1">
                            <a href="activity_log.php" class="btn btn-reset btn-sm w-100"><i class="bi bi-arrow-clockwise"></i></a>
                        </div>
                    </form>
                </div>

                <div class="row g-3">
                    <div class="col-xl-9">
                        <div class="panel-card">
                            <div class="panel-head d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-list-ul me-1"></i> Daftar Aktivitas</span>
                                <span class="meta-text">Hal <?= $page ?>/<?= $total_pages ?></span>
                            </div>

                            <?php if (empty($activityRows)): ?>
                                <div class="empty-box">
                                    <i class="bi bi-inbox"></i>
                                    <div>Belum ada riwayat aktivitas untuk filter ini.</div>
                                </div>
                            <?php elseif ($view_mode === 'user'): ?>
                                <?php
                                $grouped = [];
                                foreach ($activityRows as $act) {
                                    $grouped[$act['user_id']][] = $act;
                                }
                                foreach ($grouped as $uid => $items):
                                    $uname = get_user_name($koneksi, (int)$uid);
                                ?>
                                    <div class="group-block border-0 rounded-0 mb-0" style="border-bottom:1px solid var(--border-soft)!important;">
                                        <div class="group-head">
                                            <div class="user-chip mb-0">
                                                <span class="initial"><?= strtoupper(substr($uname, 0, 1)) ?></span>
                                                <?= h($uname) ?>
                                            </div>
                                            <span class="meta-text"><?= count($items) ?> aktivitas</span>
                                        </div>
                                        <?php foreach ($items as $act):
                                            $parsed = parse_activity_description($act['description']);
                                            $badgeType = get_activity_badge_type($act['action']);
                                        ?>
                                            <div class="group-item d-flex justify-content-between gap-3 flex-wrap">
                                                <div>
                                                    <span class="act-badge <?= h($badgeType) ?>"><?= h(get_activity_label($act['action'])) ?></span>
                                                    <div class="desc-text mt-1"><?= h($parsed['text']) ?></div>
                                                    <div class="meta-text"><?= h(get_branch_name($koneksi, $act['branch_id'])) ?></div>
                                                </div>
                                                <div class="text-end meta-text">
                                                    <?= date('d M Y', strtotime($act['created_at'])) ?><br>
                                                    <strong><?= date('H:i:s', strtotime($act['created_at'])) ?></strong>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>

                            <?php elseif ($view_mode === 'branch'): ?>
                                <?php
                                $grouped = [];
                                foreach ($activityRows as $act) {
                                    $bid = (string)($act['branch_id'] ?? '0');
                                    $grouped[$bid][] = $act;
                                }
                                foreach ($grouped as $bid => $items):
                                    $bname = get_branch_name($koneksi, $bid === '0' ? null : (int)$bid);
                                ?>
                                    <div class="group-block border-0 rounded-0 mb-0" style="border-bottom:1px solid var(--border-soft)!important;">
                                        <div class="group-head">
                                            <strong><i class="bi bi-geo-alt-fill text-danger me-1"></i><?= h($bname) ?></strong>
                                            <span class="meta-text"><?= count($items) ?> aktivitas</span>
                                        </div>
                                        <?php foreach ($items as $act):
                                            $parsed = parse_activity_description($act['description']);
                                            $badgeType = get_activity_badge_type($act['action']);
                                            $uname = get_user_name($koneksi, (int)$act['user_id']);
                                        ?>
                                            <div class="group-item d-flex justify-content-between gap-3 flex-wrap">
                                                <div>
                                                    <span class="act-badge <?= h($badgeType) ?>"><?= h(get_activity_label($act['action'])) ?></span>
                                                    <div class="desc-text mt-1"><?= h($parsed['text']) ?></div>
                                                    <div class="meta-text">Oleh: <?= h($uname) ?></div>
                                                </div>
                                                <div class="text-end meta-text">
                                                    <?= date('d M Y H:i', strtotime($act['created_at'])) ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>

                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table activity-table">
                                        <thead>
                                            <tr>
                                                <th>Waktu</th>
                                                <th>User</th>
                                                <th>Cabang</th>
                                                <th>Aksi</th>
                                                <th>Deskripsi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($activityRows as $act):
                                                $parsed = parse_activity_description($act['description']);
                                                $badgeType = get_activity_badge_type($act['action']);
                                                $uname = get_user_name($koneksi, (int)$act['user_id']);
                                            ?>
                                                <tr>
                                                    <td class="meta-text">
                                                        <?= date('d M Y', strtotime($act['created_at'])) ?><br>
                                                        <strong><?= date('H:i:s', strtotime($act['created_at'])) ?></strong>
                                                    </td>
                                                    <td>
                                                        <div class="user-chip">
                                                            <span class="initial"><?= strtoupper(substr($uname, 0, 1)) ?></span>
                                                            <?= h($uname) ?>
                                                        </div>
                                                        <div class="meta-text mt-1"><?= h($act['role']) ?></div>
                                                    </td>
                                                    <td><?= h(get_branch_name($koneksi, $act['branch_id'])) ?></td>
                                                    <td><span class="act-badge <?= h($badgeType) ?>"><?= h(get_activity_label($act['action'])) ?></span></td>
                                                    <td>
                                                        <div class="desc-text"><?= h($parsed['text']) ?></div>
                                                        <?php if (!empty($parsed['details'])): ?>
                                                            <div class="meta-text"><?= h(json_encode($parsed['details'], JSON_UNESCAPED_UNICODE)) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <nav>
                                <ul class="pagination pagination-sm justify-content-center">
                                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= h(build_query(['page' => $p])) ?>"><?= $p ?></a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>

                    <div class="col-xl-3">
                        <div class="panel-card">
                            <div class="panel-head"><i class="bi bi-bar-chart me-1"></i> Aktif 7 Hari — Per Cabang</div>
                            <?php if ($branchSummary && mysqli_num_rows($branchSummary) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($branchSummary)): ?>
                                    <div class="summary-item">
                                        <span><?= h(get_branch_name($koneksi, $row['branch_id'] ? (int)$row['branch_id'] : null)) ?></span>
                                        <span class="badge bg-dark rounded-pill"><?= (int)$row['total'] ?></span>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="summary-item text-muted">Belum ada data</div>
                            <?php endif; ?>
                        </div>

                        <div class="panel-card">
                            <div class="panel-head"><i class="bi bi-person-lines-fill me-1"></i> Aktif 7 Hari — Per User</div>
                            <?php if ($userSummary && mysqli_num_rows($userSummary) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($userSummary)):
                                    $uid = (int)$row['user_id'];
                                ?>
                                    <a href="<?= h(build_query(['user_id' => $uid, 'page' => 1])) ?>" class="summary-item text-decoration-none text-dark">
                                        <span><?= h(get_user_name($koneksi, $uid)) ?></span>
                                        <span>
                                            <span class="badge bg-secondary rounded-pill me-1"><?= (int)$row['total'] ?></span>
                                            <span class="meta-text"><?= date('d/m H:i', strtotime($row['last_activity'])) ?></span>
                                        </span>
                                    </a>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="summary-item text-muted">Belum ada data</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const currentTab = <?= json_encode($tab) ?>;

    if (currentTab === 'activity') {
        fetch('get_activity_stats.php')
            .then(r => r.json())
            .then(d => {
                const el = (id) => document.getElementById(id);
                if (el('today-count')) el('today-count').textContent = d.today ?? 0;
                if (el('week-count')) el('week-count').textContent = d.week ?? 0;
                if (el('month-count')) el('month-count').textContent = d.month ?? 0;
            })
            .catch(() => {});
    }

    if (currentTab === 'presence') {
        window.refreshPresence = function () {
            fetch(window.location.pathname + '?action=status', { cache: 'no-store' })
                .then(r => r.json())
                .then(data => {
                    const syncEl = document.getElementById('presenceSyncTime');
                    const totalEl = document.getElementById('presenceTotalUsers');
                    const onlineEl = document.getElementById('presenceOnlineUsers');
                    const offlineEl = document.getElementById('presenceOfflineUsers');
                    const inactiveEl = document.getElementById('presenceInactiveUsers');
                    const tableBody = document.getElementById('presenceTableBody');
                    const tabBadge = document.getElementById('tab-online-badge');

                    if (syncEl) {
                        syncEl.textContent = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) + ' WIB';
                    }
                    if (totalEl) totalEl.textContent = data.totalUsers ?? 0;
                    if (onlineEl) onlineEl.textContent = data.onlineUsers ?? 0;
                    if (offlineEl) offlineEl.textContent = data.offlineUsers ?? 0;
                    if (inactiveEl) inactiveEl.textContent = data.inactiveUsers ?? 0;
                    if (tabBadge) tabBadge.textContent = data.onlineUsers ?? 0;

                    if (tableBody && Array.isArray(data.usersData)) {
                        if (data.usersData.length === 0) {
                            tableBody.innerHTML = '<tr><td colspan="7" class="empty-box"><i class="bi bi-people"></i>Belum ada akun user cabang.</td></tr>';
                            return;
                        }
                        tableBody.innerHTML = data.usersData.map((user, index) => {
                            const initial = String(user.username || '?').charAt(0).toUpperCase();
                            const employmentBadge = user.employment_status === 'inactive'
                                ? '<span class="badge-status badge-inactive"><span class="status-dot dot-offline"></span>Tidak Bekerja</span>'
                                : '<span class="badge-status badge-online" style="background:rgba(16,185,129,.12);color:#059669;border-color:rgba(16,185,129,.2);"><i class="bi bi-briefcase-fill"></i> Masih Bekerja</span>';
                            let connectionBadge = '';
                            if (user.employment_status === 'inactive') {
                                connectionBadge = '<span class="meta-text">Akses diblokir</span>';
                            } else if (user.is_online) {
                                connectionBadge = '<span class="badge-status badge-online"><span class="status-dot dot-online"></span>Online</span>';
                            } else {
                                connectionBadge = '<span class="badge-status badge-offline"><span class="status-dot dot-offline"></span>Offline</span>';
                            }
                            return `
                                <tr>
                                    <td class="meta-text">${index + 1}</td>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">${initial}</div>
                                            <div>
                                                <div class="text-bold">${user.username || '-'}</div>
                                                <div class="meta-text"><i class="bi bi-envelope me-1"></i>${user.email || '-'}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><i class="bi bi-geo-alt-fill text-danger me-1"></i>${user.nama_branch || '-'}</td>
                                    <td>${employmentBadge}</td>
                                    <td>${connectionBadge}</td>
                                    <td class="meta-text"><i class="bi bi-clock-history me-1"></i>${user.last_seen_formatted || '-'}</td>
                                    <td class="meta-text font-monospace">${user.ip_address || '-'}</td>
                                </tr>`;
                        }).join('');
                    }
                })
                .catch(() => {
                    const syncEl = document.getElementById('presenceSyncTime');
                    if (syncEl) syncEl.textContent = 'Gagal sinkronisasi';
                });
        };

        refreshPresence();
        setInterval(refreshPresence, 5000);
    }
});
</script>
</body>
</html>
