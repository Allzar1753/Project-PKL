<?php
/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

// Pastikan hanya user cabang yang bisa akses
if (is_admin()) {
    exit('Akses ditolak. Gunakan form update master untuk admin.');
}

$myBranchId = (int) current_user_branch_id();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

// 1. Ambil Data Barang
$query = mysqli_query($koneksi, "SELECT * FROM barang WHERE id = $id AND id_branch = $myBranchId LIMIT 1");
$data = mysqli_fetch_assoc($query);

if (!$data) {
    exit('Data tidak ditemukan atau Anda tidak memiliki akses ke aset ini.');
}

function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ==========================================
// HANDLE PROSES UPDATE (METODE POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $no_asset      = mysqli_real_escape_string($koneksi, $_POST['no_asset'] ?? '');
    $serial_number = mysqli_real_escape_string($koneksi, $_POST['serial_number'] ?? '');
    $id_barang     = (int) ($_POST['id_barang'] ?? 0);
    $id_merk       = (int) ($_POST['id_merk'] ?? 0);
    $id_tipe       = (int) ($_POST['id_tipe'] ?? 0);
    $user          = mysqli_real_escape_string($koneksi, $_POST['user'] ?? '');

    if (empty($serial_number) || $id_barang === 0 || $id_merk === 0 || $id_tipe === 0 || empty($user)) {
        echo json_encode(['status' => 'error', 'message' => 'Semua field bertanda * wajib diisi!']);
        exit;
    }

    // Cek Duplikat Serial Number (kecuali milik sendiri)
    $cekSerial = mysqli_query($koneksi, "SELECT id FROM barang WHERE serial_number = '$serial_number' AND id != $id LIMIT 1");
    if (mysqli_num_rows($cekSerial) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Serial Number sudah digunakan aset lain!']);
        exit;
    }

    // Handle Upload Foto Baru
    $fotoSql = "";
    if (!empty($_FILES['foto']['name'])) {
        $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        $fotoName = uniqid('aset_upd_', true) . '.' . $ext;
        if (move_uploaded_file($_FILES['foto']['tmp_name'], "../assets/images/" . $fotoName)) {
            $fotoSql = ", foto = '$fotoName'";
        }
    }

    $updateQuery = "UPDATE barang SET 
        no_asset = '$no_asset',
        serial_number = '$serial_number',
        id_barang = '$id_barang',
        id_merk = '$id_merk',
        id_tipe = '$id_tipe',
        `user` = '$user'
        $fotoSql
        WHERE id = $id AND id_branch = $myBranchId";

    if (mysqli_query($koneksi, $updateQuery)) {
        echo json_encode(['status' => 'success', 'message' => 'Data aset berhasil diperbarui.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Gagal update: ' . mysqli_error($koneksi)]);
    }
    exit;
}

// ==========================================
// AMBIL DATA DROPDOWN (DIFILTER SESUAI DATA SAAT INI)
// ==========================================
$currIdBarang = (int) $data['id_barang'];
$currIdMerk   = (int) $data['id_merk'];

// 1. Kategori barang ambil semua
$barangs = mysqli_query($koneksi, "SELECT * FROM tb_barang ORDER BY nama_barang ASC");

// 2. Merk HANYA ambil berdasarkan Kategori Barang saat ini
$merks = mysqli_query($koneksi, "SELECT DISTINCT m.id_merk, m.nama_merk FROM tb_tipe t JOIN tb_merk m ON t.id_merk = m.id_merk WHERE t.id_barang = $currIdBarang ORDER BY m.nama_merk ASC");

// 3. Tipe HANYA ambil berdasarkan Kategori dan Merk saat ini
$tipes = mysqli_query($koneksi, "SELECT id_tipe, nama_tipe FROM tb_tipe WHERE id_barang = $currIdBarang AND id_merk = $currIdMerk ORDER BY nama_tipe ASC");
?>

<!-- STYLE KHUSUS UNTUK FORM SINKRON TEMA HEXINDO -->
<style>
    /* Jarak vertikal antar input form yang lega */
    #formUpdateCabang .col-md-6, 
    #formUpdateCabang .col-md-12 {
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
        color: #D32F2F !important;
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

