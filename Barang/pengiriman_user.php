<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'barang.kirim');

if (!is_user_role()) {
    http_response_code(403);
    exit('Halaman ini khusus user cabang.');
}

const TARGET_JAKARTA_BRANCH_NAME = 'Cabang Jakarta';
const STATUS_SEDANG_PERJALANAN = 'Sedang perjalanan';

function esc(mysqli $koneksi, $value): string
{
    return mysqli_real_escape_string($koneksi, trim((string) $value));
}

function jsonError(string $message): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => $message
    ]);
    exit;
}

function jsonSuccess(string $message): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'message' => $message
    ]);
    exit;
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
        return ['status' => 'error', 'message' => "Gagal upload file {$fieldName}"];
    }

    return ['status' => 'success', 'filename' => $filename];
}

function generateResiPengirimanJakarta(): string
{
    return 'JKT-' . date('YmdHis') . '-' . strtoupper(substr(md5((string) microtime(true)), 0, 6));
}

function getJakartaBranch(mysqli $koneksi): ?array
{
    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT id_branch, nama_branch
         FROM tb_branch
         WHERE LOWER(TRIM(nama_branch)) = LOWER(TRIM(?))
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $branchName = TARGET_JAKARTA_BRANCH_NAME;
    mysqli_stmt_bind_param($stmt, 's', $branchName);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result) ?: null;

    mysqli_stmt_close($stmt);

    return $row;
}

function getBarangUserCabang(mysqli $koneksi, int $branchId): array
{
    $rows = [];

    $sql = "
        SELECT
            barang.id,
            barang.no_asset,
            barang.serial_number,
            barang.bermasalah,
            barang.keterangan_masalah,
            tb_barang.nama_barang,
            tb_merk.nama_merk,
            tb_tipe.nama_tipe
        FROM barang
        LEFT JOIN tb_barang ON barang.id_barang = tb_barang.id_barang
        LEFT JOIN tb_merk ON barang.id_merk = tb_merk.id_merk
        LEFT JOIN tb_tipe ON barang.id_tipe = tb_tipe.id_tipe
        LEFT JOIN (
            SELECT bp1.*
            FROM barang_pengiriman bp1
            INNER JOIN (
                SELECT id_barang, MAX(id_pengiriman) AS id_pengiriman_terakhir
                FROM barang_pengiriman
                GROUP BY id_barang
            ) last_bp ON bp1.id_pengiriman = last_bp.id_pengiriman_terakhir
        ) AS pengiriman ON barang.id = pengiriman.id_barang
        WHERE barang.id_branch = {$branchId}
          AND barang.bermasalah = 'Iya'
          AND (
                pengiriman.id_pengiriman IS NULL
                OR COALESCE(pengiriman.status_pengiriman, 'Belum dikirim') = 'Sudah diterima'
          )
        ORDER BY barang.id DESC
    ";

    $query = mysqli_query($koneksi, $sql);
    if (!$query) {
        return [];
    }

    while ($row = mysqli_fetch_assoc($query)) {
        $rows[] = $row;
    }

    return $rows;
}

function getBarangDetail(mysqli $koneksi, int $id, int $branchId): ?array
{
    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT id, no_asset, serial_number, id_branch, bermasalah
         FROM barang
         WHERE id = ? AND id_branch = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 'ii', $id, $branchId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result) ?: null;

    mysqli_stmt_close($stmt);

    return $row;
}

function hasActiveShipping(mysqli $koneksi, int $idBarang): bool
{
    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT id_pengiriman
         FROM barang_pengiriman
         WHERE id_barang = ?
           AND COALESCE(status_pengiriman, 'Belum dikirim') IN ('Sedang dikemas', 'Sedang perjalanan')
         ORDER BY id_pengiriman DESC
         LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'i', $idBarang);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $exists = (bool) mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    return $exists;
}

$myBranchId = (int) current_user_branch_id();
if ($myBranchId <= 0) {
    http_response_code(403);
    exit('Branch user belum ditentukan.');
}

