<?php

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

$id_pengiriman = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$resi_keluar = '';

$currentUser = current_user();
$nama_user_login = $currentUser['username'] ?? $currentUser['email'] ?? 'Nama User Tidak Terbaca';

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

<style>
    /* Styling Form Modal - TEMA HEXINDO CLEAN */
    .alert-custom {
        background-color: #FFF8E1;
        border-left: 4px solid #F25C05; /* Oranye Hexindo Terang */
        border-radius: 6px;
        padding: 1rem 1.2rem;
        display: flex;
        gap: 12px;
        color: #854D0E;
        font-size: 0.9rem;
        line-height: 1.5;
        margin-bottom: 1.5rem;
    }

    .alert-custom i {
        font-size: 1.3rem;
        color: #E64312;
        margin-top: -2px;
    }

    .form-label-custom {
        font-weight: 600;
        color: #333333;
        font-size: 0.88rem;
        margin-bottom: 0.5rem;
        display: block;
    }

    /* Ubah warna bintang wajib isi jadi merah/oranye pekat */
    .asterisk-orange {
        color: #D32F2F;
        font-weight: bold;
    }

    .form-control-custom {
        border: 1px solid #E0E4E8;
        border-radius: 6px;
        padding: 0.6rem 0.8rem;
        font-size: 0.95rem;
        color: #333333;
        transition: all 0.2s ease;
        background-color: #ffffff;
        box-shadow: none !important;
    }

    .form-control-custom:focus {
        border-color: #E64312;
        box-shadow: 0 0 0 0.25rem rgba(230, 67, 18, 0.1) !important;
        outline: none;
    }

    .form-control-readonly {
        background-color: #F4F6F9; /* Abu-abu muda khas Hexindo */
        border: 1px solid #E0E4E8;
        color: #666666;
        font-weight: 600;
        cursor: not-allowed;
    }
    
    .form-control-readonly:focus {
        border-color: #E0E4E8; /* Kunci border saat focus */
        box-shadow: none !important;
    }

    .form-text-custom {
        font-size: 0.8rem;
        color: #666666; /* Warna teks note standar */
        margin-top: 6px;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    /* Tombol Oranye Hexindo */
    .btn-submit-modal {
        background-color: #E64312;
        color: white;
        border: none;
        border-radius: 6px;
        padding: 0.7rem 1.5rem;
        font-weight: 600;
        font-size: 0.95rem;
        transition: all 0.2s ease;
        width: 100%;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .btn-submit-modal:hover {
        background-color: #F25C05;
        color: white;
    }
</style>

<form id="formTerimaCabang" enctype="multipart/form-data">
    <!-- Input hidden untuk ID Pengiriman -->
    <input type="hidden" name="id_pengiriman" value="<?= $id_pengiriman ?>">

    <!-- Custom Alert Warning Hexindo -->
    <div class="alert-custom">
        <i class="bi bi-info-circle-fill"></i>
        <div>Silakan lengkapi form di bawah ini. Setelah disimpan, status pengiriman barang akan otomatis ditandai sebagai <b>Selesai / Diterima</b> oleh sistem.</div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label-custom">Tanggal Diterima <span class="asterisk-orange">*</span></label>
            <input type="date" name="tanggal_diterima" class="form-control form-control-custom" required value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
        </div>

        <div class="col-md-6">
            <label class="form-label-custom">Nama Penerima</label>
            <input type="text" name="nama_penerima" class="form-control form-control-custom form-control-readonly"
                value="<?= htmlspecialchars($nama_user_login, ENT_QUOTES, 'UTF-8') ?>"
                readonly required>
            <small class="text-muted" style="font-size: 0.75rem;">Otomatis menyesuaikan akun login.</small>
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label-custom">Nomor Resi / AWB Paket</label>
        <input type="text" name="nomor_resi_masuk" class="form-control form-control-custom form-control-readonly" required value="<?= htmlspecialchars($resi_keluar, ENT_QUOTES, 'UTF-8') ?>" readonly>
        <div class="form-text-custom"><i class="bi bi-lock-fill"></i> Diisi otomatis dari sistem Logistik Head Office.</div>
    </div>

    <div class="mb-4">
        <label class="form-label-custom">Foto Bukti Terima Fisik <span class="asterisk-orange">*</span></label>
        <input type="file" name="foto_barang_diterima" class="form-control form-control-custom" required accept=".jpg,.jpeg,.png,.gif,.webp">
        <div class="form-text-custom"><i class="bi bi-camera"></i> Silakan upload foto resi paket atau foto fisik barang yang tiba (Maks 2MB).</div>
    </div>

    <div class="mt-4 border-top pt-3">
        <button type="submit" class="btn-submit-modal" id="btnSubmitTerima">
            <i class="bi bi-check2-all me-2"></i> Konfirmasi Penerimaan Barang
        </button>
    </div>
</form>