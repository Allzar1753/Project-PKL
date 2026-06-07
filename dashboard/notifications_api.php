<?php

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';
require_once '../config/warranty_helper.php';

require_permission($koneksi, 'dashboard.view');

header('Content-Type: application/json');

sync_warranty_notifications($koneksi);

$role = (string) current_role();
$branchId = (int) (current_user_branch_id() ?? 0);
$userId = (int) (current_user_id() ?? 0);
$limit = min(20, max(1, (int) ($_GET['limit'] ?? 8)));

if ($role === 'user' && $branchId > 0) {
    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT id, title, message, link, notification_type, is_read, created_at
         FROM system_notifications
         WHERE target_role = ?
           AND (target_user_id IS NULL OR target_user_id = ?)
           AND (target_branch_id IS NULL OR target_branch_id = ?)
         ORDER BY is_read ASC, created_at DESC
         LIMIT ?"
    );
    mysqli_stmt_bind_param($stmt, 'siii', $role, $userId, $branchId, $limit);
} else {
    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT id, title, message, link, notification_type, is_read, created_at
         FROM system_notifications
         WHERE target_role = ?
           AND (target_user_id IS NULL OR target_user_id = ?)
         ORDER BY is_read ASC, created_at DESC
         LIMIT ?"
    );
    mysqli_stmt_bind_param($stmt, 'sii', $role, $userId, $limit);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$items = [];

while ($row = mysqli_fetch_assoc($result)) {
    $typeMeta = notification_type_meta($row['notification_type'] ?? 'general');
    $link = trim((string) ($row['link'] ?? ''));
    $notifId = (int) $row['id'];
    $items[] = [
        'id' => $notifId,
        'title' => $row['title'],
        'message' => $row['message'],
        'type' => $row['notification_type'] ?? 'general',
        'icon' => $typeMeta['icon'],
        'color' => $typeMeta['color'],
        'bg' => $typeMeta['bg'],
        'is_read' => (int) ($row['is_read'] ?? 0) === 1,
        'time_ago' => time_ago_id($row['created_at'] ?? null),
        'read_url' => 'notification_read.php?id=' . $notifId . '&redirect=' . urlencode($link !== '' ? $link : 'index.php'),
    ];
}
mysqli_stmt_close($stmt);

echo json_encode([
    'unread_count' => count_unread_notifications($koneksi, $role, $branchId > 0 ? $branchId : null),
    'items' => $items,
]);
