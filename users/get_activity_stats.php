<?php

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'users.view');

if (!is_admin()) {
    http_response_code(403);
    exit('Halaman ini khusus administrator.');
}

header('Content-Type: application/json');

$today = date('Y-m-d');
$week_start = date('Y-m-d', strtotime('monday this week'));
$month_start = date('Y-m-01');

$today_query = "SELECT COUNT(id) as total FROM user_activity_logs WHERE DATE(created_at) = ?";
$stmt = mysqli_prepare($koneksi, $today_query);
mysqli_stmt_bind_param($stmt, 's', $today);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$today_row = mysqli_fetch_assoc($result);
$today_count = $today_row['total'];
mysqli_stmt_close($stmt);

$week_query = "SELECT COUNT(id) as total FROM user_activity_logs WHERE DATE(created_at) >= ?";
$stmt = mysqli_prepare($koneksi, $week_query);
mysqli_stmt_bind_param($stmt, 's', $week_start);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$week_row = mysqli_fetch_assoc($result);
$week_count = $week_row['total'];
mysqli_stmt_close($stmt);

$month_query = "SELECT COUNT(id) as total FROM user_activity_logs WHERE DATE(created_at) >= ?";
$stmt = mysqli_prepare($koneksi, $month_query);
mysqli_stmt_bind_param($stmt, 's', $month_start);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$month_row = mysqli_fetch_assoc($result);
$month_count = $month_row['total'];
mysqli_stmt_close($stmt);

$catQuery = mysqli_query($koneksi, "
    SELECT
        SUM(CASE WHEN action IN ('create_barang','update_barang','delete_barang') THEN 1 ELSE 0 END) as barang,
        SUM(CASE WHEN action IN ('send_barang','send_pengiriman_cabang') THEN 1 ELSE 0 END) as pengiriman,
        SUM(CASE WHEN action IN ('receive_barang','receive_barang_ho','approve_pengiriman_ho') THEN 1 ELSE 0 END) as penerimaan,
        SUM(CASE WHEN action IN ('create_user','update_user','delete_user') THEN 1 ELSE 0 END) as user_mgmt
    FROM user_activity_logs
    WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
");
$catRow = $catQuery ? (mysqli_fetch_assoc($catQuery) ?: []) : [];

echo json_encode([
    'today' => $today_count,
    'week' => $week_count,
    'month' => $month_count,
    'by_category' => [
        'barang' => (int)($catRow['barang'] ?? 0),
        'pengiriman' => (int)($catRow['pengiriman'] ?? 0),
        'penerimaan' => (int)($catRow['penerimaan'] ?? 0),
        'user' => (int)($catRow['user_mgmt'] ?? 0),
    ],
]);
