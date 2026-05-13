<?php

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

require_login();

const STATUS_BELUM_DIKIRIM = 'Belum dikirim';
const STATUS_SEDANG_DIKEMAS = 'Sedang dikemas';
const STATUS_SEDANG_PERJALANAN = 'Sedang perjalanan';
const STATUS_SUDAH_DITERIMA = 'Sudah diterima';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function normalizeNullableString($value): ?string
{
    $value = trim((string) $value);
    return $value !== '' ? $value : null;
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

    $filename = uniqid($fieldName . "_", true) . "." . $ext;

    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetDir . $filename)) {
        return ['status' => 'error', 'message' => "Gagal upload file {$fieldName}"];
    }

    return ['status' => 'success', 'filename' => $filename];
}

function jsonResponse(string $status, string $message): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'message' => $message
    ]);
    exit;
}

function jsonError(string $message): void
{
    jsonResponse('error', $message);
}

function jsonSuccess(string $message): void
{
    jsonResponse('success', $message);
}

function getBarangById(mysqli $koneksi, int $id): ?array
{
    $stmt = mysqli_prepare($koneksi, "SELECT * FROM barang WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result) ?: null;
    mysqli_stmt_close($stmt);

    return $row;
}

function getLastPengiriman(mysqli $koneksi, int $idBarang): ?array
{
    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT
            bp.*,
            asal.nama_branch AS nama_branch_asal,
            tujuan.nama_branch AS nama_branch_tujuan
         FROM barang_pengiriman bp
         LEFT JOIN tb_branch asal ON bp.branch_asal = asal.id_branch
         LEFT JOIN tb_branch tujuan ON bp.branch_tujuan = tujuan.id_branch
         WHERE bp.id_barang = ?
         ORDER BY bp.id_pengiriman DESC
         LIMIT 1"
    );

    mysqli_stmt_bind_param($stmt, 'i', $idBarang);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result) ?: null;
    mysqli_stmt_close($stmt);

    return $row;
}

function getSelectOptions(mysqli $koneksi, string $sql): array
{
    $rows = [];
    $query = mysqli_query($koneksi, $sql);
    if (!$query) {
        return $rows;
    }
    while ($row = mysqli_fetch_assoc($query)) {
        $rows[] = $row;
    }
    return $rows;
}

function ensureBarangAccess(array $barang, ?array $pengirimanTerakhir): void
{
    if (is_admin()) {
        return;
    }

    $myBranchId = (int) current_user_branch_id();
    $barangBranchId = (int) ($barang['id_branch'] ?? 0);
    $branchTujuan = (int) ($pengirimanTerakhir['branch_tujuan'] ?? 0);
    $statusPengiriman = (string) ($pengirimanTerakhir['status_pengiriman'] ?? '');

    $sedangDikirim = !empty($pengirimanTerakhir) && $statusPengiriman !== STATUS_SUDAH_DITERIMA;

    if ($barangBranchId === $myBranchId) {
        return;
    }

    if ($sedangDikirim && $branchTujuan === $myBranchId) {
        return;
    }

    http_response_code(403);
    exit('Anda tidak boleh mengakses barang cabang lain.');
}

function isSedangDikirim(?array $pengirimanTerakhir): bool
{
    if (empty($pengirimanTerakhir)) {
        return false;
    }
    return (string) ($pengirimanTerakhir['status_pengiriman'] ?? '') !== STATUS_SUDAH_DITERIMA;
}

function isSudahDiterima(?array $pengirimanTerakhir): bool
{
    if (empty($pengirimanTerakhir)) {
        return false;
    }
    return (string) ($pengirimanTerakhir['status_pengiriman'] ?? '') === STATUS_SUDAH_DITERIMA;
}

