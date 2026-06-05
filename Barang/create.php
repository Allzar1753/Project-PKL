<?php

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';
// require_once '../config/mail.php'; <-- SUDAH DIHAPUS/DIMATIKAN

require_admin();

// Mengambil data untuk dropdown form
$merk   = mysqli_query($koneksi, "SELECT * FROM tb_merk ORDER BY nama_merk ASC");
$tipe   = mysqli_query($koneksi, "SELECT * FROM tb_tipe ORDER BY nama_tipe ASC");
$jenis  = mysqli_query($koneksi, "SELECT * FROM tb_jenis ORDER BY nama_jenis ASC");
$branch = mysqli_query($koneksi, "SELECT * FROM tb_branch ORDER BY nama_branch ASC");
$barang = mysqli_query($koneksi, "SELECT * FROM tb_barang ORDER BY nama_barang ASC");

function esc(mysqli $koneksi, $value): string
{
    return mysqli_real_escape_string($koneksi, trim((string) $value));
}

function uploadImage(string $fieldName, string $targetDir = "../assets/images/"): array
{
    if (!isset($_FILES[$fieldName]) || empty($_FILES[$fieldName]['name'])) {
        return ['status' => 'empty', 'filename' => ''];
    }

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed, true)) {
        return ['status' => 'error', 'message' => "Format file {$fieldName} tidak diperbolehkan"];
    }

    if ($_FILES[$fieldName]['size'] > 2000000) {
        return ['status' => 'error', 'message' => "Ukuran file {$fieldName} maksimal 2MB"];
    }

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $filename = uniqid($fieldName . '_', true) . '.' . $ext;

    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetDir . $filename)) {
        return ['status' => 'error', 'message' => "Gagal mengupload file {$fieldName}"];
    }

    return ['status' => 'success', 'filename' => $filename];
}

