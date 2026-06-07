<?php
/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';
require_once '../config/activity_logger.php';
require_once '../config/warranty_helper.php';
require_once '../config/asset_code_helper.php';
require_once '../config/validation_helper.php';

if (is_admin()) {
    exit('Akses ditolak. Form ini khusus untuk user cabang.');
}

$myBranchId = (int) current_user_branch_id();
$userIdSistem = (int) current_user_id();

function esc(mysqli $koneksi, $value): string {
    return mysqli_real_escape_string($koneksi, trim((string) $value));
}
function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function jsonResponse(string $status, string $message): void {
    header('Content-Type: application/json');
    echo json_encode(['status' => $status, 'message' => $message]);
    exit;
}

// ==========================================
// HANDLE PROSES DATA (METODE POST) - TIDAK DIUBAH
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $no_asset      = esc($koneksi, $_POST['no_asset'] ?? '');
    $serial_number = esc($koneksi, $_POST['serial_number'] ?? '');
    $id_barang     = (int) ($_POST['id_barang'] ?? 0);
    $id_merk       = (int) ($_POST['id_merk'] ?? 0);
    $id_tipe       = (int) ($_POST['id_tipe'] ?? 0);
    $user          = esc($koneksi, $_POST['user'] ?? '');
    
    // Auto Generate Data Belakang Layar
    $tanggal_terima = date('Y-m-d');
    $bermasalah     = 'Tidak';
    $id_status      = 4; // Asumsi 4 = Tersedia

    // Ganti angka 1 dengan ID Jenis (di tabel tb_jenis) yang paling umum, misal ID untuk "Hardware"
    $id_jenis       = 1; 

    if (empty($serial_number) || $id_barang === 0 || $id_merk === 0 || $id_tipe === 0 || empty($user)) {
        jsonResponse('error', 'Semua field bertanda * wajib diisi!');
    }

    $nameError = validate_person_name($user);
    if ($nameError !== null) {
        jsonResponse('error', $nameError);
    }

    // 1. Cek Duplikat Serial Number
    $cekSerial = mysqli_query($koneksi, "SELECT id FROM barang WHERE serial_number = '$serial_number' LIMIT 1");
    if (mysqli_num_rows($cekSerial) > 0) {
        jsonResponse('error', 'Serial Number sudah terdaftar di sistem!');
    }

    // 2. Upload Foto
    $foto = null;
    if (!empty($_FILES['foto']['name'])) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) jsonResponse('error', 'Format foto tidak valid.');
        if ($_FILES['foto']['size'] > 2000000) jsonResponse('error', 'Ukuran foto maksimal 2MB.');
        
        $targetDir = "../assets/images/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        $foto = uniqid('aset_', true) . '.' . $ext;
        move_uploaded_file($_FILES['foto']['tmp_name'], $targetDir . $foto);
    }

    $tanggal_pembelian = date('Y-m-d');
    $masa_garansi_bulan = normalize_warranty_months($_POST['masa_garansi_bulan'] ?? 12);
    $tanggal_garansi_berakhir = compute_warranty_end_date($tanggal_pembelian, $masa_garansi_bulan);
    $kode_aset = esc($koneksi, generate_kode_aset($koneksi, $id_barang, $tanggal_terima));

    // 3. Simpan ke Database
    $queryBarang = "INSERT INTO barang 
        (no_asset, kode_aset, id_barang, id_merk, serial_number, id_tipe, id_jenis, tanggal_terima, tanggal_pembelian, masa_garansi_bulan, tanggal_garansi_berakhir, bermasalah, id_status, id_branch, foto, `user`, user_id, status) 
        VALUES 
        ('$no_asset', '$kode_aset', '$id_barang', '$id_merk', '$serial_number', '$id_tipe', '$id_jenis', '$tanggal_terima', '$tanggal_pembelian', '$masa_garansi_bulan', " . ($tanggal_garansi_berakhir ? "'$tanggal_garansi_berakhir'" : "NULL") . ", '$bermasalah', '$id_status', '$myBranchId', " . ($foto ? "'$foto'" : "NULL") . ", '$user', '$userIdSistem', 'Tersedia')";

    if (mysqli_query($koneksi, $queryBarang)) {
        $newId = (int) mysqli_insert_id($koneksi);
        log_activity($koneksi, 'create_barang', "User cabang tambah barang - Kode: {$kode_aset}, Serial: {$serial_number}", [
            'id' => $newId,
            'kode_aset' => $kode_aset,
            'serial_number' => $serial_number,
            'no_asset' => $no_asset,
            'id_branch' => $myBranchId,
        ]);
        jsonResponse('success', "Aset berhasil ditambahkan! Kode Asset: {$kode_aset}");
    } else {
        jsonResponse('error', 'Gagal menyimpan data: ' . mysqli_error($koneksi));
    }
}

// ==========================================
// HANDLE TAMPILAN FORM (METODE GET)
// ==========================================
// HANYA QUERY BARANG SAJA YANG DIBUTUHKAN DI AWAL
$barang = mysqli_query($koneksi, "SELECT * FROM tb_barang ORDER BY nama_barang ASC");
?>

