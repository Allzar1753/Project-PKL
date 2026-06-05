<?php
/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_pengiriman    = (int)($_POST['id_pengiriman'] ?? 0);
    $tipe_penerima    = $_POST['tipe_penerima'] ?? 'CABANG';
    $tanggal_diterima = $_POST['tanggal_diterima'] ?? date('Y-m-d');
    $nomor_resi_masuk = mysqli_real_escape_string($koneksi, $_POST['nomor_resi_masuk'] ?? '');
    
    $currentUser = current_user();
    $nama_user_login = $currentUser['username'] ?? $currentUser['email'] ?? ($_POST['nama_penerima'] ?? '');
    $nama_penerima = mysqli_real_escape_string($koneksi, $nama_user_login);

    if ($id_pengiriman <= 0 || empty($nama_penerima) || empty($nomor_resi_masuk)) {
        echo json_encode(['status' => 'error', 'message' => 'Harap lengkapi semua field.']);
        exit;
    }

    // --- PROSES UPLOAD FOTO ---
    $foto_barang_diterima = null;
    if (!empty($_FILES['foto_barang_diterima']['name'])) {
        $ext = strtolower(pathinfo($_FILES['foto_barang_diterima']['name'], PATHINFO_EXTENSION));
        $prefix = ($tipe_penerima === 'HO') ? 'terima_ho_' : 'terima_cabang_';
        $foto_barang_diterima = uniqid($prefix, true) . '.' . $ext;
        move_uploaded_file($_FILES['foto_barang_diterima']['tmp_name'], '../assets/images/' . $foto_barang_diterima);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Foto bukti terima wajib diupload.']);
        exit;
    }

    mysqli_begin_transaction($koneksi);
    try {
        
        // ==========================================================
        // LOGIKA JIKA YANG MENERIMA ADALAH ADMIN HO (DARI APPROVAL)
        // ==========================================================
        // ==========================================================
        // LOGIKA JIKA YANG MENERIMA ADALAH ADMIN HO (DARI APPROVAL)
        // ==========================================================
        if ($tipe_penerima === 'HO') {
            if (!is_admin()) throw new Exception('Hanya admin yang dapat memproses data HO.');
            
            $idBranchHO = 40; // Hardcode ID HO Jakarta
            $adminId = current_user_id() ? (int) current_user_id() : null;
            
            // Format datetime untuk kolom disetujui_pada
            $datetime_diterima = $tanggal_diterima . ' ' . date('H:i:s');
            
            // Nama penerima disimpan di catatan_admin
            $catatan_admin = "Diterima fisik oleh: " . $nama_penerima;

            // 1. Update pengiriman_cabang_ho (SEKARANG MEMASUKKAN FOTO BUKTI)
            $stmt = mysqli_prepare($koneksi, "
                UPDATE pengiriman_cabang_ho 
                SET status_pengiriman = 'Sudah diterima HO', 
                    disetujui_oleh = ?,
                    disetujui_pada = ?,
                    catatan_admin = ?,
                    foto_barang_diterima_ho = ?
                WHERE id_pengiriman_ho = ?
            ");
            
            // Bind param: i (int), s (string), s (string), s (string), i (int)
            mysqli_stmt_bind_param($stmt, 'isssi', $adminId, $datetime_diterima, $catatan_admin, $foto_barang_diterima, $id_pengiriman);
            mysqli_stmt_execute($stmt);
            
            if (mysqli_stmt_affected_rows($stmt) <= 0) {
                throw new Exception('Data pengiriman tidak valid atau sudah diproses.');
            }

            // 2. Notifikasi ke Cabang & Ambil Data
            $stmtGetInfo = mysqli_prepare($koneksi, "SELECT serial_number, catatan_user, branch_asal, nomor_resi_keluar, pemilik_barang FROM pengiriman_cabang_ho WHERE id_pengiriman_ho = ?");
            mysqli_stmt_bind_param($stmtGetInfo, 'i', $id_pengiriman);
            mysqli_stmt_execute($stmtGetInfo);
            $resInfo = mysqli_stmt_get_result($stmtGetInfo);
            $dataInfo = mysqli_fetch_assoc($resInfo);
            
            if ($dataInfo) {
                $sn = $dataInfo['serial_number'];
                $catatanUser = $dataInfo['catatan_user'];
                $pemilikBarang = $dataInfo['pemilik_barang'];

                // Kirim Notif ke cabang
                if ($dataInfo['branch_asal'] > 0) {
                    $stmtNotif = mysqli_prepare($koneksi, "INSERT INTO system_notifications (target_role, target_branch_id, title, message, link, is_read) VALUES ('user', ?, 'Barang sudah diterima HO', ?, '../Barang/index.php?filter=keluar', 0)");
                    $msgNotif = 'Pengiriman resi ' . $dataInfo['nomor_resi_keluar'] . ' sudah tiba dan diterima Admin HO.';
                    mysqli_stmt_bind_param($stmtNotif, 'is', $dataInfo['branch_asal'], $msgNotif);
                    mysqli_stmt_execute($stmtNotif);
                }

                // 3. Update master barang (Tarik kembali ke HO)
                $stmtUpdateBarang = mysqli_prepare($koneksi, "
                    UPDATE barang SET 
                        id_branch = ?, 
                        status = 'Diterima', 
                        bermasalah = 'Iya', 
                        keterangan_masalah = ?,
                        tanggal_terima = ?,
                        id_status = 5,
                        `user` = CASE WHEN ? != '' AND ? != '0' THEN ? ELSE `user` END
                    WHERE serial_number = ?
                ");
                mysqli_stmt_bind_param($stmtUpdateBarang, 'issssss', $idBranchHO, $catatanUser, $tanggal_diterima, $pemilikBarang, $pemilikBarang, $pemilikBarang, $sn);
                mysqli_stmt_execute($stmtUpdateBarang);
            }
        }
        
        // ==========================================================
        // LOGIKA JIKA YANG MENERIMA ADALAH USER CABANG (DARI INDEX)
        // ==========================================================
        else {
            $myBranchId = current_user_branch_id();

            // 1. Update barang_pengiriman
            $stmt = mysqli_prepare($koneksi, "
                UPDATE barang_pengiriman 
                SET status_pengiriman = 'Sudah diterima', tanggal_diterima = ?, nama_penerima = ?, nomor_resi_masuk = ?, foto_barang_diterima = ? 
                WHERE id_pengiriman = ? AND branch_tujuan = ?
            ");
            mysqli_stmt_bind_param($stmt, 'ssssii', $tanggal_diterima, $nama_penerima, $nomor_resi_masuk, $foto_barang_diterima, $id_pengiriman, $myBranchId);
            mysqli_stmt_execute($stmt);

            if (mysqli_stmt_affected_rows($stmt) <= 0) throw new Exception('Gagal: Data pengiriman tidak valid.');

            // 2. Update master barang (Masuk ke Cabang)
            $qBarang = mysqli_query($koneksi, "SELECT bp.id_barang, b.serial_number FROM barang_pengiriman bp JOIN barang b ON bp.id_barang = b.id WHERE bp.id_pengiriman = $id_pengiriman");
            if ($rowB = mysqli_fetch_assoc($qBarang)) {
                $id_barang_pk = (int)$rowB['id_barang'];
                $serialNumber = $rowB['serial_number'];

                $stmtUpdateBarang = mysqli_prepare($koneksi, "UPDATE barang SET id_branch = ?, status = 'Tersedia', bermasalah = 'Tidak', keterangan_masalah = NULL, tanggal_terima = ?, id_status = 4 WHERE id = ?");
                mysqli_stmt_bind_param($stmtUpdateBarang, 'isi', $myBranchId, $tanggal_diterima, $id_barang_pk);
                mysqli_stmt_execute($stmtUpdateBarang);

                // 3. Selesaikan status pengembalian cabang -> HO (jika ada cycle)
                $stmtCompleteHo = mysqli_prepare($koneksi, "UPDATE pengiriman_cabang_ho SET status_pengiriman = 'Selesai' WHERE branch_asal = ? AND serial_number = ? AND status_pengiriman = 'Sudah diterima HO'");
                if ($stmtCompleteHo) {
                    mysqli_stmt_bind_param($stmtCompleteHo, 'is', $myBranchId, $serialNumber);
                    mysqli_stmt_execute($stmtCompleteHo);
                }
            }
        }

        mysqli_commit($koneksi);
        echo json_encode(['status' => 'success', 'message' => 'Barang berhasil diterima oleh ' . $nama_penerima]);
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}