// PROSES SIMPAN DATA (AJAX REQUEST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $required = [
        'id_barang', 'id_merk', 'serial_number', 'id_tipe', 
        'id_jenis', 'tanggal_terima', 'bermasalah', 'id_branch', 'user'
    ];

    foreach ($required as $field) {
        if (!isset($_POST[$field]) || trim((string) $_POST[$field]) === '') {
            echo json_encode(['status' => 'error', 'message' => "Field " . str_replace('id_', '', $field) . " wajib diisi"]);
            exit;
        }
    }

    $no_asset      = esc($koneksi, $_POST['no_asset']);
    $id_barang     = (int) $_POST['id_barang'];
    $id_merk       = (int) $_POST['id_merk'];
    $serial_number = esc($koneksi, $_POST['serial_number']);
    $id_tipe       = (int) $_POST['id_tipe'];
    $id_jenis      = (int) $_POST['id_jenis'];
    $tanggal_terima = esc($koneksi, $_POST['tanggal_terima']);
    $today = date('Y-m-d');
    
    if ($tanggal_terima < $today) {
        echo json_encode(['status' => 'error', 'message' => 'Tanggal terima tidak boleh tanggal yang lampau']);
        exit;
    }
    
    $bermasalah    = esc($koneksi, $_POST['bermasalah']);
    $id_branch     = (int) $_POST['id_branch'];
    $user          = esc($koneksi, $_POST['user']);

    $user_id_sistem = (int) current_user_id();

    $keterangan_masalah = ($bermasalah === 'Iya') ? esc($koneksi, $_POST['keterangan_masalah'] ?? '') : null;
    if ($bermasalah === 'Iya' && empty($keterangan_masalah)) {
        echo json_encode(['status' => 'error', 'message' => 'Keterangan masalah wajib diisi jika barang bermasalah']);
        exit;
    }

    $getBarangInput = mysqli_query($koneksi, "SELECT nama_barang FROM tb_barang WHERE id_barang = $id_barang LIMIT 1");
    $dataBarangInput = mysqli_fetch_assoc($getBarangInput);
    $namaBarangInput = strtolower(trim($dataBarangInput['nama_barang']));

    $cekSerial = mysqli_query($koneksi, "SELECT id FROM barang WHERE serial_number = '$serial_number' LIMIT 1");
    if (mysqli_num_rows($cekSerial) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Serial Number sudah terdaftar']);
        exit;
    }

    if (!empty($no_asset)) {
        $pasanganDiizinkan = ['monitor', 'cpu'];
        $inputAdalahPasangan = in_array($namaBarangInput, $pasanganDiizinkan, true);

        $cekNoAsset = mysqli_query($koneksi, "
            SELECT b.id, tb.nama_barang
            FROM barang b 
            INNER JOIN tb_barang tb ON b.id_barang = tb.id_barang
            WHERE b.no_asset = '$no_asset' 
        ");

        if (mysqli_num_rows($cekNoAsset) > 0) {
            $barangSudahAda = [];

            while ($row = mysqli_fetch_assoc($cekNoAsset)) {
                $namaDb = strtolower(trim($row['nama_barang']));
                $barangSudahAda[] = $namaDb;

                if (!in_array($namaDb, $pasanganDiizinkan, true)) {
                    echo json_encode(['status' => 'error',  'message' => "No asset '$no_asset' Sudah memiliki " . ucwords($namaBarangInput) . " terdaftar."]);
                    exit;
                }
            }

            if (!$inputAdalahPasangan) {
                echo json_encode(['status' => 'error', 'message' => "No Asset '$no_asset' khusus digunakan untuk pasangan CPU & Monitor."]);
                exit;
            }

            if (count($barangSudahAda) >= 2) {
                echo json_encode(['status' => 'error', 'message' => "No Asset '$no_asset' ini sudah lengkap terisi oleh (Monitor & CPU)."]);
                exit;
            }
        }
    }

    $uploadFoto = uploadImage('foto');
    if ($uploadFoto['status'] === 'error') {
        echo json_encode(['status' => 'error', 'message' => $uploadFoto['message']]);
        exit;
    }

    $foto = ($uploadFoto['status'] === 'success') ? $uploadFoto['filename'] : null;
    $id_status = ($bermasalah === 'Iya') ? 5 : 4;
    $statusField = 'Tersedia';

    $queryBarang = "INSERT INTO barang (no_asset, id_barang, id_merk, serial_number, id_tipe, id_jenis, tanggal_terima, bermasalah, keterangan_masalah, id_status, id_branch, foto, `user`, user_id, status) 
                    VALUES ('$no_asset', '$id_barang', '$id_merk', '$serial_number', '$id_tipe', '$id_jenis', '$tanggal_terima', '$bermasalah', " . ($keterangan_masalah ? "'$keterangan_masalah'" : "NULL") . ", '$id_status', '$id_branch', " . ($foto ? "'$foto'" : "NULL") . ", '$user', '$user_id_sistem', '$statusField')";

    mysqli_begin_transaction($koneksi);
    try {
        if (!mysqli_query($koneksi, $queryBarang)) throw new Exception(mysqli_error($koneksi));
        mysqli_commit($koneksi);

        echo json_encode(['status' => 'success', 'message' => 'Data barang berhasil ditambahkan!']);
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        echo json_encode(['status' => 'error', 'message' => 'Gagal simpan: ' . $e->getMessage()]);
    }
    exit;
}
?>

<!-- STYLE KHUSUS UNTUK FORM INI AGAR SINKRON DENGAN TEMA HEXINDO -->
<style>
    /* 1. Mengatur jarak vertikal antar form input */
    #formCreate .col-md-6, 
    #formCreate .col-md-12 {
        margin-bottom: 1.2rem;
    }

    /* 2. Menyamakan tinggi Input Teks biasa */
    .form-control {
        border: 1px solid #E0E4E8;
        border-radius: 6px;
        padding: 0.5rem 0.75rem;
        font-size: 0.95rem;
        min-height: 42px; /* Tinggi standar agar sama dengan dropdown */
        box-shadow: none !important;
    }
    .form-control:focus {
        border-color: #E64312;
        box-shadow: 0 0 0 0.25rem rgba(230, 67, 18, 0.1) !important;
    }

    /* 3. Menyamakan tinggi Select2 (Dropdown) agar sejajar dengan Input Teks */
    .select2-container .select2-selection--single {
        border: 1px solid #E0E4E8 !important;
        border-radius: 6px !important;
        height: 42px !important; /* Samakan tingginya */
        display: flex !important;
        align-items: center !important;
        padding: 0.2rem 0.5rem;
        box-shadow: none !important;
    }
    
    /* Memperbaiki posisi panah dropdown Select2 */
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 40px !important;
        right: 8px !important;
    }
    
    /* Memperbaiki posisi tombol "X" (clear) pada Select2 */
    .select2-selection__clear {
        margin-right: 15px;
        font-size: 1.2rem;
    }

    /* 4. Merapikan Label */
    .form-label {
        font-weight: 600;
        color: #333333;
        font-size: 0.88rem;
        margin-bottom: 0.5rem;
        display: block;
    }
    
    /* Bintang merah wajib isi */
    .text-danger {
        font-weight: bold;
    }

    /* 5. Catatan Info */
    .alert-info-custom {
        background-color: #F0F7FF;
        border-left: 4px solid #0066CC;
        color: #004085;
        border-radius: 6px;
        padding: 1rem;
        font-size: 0.9rem;
        margin-bottom: 0.5rem;
    }

    /* 6. Tombol Hexindo */
    .btn-hexindo {
        background-color: #E64312;
        color: white;
        font-weight: 600;
        border: none;
        border-radius: 6px;
        padding: 0.6rem 1.5rem;
        transition: all 0.2s;
    }
    .btn-hexindo:hover {
        background-color: #F25C05;
        color: white;
    }
