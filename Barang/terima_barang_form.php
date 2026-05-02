<?php
include '../config/koneksi.php';

$id_pengiriman = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$resi_keluar = '';

// Ambil nomor resi keluar dari database
if ($id_pengiriman > 0) {
    $stmt = mysqli_prepare($koneksi, "SELECT nomor_resi_keluar FROM barang_pengiriman WHERE id_pengiriman = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $id_pengiriman);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) {
            $resi_keluar = $row['nomor_resi_keluar'];
        }
        mysqli_stmt_close($stmt);
    }
}
?>
<form id="formTerimaCabang" enctype="multipart/form-data">
    <!-- Input hidden untuk ID Pengiriman -->
    <input type="hidden" name="id_pengiriman" value="<?= $id_pengiriman ?>">
    
    <div class="alert alert-info small mb-3">
        Lengkapi form di bawah ini. Setelah disimpan, barang akan ditandai sebagai <b>Selesai/Diterima</b> dan tidak akan muncul di Stok Inventaris Aktif.
    </div>

    <div class="mb-3">
        <label class="fw-bold mb-1">Tanggal Diterima <span class="text-danger">*</span></label>
        <input type="date" name="tanggal_diterima" class="form-control" required value="<?= date('Y-m-d') ?>">
    </div>
    
    <div class="mb-3">
        <label class="fw-bold mb-1">Nama Penerima <span class="text-danger">*</span></label>
        <input type="text" name="nama_penerima" class="form-control" required placeholder="Nama staf yang menerima barang">
    </div>

    <div class="mb-3">
        <label class="fw-bold mb-1">Nomor Resi (Otomatis dari HO)</label>
        <input type="text" name="nomor_resi_masuk" class="form-control bg-light" required value="<?= htmlspecialchars($resi_keluar, ENT_QUOTES, 'UTF-8') ?>" readonly style="cursor: not-allowed;">
    </div>

    <div class="mb-4">
        <label class="fw-bold mb-1">Foto Barang / Bukti Terima <span class="text-danger">*</span></label>
        <input type="file" name="foto_barang_diterima" class="form-control" required accept="image/*">
        <small class="text-muted">Upload foto paket atau barang sebagai bukti fisik.</small>
    </div>

    <div class="text-end">
        <button type="submit" class="btn btn-success fw-bold rounded-pill px-4" id="btnSubmitTerima">
            <i class="bi bi-check-circle me-1"></i> Konfirmasi Penerimaan Selesai
        </button>
    </div>
</form>