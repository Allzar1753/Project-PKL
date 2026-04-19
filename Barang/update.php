<?php
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

function validateDuplicateBarang(mysqli $koneksi, int $id, string $noAsset, string $serialNumber): ?string
{
    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT id, no_asset, serial_number
         FROM barang
         WHERE (no_asset = ? OR serial_number = ?)
           AND id != ?
         LIMIT 1"
    );

    mysqli_stmt_bind_param($stmt, 'ssi', $noAsset, $serialNumber, $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $duplikat = mysqli_fetch_assoc($result) ?: null;
    mysqli_stmt_close($stmt);

    if (!$duplikat) {
        return null;
    }

    if (($duplikat['no_asset'] ?? '') === $noAsset && ($duplikat['serial_number'] ?? '') === $serialNumber) {
        return "No Asset dan Serial Number sudah digunakan oleh data lain.";
    }

    if (($duplikat['no_asset'] ?? '') === $noAsset) {
        return "No Asset sudah digunakan oleh data lain.";
    }

    if (($duplikat['serial_number'] ?? '') === $serialNumber) {
        return "Serial Number sudah digunakan oleh data lain.";
    }

    return "Data sudah digunakan oleh barang lain.";
}

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    exit('ID tidak valid.');
}

$barang = getBarangById($koneksi, $id);
if (!$barang) {
    exit('Data barang tidak ditemukan.');
}

$pengirimanTerakhir = getLastPengiriman($koneksi, $id);
ensureBarangAccess($barang, $pengirimanTerakhir);

