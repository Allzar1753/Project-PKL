<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'dashboard.view');

function safe_redirect_target(string $fallback): string
{
    $target = trim((string) ($_GET['redirect'] ?? ''));
    if ($target === '') {
        return $fallback;
    }

    // Prevent open redirect to external host.
    if (preg_match('/^https?:\/\//i', $target)) {
        return $fallback;
    }

    return $target;
}

$notificationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$fallback = '../dashboard/index.php';
$redirectTarget = safe_redirect_target($fallback);

if ($notificationId > 0) {
    $isAdmin = is_admin();
    $currentRole = (string) current_role();
    $currentUserId = (int) (current_user_id() ?? 0);
    $currentBranchId = (int) (current_user_branch_id() ?? 0);

    if ($isAdmin) {
        $stmt = mysqli_prepare(
            $koneksi,
            "UPDATE system_notifications
             SET is_read = 1
             WHERE id = ?
               AND target_role = ?
               AND (target_user_id IS NULL OR target_user_id = ?)"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'isi', $notificationId, $currentRole, $currentUserId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    } else {
        $stmt = mysqli_prepare(
            $koneksi,
            "UPDATE system_notifications
             SET is_read = 1
             WHERE id = ?
               AND target_role = ?
               AND (target_user_id IS NULL OR target_user_id = ?)
               AND (target_branch_id IS NULL OR target_branch_id = ?)"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'isii', $notificationId, $currentRole, $currentUserId, $currentBranchId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}

header('Location: ' . $redirectTarget);
exit;
