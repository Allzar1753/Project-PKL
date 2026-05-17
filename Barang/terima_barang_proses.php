<?php

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

// Pastikan output selalu JSON
header('Content-Type: application/json');

if (is_admin()) {
    echo json_encode(['status' => 'error', 'message' => 'Hanya user cabang yang bisa memproses form ini.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_pengiriman    = (int)($_POST['id_pengiriman'] ?? 0);
    $tanggal_diterima = $_POST['tanggal_diterima'] ?? '';

    $currentUser = current_user();
    $nama_user_login = $currentUser['username'] ?? $currentUser['email'] ?? ($_POST['nama_penerima'] ?? '');

    $nama_penerima = mysqli_real_escape_string($koneksi, $nama_user_login);

    $nomor_resi_masuk = mysqli_real_escape_string($koneksi, $_POST['nomor_resi_masuk'] ?? '');
    $myBranchId       = current_user_branch_id();

    // Validasi tetap dilakukan
    if ($id_pengiriman <= 0 || empty($tanggal_diterima) || empty($nama_penerima) || empty($nomor_resi_masuk)) {
        echo json_encode(['status' => 'error', 'message' => 'Harap lengkapi semua field yang wajib diisi.']);
        exit;
    }

    // Proses Upload Foto (Tetap sama)
    $foto_barang_diterima = null;
    if (!empty($_FILES['foto_barang_diterima']['name'])) {
        $ext = strtolower(pathinfo($_FILES['foto_barang_diterima']['name'], PATHINFO_EXTENSION));
        $foto_barang_diterima = uniqid('terima_', true) . '.' . $ext;
        move_uploaded_file($_FILES['foto_barang_diterima']['tmp_name'], '../assets/images/' . $foto_barang_diterima);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Foto bukti terima wajib diupload.']);
        exit;
    }

    mysqli_begin_transaction($koneksi);
    try {
        // 1. Update tabel pengiriman
        // Pastikan status_pengiriman yang bisa diupdate adalah 'Sedang perjalanan'
        $stmt = mysqli_prepare($koneksi, "
            UPDATE barang_pengiriman 
            SET status_pengiriman = 'Sudah diterima', 
                tanggal_diterima = ?, 
                nama_penerima = ?, 
                nomor_resi_masuk = ?, 
                foto_barang_diterima = ? 
            WHERE id_pengiriman = ? 
              AND branch_tujuan = ? 
              AND status_pengiriman = 'Sedang perjalanan'
        ");

        mysqli_stmt_bind_param($stmt, 'ssssii', $tanggal_diterima, $nama_penerima, $nomor_resi_masuk, $foto_barang_diterima, $id_pengiriman, $myBranchId);
        mysqli_stmt_execute($stmt);

        if (mysqli_stmt_affected_rows($stmt) <= 0) {
            throw new Exception('Gagal: Data pengiriman tidak valid, sudah dikonfirmasi, atau Anda tidak berhak memproses ini.');
        }

        // 2. Update status barang agar kembali ke inventory cabang sebagai asset tersedia
        $qBarang = mysqli_query($koneksi, "SELECT bp.id_barang, b.serial_number FROM barang_pengiriman bp JOIN barang b ON bp.id_barang = b.id WHERE bp.id_pengiriman = $id_pengiriman");
        if ($rowB = mysqli_fetch_assoc($qBarang)) {
            $id_barang_pk = (int)$rowB['id_barang'];
            $serialNumber = $rowB['serial_number'];
            $userId = current_user_id() ? (int) current_user_id() : 0;

            $stmtUpdateBarang = mysqli_prepare($koneksi, "UPDATE barang SET 
                id_branch = ?,
                status = 'Tersedia',
                user = ?,
                user_id = ?,
                bermasalah = 'Tidak',
                keterangan_masalah = NULL,
                id_status = 4
                WHERE id = ?");

            if (!$stmtUpdateBarang) {
                throw new Exception('Gagal menyiapkan update barang: ' . mysqli_error($koneksi));
            }

            mysqli_stmt_bind_param($stmtUpdateBarang, 'isii', $myBranchId, $nama_penerima, $userId, $id_barang_pk);
            mysqli_stmt_execute($stmtUpdateBarang);

            if (mysqli_stmt_affected_rows($stmtUpdateBarang) <= 0) {
                mysqli_stmt_close($stmtUpdateBarang);

                $stmtFallback = mysqli_prepare($koneksi, "UPDATE barang SET 
                    id_branch = ?,
                    status = 'Tersedia',
                    user = ?,
                    user_id = ?,
                    bermasalah = 'Tidak',
                    keterangan_masalah = NULL,
                    id_status = 4
                    WHERE serial_number = ? LIMIT 1");

                if (!$stmtFallback) {
                    throw new Exception('Gagal menyiapkan fallback update barang: ' . mysqli_error($koneksi));
                }

                mysqli_stmt_bind_param($stmtFallback, 'isis', $myBranchId, $nama_penerima, $userId, $serialNumber);
                mysqli_stmt_execute($stmtFallback);
                if (mysqli_stmt_affected_rows($stmtFallback) <= 0) {
                    throw new Exception('Gagal update data barang cabang.');
                }
                mysqli_stmt_close($stmtFallback);
            } else {
                mysqli_stmt_close($stmtUpdateBarang);
            }

            // 3. Tandai pengiriman cabang ke HO sebagai selesai apabila barang sudah kembali ke cabang
            $stmtCompleteHo = mysqli_prepare($koneksi, "UPDATE pengiriman_cabang_ho SET status_pengiriman = 'Selesai' WHERE branch_asal = ? AND serial_number = ? AND status_pengiriman = 'Sudah diterima HO'");
            if ($stmtCompleteHo) {
                mysqli_stmt_bind_param($stmtCompleteHo, 'is', $myBranchId, $serialNumber);
                mysqli_stmt_execute($stmtCompleteHo);
                mysqli_stmt_close($stmtCompleteHo);
            }
        } else {
            throw new Exception('Data pengiriman barang tidak ditemukan.');
        }

        mysqli_commit($koneksi);
        echo json_encode(['status' => 'success', 'message' => 'Konfirmasi berhasil! Barang telah diterima oleh ' . $nama_penerima]);
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
