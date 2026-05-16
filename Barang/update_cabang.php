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

// Ambil Data untuk Dropdown
$barangs = mysqli_query($koneksi, "SELECT * FROM tb_barang ORDER BY nama_barang ASC");
$merks   = mysqli_query($koneksi, "SELECT * FROM tb_merk ORDER BY nama_merk ASC");
$tipes   = mysqli_query($koneksi, "SELECT * FROM tb_tipe ORDER BY nama_tipe ASC");
?>

<!-- FORM HTML (Metode GET) -->
<form id="formUpdateCabang" action="update_cabang.php" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label fw-bold">No Asset</label>
            <input type="text" name="no_asset" class="form-control" value="<?= h($data['no_asset']) ?>">
        </div>

        <div class="col-md-6">
            <label class="form-label fw-bold">Serial Number / Service tag <span class="text-danger">*</span></label>
            <input type="text" name="serial_number" class="form-control" required value="<?= h($data['serial_number']) ?>">
        </div>

        <div class="col-md-6">
            <label class="form-label fw-bold">Nama Barang <span class="text-danger">*</span></label>
            <select name="id_barang" class="form-control select2" required>
                <?php while ($b = mysqli_fetch_assoc($barangs)): ?>
                    <option value="<?= $b['id_barang'] ?>" <?= $b['id_barang'] == $data['id_barang'] ? 'selected' : '' ?>>
                        <?= h($b['nama_barang']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label fw-bold">Merk <span class="text-danger">*</span></label>
            <select name="id_merk" class="form-control select2" required>
                <?php while ($m = mysqli_fetch_assoc($merks)): ?>
                    <option value="<?= $m['id_merk'] ?>" <?= $m['id_merk'] == $data['id_merk'] ? 'selected' : '' ?>>
                        <?= h($m['nama_merk']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label fw-bold">Tipe <span class="text-danger">*</span></label>
            <select name="id_tipe" class="form-control select2" required>
                <?php while ($t = mysqli_fetch_assoc($tipes)): ?>
                    <option value="<?= $t['id_tipe'] ?>" <?= $t['id_tipe'] == $data['id_tipe'] ? 'selected' : '' ?>>
                        <?= h($t['nama_tipe']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label fw-bold">User Pengguna Aset <span class="text-danger">*</span></label>
            <input type="text" name="user" class="form-control" required value="<?= h($data['user']) ?>">
        </div>

        <div class="col-md-12">
            <label class="form-label fw-bold">Foto Barang (Biarkan kosong jika tidak diganti)</label>
            <input type="file" name="foto" class="form-control" id="fotoUpdateCabang">
            <?php if ($data['foto']): ?>
                <div class="mt-2">
                    <small class="text-muted d-block">Foto saat ini:</small>
                    <img src="../assets/images/<?= h($data['foto']) ?>" class="rounded border" style="max-width:100px;">
                </div>
            <?php endif; ?>
        </div>

        <div class="col-12 mt-4 text-end">
            <button type="submit" class="btn btn-primary fw-bold rounded-pill px-4 shadow-sm">
                <i class="bi bi-save me-1"></i> Simpan Perubahan
            </button>
        </div>
    </div>
</form>