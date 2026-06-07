<?php
/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';
require_once '../config/activity_logger.php';
require_once '../config/warranty_helper.php';
require_once '../config/asset_code_helper.php';
require_once '../config/validation_helper.php';

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

    if (!is_date_within_back_tolerance($tanggal_terima, 3)) {
        echo json_encode(['status' => 'error', 'message' => 'Tanggal terima hanya boleh diisi mulai 3 hari sebelum hari ini']);
        exit;
    }
    
    $bermasalah    = esc($koneksi, $_POST['bermasalah']);
    $id_branch     = (int) $_POST['id_branch'];
    $user          = esc($koneksi, $_POST['user']);

    $nameError = validate_person_name($user);
    if ($nameError !== null) {
        echo json_encode(['status' => 'error', 'message' => $nameError]);
        exit;
    }

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

    $tanggal_pembelian = esc($koneksi, $_POST['tanggal_pembelian'] ?? '');
    if ($tanggal_pembelian === '') {
        $tanggal_pembelian = $tanggal_terima;
    }
    $masa_garansi_bulan = normalize_warranty_months($_POST['masa_garansi_bulan'] ?? 12);
    $tanggal_garansi_berakhir = compute_warranty_end_date($tanggal_pembelian, $masa_garansi_bulan);
    $kode_aset = esc($koneksi, generate_kode_aset($koneksi, $id_barang, $tanggal_terima));

    $queryBarang = "INSERT INTO barang (no_asset, kode_aset, id_barang, id_merk, serial_number, id_tipe, id_jenis, tanggal_terima, tanggal_pembelian, masa_garansi_bulan, tanggal_garansi_berakhir, bermasalah, keterangan_masalah, id_status, id_branch, foto, `user`, user_id, status) 
                    VALUES ('$no_asset', '$kode_aset', '$id_barang', '$id_merk', '$serial_number', '$id_tipe', '$id_jenis', '$tanggal_terima', '$tanggal_pembelian', '$masa_garansi_bulan', " . ($tanggal_garansi_berakhir ? "'$tanggal_garansi_berakhir'" : "NULL") . ", '$bermasalah', " . ($keterangan_masalah ? "'$keterangan_masalah'" : "NULL") . ", '$id_status', '$id_branch', " . ($foto ? "'$foto'" : "NULL") . ", '$user', '$user_id_sistem', '$statusField')";

    mysqli_begin_transaction($koneksi);
    try {
        if (!mysqli_query($koneksi, $queryBarang)) throw new Exception(mysqli_error($koneksi));
        mysqli_commit($koneksi);

        log_activity($koneksi, 'create_barang', "Tambah barang baru - Kode: {$kode_aset}, Serial: {$serial_number}, Cabang: {$id_branch}", [
            'kode_aset' => $kode_aset,
            'serial_number' => $serial_number,
            'no_asset' => $no_asset,
            'id_barang' => $id_barang,
            'id_branch' => $id_branch,
            'bermasalah' => $bermasalah
        ]);

        echo json_encode(['status' => 'success', 'message' => "Data barang berhasil ditambahkan! Kode Asset: {$kode_aset}"]);
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        echo json_encode(['status' => 'error', 'message' => 'Gagal simpan: ' . $e->getMessage()]);
    }
    exit;
}
?>

