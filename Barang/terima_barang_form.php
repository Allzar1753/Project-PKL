<?php

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

$id_pengiriman = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$resi_keluar = '';

$nama_user_login = $_SESSION['username'] ?? 'Nama User Tidak Terbaca';

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
    /* Styling khusus Form di dalam Modal - TEMA ORANGE */
    .alert-custom {
        background: #fff4e6;
        border: 1px solid rgba(255, 152, 0, 0.3);
        border-radius: 14px;
        padding: 1rem 1.2rem;
        display: flex;
        gap: 12px;
        color: #b45309;
        font-size: 0.85rem;
        line-height: 1.5;
        margin-bottom: 1.5rem;
    }

    .alert-custom i {
        font-size: 1.4rem;
        color: #ff7a00;
        margin-top: -2px;
    }

    .form-label-custom {
        font-size: 0.8rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #444;
        margin-bottom: 6px;
        display: block;
    }

    /* Ubah warna bintang wajib isi jadi orange pekat */
    .asterisk-orange {
        color: #ea580c;
    }

    .form-control-custom {
        border-radius: 12px;
        padding: 0.75rem 1rem;
        border: 1px solid #e5e7eb;
        font-size: 0.95rem;
        color: #111;
        transition: all 0.2s ease;
        background-color: #fff;
    }

    .form-control-custom:focus {
        border-color: #ff9800;
        box-shadow: 0 0 0 4px rgba(255, 152, 0, 0.15);
        outline: none;
    }

    .form-control-readonly {
        background-color: #fffaf5;
        border: 1px dashed #ffb000;
        color: #9a5410;
        font-weight: 700;
        cursor: not-allowed;
    }

    .form-text-custom {
        font-size: 0.8rem;
        color: #8b5cf6;
        /* Warna ikon note sedikit redup */
        color: #9a5410;
        margin-top: 6px;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    /* Tombol Full Orange */
    .btn-submit-modal {
        background: linear-gradient(135deg, #ff7a00, #ffb000);
        color: white;
        border: none;
        border-radius: 12px;
        padding: 0.85rem 1.5rem;
        font-weight: 800;
        font-size: 0.95rem;
        transition: all 0.3s ease;
        box-shadow: 0 8px 18px rgba(255, 122, 0, 0.25);
        width: 100%;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .btn-submit-modal:hover {
        background: linear-gradient(135deg, #e66e00, #f59e0b);
        transform: translateY(-2px);
        box-shadow: 0 12px 25px rgba(255, 122, 0, 0.35);
        color: white;
    }
</style>

<form id="formTerimaCabang" enctype="multipart/form-data">
    <!-- Input hidden untuk ID Pengiriman -->
    <input type="hidden" name="id_pengiriman" value="<?= $id_pengiriman ?>">

    <!-- Custom Alert Warning Orange -->
    <div class="alert-custom">
        <i class="bi bi-exclamation-circle-fill"></i>
        <div>Lengkapi form di bawah ini. Setelah disimpan, status pengiriman barang akan ditandai sebagai <b style="color: #ea580c;">Selesai / Diterima</b>.</div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label-custom">Tanggal Diterima <span class="asterisk-orange">*</span></label>
            <input type="date" name="tanggal_diterima" class="form-control form-control-custom" required value="<?= date('Y-m-d') ?>">
        </div>

        <div class="col-md-6">
            <label class="form-label-custom">Nama Penerima <span class="asterisk-orange">*</span></label>
            <input type="text" name="nama_penerima" class="form-control form-control-custom form-control-readonly"
                value="<?= htmlspecialchars($nama_user_login, ENT_QUOTES, 'UTF-8') ?>"
                readonly required>
            <small class="text-muted" style="font-size: 0.7rem;">Otomatis sesuai akun login.</small>
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label-custom">Nomor Resi Paket</label>
        <input type="text" name="nomor_resi_masuk" class="form-control form-control-custom form-control-readonly" required value="<?= htmlspecialchars($resi_keluar, ENT_QUOTES, 'UTF-8') ?>" readonly>
        <div class="form-text-custom"><i class="bi bi-lock-fill"></i> Diisi otomatis dari sistem Head Office.</div>
    </div>

    <div class="mb-4">
        <label class="form-label-custom">Foto Bukti Terima Fisik <span class="asterisk-orange">*</span></label>
        <input type="file" name="foto_barang_diterima" class="form-control form-control-custom" required accept="image/*">
        <div class="form-text-custom"><i class="bi bi-image"></i> Upload foto resi paket atau foto fisik barang yang tiba.</div>
    </div>

    <div class="mt-4 border-top pt-3">
        <button type="submit" class="btn-submit-modal" id="btnSubmitTerima">
            <i class="bi bi-check2-all me-2 fs-5"></i> Konfirmasi Penerimaan
        </button>
    </div>
</form>