<!-- FORM HTML (Metode GET) -->
<form id="formUpdateCabang" action="update_cabang.php" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="id" value="<?= $id ?>">
    
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">No Asset</label>
            <input type="text" name="no_asset" class="form-control text-uppercase" value="<?= h($data['no_asset']) ?>" placeholder="Opsional">
        </div>

        <div class="col-md-6">
            <label class="form-label">Serial Number / Service Tag <span class="text-danger">*</span></label>
            <input type="text" name="serial_number" class="form-control text-uppercase" required value="<?= h($data['serial_number']) ?>">
        </div>

        <div class="col-md-6">
            <label class="form-label">Kategori Barang <span class="text-danger">*</span></label>
            <!-- Tambahkan ID: cb_id_barang -->
            <select name="id_barang" id="cb_id_barang" class="form-control select2" required>
                <option value="">Pilih Barang...</option>
                <?php mysqli_data_seek($barangs, 0); while ($b = mysqli_fetch_assoc($barangs)): ?>
                    <option value="<?= $b['id_barang'] ?>" <?= $b['id_barang'] == $data['id_barang'] ? 'selected' : '' ?>>
                        <?= h($b['nama_barang']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Merk Barang <span class="text-danger">*</span></label>
            <!-- Tambahkan ID: cb_id_merk -->
            <select name="id_merk" id="cb_id_merk" class="form-control select2" required>
                <option value="">Pilih Merk...</option>
                <?php mysqli_data_seek($merks, 0); while ($m = mysqli_fetch_assoc($merks)): ?>
                    <option value="<?= $m['id_merk'] ?>" <?= $m['id_merk'] == $data['id_merk'] ? 'selected' : '' ?>>
                        <?= h($m['nama_merk']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Tipe / Spesifikasi <span class="text-danger">*</span></label>
            <!-- Tambahkan ID: cb_id_tipe -->
            <select name="id_tipe" id="cb_id_tipe" class="form-control select2" required>
                <option value="">Pilih Tipe...</option>
                <?php mysqli_data_seek($tipes, 0); while ($t = mysqli_fetch_assoc($tipes)): ?>
                    <option value="<?= $t['id_tipe'] ?>" <?= $t['id_tipe'] == $data['id_tipe'] ? 'selected' : '' ?>>
                        <?= h($t['nama_tipe']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Nama User Pengguna Aset <span class="text-danger">*</span></label>
            <input type="text" name="user" class="form-control text-uppercase" required value="<?= h($data['user']) ?>">
        </div>

        <div class="col-md-12 border-top pt-3 mt-2">
            <label class="form-label">Upload Foto Fisik Barang</label>
            <input type="file" name="foto" class="form-control" id="fotoUpdateCabang" accept=".jpg,.jpeg,.png,.gif,.webp">
            <div class="mt-1 text-muted" style="font-size: 0.8rem;">Biarkan kosong jika tidak ingin mengganti foto saat ini.</div>
            
            <?php if (!empty($data['foto'])): ?>
                <div class="mt-3 p-2 border rounded bg-light d-inline-block">
                    <span class="d-block mb-2 text-muted" style="font-size: 0.8rem; font-weight: 600;"><i class="bi bi-image me-1"></i> Foto Saat Ini:</span>
                    <img src="../assets/images/<?= h($data['foto']) ?>" class="rounded shadow-sm" style="height: 100px; object-fit: cover;">
                </div>
            <?php endif; ?>
        </div>

        <div class="col-12 mt-4 pt-3 border-top text-end">
            <button type="button" class="btn btn-light border px-4 me-2 rounded-2" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-hexindo">
                <span class="btn-text"><i class="bi bi-save me-1"></i> Simpan Perubahan</span>
                <span class="btn-loading d-none">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Menyimpan...
                </span>
            </button>
        </div>
    </div>
</form>

<!-- SCRIPT AJAX UNTUK FILTER DROPDOWN -->
<script>
    $(document).ready(function() {
        
        // Ketika Kategori Barang diganti
        $(document).off('change', '#cb_id_barang').on('change', '#cb_id_barang', function() {
            var id_barang = $(this).val();
            
            // Reset dropdown Merk dan Tipe
            $('#cb_id_merk').html('<option value="">Sedang memuat...</option>').trigger('change');
            $('#cb_id_tipe').html('<option value="">Pilih Merk Dulu...</option>').trigger('change');
            
            if (id_barang) {
                // Request Merk via AJAX ke file ajax_dropdown.php
                $.ajax({
                    url: 'ajax_dropdown.php',
                    type: 'POST',
                    data: {
                        action: 'get_merk',
                        id_barang: id_barang
                    },
                    success: function(response) {
                        $('#cb_id_merk').html('<option value="">Pilih Merk...</option>' + response).trigger('change');
                    }
                });
            } else {
                $('#cb_id_merk').html('<option value="">Pilih Barang Dulu...</option>').trigger('change');
            }
        });

        // Ketika Merk diganti
        $(document).off('change', '#cb_id_merk').on('change', '#cb_id_merk', function() {
            var id_barang = $('#cb_id_barang').val();
            var id_merk = $(this).val();
            
            // Reset dropdown Tipe
            $('#cb_id_tipe').html('<option value="">Sedang memuat...</option>').trigger('change');
            
            if (id_merk && id_barang) {
                // Request Tipe via AJAX ke file ajax_dropdown.php
                $.ajax({
                    url: 'ajax_dropdown.php',
                    type: 'POST',
                    data: {
                        action: 'get_tipe',
                        id_barang: id_barang,
                        id_merk: id_merk
                    },
                    success: function(response) {
                        $('#cb_id_tipe').html('<option value="">Pilih Tipe / Spesifikasi...</option>' + response).trigger('change');
                    }
                });
            } else {
                $('#cb_id_tipe').html('<option value="">Pilih Merk Dulu...</option>').trigger('change');
            }
        });
    });
</script>