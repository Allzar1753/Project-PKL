<?php
/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

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
// HANDLE PROSES DATA (METODE POST)
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

    // 3. Simpan ke Database
    $queryBarang = "INSERT INTO barang 
        (no_asset, id_barang, id_merk, serial_number, id_tipe, id_jenis, tanggal_terima, bermasalah, id_status, id_branch, foto, `user`, user_id, status) 
        VALUES 
        ('$no_asset', '$id_barang', '$id_merk', '$serial_number', '$id_tipe', '$id_jenis', '$tanggal_terima', '$bermasalah', '$id_status', '$myBranchId', " . ($foto ? "'$foto'" : "NULL") . ", '$user', '$userIdSistem', 'Tersedia')";

    if (mysqli_query($koneksi, $queryBarang)) {
        jsonResponse('success', 'Aset baru berhasil ditambahkan ke inventaris cabang Anda.');
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

<form id="formCreateCabang" action="create_cabang.php" method="POST" enctype="multipart/form-data">
    <div class="row g-3">
        <div class="col-12">
            <div class="alert alert-success bg-success-subtle border-success mb-0">
                <i class="bi bi-info-circle-fill me-2 text-success"></i>
                <b>Info:</b> Aset yang Anda input akan otomatis terdaftar sebagai stok di cabang Anda pada hari ini dengan status <b>Normal & Tersedia</b>.
            </div>
        </div>

        <div class="col-md-6">
            <label class="form-label fw-bold">No Asset</label>
            <input type="text" name="no_asset" class="form-control" placeholder="Opsional (Boleh dikosongkan)">
        </div>

        <div class="col-md-6">
            <label class="form-label fw-bold">Serial Number / Service tag <span class="text-danger">*</span></label>
            <input type="text" name="serial_number" class="form-control" required placeholder="Wajib diisi!">
        </div>

        <div class="col-md-6">
            <label class="form-label fw-bold">Nama Barang <span class="text-danger">*</span></label>
            <!-- DITAMBAHKAN ID id_barang -->
            <select name="id_barang" id="id_barang" class="form-control select2" required>
                <option value="">Pilih Barang...</option>
                <?php while ($row = mysqli_fetch_assoc($barang)): ?>
                    <option value="<?= (int) $row['id_barang'] ?>"><?= h($row['nama_barang']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label fw-bold">Merk <span class="text-danger">*</span></label>
            <!-- DITAMBAHKAN ID id_merk DAN DIKOSONGKAN -->
            <select name="id_merk" id="id_merk" class="form-control select2" required>
                <option value="">Pilih Barang Dulu...</option>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label fw-bold">Tipe <span class="text-danger">*</span></label>
            <!-- DITAMBAHKAN ID id_tipe DAN DIKOSONGKAN -->
            <select name="id_tipe" id="id_tipe" class="form-control select2" required>
                <option value="">Pilih Merk Dulu...</option>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label fw-bold">User Pengguna Aset <span class="text-danger">*</span></label>
            <input type="text" name="user" class="form-control" required placeholder="Contoh: Nabila">
        </div>

        <div class="col-md-12">
            <label class="form-label fw-bold">Foto Barang</label>
            <input type="file" name="foto" class="form-control" id="fotoInputCabang" accept=".jpg,.jpeg,.png,.gif,.webp">
            <img id="previewFotoCabang" class="rounded mt-2 shadow-sm border" style="max-width:120px; display:none;">
        </div>

        <div class="col-12 mt-4 text-end">
            <button type="submit" class="btn btn-warning fw-bold rounded-pill px-4 text-dark shadow-sm">
                <span class="btn-text"><i class="bi bi-save me-1"></i> Simpan Aset</span>
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
        // DYNAMIC DROPDOWN AJAX
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
                $('#id_merk').html('<option value="">Pilih Barang Dulu...</option>').trigger('change');
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