function ensureUserCanReceive(?array $pengirimanTerakhir): void
{
    if (is_admin()) {
        return;
    }

    $myBranchId = (int) current_user_branch_id();
    $branchTujuan = (int) ($pengirimanTerakhir['branch_tujuan'] ?? 0);

    if (!$pengirimanTerakhir || !isSedangDikirim($pengirimanTerakhir) || $branchTujuan !== $myBranchId) {
        http_response_code(403);
        exit('User cabang hanya boleh konfirmasi penerimaan barang untuk branch-nya sendiri.');
    }
}

// FUNGSI VALIDASI DUPLIKAT
function validateDuplicateBarang(mysqli $koneksi, int $id, string $noAsset, string $serialNumber): ?string
{
    $stmtCurrent = mysqli_prepare(
        $koneksi,
        "SELECT tb.nama_barang
         FROM barang b
         INNER JOIN tb_barang tb ON b.id_barang = tb.id_barang
         WHERE b.id = ?
         LIMIT 1"
    );

    mysqli_stmt_bind_param($stmtCurrent, 'i', $id);
    mysqli_stmt_execute($stmtCurrent);
    $resultCurrent = mysqli_stmt_get_result($stmtCurrent);
    $currentRow = mysqli_fetch_assoc($resultCurrent) ?: null;
    mysqli_stmt_close($stmtCurrent);

    if (!$currentRow) return "Data barang saat ini tidak ditemukan.";

    $namaBarangInput = strtolower(trim($currentRow['nama_barang']));
    $pasanganBarang = ['monitor', 'cpu'];

    $stmSerial = mysqli_prepare($koneksi, "SELECT id FROM barang WHERE serial_number = ? AND id != ? LIMIT 1");
    mysqli_stmt_bind_param($stmSerial, 'si', $serialNumber, $id);
    mysqli_stmt_execute($stmSerial);
    $resultSerial = mysqli_stmt_get_result($stmSerial);
    $serialDuplikat = mysqli_fetch_assoc($resultSerial) ?: null;
    mysqli_stmt_close($stmSerial);

    if ($serialDuplikat) return "Serial Number sudah digunakan oleh data lain.";

    if (!empty($noAsset)) {
        $stmtAsset = mysqli_prepare(
            $koneksi,
            "SELECT b.id, b.no_asset, tb.nama_barang
            FROM barang b 
            INNER JOIN tb_barang tb ON b.id_barang = tb.id_barang 
            WHERE b.no_asset = ? AND b.id != ?"
        );
        mysqli_stmt_bind_param($stmtAsset, 'si', $noAsset, $id);
        mysqli_stmt_execute($stmtAsset);
        $resultAsset = mysqli_stmt_get_result($stmtAsset);

        $barangSudahAda = [];
        $inputTermasukPasangan = in_array($namaBarangInput, $pasanganBarang, true);

        while ($row = mysqli_fetch_assoc($resultAsset)) {
            $namaBarangDB = strtolower(trim($row['nama_barang']));
            $barangSudahAda[] = $namaBarangDB;

            if ($namaBarangDB === $namaBarangInput) {
                mysqli_stmt_close($stmtAsset);
                return "Barang tersebut sudah tersedia di daftar barang.";
            }
            if (!in_array($namaBarangDB, $pasanganBarang, true)) {
                mysqli_stmt_close($stmtAsset);
                return "No Asset sudah digunakan oleh data lain.";
            }
        }
        mysqli_stmt_close($stmtAsset);

        $barangSudahAda = array_unique($barangSudahAda);
        if (count($barangSudahAda) > 0) {
            if (!$inputTermasukPasangan) return "No Asset sudah digunakan oleh data lain.";
            if (count($barangSudahAda) >= 2) return "No Asset ini sudah dipakai untuk Monitor dan CPU.";
        }
    }
    return null;
}

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$type = $_GET['type'] ?? 'master';

if ($id <= 0) exit('ID tidak valid.');

$barang = getBarangById($koneksi, $id);
if (!$barang) exit('Data barang tidak ditemukan.');