</style>

<form id="formCreate" enctype="multipart/form-data">
    <div class="row g-3">
        <div class="col-12">
            <div class="alert alert-info-custom mb-2">
                <i class="bi bi-info-circle-fill me-2"></i> 
                <b>Catatan:</b> Form create digunakan untuk menyimpan data barang yang akan dikirim ke branch inti.
                Tanggal yang diinput adalah tanggal kirim.
            </div>
        </div>

        <div class="col-md-6">
            <label class="form-label">No Asset</label>
            <input type="text" name="no_asset" class="form-control" placeholder="Boleh dikosongkan (Otomatis untuk CPU/Monitor)">
        </div>

        <div class="col-md-6">
            <label class="form-label">Serial Number / Service Tag <span class="text-danger">*</span></label>
            <input type="text" name="serial_number" class="form-control" required placeholder="Masukkan SN/Service Tag">
        </div>

        <div class="col-md-6">
            <label class="form-label">Kategori Barang <span class="text-danger">*</span></label>
            <select name="id_barang" id="id_barang" class="form-control select2" required>
                <option value="">Pilih Kategori...</option>
                <?php mysqli_data_seek($barang, 0);
                while ($row = mysqli_fetch_assoc($barang)): ?>
                    <option value="<?= (int) $row['id_barang'] ?>"><?= h($row['nama_barang']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Merk Barang <span class="text-danger">*</span></label>
            <select name="id_merk" id="id_merk" class="form-control select2" required>
                <option value="">Pilih Kategori Dulu...</option>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Tipe / Spesifikasi <span class="text-danger">*</span></label>
            <select name="id_tipe" id="id_tipe" class="form-control select2" required>
                <option value="">Pilih Merk Dulu...</option>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Jenis Kepemilikan <span class="text-danger">*</span></label>
            <select name="id_jenis" class="form-control select2" required>
                <option value="">Pilih Jenis...</option>
                <?php mysqli_data_seek($jenis, 0);
                while ($row = mysqli_fetch_assoc($jenis)): ?>
                    <option value="<?= (int) $row['id_jenis'] ?>"><?= h($row['nama_jenis']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Tanggal Terima <span class="text-danger">*</span></label>
            <input type="date" name="tanggal_terima" class="form-control" required min="<?= date('Y-m-d') ?>">
        </div>

        <div class="col-md-6">
            <label class="form-label">Lokasi Branch <span class="text-danger">*</span></label>
            <select name="id_branch" class="form-control select2" required>
                <option value="">Pilih Branch...</option>
                <?php mysqli_data_seek($branch, 0);
                while ($row = mysqli_fetch_assoc($branch)): ?>
                    <option value="<?= (int) $row['id_branch'] ?>"><?= h($row['nama_branch']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Nama User Pengguna <span class="text-danger">*</span></label>
            <input type="text" name="user" class="form-control" required placeholder="Masukkan nama pengguna alat">
        </div>

        <div class="col-md-6">
            <label class="form-label">Apakah Barang Bermasalah? <span class="text-danger">*</span></label>
            <select name="bermasalah" class="form-control select2" id="bermasalahSelect" required>
                <option value="">Pilih Kondisi...</option>
                <option value="Tidak">Tidak (Kondisi Normal)</option>
                <option value="Iya">Iya (Rusak/Kendala)</option>
            </select>
        </div>

        <div class="col-md-12" id="keteranganMasalahDiv" style="display:none;">
            <label class="form-label text-danger">Detail Keterangan Masalah <span class="text-danger">*</span></label>
            <textarea name="keterangan_masalah" class="form-control" rows="3" placeholder="Jelaskan secara detail masalah atau kerusakan pada barang ini..."></textarea>
        </div>

        <div class="col-md-12 border-top pt-3 mt-3">
            <label class="form-label">Upload Foto Fisik Barang <span class="text-danger">*</span></label>
            <input type="file" name="foto" class="form-control" id="fotoInput" accept=".jpg,.jpeg,.png,.gif,.webp">
            <div class="mt-2 text-muted small">Format: JPG, PNG, WEBP. Maksimal 2MB.</div>
            <img id="previewFoto" class="shadow-sm border mt-3" style="max-height: 150px; display: none; border-radius: 8px; object-fit: cover;">
        </div>

        <!-- Bagian Tombol Simpan -->
        <div class="col-md-12 text-end mt-4 pt-3 border-top">
            <!-- Tombol Batal untuk menutup modal -->
            <button type="button" class="btn btn-light border px-4 me-2 rounded-2" data-bs-dismiss="modal">Batal</button>
            <!-- Tombol Simpan (Tema Hexindo) -->
            <button type="submit" class="btn btn-hexindo" id="btnSimpanBarang">
                <span class="btn-text"><i class="bi bi-floppy me-1"></i> Simpan Data Aset</span>
                <span class="btn-loading d-none">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Menyimpan...
                </span>
            </button>
        </div>
    </div>
</form>

<!-- INI ADALAH SCRIPT JAVASCRIPT YANG MEMBUAT FUNGSINYA BERJALAN -->
<script>
    $(document).ready(function() {

        // 1. SCRIPT UNTUK KETERANGAN MASALAH
        $('#bermasalahSelect').on('change', function() {
            if ($(this).val() === 'Iya') {
                $('#keteranganMasalahDiv').slideDown();
                $('textarea[name="keterangan_masalah"]').attr('required', true);
            } else {
                $('#keteranganMasalahDiv').slideUp();
                $('textarea[name="keterangan_masalah"]').removeAttr('required').val('');
            }
        });

        // 2. SCRIPT UNTUK PREVIEW FOTO
        $('#fotoInput').change(function() {
            if (!this.files || !this.files[0]) return;
            let reader = new FileReader();
            reader.onload = function(e) {
                $('#previewFoto').attr('src', e.target.result).show();
            };
            reader.readAsDataURL(this.files[0]);
        });

        // 3. SCRIPT DYNAMIC DROPDOWN (BARANG -> MERK -> TIPE)
        $(document).off('change', '#id_barang').on('change', '#id_barang', function() {
            var id_barang = $(this).val();
            $('#id_merk').html('<option value="">Sedang memuat... </option>').trigger('change');
            $('#id_tipe').html('<option value="">Pilih Merk Dulu...</option>').trigger('change');

            if (id_barang) {
                $.ajax({
                    url: 'ajax_dropdown.php',
                    type: 'POST',
                    data: { action: 'get_merk', id_barang: id_barang },
                    success: function(response) {
                        $('#id_merk').html(response).trigger('change');
                    }
                });
            } else {
                $('#id_merk').html('<option value="">Pilih Kategori Dulu...</option>').trigger('change');
            }
        });

        $(document).off('change', '#id_merk').on('change', '#id_merk', function() {
            var id_barang = $('#id_barang').val();
            var id_merk = $(this).val();
            $('#id_tipe').html('<option value="">Sedang memuat...</option>').trigger('change');

            if (id_merk && id_barang) {
                $.ajax({
                    url: 'ajax_dropdown.php',
                    type: 'POST',
                    data: { action: 'get_tipe', id_barang: id_barang, id_merk: id_merk },
                    success: function(response) {
                        $('#id_tipe').html(response).trigger('change');
                    }
                });
            } else {
                $('#id_tipe').html('<option value="">Pilih Merk Dulu...</option>').trigger('change');
            }
        });

    }); 
</script>