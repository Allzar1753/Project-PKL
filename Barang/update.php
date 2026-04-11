<?php
include '../config/koneksi.php';
require_once '../config/auth.php';
require_login();

function esc(mysqli $koneksi, $value): string
{
    return mysqli_real_escape_string($koneksi, trim((string) $value));
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function sqlNullable(string $value): string
{
    return $value !== '' ? "'$value'" : "NULL";
}

function uploadImage(string $fieldName, string $targetDir = "../assets/images/"): array
{
    if (!isset($_FILES[$fieldName]) || empty($_FILES[$fieldName]['name'])) {
        return ["status" => "empty", "filename" => ""];
    }

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed, true)) {
        return ["status" => "error", "message" => "Format file {$fieldName} tidak diperbolehkan"];
    }

    if ($_FILES[$fieldName]['size'] > 2000000) {
        return ["status" => "error", "message" => "Ukuran file {$fieldName} maksimal 2MB"];
    }

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $filename = uniqid($fieldName . "_", true) . "." . $ext;

    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetDir . $filename)) {
        return ["status" => "error", "message" => "Gagal upload file {$fieldName}"];
    }

    return ["status" => "success", "filename" => $filename];
}

function jsonError(string $message): void
{
    echo json_encode([
        "status" => "error",
        "message" => $message
    ]);
    exit;
}