$pengirimanTerakhir = getLastPengiriman($koneksi, $id);
ensureBarangAccess($barang, $pengirimanTerakhir);

$pernahDikirim = !empty($pengirimanTerakhir);
$sudahDiterima = isSudahDiterima($pengirimanTerakhir);
$sedangDikirim = isSedangDikirim($pengirimanTerakhir);

// ==========================================
// HANDLE RENDER FORM HTML (METODE GET)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (is_admin()) {
        if ($sedangDikirim) require_permission($koneksi, 'barang.kirim');
        elseif (!$pernahDikirim) require_permission($koneksi, 'barang.update');
        else require_permission($koneksi, 'barang.view');
    } else {
        ensureUserCanReceive($pengirimanTerakhir);
        require_permission($koneksi, 'barang.kirim');
    }

    $merkOptions = getSelectOptions($koneksi, "SELECT * FROM tb_merk ORDER BY nama_merk ASC");
    $tipeOptions = getSelectOptions($koneksi, "SELECT * FROM tb_tipe ORDER BY nama_tipe ASC");
    $jenisOptions = getSelectOptions($koneksi, "SELECT * FROM tb_jenis ORDER BY nama_jenis ASC");
    $branchOptions = getSelectOptions($koneksi, "SELECT * FROM tb_branch ORDER BY nama_branch ASC");
    $tujuanOptions = getSelectOptions($koneksi, "SELECT * FROM tb_branch ORDER BY nama_branch ASC");
