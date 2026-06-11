<?php
include '../config/koneksi.php';
require_once '../config/auth.php';
require_once '../config/activity_logger.php';
/** @var mysqli $koneksi */

// Cek hak akses admin
require_permission($koneksi, 'barang.scrap');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$idAset = (int)($_POST['id_barang'] ?? 0);
$alasanScrap = trim($_POST['alasan'] ?? '');
$adminId = current_user_id();

if ($idAset <= 0 || $alasanScrap === '') {
    echo json_encode(['status' => 'error', 'message' => 'ID Aset dan Alasan Scrap wajib diisi.']);
    exit;
}

mysqli_begin_transaction($koneksi);

try {
    // 1. Ambil data barang fisik & Pemilik Mutlak (id_branch_pemilik)
    $queryCek = "SELECT b.id_branch, b.id_branch_pemilik, b.user, b.id_status, b.serial_number, tb.nama_barang 
                 FROM barang b 
                 LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang 
                 WHERE b.id = ? LIMIT 1";
    $stmtCek = mysqli_prepare($koneksi, $queryCek);
    mysqli_stmt_bind_param($stmtCek, 'i', $idAset);
    mysqli_stmt_execute($stmtCek);
    $barang = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCek));
    mysqli_stmt_close($stmtCek);

    if (!$barang) throw new Exception('Data barang tidak ditemukan di database.');
    if (in_array((int)$barang['id_status'], [7, 8], true)) throw new Exception('Barang ini sudah dalam proses scrap atau sudah dimusnahkan.');

    $sn = trim((string)$barang['serial_number']);
    $namaBarang = (string)$barang['nama_barang'];
    $pemakaiTerakhir = (string)$barang['user'];
    
    // =====================================================================
    // 2. KUNCI UTAMA: Langsung gunakan id_branch_pemilik!
    // =====================================================================
    $targetNotifikasi = (int)$barang['id_branch_pemilik']; 

    // Proteksi: Jika id_branch_pemilik kosong/0 (misal data lama belum di-update SQL)
    if ($targetNotifikasi <= 0) {
        $targetNotifikasi = (int)$barang['id_branch']; // Fallback ke lokasi saat ini
    }
    // =====================================================================

    // 3. MASUKKAN KE PENGAJUAN 
    $stmtInsert = mysqli_prepare($koneksi, "INSERT INTO pengajuan_scrap (id_barang, diajukan_oleh_admin, id_branch_target, nama_pemakai_terakhir, alasan_scrap, status_scrap, created_at) VALUES (?, ?, ?, ?, ?, 'Pending', NOW())");
    mysqli_stmt_bind_param($stmtInsert, 'iiiss', $idAset, $adminId, $targetNotifikasi, $pemakaiTerakhir, $alasanScrap);
    mysqli_stmt_execute($stmtInsert);
    mysqli_stmt_close($stmtInsert);

    // 4. Update status barang jadi 7 (Menunggu Persetujuan Scrap)
    $stmtUpdate = mysqli_prepare($koneksi, "UPDATE barang SET id_status = 7 WHERE id = ?");
    mysqli_stmt_bind_param($stmtUpdate, 'i', $idAset);
    mysqli_stmt_execute($stmtUpdate);
    mysqli_stmt_close($stmtUpdate);

    // 5. Kirim Notifikasi ke Cabang Pemilik Asli
    $notifTitle = "Persetujuan Scrap Aset";
    $notifMsg = "Admin HO mengajukan SCRAP untuk {$namaBarang} (SN: {$sn}). Alasan: {$alasanScrap}. Harap berikan persetujuan Anda.";
    $notifLink = "../Barang/approval_scrap.php"; 
    
    $stmtNotif = mysqli_prepare($koneksi, "INSERT INTO system_notifications (target_role, target_branch_id, title, message, link, notification_type, is_read, created_at) VALUES ('user', ?, ?, ?, ?, 'warranty', 0, NOW())");
    mysqli_stmt_bind_param($stmtNotif, 'isss', $targetNotifikasi, $notifTitle, $notifMsg, $notifLink);
    mysqli_stmt_execute($stmtNotif);
    mysqli_stmt_close($stmtNotif);

    log_activity($koneksi, 'ajukan_scrap', "Admin mengajukan scrap untuk SN: {$sn} ke Cabang ID: {$targetNotifikasi}. Alasan: {$alasanScrap}");

    mysqli_commit($koneksi);
    echo json_encode(['status' => 'success', 'message' => "Pengajuan Scrap berhasil dikirim ke Cabang Pemilik untuk meminta persetujuan."]);

} catch (Exception $e) {
    mysqli_rollback($koneksi);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>