function jsonSuccess(string $message): void
{
    echo json_encode([
        "status" => "success",
        "message" => $message
    ]);
    exit;
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
        "SELECT * FROM barang_pengiriman WHERE id_barang = ? ORDER BY id_pengiriman DESC LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $idBarang);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result) ?: null;
    mysqli_stmt_close($stmt);

    return $row;
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

$pernahDikirim = !empty($pengirimanTerakhir);
$sudahDiterima = $pernahDikirim && (($pengirimanTerakhir['status_pengiriman'] ?? '') === 'Sudah diterima');
$sedangDikirim = $pernahDikirim && !$sudahDiterima;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($sedangDikirim) {
        require_permission($koneksi, 'barang.kirim');
    } elseif (!$pernahDikirim) {
        require_permission($koneksi, 'barang.update');
    } else {
        require_permission($koneksi, 'barang.view');
    }

    $merkQuery   = mysqli_query($koneksi, "SELECT * FROM tb_merk ORDER BY nama_merk ASC");
    $tipeQuery   = mysqli_query($koneksi, "SELECT * FROM tb_tipe ORDER BY nama_tipe ASC");
    $jenisQuery  = mysqli_query($koneksi, "SELECT * FROM tb_jenis ORDER BY nama_jenis ASC");
    $branchQuery = mysqli_query($koneksi, "SELECT * FROM tb_branch ORDER BY nama_branch ASC");
    $tujuanQuery = mysqli_query($koneksi, "SELECT * FROM tb_branch ORDER BY nama_branch ASC");

    $statusPengirimanBaruOptions = [
        'Sedang dikemas',
        'Sedang perjalanan'
    ];

    $statusPenerimaanOptions = [
        'Sedang dikemas',
        'Sedang perjalanan',
        'Sudah diterima'
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
                                <?php while ($m = mysqli_fetch_assoc($merkQuery)): ?>
                                    <option value="<?= (int) $m['id_merk'] ?>" <?= (int) $m['id_merk'] === (int) $barang['id_merk'] ? 'selected' : '' ?>>
                                        <?= h($m['nama_merk']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Tipe</label>
                            <select name="id_tipe" class="form-control select2">
                                <?php while ($t = mysqli_fetch_assoc($tipeQuery)): ?>
                                    <option value="<?= (int) $t['id_tipe'] ?>" <?= (int) $t['id_tipe'] === (int) $barang['id_tipe'] ? 'selected' : '' ?>>
                                        <?= h($t['nama_tipe']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Jenis</label>
                            <select name="id_jenis" class="form-control select2">
                                <?php while ($j = mysqli_fetch_assoc($jenisQuery)): ?>
                                    <option value="<?= (int) $j['id_jenis'] ?>" <?= (int) $j['id_jenis'] === (int) $barang['id_jenis'] ? 'selected' : '' ?>>
                                        <?= h($j['nama_jenis']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Branch</label>
                            <select name="id_branch" class="form-control select2">
                                <?php while ($b = mysqli_fetch_assoc($branchQuery)): ?>
                                    <option value="<?= (int) $b['id_branch'] ?>" <?= (int) $b['id_branch'] === (int) $barang['id_branch'] ? 'selected' : '' ?>>
                                        <?= h($b['nama_branch']) ?>
                                    </option>
                                <?php endwhile; ?>
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
                            <label class="form-label">Tanggal Masuk</label>
                            <input type="date" name="tanggal_masuk" class="form-control" value="<?= h($barang['tanggal_masuk'] ?? '') ?>">
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
                                <?php while ($bt = mysqli_fetch_assoc($tujuanQuery)): ?>
                                    <option value="<?= (int) $bt['id_branch'] ?>"><?= h($bt['nama_branch']) ?></option>
                                <?php endwhile; ?>
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
                                Tujuan: <?= h($pengirimanTerakhir['branch_tujuan'] ?? '-') ?><br>
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
                            <input type="text" name="nomor_resi_masuk" class="form-control" placeholder="Masukkan nomor resi masuk" value="<?= h($pengirimanTerakhir['nomor_resi_masuk'] ?? '') ?>">
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

header('Content-Type: application/json');

$barang = getBarangById($koneksi, $id);
if (!$barang) {
    jsonError("Data barang tidak ditemukan.");
}

$pengirimanTerakhir = getLastPengiriman($koneksi, $id);

$pernahDikirim = !empty($pengirimanTerakhir);
$sudahDiterima = $pernahDikirim && (($pengirimanTerakhir['status_pengiriman'] ?? '') === 'Sudah diterima');
$sedangDikirim = $pernahDikirim && !$sudahDiterima;

if ($sudahDiterima) {
    jsonError("Barang yang sudah diterima tidak bisa diubah lagi.");
}

if ($sedangDikirim) {
    require_permission($koneksi, 'barang.kirim');

    $status_penerimaan = trim((string) ($_POST['status_penerimaan'] ?? ($pengirimanTerakhir['status_pengiriman'] ?? 'Sedang perjalanan')));
    $allowedStatusPenerimaan = ['Sedang dikemas', 'Sedang perjalanan', 'Sudah diterima'];

    if (!in_array($status_penerimaan, $allowedStatusPenerimaan, true)) {
        $status_penerimaan = $pengirimanTerakhir['status_pengiriman'] ?? 'Sedang perjalanan';
    }

    $tanggal_diterima   = esc($koneksi, $_POST['tanggal_diterima'] ?? '');
    $nama_penerima      = esc($koneksi, $_POST['nama_penerima'] ?? '');
    $nomor_resi_masuk   = esc($koneksi, $_POST['nomor_resi_masuk'] ?? '');
    $catatan_penerimaan = esc($koneksi, $_POST['catatan_penerimaan'] ?? '');

    if ($status_penerimaan === 'Sudah diterima') {
        if ($tanggal_diterima === '') {
            jsonError("Tanggal diterima wajib diisi jika status sudah diterima.");
        }

        if ($nama_penerima === '') {
            jsonError("Nama penerima wajib diisi jika status sudah diterima.");
        }
    }

    $uploadFotoResiMasuk = uploadImage('foto_resi_masuk');
    if ($uploadFotoResiMasuk['status'] === 'error') {
        jsonError($uploadFotoResiMasuk['message']);
    }
    $fotoResiMasukBaru = $uploadFotoResiMasuk['status'] === 'success' ? $uploadFotoResiMasuk['filename'] : '';

    $id_status_barang = (($barang['bermasalah'] ?? '') === 'Iya') ? 5 : 3;
    $id_branch_final = (int) $barang['id_branch'];

    if ($status_penerimaan === 'Sudah diterima') {
        $id_status_barang = (($barang['bermasalah'] ?? '') === 'Iya') ? 5 : 4;
        if ((int) ($pengirimanTerakhir['branch_tujuan'] ?? 0) > 0) {
            $id_branch_final = (int) $pengirimanTerakhir['branch_tujuan'];
        }
    }

    mysqli_begin_transaction($koneksi);

    try {
        $queryLogistik = "
            UPDATE barang_pengiriman SET
                status_pengiriman = '$status_penerimaan',
                tanggal_diterima = " . sqlNullable($tanggal_diterima) . ",
                nama_penerima = " . sqlNullable($nama_penerima) . ",
                nomor_resi_masuk = " . sqlNullable($nomor_resi_masuk) . ",
                catatan_penerimaan = " . sqlNullable($catatan_penerimaan);

        if ($fotoResiMasukBaru !== '') {
            $queryLogistik .= ", foto_resi_masuk = '$fotoResiMasukBaru'";
        }

        $queryLogistik .= " WHERE id_pengiriman = '" . (int) $pengirimanTerakhir['id_pengiriman'] . "'";

        if (!mysqli_query($koneksi, $queryLogistik)) {
            throw new Exception("Update logistik penerimaan gagal: " . mysqli_error($koneksi));
        }

        $queryBarang = "
            UPDATE barang SET
                id_status = '$id_status_barang',
                id_branch = '$id_branch_final'
            WHERE id = '$id'
        ";

        if (!mysqli_query($koneksi, $queryBarang)) {
            throw new Exception("Update status barang gagal: " . mysqli_error($koneksi));
        }

        mysqli_commit($koneksi);

        if ($status_penerimaan === 'Sudah diterima') {
            jsonSuccess("Penerimaan barang berhasil disimpan. Barang sudah diterima dan sekarang terkunci total.");
        }

        jsonSuccess("Logistik penerimaan berhasil diupdate.");
    } catch (Throwable $e) {
        mysqli_rollback($koneksi);
        jsonError($e->getMessage());
    }
}

require_permission($koneksi, 'barang.update');

$no_asset      = esc($koneksi, $_POST['no_asset'] ?? '');
$serial        = esc($koneksi, $_POST['serial_number'] ?? '');
$id_merk       = (int) ($_POST['id_merk'] ?? 0);
$id_tipe       = (int) ($_POST['id_tipe'] ?? 0);
$id_jenis      = (int) ($_POST['id_jenis'] ?? 0);
$id_branch     = (int) ($_POST['id_branch'] ?? 0);
$user          = esc($koneksi, $_POST['user'] ?? '');
$bermasalah    = esc($koneksi, $_POST['bermasalah'] ?? 'Tidak');
$ket           = esc($koneksi, $_POST['keterangan_masalah'] ?? '');
$tanggal_masuk = esc($koneksi, $_POST['tanggal_masuk'] ?? '');

$tanggal_keluar      = esc($koneksi, $_POST['tanggal_keluar'] ?? '');
$tujuan              = (isset($_POST['tujuan']) && $_POST['tujuan'] !== '') ? (int) $_POST['tujuan'] : 0;
$jasa_pengiriman     = esc($koneksi, $_POST['jasa_pengiriman'] ?? '');
$nomor_resi          = esc($koneksi, $_POST['nomor_resi'] ?? '');
$estimasi_pengiriman = esc($koneksi, $_POST['estimasi_pengiriman'] ?? '');
$catatan_pengiriman  = esc($koneksi, $_POST['catatan_pengiriman'] ?? '');
$status_pengiriman_baru = trim((string) ($_POST['status_pengiriman_baru'] ?? 'Sedang dikemas'));

$allowedStatusBaru = ['Sedang dikemas', 'Sedang perjalanan'];
if (!in_array($status_pengiriman_baru, $allowedStatusBaru, true)) {
    $status_pengiriman_baru = 'Sedang dikemas';
}

$adaInputPengirimanBaru = (
    $tanggal_keluar !== '' ||
    $tujuan > 0 ||
    $jasa_pengiriman !== '' ||
    $nomor_resi !== '' ||
    $estimasi_pengiriman !== '' ||
    $catatan_pengiriman !== '' ||
    (isset($_FILES['foto_resi']) && !empty($_FILES['foto_resi']['name']))
);

if ($adaInputPengirimanBaru) {
    require_permission($koneksi, 'barang.kirim');
}

if ($no_asset === '' || $serial === '') {
    jsonError("No Asset dan Serial Number wajib diisi.");
}

$cekDuplikat = mysqli_query(
    $koneksi,
    "SELECT id, no_asset, serial_number
     FROM barang
     WHERE (no_asset = '$no_asset' OR serial_number = '$serial')
     AND id != '$id'"
);

if (!$cekDuplikat) {
    jsonError("Gagal cek data duplikat: " . mysqli_error($koneksi));
}

if (mysqli_num_rows($cekDuplikat) > 0) {
    $duplikat = mysqli_fetch_assoc($cekDuplikat);
    $pesan = "Data sudah digunakan oleh barang lain.";

    if (($duplikat['no_asset'] ?? '') === $no_asset && ($duplikat['serial_number'] ?? '') === $serial) {
        $pesan = "No Asset dan Serial Number sudah digunakan oleh data lain.";
    } elseif (($duplikat['no_asset'] ?? '') === $no_asset) {
        $pesan = "No Asset sudah digunakan oleh data lain.";
    } elseif (($duplikat['serial_number'] ?? '') === $serial) {
        $pesan = "Serial Number sudah digunakan oleh data lain.";
    }

    jsonError($pesan);
}

if ($bermasalah === 'Iya' && $adaInputPengirimanBaru) {
    jsonError("Barang bermasalah tidak boleh dikirim.");
}

if ($adaInputPengirimanBaru) {
    if ($tanggal_keluar === '') {
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
$fotoBarangBaru = $uploadFotoBarang['status'] === 'success' ? $uploadFotoBarang['filename'] : '';

$uploadFotoResiKeluar = uploadImage('foto_resi');
if ($uploadFotoResiKeluar['status'] === 'error') {
    jsonError($uploadFotoResiKeluar['message']);
}
$fotoResiKeluarBaru = $uploadFotoResiKeluar['status'] === 'success' ? $uploadFotoResiKeluar['filename'] : '';

$id_status_barang = 4;
$id_branch_final = $id_branch;

if ($bermasalah === 'Iya') {
    $id_status_barang = 5;
} elseif ($adaInputPengirimanBaru) {
    $id_status_barang = 3;
    $id_branch_final = (int) $barang['id_branch'];
}

mysqli_begin_transaction($koneksi);

try {
    $queryBarang = "
        UPDATE barang SET
            no_asset = '$no_asset',
            serial_number = '$serial',
            id_merk = '$id_merk',
            id_tipe = '$id_tipe',
            id_jenis = '$id_jenis',
            id_status = '$id_status_barang',
            id_branch = '$id_branch_final',
            `user` = '$user',
            bermasalah = '$bermasalah',
            keterangan_masalah = " . sqlNullable($ket) . ",
            tanggal_masuk = " . sqlNullable($tanggal_masuk);

    if ($fotoBarangBaru !== '') {
        $queryBarang .= ", foto = '$fotoBarangBaru'";
    }

    $queryBarang .= " WHERE id = '$id'";

    if (!mysqli_query($koneksi, $queryBarang)) {
        throw new Exception("Update barang gagal: " . mysqli_error($koneksi));
    }

    if ($adaInputPengirimanBaru) {
        $branch_asal_transaksi = (int) $barang['id_branch'];

        $queryLogistik = "
            INSERT INTO barang_pengiriman (
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
            ) VALUES (
                '$id',
                '$branch_asal_transaksi',
                '$tujuan',
                " . sqlNullable($tanggal_keluar) . ",
                " . sqlNullable($jasa_pengiriman) . ",
                " . sqlNullable($nomor_resi) . ",
                " . ($fotoResiKeluarBaru !== '' ? "'$fotoResiKeluarBaru'" : "NULL") . ",
                " . sqlNullable($estimasi_pengiriman) . ",
                " . sqlNullable($catatan_pengiriman) . ",
                '$status_pengiriman_baru',
                NULL,
                NULL,
                NULL,
                NULL,
                NULL,
                NULL
            )
        ";

        if (!mysqli_query($koneksi, $queryLogistik)) {
            throw new Exception("Simpan pengiriman baru gagal: " . mysqli_error($koneksi));
        }
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