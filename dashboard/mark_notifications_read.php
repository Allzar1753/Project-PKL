<?php

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'dashboard.view');

$role = (string) current_role();
$branchId = (int) (current_user_branch_id() ?? 0);
$userId = (int) (current_user_id() ?? 0);

if ($role === 'user' && $branchId > 0) {
    $stmt = mysqli_prepare(
        $koneksi,
        "UPDATE system_notifications SET is_read = 1
         WHERE target_role = ? AND is_read = 0
           AND (target_user_id IS NULL OR target_user_id = ?)
           AND (target_branch_id IS NULL OR target_branch_id = ?)"
    );
    mysqli_stmt_bind_param($stmt, 'sii', $role, $userId, $branchId);
} else {
    $stmt = mysqli_prepare(
        $koneksi,
        "UPDATE system_notifications SET is_read = 1
         WHERE target_role = ? AND is_read = 0
           AND (target_user_id IS NULL OR target_user_id = ?)"
    );
    mysqli_stmt_bind_param($stmt, 'si', $role, $userId);
}

mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

header('Location: notifications.php');
exit;