<style>
    /* FIX: Mencegah dropdown Select2 yang ada di bagian bawah terpotong oleh batas modal */
    .modal-content {
        overflow: visible !important; 
    }

    /* Mengatur jarak vertikal antar form input */
    #formCreate .col-md-6, 
    #formCreate .col-md-12 {
        margin-bottom: 1.2rem;
    }

    .form-control {
        border: 1px solid #E0E4E8;
        border-radius: 6px;
        padding: 0.5rem 0.75rem;
        font-size: 0.95rem;
        min-height: 42px; 
        box-shadow: none !important;
    }
    .form-control:focus {
        border-color: #E64312;
        box-shadow: 0 0 0 0.25rem rgba(230, 67, 18, 0.1) !important;
    }

    /* Menyamakan tinggi Select2 (Dropdown) agar sejajar dengan Input Teks */
    .select2-container .select2-selection--single {
        border: 1px solid #E0E4E8 !important;
        border-radius: 6px !important;
        height: 42px !important; 
        display: flex !important;
        align-items: center !important;
        padding: 0.2rem 0.5rem;
        box-shadow: none !important;
    }
    
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 40px !important;
        right: 8px !important;
    }
    
    .select2-selection__clear {
        margin-right: 15px;
        font-size: 1.2rem;
    }

    .form-label {
        font-weight: 600;
        color: #333333;
        font-size: 0.88rem;
        margin-bottom: 0.5rem;
        display: block;
    }
    
    .text-danger {
        font-weight: bold;
    }

    .alert-info-custom {
        background-color: #F0F7FF;
        border-left: 4px solid #0066CC;
        color: #004085;
        border-radius: 6px;
        padding: 1rem;
        font-size: 0.9rem;
        margin-bottom: 0.5rem;
    }

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
     .select2-container--open {
        z-index: 9999999 !important;
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
            <label class="form-label">Kode Asset (Otomatis)</label>
            <input type="text" class="form-control bg-light" value="HXI-KATEGORI-TAHUN-00001" readonly disabled>
            <small class="text-muted">Dibuat otomatis saat simpan.</small>
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
            <!-- FIX: Atribut 'required' pada Select2 dihapus agar tidak menyebabkan silent form block. Validasi akan dicover oleh backend (SweetAlert) -->
            <select name="id_barang" id="id_barang" class="form-control select2">
                <option value="">Pilih Kategori...</option>
                <?php mysqli_data_seek($barang, 0);
                while ($row = mysqli_fetch_assoc($barang)): ?>
                    <option value="<?= (int) $row['id_barang'] ?>"><?= h($row['nama_barang']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Merk Barang <span class="text-danger">*</span></label>
            <select name="id_merk" id="id_merk" class="form-control select2">
                <option value="">Pilih Kategori Dulu...</option>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Tipe / Spesifikasi <span class="text-danger">*</span></label>
            <select name="id_tipe" id="id_tipe" class="form-control select2">
                <option value="">Pilih Merk Dulu...</option>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Jenis Kepemilikan <span class="text-danger">*</span></label>
            <select name="id_jenis" id="id_jenis" class="form-control select2">
                <option value="">Pilih Jenis...</option>
                <?php mysqli_data_seek($jenis, 0);
                while ($row = mysqli_fetch_assoc($jenis)): ?>
                    <option value="<?= (int) $row['id_jenis'] ?>"><?= h($row['nama_jenis']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Tanggal Terima <span class="text-danger">*</span></label>
            <input type="date" name="tanggal_terima" class="form-control" required min="<?= date_min_back_tolerance(3) ?>">
            <small class="text-muted">Boleh diisi hingga 3 hari sebelum hari ini.</small>
        </div>

        <div class="col-12"><hr class="my-1"><div class="fw-semibold text-muted small mb-2"><i class="bi bi-shield-check me-1"></i> Informasi Garansi Produk</div></div>

        <div class="col-md-4">
            <label class="form-label">Tanggal Pembelian</label>
            <input type="date" name="tanggal_pembelian" id="tanggal_pembelian" class="form-control">
            <small class="text-muted">Kosongkan = sama dengan tanggal terima</small>
        </div>
        <div class="col-md-4">
            <label class="form-label">Masa Garansi</label>
            <select name="masa_garansi_bulan" id="masa_garansi_bulan" class="form-control">
                <option value="6">6 Bulan</option>
                <option value="12" selected>12 Bulan (1 Tahun)</option>
                <option value="24">24 Bulan (2 Tahun)</option>
                <option value="36">36 Bulan (3 Tahun)</option>
                <option value="48">48 Bulan (4 Tahun)</option>
                <option value="60">60 Bulan (5 Tahun)</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Garansi Berakhir</label>
            <input type="text" id="preview_garansi_berakhir" class="form-control bg-light" readonly placeholder="Otomatis dihitung">
        </div>

        <div class="col-md-6">
            <label class="form-label">Lokasi Branch <span class="text-danger">*</span></label>
            <!-- FIX: Menambahkan id="id_branch" dan menghapus atribut required -->
            <select name="id_branch" id="id_branch" class="form-control select2">
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
            <!-- FIX: Menghapus atribut required untuk menghindari silent form validation lock -->
            <select name="bermasalah" class="form-control select2" id="bermasalahSelect">
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
            <input type="file" name="foto" class="form-control" id="fotoInput" required accept=".jpg,.jpeg,.png,.gif,.webp">
            <div class="mt-2 text-muted small">Format: JPG, PNG, WEBP. Maksimal 2MB.</div>
            <img id="previewFoto" class="shadow-sm border mt-3" style="max-height: 150px; display: none; border-radius: 8px; object-fit: cover;">
        </div>

        <div class="col-md-12 text-end mt-4 pt-3 border-top">
            <button type="button" class="btn btn-light border px-4 me-2 rounded-2" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-hexindo" id="btnSimpanBarang">
                <span class="btn-text"><i class="bi bi-floppy me-1"></i> Simpan Data Aset</span>
            </button>
        </div>
    </div>
</form>

<script>
    $(document).ready(function() {

        // 1. SCRIPT UNTUK KETERANGAN MASALAH (DIPERBARUI)
        // FIX: Menggunakan $(document).on() agar event tidak hilang saat modal diload ulang
        $(document).off('change', '#bermasalahSelect').on('change', '#bermasalahSelect', function() {
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

        function refreshWarrantyPreview() {
            const purchase = $('#tanggal_pembelian').val() || $('input[name="tanggal_terima"]').val();
            const months = parseInt($('#masa_garansi_bulan').val() || '12', 10);
            if (!purchase || months <= 0) {
                $('#preview_garansi_berakhir').val('');
                return;
            }
            const d = new Date(purchase);
            d.setMonth(d.getMonth() + months);
            $('#preview_garansi_berakhir').val(d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }));
        }
        $('input[name="tanggal_terima"], #tanggal_pembelian, #masa_garansi_bulan').on('change input', refreshWarrantyPreview);

    }); 
</script>