$jakartaBranch = getJakartaBranch($koneksi);
if (!$jakartaBranch) {
    http_response_code(500);
    exit('Branch tujuan Cabang Jakarta belum ditemukan di tabel branch.');
}

if ((int) $jakartaBranch['id_branch'] === $myBranchId) {
    http_response_code(403);
    exit('Form ini hanya untuk cabang selain Cabang Jakarta.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $idBarang = (int) ($_POST['id_barang'] ?? 0);
    $tanggalKeluar = esc($koneksi, $_POST['tanggal_keluar'] ?? '');
    $jasaPengiriman = esc($koneksi, $_POST['jasa_pengiriman'] ?? '');

    if ($idBarang <= 0) {
        jsonError('Barang wajib dipilih.');
    }

    if ($tanggalKeluar === '') {
        jsonError('Tanggal kirim wajib diisi.');
    }

    $allowedJasa = ['Internal', 'JNE', 'TIKI', 'J&T', 'SiCepat', 'AnterAja', 'Ninja Xpress'];
    if (!in_array($jasaPengiriman, $allowedJasa, true)) {
        jsonError('Jasa pengiriman tidak valid.');
    }

    $barang = getBarangDetail($koneksi, $idBarang, $myBranchId);
    if (!$barang) {
        jsonError('Barang tidak ditemukan atau bukan milik cabang Anda.');
    }

    if (($barang['bermasalah'] ?? '') !== 'Iya') {
        jsonError('Form ini hanya untuk barang bermasalah.');
    }

    if (hasActiveShipping($koneksi, $idBarang)) {
        jsonError('Barang ini masih memiliki pengiriman aktif.');
    }

    $uploadFotoResi = uploadImage('foto_resi_keluar');
    if ($uploadFotoResi['status'] === 'error') {
        jsonError($uploadFotoResi['message']);
    }

    $fotoResiKeluar = $uploadFotoResi['status'] === 'success' ? $uploadFotoResi['filename'] : null;
    $nomorResiKeluar = generateResiPengirimanJakarta();
    $nomorResiMasuk = $nomorResiKeluar;
    $branchTujuan = (int) $jakartaBranch['id_branch'];
    $dibuatOleh = current_user_id() ? (int) current_user_id() : null;
    $idStatusBarang = 3;

    mysqli_begin_transaction($koneksi);

    try {
        $stmtInsert = mysqli_prepare(
            $koneksi,
            "INSERT INTO barang_pengiriman (
                id_barang,
                branch_asal,
                branch_tujuan,
                tanggal_keluar,
                jasa_pengiriman,
                nomor_resi_keluar,
                foto_resi_keluar,
                estimasi_pengiriman,
                catatan_pengiriman_keluar,
                status_pengiriman,
                tanggal_diterima,
                nama_penerima,
                nomor_resi_masuk,
                foto_resi_masuk,
                catatan_penerimaan,
                dibuat_oleh
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, NULL, NULL, ?, NULL, NULL, ?)"
        );

        if (!$stmtInsert) {
            throw new Exception('Gagal menyiapkan query pengiriman: ' . mysqli_error($koneksi));
        }

        mysqli_stmt_bind_param(
            $stmtInsert,
            'iiissssssi',
            $idBarang,
            $myBranchId,
            $branchTujuan,
            $tanggalKeluar,
            $jasaPengiriman,
            $nomorResiKeluar,
            $fotoResiKeluar,
            STATUS_SEDANG_PERJALANAN,
            $nomorResiMasuk,
            $dibuatOleh
        );

        if (!mysqli_stmt_execute($stmtInsert)) {
            throw new Exception('Gagal menyimpan pengiriman ke Cabang Jakarta: ' . mysqli_stmt_error($stmtInsert));
        }

        mysqli_stmt_close($stmtInsert);

        $stmtUpdateBarang = mysqli_prepare(
            $koneksi,
            "UPDATE barang
             SET id_status = ?
             WHERE id = ?"
        );

        if (!$stmtUpdateBarang) {
            throw new Exception('Gagal menyiapkan update status barang: ' . mysqli_error($koneksi));
        }

        mysqli_stmt_bind_param($stmtUpdateBarang, 'ii', $idStatusBarang, $idBarang);

        if (!mysqli_stmt_execute($stmtUpdateBarang)) {
            throw new Exception('Gagal update status barang: ' . mysqli_stmt_error($stmtUpdateBarang));
        }

        mysqli_stmt_close($stmtUpdateBarang);

        mysqli_commit($koneksi);

        jsonSuccess('Pengiriman barang bermasalah ke Cabang Jakarta berhasil dibuat. Status otomatis menjadi Sedang perjalanan.');
    } catch (Throwable $e) {
        mysqli_rollback($koneksi);
        jsonError($e->getMessage());
    }
}

