<?php

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';
require_once '../config/warranty_helper.php';

require_permission($koneksi, 'dashboard.view');

sync_warranty_notifications($koneksi);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$role = (string) current_role();
$branchId = (int) (current_user_branch_id() ?? 0);
$userId = (int) (current_user_id() ?? 0);
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'unread', 'warranty', 'logistics'], true)) {
    $filter = 'all';
}

$where = ["target_role = ?"];
$params = [$role];
$types = 's';

if ($role === 'user' && $branchId > 0) {
    $where[] = '(target_branch_id IS NULL OR target_branch_id = ?)';
    $params[] = $branchId;
    $types .= 'i';
}

$where[] = '(target_user_id IS NULL OR target_user_id = ?)';
$params[] = $userId;
$types .= 'i';

if ($filter === 'unread') {
    $where[] = 'is_read = 0';
} elseif ($filter === 'warranty') {
    $where[] = "notification_type LIKE 'warranty_%'";
} elseif ($filter === 'logistics') {
    $where[] = "(notification_type = 'general' OR notification_type IS NULL OR notification_type = '')";
}

$whereSql = 'WHERE ' . implode(' AND ', $where);
$sql = "SELECT * FROM system_notifications $whereSql ORDER BY created_at DESC LIMIT 100";
$stmt = mysqli_prepare($koneksi, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$notifications = [];
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $notifications[] = $row;
}
mysqli_stmt_close($stmt);

$unreadCount = count_unread_notifications($koneksi, $role, $branchId > 0 ? $branchId : null);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pusat Notifikasi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --orange-1:#E64312; --dark-1:#231F20; --surface:#F4F6F9; --border:#e5e7eb; }
        body { font-family:'Plus Jakarta Sans',sans-serif; background:var(--surface); }
        .page-shell { padding:24px 28px; }
        .hero { background:var(--dark-1); border-top:4px solid var(--orange-1); border-radius:8px; padding:1.4rem 1.6rem; color:#fff; margin-bottom:1rem; }
        .filter-btn { border:1px solid var(--border); background:#fff; border-radius:999px; padding:.4rem .9rem; font-weight:600; font-size:.82rem; text-decoration:none; color:#374151; }
        .filter-btn.active { background:var(--orange-1); border-color:var(--orange-1); color:#fff; }
        .notif-card { background:#fff; border:1px solid var(--border); border-radius:12px; padding:1rem 1.1rem; margin-bottom:.75rem; display:flex; gap:1rem; transition:.2s; }
        .notif-card:hover { box-shadow:0 8px 24px rgba(0,0,0,.06); transform:translateY(-1px); }
        .notif-card.unread { border-left:4px solid var(--orange-1); background:#fffdfb; }
        .notif-icon { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; }
        .notif-title { font-weight:700; color:var(--dark-1); margin-bottom:.2rem; }
        .notif-msg { color:#6b7280; font-size:.9rem; margin-bottom:.35rem; }
        .notif-time { font-size:.78rem; color:#9ca3af; }
        .empty { text-align:center; padding:3rem 1rem; color:#9ca3af; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
<div class="d-flex flex-nowrap w-100 overflow-hidden">
<?php include '../layout/sidebar.php'; ?>
<div id="mainContent" class="flex-grow-1" style="min-width:0;">
<div class="page-shell">
    <div class="hero d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="h4 mb-1"><i class="bi bi-bell me-2" style="color:var(--orange-1)"></i>Pusat Notifikasi</h1>
            <p class="mb-0 small text-secondary">Pengingat garansi, logistik, dan aktivitas penting — <?= $unreadCount ?> belum dibaca</p>
        </div>
        <?php if ($unreadCount > 0): ?>
            <a href="mark_notifications_read.php" class="btn btn-light btn-sm fw-semibold"><i class="bi bi-check2-all me-1"></i>Tandai semua dibaca</a>
        <?php endif; ?>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="?filter=all" class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>">Semua</a>
        <a href="?filter=unread" class="filter-btn <?= $filter === 'unread' ? 'active' : '' ?>">Belum Dibaca</a>
        <a href="?filter=warranty" class="filter-btn <?= $filter === 'warranty' ? 'active' : '' ?>"><i class="bi bi-shield-check me-1"></i>Garansi</a>
        <a href="?filter=logistics" class="filter-btn <?= $filter === 'logistics' ? 'active' : '' ?>"><i class="bi bi-truck me-1"></i>Logistik</a>
    </div>

    <?php if (empty($notifications)): ?>
        <div class="empty"><i class="bi bi-bell-slash fs-1 d-block mb-2"></i>Tidak ada notifikasi untuk filter ini.</div>
    <?php else: ?>
        <?php foreach ($notifications as $n):
            $meta = notification_type_meta($n['notification_type'] ?? 'general');
            $link = trim((string)($n['link'] ?? ''));
            $readUrl = 'notification_read.php?id=' . (int)$n['id'] . '&redirect=' . urlencode($link !== '' ? $link : 'index.php');
        ?>
            <a href="<?= h($readUrl) ?>" class="text-decoration-none text-dark">
                <div class="notif-card <?= (int)($n['is_read'] ?? 0) === 0 ? 'unread' : '' ?>">
                    <div class="notif-icon" style="background:<?= h($meta['bg']) ?>;color:<?= h($meta['color']) ?>;">
                        <i class="bi <?= h($meta['icon']) ?>"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="notif-title"><?= h($n['title']) ?></div>
                        <div class="notif-msg"><?= h($n['message']) ?></div>
                        <div class="notif-time"><i class="bi bi-clock me-1"></i><?= h(time_ago_id($n['created_at'] ?? null)) ?></div>
                    </div>
                    <?php if ((int)($n['is_read'] ?? 0) === 0): ?>
                        <span class="badge rounded-pill bg-danger align-self-start">Baru</span>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</div>
</div>
</div>
</body>
</html>