$pernahDikirim = !empty($pengirimanTerakhir);
$sudahDiterima = isSudahDiterima($pengirimanTerakhir);
$sedangDikirim = isSedangDikirim($pengirimanTerakhir);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (is_admin()) {
        if ($sedangDikirim) {
            require_permission($koneksi, 'barang.kirim');
        } elseif (!$pernahDikirim) {
            require_permission($koneksi, 'barang.update');
        } else {
            require_permission($koneksi, 'barang.view');
        }
    } else {
        ensureUserCanReceive($pengirimanTerakhir);
        require_permission($koneksi, 'barang.kirim');
    }

    $merkOptions = getSelectOptions($koneksi, "SELECT * FROM tb_merk ORDER BY nama_merk ASC");
    $tipeOptions = getSelectOptions($koneksi, "SELECT * FROM tb_tipe ORDER BY nama_tipe ASC");
    $jenisOptions = getSelectOptions($koneksi, "SELECT * FROM tb_jenis ORDER BY nama_jenis ASC");
    $branchOptions = getSelectOptions($koneksi, "SELECT * FROM tb_branch ORDER BY nama_branch ASC");
    $tujuanOptions = getSelectOptions($koneksi, "SELECT * FROM tb_branch ORDER BY nama_branch ASC");

    $statusPengirimanBaruOptions = [
        STATUS_SEDANG_DIKEMAS,
        STATUS_SEDANG_PERJALANAN,
    ];

    $statusPenerimaanOptions = [
        STATUS_SEDANG_DIKEMAS,
        STATUS_SEDANG_PERJALANAN,
        STATUS_SUDAH_DITERIMA,
    ];
    ?>
    <form id="formUpdate" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= (int) $barang['id'] ?>">

        <?php if (!$pernahDikirim): ?>
            <div class="alert alert-info mb-3">
                Barang ini belum pernah dikirim. Kamu masih bisa ubah <b>Data Barang</b> dan isi <b>Logistik Pengiriman</b>.
            </div>
        <?php elseif ($sedangDikirim): ?>
            <div class="alert alert-warning mb-3">
                Barang ini <b>sudah dikirim</b>. Data Barang dan Pengiriman sudah dikunci.
                Sekarang yang bisa diisi hanya <b>Logistik Penerimaan</b>.
            </div>
        <?php else: ?>
            <div class="alert alert-secondary mb-3">
                Barang ini sudah <b>diterima</b>. Semua data sudah terkunci dan tidak bisa diubah lagi.
            </div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-3">
            <?php if (!$pernahDikirim): ?>
                <li class="nav-item">
                    <button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabBarang">
                        Data Barang + Status
                    </button>
                </li>
                <li class="nav-item">
                    <button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tabPengiriman">
                        Logistik Pengiriman
                    </button>
                </li>
            <?php elseif ($sedangDikirim): ?>
                <li class="nav-item">
                    <button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabPenerimaan">
                        Logistik Penerimaan
                    </button>
                </li>
            <?php else: ?>
                <li class="nav-item">
                    <button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabReadonly">
                        Detail Pengiriman
                    </button>
                </li>
            <?php endif; ?>
        </ul>

        <div class="tab-content">
            <?php if (!$pernahDikirim): ?>
                <div class="tab-pane fade show active" id="tabBarang">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">No Asset</label>
                            <input type="text" name="no_asset" class="form-control" value="<?= h($barang['no_asset'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Serial Number</label>
                            <input type="text" name="serial_number" class="form-control" value="<?= h($barang['serial_number'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Merk</label>
                            <select name="id_merk" class="form-control select2">
                                <?php foreach ($merkOptions as $m): ?>
                                    <option value="<?= (int) $m['id_merk'] ?>" <?= (int) $m['id_merk'] === (int) $barang['id_merk'] ? 'selected' : '' ?>>
                                        <?= h($m['nama_merk']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Tipe</label>
                            <select name="id_tipe" class="form-control select2">
                                <?php foreach ($tipeOptions as $t): ?>
                                    <option value="<?= (int) $t['id_tipe'] ?>" <?= (int) $t['id_tipe'] === (int) $barang['id_tipe'] ? 'selected' : '' ?>>
                                        <?= h($t['nama_tipe']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Jenis</label>
                            <select name="id_jenis" class="form-control select2">
                                <?php foreach ($jenisOptions as $j): ?>
                                    <option value="<?= (int) $j['id_jenis'] ?>" <?= (int) $j['id_jenis'] === (int) $barang['id_jenis'] ? 'selected' : '' ?>>
                                        <?= h($j['nama_jenis']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Branch</label>
                            <select name="id_branch" class="form-control select2">
                                <?php foreach ($branchOptions as $b): ?>
                                    <option value="<?= (int) $b['id_branch'] ?>" <?= (int) $b['id_branch'] === (int) $barang['id_branch'] ? 'selected' : '' ?>>
                                        <?= h($b['nama_branch']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">User</label>
                            <input type="text" name="user" class="form-control" value="<?= h($barang['user'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Bermasalah</label>
                            <select name="bermasalah" class="form-control">
                                <option value="Tidak" <?= ($barang['bermasalah'] ?? '') === 'Tidak' ? 'selected' : '' ?>>Tidak</option>
                                <option value="Iya" <?= ($barang['bermasalah'] ?? '') === 'Iya' ? 'selected' : '' ?>>Iya</option>
                            </select>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Keterangan Masalah</label>
                            <textarea name="keterangan_masalah" class="form-control"><?= h($barang['keterangan_masalah'] ?? '') ?></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Tanggal Kirim</label>
                            <input type="date" name="tanggal_kirim" class="form-control" value="<?= h($barang['tanggal_kirim'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Foto Barang Baru</label>
                            <input type="file" name="foto" class="form-control">
                        </div>

                        <?php if (!empty($barang['foto'])): ?>
                            <div class="col-md-6">
                                <label class="form-label">Foto Barang Saat Ini</label>
                                <div>
                                    <button type="button" class="btn btn-outline-secondary previewFoto" data-foto="../assets/images/<?= h($barang['foto']) ?>">
                                        <i class="bi bi-image"></i> Lihat Foto Barang
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="tab-pane fade" id="tabPengiriman">
                    <div class="alert alert-info mb-3">
                        Begitu data pengiriman disimpan, barang akan menjadi <b>Barang Keluar</b> permanen dan form ini tidak akan muncul lagi.
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Tanggal Keluar</label>
                            <input type="date" name="tanggal_keluar" class="form-control" <?= (($barang['bermasalah'] ?? '') === 'Iya') ? 'disabled' : '' ?>>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Tujuan</label>
                            <select name="tujuan" class="form-control select2">
                                <option value="">-- Pilih Tujuan --</option>
                                <?php foreach ($tujuanOptions as $bt): ?>
                                    <option value="<?= (int) $bt['id_branch'] ?>"><?= h($bt['nama_branch']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Jasa Pengiriman</label>
                            <input type="text" name="jasa_pengiriman" class="form-control" placeholder="Contoh: Internal / JNE / TIKI">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Nomor Resi Keluar</label>
                            <input type="text" name="nomor_resi" class="form-control" placeholder="Masukkan nomor resi keluar">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Status Pengiriman</label>
                            <select name="status_pengiriman_baru" class="form-control">
                                <?php foreach ($statusPengirimanBaruOptions as $statusItem): ?>
                                    <option value="<?= h($statusItem) ?>"><?= h($statusItem) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Estimasi Pengiriman</label>
                            <input type="text" name="estimasi_pengiriman" class="form-control" placeholder="Contoh: 2-3 hari / Tiba besok">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Foto Resi Keluar / Bukti Kirim</label>
                            <input type="file" name="foto_resi" class="form-control">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Catatan Pengiriman Keluar</label>
                            <textarea name="catatan_pengiriman" class="form-control" rows="3" placeholder="Catatan tambahan pengiriman keluar"></textarea>
                        </div>
                    </div>
                </div>
            <?php elseif ($sedangDikirim): ?>
                <div class="tab-pane fade show active" id="tabPenerimaan">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="alert alert-light border mb-2">
                                <b>Ringkasan Pengiriman</b><br>
                                Tanggal Keluar: <?= h($pengirimanTerakhir['tanggal_keluar'] ?? '-') ?><br>
                                Tujuan: <?= h($pengirimanTerakhir['nama_branch_tujuan'] ?? '-') ?><br>
                                Status Saat Ini: <?= h($pengirimanTerakhir['status_pengiriman'] ?? '-') ?><br>
                                Resi Keluar: <?= h($pengirimanTerakhir['nomor_resi_keluar'] ?? '-') ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Status Penerimaan</label>
                            <select name="status_penerimaan" class="form-control">
                                <?php foreach ($statusPenerimaanOptions as $statusItem): ?>
                                    <option value="<?= h($statusItem) ?>" <?= (($pengirimanTerakhir['status_pengiriman'] ?? '') === $statusItem) ? 'selected' : '' ?>>
                                        <?= h($statusItem) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Tanggal Diterima</label>
                            <input type="date" name="tanggal_diterima" class="form-control" value="<?= h($pengirimanTerakhir['tanggal_diterima'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Nama Penerima</label>
                            <input type="text" name="nama_penerima" class="form-control" placeholder="Nama penerima barang" value="<?= h($pengirimanTerakhir['nama_penerima'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Nomor Resi Masuk</label>
                            <input
                                type="text"
                                name="nomor_resi_masuk"
                                class="form-control"
                                readonly
                                value="<?= h($pengirimanTerakhir['nomor_resi_masuk'] ?? ($pengirimanTerakhir['nomor_resi_keluar'] ?? '')) ?>">
                            <small class="text-muted">Otomatis mengikuti nomor resi keluar.</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Foto Resi Masuk / Bukti Terima</label>
                            <input type="file" name="foto_resi_masuk" class="form-control">
                        </div>

                        <?php if (!empty($pengirimanTerakhir['foto_resi_masuk'])): ?>
                            <div class="col-md-6">
                                <label class="form-label">Foto Resi Masuk Saat Ini</label>
                                <div>
                                    <button type="button" class="btn btn-outline-secondary previewFoto" data-foto="../assets/images/<?= h($pengirimanTerakhir['foto_resi_masuk']) ?>">
                                        <i class="bi bi-image"></i> Lihat Foto Resi Masuk
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="col-md-12">
                            <label class="form-label">Catatan Penerimaan</label>
                            <textarea name="catatan_penerimaan" class="form-control" rows="3" placeholder="Catatan penerimaan barang"><?= h($pengirimanTerakhir['catatan_penerimaan'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="tab-pane fade show active" id="tabReadonly">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">No Asset</label>
                            <input type="text" class="form-control" value="<?= h($barang['no_asset'] ?? '') ?>" readonly>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Serial Number</label>
                            <input type="text" class="form-control" value="<?= h($barang['serial_number'] ?? '') ?>" readonly>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Tanggal Keluar</label>
                            <input type="text" class="form-control" value="<?= h($pengirimanTerakhir['tanggal_keluar'] ?? '') ?>" readonly>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Status Pengiriman</label>
                            <input type="text" class="form-control" value="<?= h($pengirimanTerakhir['status_pengiriman'] ?? '') ?>" readonly>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Tanggal Diterima</label>
                            <input type="text" class="form-control" value="<?= h($pengirimanTerakhir['tanggal_diterima'] ?? '') ?>" readonly>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Nama Penerima</label>
                            <input type="text" class="form-control" value="<?= h($pengirimanTerakhir['nama_penerima'] ?? '') ?>" readonly>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Catatan Penerimaan</label>
                            <textarea class="form-control" rows="3" readonly><?= h($pengirimanTerakhir['catatan_penerimaan'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$sudahDiterima): ?>
            <div class="mt-4 text-end">
                <button type="submit" class="btn btn-warning">
                    <?php if ($sedangDikirim): ?>
                        Simpan Penerimaan
                    <?php else: ?>
                        Simpan
                    <?php endif; ?>
                </button>
            </div>
        <?php endif; ?>
    </form>
    <?php
    exit;
}

$barang = getBarangById($koneksi, $id);
if (!$barang) {
    jsonError("Data barang tidak ditemukan.");
}

$pengirimanTerakhir = getLastPengiriman($koneksi, $id);
ensureBarangAccess($barang, $pengirimanTerakhir);

$pernahDikirim = !empty($pengirimanTerakhir);
$sudahDiterima = isSudahDiterima($pengirimanTerakhir);
$sedangDikirim = isSedangDikirim($pengirimanTerakhir);

if ($sudahDiterima) {
    jsonError("Barang yang sudah diterima tidak bisa diubah lagi.");
}

if ($sedangDikirim) {
    require_permission($koneksi, 'barang.kirim');

    if (!is_admin()) {
        ensureUserCanReceive($pengirimanTerakhir);
    }

    $allowedStatusPenerimaan = [
        STATUS_SEDANG_DIKEMAS,
        STATUS_SEDANG_PERJALANAN,
        STATUS_SUDAH_DITERIMA,
    ];

    $statusPenerimaan = trim((string) ($_POST['status_penerimaan'] ?? ($pengirimanTerakhir['status_pengiriman'] ?? STATUS_SEDANG_PERJALANAN)));
    if (!in_array($statusPenerimaan, $allowedStatusPenerimaan, true)) {
        $statusPenerimaan = (string) ($pengirimanTerakhir['status_pengiriman'] ?? STATUS_SEDANG_PERJALANAN);
    }

    $tanggalDiterima = normalizeNullableString($_POST['tanggal_diterima'] ?? '');
    $namaPenerima = normalizeNullableString($_POST['nama_penerima'] ?? '');
    $nomorResiMasuk = normalizeNullableString($_POST['nomor_resi_masuk'] ?? ($pengirimanTerakhir['nomor_resi_keluar'] ?? ''));
    $catatanPenerimaan = normalizeNullableString($_POST['catatan_penerimaan'] ?? '');

    if ($nomorResiMasuk === null) {
        $nomorResiMasuk = (string) ($pengirimanTerakhir['nomor_resi_keluar'] ?? '');
    }

    if ($statusPenerimaan === STATUS_SUDAH_DITERIMA) {
        if ($tanggalDiterima === null) {
            jsonError("Tanggal diterima wajib diisi jika status sudah diterima.");
        }

        if ($namaPenerima === null) {
            jsonError("Nama penerima wajib diisi jika status sudah diterima.");
        }
    }

    $uploadFotoResiMasuk = uploadImage('foto_resi_masuk');
    if ($uploadFotoResiMasuk['status'] === 'error') {
        jsonError($uploadFotoResiMasuk['message']);
    }

    $fotoResiMasukBaru = $uploadFotoResiMasuk['status'] === 'success' ? $uploadFotoResiMasuk['filename'] : null;

    $idStatusBarang = (($barang['bermasalah'] ?? '') === 'Iya') ? 5 : 3;
    $idBranchFinal = (int) $barang['id_branch'];

    if ($statusPenerimaan === STATUS_SUDAH_DITERIMA) {
        $idStatusBarang = (($barang['bermasalah'] ?? '') === 'Iya') ? 5 : 4;

        if ((int) ($pengirimanTerakhir['branch_tujuan'] ?? 0) > 0) {
            $idBranchFinal = (int) $pengirimanTerakhir['branch_tujuan'];
        }
    }

    mysqli_begin_transaction($koneksi);

    try {
        $stmtUpdatePengiriman = mysqli_prepare(
            $koneksi,
            "UPDATE barang_pengiriman
             SET status_pengiriman = ?,
                 tanggal_diterima = ?,
                 nama_penerima = ?,
                 nomor_resi_masuk = ?,
                 foto_resi_masuk = COALESCE(?, foto_resi_masuk),
                 catatan_penerimaan = ?
             WHERE id_pengiriman = ?"
        );

        $idPengiriman = (int) $pengirimanTerakhir['id_pengiriman'];
        mysqli_stmt_bind_param(
            $stmtUpdatePengiriman,
            'ssssssi',
            $statusPenerimaan,
            $tanggalDiterima,
            $namaPenerima,
            $nomorResiMasuk,
            $fotoResiMasukBaru,
            $catatanPenerimaan,
            $idPengiriman
        );

        if (!mysqli_stmt_execute($stmtUpdatePengiriman)) {
            throw new Exception("Update logistik penerimaan gagal: " . mysqli_stmt_error($stmtUpdatePengiriman));
        }
        mysqli_stmt_close($stmtUpdatePengiriman);

        $stmtUpdateBarang = mysqli_prepare(
            $koneksi,
            "UPDATE barang
             SET id_status = ?, id_branch = ?
             WHERE id = ?"
        );

        mysqli_stmt_bind_param($stmtUpdateBarang, 'iii', $idStatusBarang, $idBranchFinal, $id);
        if (!mysqli_stmt_execute($stmtUpdateBarang)) {
            throw new Exception("Update status barang gagal: " . mysqli_stmt_error($stmtUpdateBarang));
        }
        mysqli_stmt_close($stmtUpdateBarang);

        mysqli_commit($koneksi);

        if ($statusPenerimaan === STATUS_SUDAH_DITERIMA) {
            jsonSuccess("Penerimaan barang berhasil disimpan. Barang sudah diterima dan sekarang terkunci total.");
        }

        jsonSuccess("Logistik penerimaan berhasil diupdate.");
    } catch (Throwable $e) {
        mysqli_rollback($koneksi);
        jsonError($e->getMessage());
    }
}

if (is_user_role()) {
    jsonError("User cabang tidak boleh mengubah data master barang dari halaman ini.");
}

require_permission($koneksi, 'barang.update');

$noAsset = trim((string) ($_POST['no_asset'] ?? ''));
$serialNumber = trim((string) ($_POST['serial_number'] ?? ''));
$idMerk = (int) ($_POST['id_merk'] ?? 0);
$idTipe = (int) ($_POST['id_tipe'] ?? 0);
$idJenis = (int) ($_POST['id_jenis'] ?? 0);
$idBranch = (int) ($_POST['id_branch'] ?? 0);
$userBarang = trim((string) ($_POST['user'] ?? ''));
$bermasalah = trim((string) ($_POST['bermasalah'] ?? 'Tidak'));
$keteranganMasalah = normalizeNullableString($_POST['keterangan_masalah'] ?? '');
$tanggalMasuk = normalizeNullableString($_POST['tanggal_masuk'] ?? '');

$tanggalKeluar = normalizeNullableString($_POST['tanggal_keluar'] ?? '');
$tujuan = isset($_POST['tujuan']) && trim((string) $_POST['tujuan']) !== '' ? (int) $_POST['tujuan'] : 0;
$jasaPengiriman = normalizeNullableString($_POST['jasa_pengiriman'] ?? '');
$nomorResiKeluar = normalizeNullableString($_POST['nomor_resi'] ?? '');
$estimasiPengiriman = normalizeNullableString($_POST['estimasi_pengiriman'] ?? '');
$catatanPengiriman = normalizeNullableString($_POST['catatan_pengiriman'] ?? '');
$statusPengirimanBaru = trim((string) ($_POST['status_pengiriman_baru'] ?? STATUS_SEDANG_DIKEMAS));

$allowedStatusBaru = [
    STATUS_SEDANG_DIKEMAS,
    STATUS_SEDANG_PERJALANAN,
];

if (!in_array($statusPengirimanBaru, $allowedStatusBaru, true)) {
    $statusPengirimanBaru = STATUS_SEDANG_DIKEMAS;
}

$adaInputPengirimanBaru = (
    $tanggalKeluar !== null ||
    $tujuan > 0 ||
    $jasaPengiriman !== null ||
    $nomorResiKeluar !== null ||
    $estimasiPengiriman !== null ||
    $catatanPengiriman !== null ||
    (isset($_FILES['foto_resi']) && !empty($_FILES['foto_resi']['name']))
);

if ($adaInputPengirimanBaru) {
    require_permission($koneksi, 'barang.kirim');
}

if ($noAsset === '' || $serialNumber === '') {
    jsonError("No Asset dan Serial Number wajib diisi.");
}

if ($idMerk <= 0 || $idTipe <= 0 || $idJenis <= 0 || $idBranch <= 0) {
    jsonError("Merk, tipe, jenis, dan branch wajib dipilih.");
}

$duplicateMessage = validateDuplicateBarang($koneksi, $id, $noAsset, $serialNumber);
if ($duplicateMessage !== null) {
    jsonError($duplicateMessage);
}

if ($bermasalah === 'Iya' && $adaInputPengirimanBaru) {
    jsonError("Barang bermasalah tidak boleh dikirim dari form ini.");
}

if ($adaInputPengirimanBaru) {
    if ($tanggalKeluar === null) {
        jsonError("Tanggal keluar wajib diisi saat membuat pengiriman.");
    }

    if ($tujuan <= 0) {
        jsonError("Tujuan wajib dipilih saat membuat pengiriman.");
    }
}

$uploadFotoBarang = uploadImage('foto');
if ($uploadFotoBarang['status'] === 'error') {
    jsonError($uploadFotoBarang['message']);
}
$fotoBarangBaru = $uploadFotoBarang['status'] === 'success' ? $uploadFotoBarang['filename'] : null;

$uploadFotoResiKeluar = uploadImage('foto_resi');
if ($uploadFotoResiKeluar['status'] === 'error') {
    jsonError($uploadFotoResiKeluar['message']);
}
$fotoResiKeluarBaru = $uploadFotoResiKeluar['status'] === 'success' ? $uploadFotoResiKeluar['filename'] : null;

$idStatusBarang = 4;
$idBranchFinal = $idBranch;

if ($bermasalah === 'Iya') {
    $idStatusBarang = 5;
} elseif ($adaInputPengirimanBaru) {
    $idStatusBarang = 3;
    $idBranchFinal = (int) $barang['id_branch'];
}

mysqli_begin_transaction($koneksi);

try {
    $stmtUpdateBarang = mysqli_prepare(
        $koneksi,
        "UPDATE barang
         SET no_asset = ?,
             serial_number = ?,
             id_merk = ?,
             id_tipe = ?,
             id_jenis = ?,
             id_status = ?,
             id_branch = ?,
             `user` = ?,
             bermasalah = ?,
             keterangan_masalah = ?,
             tanggal_masuk = ?,
             foto = COALESCE(?, foto)
         WHERE id = ?"
    );

    mysqli_stmt_bind_param(
        $stmtUpdateBarang,
        'ssiiiiisssssi',
        $noAsset,
        $serialNumber,
        $idMerk,
        $idTipe,
        $idJenis,
        $idStatusBarang,
        $idBranchFinal,
        $userBarang,
        $bermasalah,
        $keteranganMasalah,
        $tanggalMasuk,
        $fotoBarangBaru,
        $id
    );

    if (!mysqli_stmt_execute($stmtUpdateBarang)) {
        throw new Exception("Update barang gagal: " . mysqli_stmt_error($stmtUpdateBarang));
    }
    mysqli_stmt_close($stmtUpdateBarang);

    if ($adaInputPengirimanBaru) {
        $branchAsalTransaksi = (int) $barang['id_branch'];
        $tanggalDiterimaNull = null;
        $namaPenerimaNull = null;
        $nomorResiMasukNull = null;
        $fotoResiMasukNull = null;
        $catatanPenerimaanNull = null;
        $dibuatOleh = (int) current_user_id();

        $stmtInsertPengiriman = mysqli_prepare(
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
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        mysqli_stmt_bind_param(
            $stmtInsertPengiriman,
            'iiissssssssssssi',
            $id,
            $branchAsalTransaksi,
            $tujuan,
            $tanggalKeluar,
            $jasaPengiriman,
            $nomorResiKeluar,
            $fotoResiKeluarBaru,
            $estimasiPengiriman,
            $catatanPengiriman,
            $statusPengirimanBaru,
            $tanggalDiterimaNull,
            $namaPenerimaNull,
            $nomorResiMasukNull,
            $fotoResiMasukNull,
            $catatanPenerimaanNull,
            $dibuatOleh
        );

        if (!mysqli_stmt_execute($stmtInsertPengiriman)) {
            throw new Exception("Simpan pengiriman baru gagal: " . mysqli_stmt_error($stmtInsertPengiriman));
        }
        mysqli_stmt_close($stmtInsertPengiriman);
    }

    mysqli_commit($koneksi);

    if ($adaInputPengirimanBaru) {
        jsonSuccess("Data barang berhasil diupdate dan pengiriman berhasil dibuat. Setelah ini data barang terkunci dan hanya form penerimaan yang tersisa.");
    }

    jsonSuccess("Data barang berhasil diupdate.");
} catch (Throwable $e) {
    mysqli_rollback($koneksi);
    jsonError($e->getMessage());
}