<!-- STYLE KHUSUS UNTUK FORM SINKRON TEMA HEXINDO & LEBIH RAPIH -->
<style>
    /* Jarak vertikal antar input form yang lega */
    #formCreateCabang .col-md-6, 
    #formCreateCabang .col-md-12 {
        margin-bottom: 1.2rem;
    }

    /* Penyesuaian tinggi dan padding Input Teks biasa */
    .form-control {
        border: 1px solid #E0E4E8;
        border-radius: 6px;
        padding: 0.5rem 0.75rem;
        font-size: 0.95rem;
        min-height: 42px; /* Standar tinggi */
        box-shadow: none !important;
    }
    .form-control:focus {
        border-color: #E64312;
        box-shadow: 0 0 0 0.25rem rgba(230, 67, 18, 0.1) !important;
    }

    /* Sinkronisasi tinggi dropdown Select2 dengan Input Teks */
    .select2-container .select2-selection--single {
        border: 1px solid #E0E4E8 !important;
        border-radius: 6px !important;
        height: 42px !important;
        display: flex !important;
        align-items: center !important;
        padding: 0.2rem 0.5rem;
    }
    
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 40px !important;
        right: 8px !important;
    }
    
    .select2-selection__clear {
        margin-right: 15px;
        font-size: 1.2rem;
    }

    /* Merapikan posisi dan warna Label Form */
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

    /* Kotak Informasi User Cabang */
    .alert-success-custom {
        background-color: #F0FDF4;
        border-left: 4px solid #16A34A;
        color: #166534;
        border-radius: 6px;
        padding: 1rem;
        font-size: 0.9rem;
        margin-bottom: 0.5rem;
    }

    /* Tombol Utama Hexindo */
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

<form id="formCreateCabang" action="create_cabang.php" method="POST" enctype="multipart/form-data">
    <div class="row g-3">
        
        <div class="col-12">
            <div class="alert alert-success-custom mb-2">
                <i class="bi bi-info-circle-fill me-2"></i>
                <b>Informasi:</b> Aset yang Anda input akan otomatis terdaftar sebagai stok di cabang Anda pada hari ini dengan status <b>Normal & Tersedia</b>.
            </div>
        </div>

        <div class="col-md-6">
            <label class="form-label">Kode Asset (Otomatis)</label>
            <input type="text" class="form-control bg-light" value="HXI-KATEGORI-TAHUN-00001" readonly disabled>
            <small class="text-muted">Dibuat otomatis saat simpan. Contoh: HXI-MON-2026-00012</small>
        </div>

        <div class="col-md-6">
            <label class="form-label">No Asset</label>
            <input type="text" name="no_asset" class="form-control" placeholder="Opsional (Boleh dikosongkan)">
        </div>

        <div class="col-md-6">
            <label class="form-label">Serial Number / Service Tag <span class="text-danger">*</span></label>
            <input type="text" name="serial_number" class="form-control" required placeholder="Masukkan SN / Service Tag">
        </div>

        <div class="col-md-6">
            <label class="form-label">Kategori Barang <span class="text-danger">*</span></label>
            <select name="id_barang" id="id_barang" class="form-control select2" required>
                <option value="">Pilih Kategori...</option>
                <?php while ($row = mysqli_fetch_assoc($barang)): ?>
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
            <label class="form-label">Nama User Pengguna Aset <span class="text-danger">*</span></label>
            <input type="text" name="user" class="form-control" required placeholder="Contoh: Nabila">
        </div>

        <div class="col-md-12 border-top pt-3 mt-3">
            <label class="form-label">Upload Foto Fisik Barang</label>
            <input type="file" name="foto" class="form-control" id="fotoInputCabang" accept=".jpg,.jpeg,.png,.gif,.webp">
            <div class="mt-2 text-muted small">Format: JPG, PNG, WEBP. Maksimal 2MB.</div>
            <img id="previewFotoCabang" class="shadow-sm border mt-3" style="max-height: 150px; display: none; border-radius: 8px; object-fit: cover;">
        </div>

        <div class="col-12 mt-4 pt-3 border-top text-end">
            <button type="button" class="btn btn-light border px-4 me-2 rounded-2" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-hexindo">
                <span class="btn-text"><i class="bi bi-save me-1"></i> Simpan Aset Cabang</span>
                <span class="btn-loading d-none">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Menyimpan...
                </span>
            </button>
        </div>
        
    </div>
</form>

<script>
    $(document).ready(function() {
        // Live Preview Foto
        $('#fotoInputCabang').change(function() {
            if (!this.files || !this.files[0]) return;
            let reader = new FileReader();
            reader.onload = function(e) {
                $('#previewFotoCabang').attr('src', e.target.result).fadeIn();
            };
            reader.readAsDataURL(this.files[0]);
        });

        // ==========================================
        // DYNAMIC DROPDOWN AJAX - TIDAK DIUBAH
        // ==========================================
        
        // 1. Kategori Barang Diubah
        $(document).off('change', '#id_barang').on('change', '#id_barang', function() {
            var id_barang = $(this).val();

            $('#id_merk').html('<option value="">Sedang memuat... </option>').trigger('change');
            $('#id_tipe').html('<option value="">Pilih Merk Dulu...</option>').trigger('change');

            if (id_barang) {
                $.ajax({
                    url: 'ajax_dropdown.php',
                    type: 'POST',
                    data: {
                        action: 'get_merk',
                        id_barang: id_barang
                    },
                    success: function(response) {
                        $('#id_merk').html(response).trigger('change');
                    }
                });
            } else {
                $('#id_merk').html('<option value="">Pilih Kategori Dulu...</option>').trigger('change');
            }
        });

        // 2. Merk Diubah
        $(document).off('change', '#id_merk').on('change', '#id_merk', function() {
            var id_barang = $('#id_barang').val();
            var id_merk = $(this).val();

            $('#id_tipe').html('<option value="">Sedang memuat...</option>').trigger('change');

            if (id_merk && id_barang) {
                $.ajax({
                    url: 'ajax_dropdown.php',
                    type: 'POST',
                    data: {
                        action: 'get_tipe',
                        id_barang: id_barang,
                        id_merk: id_merk
                    },
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