<?php
/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';
require_once '../config/activity_logger.php';

// Hapus require_admin(); ganti dengan login check
require_login(); 

header('Content-Type: application/json');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$myBranchId = current_user_branch_id();

if ($id <= 0) { 
    echo json_encode(['status' => 'error', 'message' => 'ID tidak valid']);
    exit;
}

// 1. Cek kepemilikan: Jika bukan admin, pastikan barang milik cabangnya
$stmtCekMilik = mysqli_prepare($koneksi, "SELECT id_branch FROM barang WHERE id = ?");
mysqli_stmt_bind_param($stmtCekMilik, 'i', $id);
mysqli_stmt_execute($stmtCekMilik);
$resMilik = mysqli_stmt_get_result($stmtCekMilik);
$dataBarang = mysqli_fetch_assoc($resMilik);

if (!$dataBarang) {
    echo json_encode(['status' => 'error', 'message' => 'Data tidak ditemukan']);
    exit;
}

if (!is_admin() && (int)$dataBarang['id_branch'] !== (int)$myBranchId) {
    echo json_encode(['status' => 'error', 'message' => 'Anda tidak berhak menghapus aset cabang lain']);
    exit;
}

// 2. Cek Riwayat Pengiriman (Agar integritas data terjaga)
$stmtCheck = mysqli_prepare($koneksi, "SELECT id_pengiriman FROM barang_pengiriman WHERE id_barang = ? LIMIT 1");
mysqli_stmt_bind_param($stmtCheck, 'i', $id);
mysqli_stmt_execute($stmtCheck);
$resultCheck = mysqli_stmt_get_result($stmtCheck);
$hasHistory = (bool) mysqli_fetch_assoc($resultCheck);

if ($hasHistory) {
    echo json_encode(['status' => 'error', 'message' => 'Aset tidak bisa dihapus karena sudah memiliki riwayat logistik/pengiriman.']);
    exit;
}

// 3. Eksekusi Hapus
$stmt = mysqli_prepare($koneksi, "DELETE FROM barang WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $id);

if (mysqli_stmt_execute($stmt)) {
    // Log activity for delete
    log_activity($koneksi, 'delete_barang', "Hapus barang - ID: {$id}, Branch: {$dataBarang['id_branch']}", [
        'id_barang' => $id,
        'id_branch' => $dataBarang['id_branch']
    ]);

    echo json_encode(['status' => 'success', 'message' => 'Data berhasil dihapus']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus: ' . mysqli_error($koneksi)]);
}