?>
    <form id="formUpdate" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= (int) $barang['id'] ?>">

        <?php if ($type === 'master'): ?>
            <input type="hidden" name="form_type" value="master">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">No Asset</label>
                    <input type="text" name="no_asset" class="form-control" value="<?= h($barang['no_asset'] ?? '') ?>" placeholder="Boleh dikosongkan">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Serial Number<span class="text-danger">*</span></label>
                    <input type="text" name="serial_number" class="form-control" value="<?= h($barang['serial_number'] ?? '') ?>" placeholder="Wajib diisi!" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Merk<span class="text-danger">*</span></label>
                    <select name="id_merk" class="form-control select2">
                        <?php foreach ($merkOptions as $m): ?>
                            <option value="<?= (int) $m['id_merk'] ?>" <?= (int) $m['id_merk'] === (int) $barang['id_merk'] ? 'selected' : '' ?>><?= h($m['nama_merk']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tipe<span class="text-danger">*</span></label>
                    <select name="id_tipe" class="form-control select2">
                        <?php foreach ($tipeOptions as $t): ?>
                            <option value="<?= (int) $t['id_tipe'] ?>" <?= (int) $t['id_tipe'] === (int) $barang['id_tipe'] ? 'selected' : '' ?>><?= h($t['nama_tipe']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Jenis<span class="text-danger">*</span></label>
                    <select name="id_jenis" class="form-control select2">
                        <?php foreach ($jenisOptions as $j): ?>
                            <option value="<?= (int) $j['id_jenis'] ?>" <?= (int) $j['id_jenis'] === (int) $barang['id_jenis'] ? 'selected' : '' ?>><?= h($j['nama_jenis']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Branch Lokasi Saat Ini</label>
                    <select name="id_branch" class="form-control select2">
                        <?php foreach ($branchOptions as $b): ?>
                            <option value="<?= (int) $b['id_branch'] ?>" <?= (int) $b['id_branch'] === (int) $barang['id_branch'] ? 'selected' : '' ?>><?= h($b['nama_branch']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">User Pengguna<span class="text-danger">*</span></label>
                    <input type="text" name="user" class="form-control" value="<?= h($barang['user'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status Bermasalah<span class="text-danger">*</span></label>
                    <select name="bermasalah" class="form-control">
                        <option value="Tidak" <?= ($barang['bermasalah'] ?? '') === 'Tidak' ? 'selected' : '' ?>>Tidak</option>
                        <option value="Iya" <?= ($barang['bermasalah'] ?? '') === 'Iya' ? 'selected' : '' ?>>Iya</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tanggal Terima</label>
                    <input type="date" name="tanggal_terima" class="form-control" value="<?= h($barang['tanggal_terima'] ?? '') ?>" min="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Update Foto Barang</label>
                    <input type="file" name="foto" class="form-control">
                </div>
            </div>

            <!-- TOMBOL KHUSUS EDIT MASTER -->
            <div class="mt-4 text-end">
                <button type="submit" class="btn btn-warning"><i class="bi bi-save me-1"></i> Simpan Perubahan</button>
            </div>

        <?php elseif ($type === 'logistik'): ?>
            <?php if (!$pernahDikirim): ?>
                <input type="hidden" name="form_type" value="logistik">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Tanggal Keluar<span class="text-danger">*</span></label>
                        <input type="date" name="tanggal_keluar" class="form-control" required min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tujuan Pengiriman<span class="text-danger">*</span></label>
                        <select name="tujuan" class="form-control select2" required>
                            <option value="">-- Pilih Tujuan --</option>
                            <?php foreach ($tujuanOptions as $bt): ?>
                                <option value="<?= (int) $bt['id_branch'] ?>"><?= h($bt['nama_branch']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Jasa Pengiriman<span class="text-danger">*</span></label>
                        <select name="jasa_pengiriman" class="form-control select2" required>
                            <option value="">Pilih...</option>
                            <option value="SAP Express">SAP Express</option>
                            <option value="PCP Express">PCP Express</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nomor Resi Keluar<span class="text-danger">*</span></label>
                        <input type="text" name="nomor_resi" class="form-control" placeholder="Masukkan nomor resi keluar" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Foto Resi Keluar / Bukti Kirim<span class="text-danger">*</span></label>
                        <input type="file" name="foto_resi" class="form-control" required>
                    </div>
                </div>

                <!-- TOMBOL KHUSUS KIRIM LOGISTIK -->
                <div class="mt-4 text-end">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Kirim Logistik</button>
                </div>

            <?php elseif ($sedangDikirim): ?>
                <input type="hidden" name="form_type" value="penerimaan">
                <div class="alert alert-warning mb-3">Barang sedang dikirim. Isi form di bawah untuk konfirmasi penerimaan.</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Tanggal Diterima<span class="text-danger">*</span></label>
                        <input type="date" name="tanggal_diterima" class="form-control" required min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nama Penerima<span class="text-danger">*</span></label>
                        <input type="text" name="nama_penerima" class="form-control" placeholder="Nama penerima barang" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Upload Foto Barang Diterima<span class="text-danger">*</span></label>
                        <input type="file" name="foto_barang_diterima" class="form-control" required>
                    </div>
                </div>

                <!-- TOMBOL KHUSUS TERIMA LOGISTIK -->
                <div class="mt-4 text-end">
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i> Konfirmasi Terima</button>
                </div>

            <?php else: ?>
                <div class="alert alert-success">Barang sudah diterima. Proses logistik selesai.</div>
            <?php endif; ?>
        <?php endif; ?>
    </form>
<?php
    exit;
}

// ==========================================
// HANDLE PROSES DATA (METODE POST)
// ==========================================
$formType = $_POST['form_type'] ?? '';

if ($formType === 'penerimaan') {
    $tanggalDiterima = normalizeNullableString($_POST['tanggal_diterima'] ?? '');
    if ($tanggalDiterima < date('Y-m-d')) jsonError("Tanggal diterima tidak boleh tanggal yang lampau.");
    $namaPenerima = normalizeNullableString($_POST['nama_penerima'] ?? '');
    $upload = uploadImage('foto_barang_diterima');
    if ($upload['status'] === 'error') jsonError($upload['message']);

    mysqli_begin_transaction($koneksi);
    try {
        $stmt = mysqli_prepare($koneksi, "UPDATE barang_pengiriman SET status_pengiriman = ?, tanggal_diterima = ?, nama_penerima = ?, foto_barang_diterima = ? WHERE id_pengiriman = ?");
        $idPeng = (int)$pengirimanTerakhir['id_pengiriman'];
        $st = STATUS_SUDAH_DITERIMA;
        mysqli_stmt_bind_param($stmt, 'ssssi', $st, $tanggalDiterima, $namaPenerima, $upload['filename'], $idPeng);
        mysqli_stmt_execute($stmt);

        $branchTujuan = (int)$pengirimanTerakhir['branch_tujuan'];
        mysqli_query($koneksi, "UPDATE barang SET id_status = 4, id_branch = $branchTujuan WHERE id = $id");
        mysqli_commit($koneksi);
        jsonSuccess("Penerimaan barang berhasil.");
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        jsonError($e->getMessage());
    }
} elseif ($formType === 'master') {
    $tanggalTerima = $_POST['tanggal_terima'] ?? '';
    if ($tanggalTerima !== '' && $tanggalTerima < date('Y-m-d')) {
        jsonError("Tanggal terima tidak boleh tanggal yang lampau.");
    }
    $noAsset = trim((string)$_POST['no_asset']);
    $serialNumber = trim((string)$_POST['serial_number']);
    $duplicate = validateDuplicateBarang($koneksi, $id, $noAsset, $serialNumber);
    if ($duplicate) jsonError($duplicate);

    $upload = uploadImage('foto');
    $fotoBaru = $upload['status'] === 'success' ? $upload['filename'] : null;

    mysqli_begin_transaction($koneksi);
    try {
        $stmt = mysqli_prepare($koneksi, "UPDATE barang SET no_asset=?, serial_number=?, id_merk=?, id_tipe=?, id_jenis=?, id_branch=?, bermasalah=?, tanggal_terima=?, foto=COALESCE(?, foto) WHERE id=?");
        mysqli_stmt_bind_param($stmt, 'ssiiiisssi', $noAsset, $serialNumber, $_POST['id_merk'], $_POST['id_tipe'], $_POST['id_jenis'], $_POST['id_branch'], $_POST['bermasalah'], $_POST['tanggal_terima'], $fotoBaru, $id);
        mysqli_stmt_execute($stmt);
        mysqli_commit($koneksi);
        jsonSuccess("Data berhasil diupdate.");
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        jsonError($e->getMessage());
    }
} elseif ($formType === 'logistik') {
    $tanggalKeluar = $_POST['tanggal_keluar'] ?? '';
    if ($tanggalKeluar < date('Y-m-d')) {
        jsonError("Tanggal keluar logistik tidak boleh tanggal lampau.");
    }
    $upload = uploadImage('foto_resi');
    if ($upload['status'] === 'error') jsonError($upload['message']);

    mysqli_begin_transaction($koneksi);
    try {
        $stmt = mysqli_prepare($koneksi, "INSERT INTO barang_pengiriman (id_barang, branch_asal, branch_tujuan, tanggal_keluar, jasa_pengiriman, nomor_resi_keluar, foto_resi_keluar, status_pengiriman) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $asal = (int)$barang['id_branch'];
        $st = STATUS_SEDANG_PERJALANAN;
        mysqli_stmt_bind_param($stmt, 'iiisssss', $id, $asal, $_POST['tujuan'], $_POST['tanggal_keluar'], $_POST['jasa_pengiriman'], $_POST['nomor_resi'], $upload['filename'], $st);
        mysqli_stmt_execute($stmt);

        mysqli_query($koneksi, "UPDATE barang SET id_status = 3 WHERE id = $id");
        mysqli_commit($koneksi);
        jsonSuccess("Pengiriman berhasil dibuat.");
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        jsonError($e->getMessage());
    }
}
