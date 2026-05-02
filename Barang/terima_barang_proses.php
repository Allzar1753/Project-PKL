<?php
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
    $nama_penerima    = mysqli_real_escape_string($koneksi, $_POST['nama_penerima'] ?? '');
    $nomor_resi_masuk = mysqli_real_escape_string($koneksi, $_POST['nomor_resi_masuk'] ?? '');
    $myBranchId       = current_user_branch_id();

    if ($id_pengiriman <= 0 || empty($tanggal_diterima) || empty($nama_penerima) || empty($nomor_resi_masuk)) {
        echo json_encode(['status' => 'error', 'message' => 'Harap lengkapi semua field yang wajib diisi.']);
        exit;
    }

    // Proses Upload Foto
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
        $stmt = mysqli_prepare($koneksi, "
            UPDATE barang_pengiriman 
            SET status_pengiriman = 'Sudah diterima', 
                tanggal_diterima = ?, 
                nama_penerima = ?, 
                nomor_resi_masuk = ?, 
                foto_barang_diterima = ? 
            WHERE id_pengiriman = ? AND branch_tujuan = ? AND status_pengiriman = 'Sedang perjalanan'
        ");
        mysqli_stmt_bind_param($stmt, 'ssssii', $tanggal_diterima, $nama_penerima, $nomor_resi_masuk, $foto_barang_diterima, $id_pengiriman, $myBranchId);
        mysqli_stmt_execute($stmt);
        
        if (mysqli_stmt_affected_rows($stmt) <= 0) {
            throw new Exception('Gagal: Data pengiriman tidak valid atau sudah dikonfirmasi.');
        }

        // 2. Ambil ID Barang dari tabel pengiriman
        $qBarang = mysqli_query($koneksi, "SELECT id_barang FROM barang_pengiriman WHERE id_pengiriman = $id_pengiriman");
        if ($rowB = mysqli_fetch_assoc($qBarang)) {
            $id_barang_pk = (int)$rowB['id_barang'];
            
            // 3. UPDATE TABEL tb_barang
            // Disini KUNCINYA: kita update status jadi 'Diterima' agar TIDAK MUNCUL di Total Inventaris
            $updateBarang = "UPDATE tb_barang SET 
                             id_branch = $myBranchId, 
                             status = 'Diterima' 
                             WHERE id_barang = $id_barang_pk";
            
            if (!mysqli_query($koneksi, $updateBarang)) {
                throw new Exception('Gagal update tabel tb_barang: ' . mysqli_error($koneksi));
            }
        }

        mysqli_commit($koneksi);
        echo json_encode(['status' => 'success', 'message' => 'Barang berhasil diterima dan dicatat sebagai barang masuk cabang.']);
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>