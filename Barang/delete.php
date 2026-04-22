<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_admin();

header('Content-Type: application/json');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) { 
    echo json_encode([
        'status' => 'error',
        'message' => 'ID tidak valid'
    ]);
    exit;
}

$stmtCheck = mysqli_prepare(
    $koneksi,
    "SELECT id_pengiriman
     FROM barang_pengiriman
     WHERE id_barang = ?
     LIMIT 1"
);

mysqli_stmt_bind_param($stmtCheck, 'i', $id);
mysqli_stmt_execute($stmtCheck);
$resultCheck = mysqli_stmt_get_result($stmtCheck);
$hasHistory = (bool) mysqli_fetch_assoc($resultCheck);
mysqli_stmt_close($stmtCheck);

if ($hasHistory) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Barang tidak bisa dihapus karena sudah memiliki riwayat pengiriman.'
    ]);
    exit;
}

$stmt = mysqli_prepare($koneksi, "DELETE FROM barang WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $id);

if (mysqli_stmt_execute($stmt)) {
    echo json_encode([
        'status' => 'success',
        'message' => 'Data berhasil dihapus'
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Data gagal dihapus: ' . mysqli_stmt_error($stmt)
    ]);
}

mysqli_stmt_close($stmt);