$barangList = getBarangUserCabang($koneksi, $myBranchId);
?>

<form id="formPengirimanUser" enctype="multipart/form-data">
    <div class="row g-3">
        <div class="col-12">
            <div class="alert alert-info mb-0">
                <b>Catatan:</b> Form ini khusus untuk pengiriman barang bermasalah dari cabang Anda ke <b><?= h($jakartaBranch['nama_branch']) ?></b>.
                Status pengiriman akan otomatis menjadi <b><?= h(STATUS_SEDANG_PERJALANAN) ?></b>.
            </div>
        </div>

        <div class="col-md-6">
            <label class="form-label">Barang Bermasalah</label>
            <select name="id_barang" class="form-control select2" required>
                <option value="">Pilih Barang...</option>
                <?php foreach ($barangList as $item): ?>
                    <option value="<?= (int) $item['id'] ?>">
                        <?= h($item['no_asset']) ?> - <?= h($item['nama_barang'] ?? '-') ?> - <?= h($item['serial_number']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($barangList)): ?>
                <small class="text-danger">Tidak ada barang bermasalah yang siap dikirim ke Cabang Jakarta.</small>
            <?php endif; ?>
        </div>

        <div class="col-md-6">
            <label class="form-label">Tujuan</label>
            <input type="text" class="form-control" value="<?= h($jakartaBranch['nama_branch']) ?>" readonly>
        </div>

        <div class="col-md-6">
            <label class="form-label">Tanggal Kirim</label>
            <input type="date" name="tanggal_keluar" class="form-control" required value="<?= date('Y-m-d') ?>">
        </div>

        <div class="col-md-6">
            <label class="form-label">Jasa Pengiriman</label>
            <select name="jasa_pengiriman" class="form-control select2" required>
                <option value="">Pilih Jasa Pengiriman...</option>
                <option value="Internal">Internal</option>
                <option value="JNE">JNE</option>
                <option value="TIKI">TIKI</option>
                <option value="J&T">J&T</option>
                <option value="SiCepat">SiCepat</option>
                <option value="AnterAja">AnterAja</option>
                <option value="Ninja Xpress">Ninja Xpress</option>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Nomor Resi Keluar</label>
            <input type="text" class="form-control" value="Otomatis dibuat saat disimpan" readonly>
        </div>

        <div class="col-md-6">
            <label class="form-label">Nomor Resi Masuk</label>
            <input type="text" class="form-control" value="Otomatis sama dengan nomor resi keluar" readonly>
        </div>

        <div class="col-md-6">
            <label class="form-label">Foto Resi / Bukti Kirim</label>
            <input type="file" name="foto_resi_keluar" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
        </div>

        <div class="col-md-6">
            <label class="form-label">Status Pengiriman</label>
            <input type="text" class="form-control" value="<?= h(STATUS_SEDANG_PERJALANAN) ?>" readonly>
        </div>

        <div class="col-md-12 text-end">
            <button
                type="submit"
                class="btn btn-warning-custom"
                id="btnSimpanPengirimanUser"
                <?= empty($barangList) ? 'disabled' : '' ?>>
                <span class="btn-text">Simpan Pengiriman ke Cabang Jakarta</span>
                <span class="btn-loading d-none">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                    Menyimpan...
                </span>
            </button>
        </div>
    </div>
</form>