<?php
include '../config/koneksi.php';
require_once '../config/auth.php';
require_once '../config/activity_logger.php';
/** @var mysqli $koneksi */


require_permission($koneksi, 'scrap.approve');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request']);
    exit;
}

$idScrap = (int)$_POST['id_scrap'];
$action = $_POST['action'] ?? '';
$alasanPenolakan = trim($_POST['alasan'] ?? '');
$userId = current_user_id();

if ($idScrap <= 0 || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['status' => 'error', 'message' => 'Data tidak valid.']);
    exit;
}

mysqli_begin_transaction($koneksi);

try {
    // 1. Ambil data pengajuan & nama barang
    // PERBAIKAN QUERY: Join ke tb_barang agar nama_barang terbaca
    $queryCek = "SELECT ps.*, b.serial_number, tb.nama_barang 
                 FROM pengajuan_scrap ps 
                 JOIN barang b ON ps.id_barang = b.id 
                 LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang
                 WHERE ps.id_scrap = ? LIMIT 1";
                 
    $stmtCek = mysqli_prepare($koneksi, $queryCek);
    mysqli_stmt_bind_param($stmtCek, 'i', $idScrap);
    mysqli_stmt_execute($stmtCek);
    $data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCek));
    mysqli_stmt_close($stmtCek);

    if (!$data || $data['status_scrap'] !== 'Pending') {
        throw new Exception('Pengajuan tidak ditemukan atau sudah diproses sebelumnya.');
    }

    $idBarangFisik = $data['id_barang']; // Ini ID di tabel `barang`
    $adminId = $data['diajukan_oleh_admin'];
    $sn = $data['serial_number'];
    $namaBarang = $data['nama_barang'] ?? 'Unknown Asset';

    if ($action === 'approve') {
        // JIKA DISETUJUI
        $stmtAcc = mysqli_prepare($koneksi, "UPDATE pengajuan_scrap SET status_scrap = 'Disetujui', dikonfirmasi_oleh_user = ? WHERE id_scrap = ?");
        mysqli_stmt_bind_param($stmtAcc, 'ii', $userId, $idScrap);
        mysqli_stmt_execute($stmtAcc);
        
        $stmtBrg = mysqli_prepare($koneksi, "UPDATE barang SET id_status = 8 WHERE id = ?");
        mysqli_stmt_bind_param($stmtBrg, 'i', $idBarangFisik);
        mysqli_stmt_execute($stmtBrg);

        // Notif ke Admin
        $pesanNotif = "User Cabang MENYETUJUI Scrap untuk $namaBarang (SN: $sn). Aset telah resmi dimusnahkan.";
        log_activity($koneksi, 'approve_scrap', "User cabang menyetujui scrap SN: $sn");

    } elseif ($action === 'reject') {
        // JIKA DITOLAK
        if ($alasanPenolakan === '') throw new Exception('Alasan penolakan wajib diisi.');

        $stmtRej = mysqli_prepare($koneksi, "UPDATE pengajuan_scrap SET status_scrap = 'Ditolak', dikonfirmasi_oleh_user = ?, catatan_penolakan = ? WHERE id_scrap = ?");
        mysqli_stmt_bind_param($stmtRej, 'isi', $userId, $alasanPenolakan, $idScrap);
        mysqli_stmt_execute($stmtRej);

        $stmtBrg = mysqli_prepare($koneksi, "UPDATE barang SET id_status = 4 WHERE id = ?");
        mysqli_stmt_bind_param($stmtBrg, 'i', $idBarangFisik);
        mysqli_stmt_execute($stmtBrg);

        // Notif ke Admin
        $pesanNotif = "User Cabang MENOLAK Scrap $namaBarang (SN: $sn). Alasan: $alasanPenolakan. Aset dikembalikan ke status Tersedia.";
        log_activity($koneksi, 'reject_scrap', "User cabang menolak scrap SN: $sn. Alasan: $alasanPenolakan");
    }

    // Kirim notifikasi ke Admin
    $stmtNotif = mysqli_prepare($koneksi, "INSERT INTO system_notifications (target_role, title, message, notification_type, is_read, created_at) VALUES ('admin', 'Konfirmasi Scrap Aset', ?, 'general', 0, NOW())");
    mysqli_stmt_bind_param($stmtNotif, 's', $pesanNotif);
    mysqli_stmt_execute($stmtNotif);
    mysqli_stmt_close($stmtNotif);

    mysqli_commit($koneksi);

    $msgResult = $action === 'approve' ? 'Aset berhasil di Scrap (Dimusnahkan).' : 'Pengajuan Scrap ditolak, aset kembali ke inventory Cabang.';
    echo json_encode(['status' => 'success', 'message' => $msgResult]);

} catch (Exception $e) {
    mysqli_rollback($koneksi);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>