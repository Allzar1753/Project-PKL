<?php
include '../config/koneksi.php';
include '../config/koneksi.php';
include '../config/auth.php';
require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = mysqli_prepare($koneksi, "SELECT tanggal_keluar FROM barang WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$barang = mysqli_fetch_assoc($result);

if (!$barang) {
    exit('Data barang tidak ditemukan.');
}

if (!empty($barang['tanggal_keluar'])) {
    require_permission($koneksi, 'barang.kirim');
} else {
    require_permission($koneksi, 'barang.update');
}


function esc($koneksi, $value)
{
    return mysqli_real_escape_string($koneksi, trim((string)$value));
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sqlNullable($value)
{
    return $value !== '' ? "'$value'" : "NULL";
}

function uploadImage($fieldName, $targetDir = "../assets/images/")
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

    $filename = uniqid($fieldName . "_") . "." . $ext;

    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetDir . $filename)) {
        return ["status" => "error", "message" => "Gagal upload file {$fieldName}"];
    }

    return ["status" => "success", "filename" => $filename];
}

$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$mode = $_GET['mode'] ?? 'full';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $dataQuery = mysqli_query($koneksi, "SELECT * FROM barang WHERE id='$id'");

    if (!$dataQuery || mysqli_num_rows($dataQuery) === 0) {
        echo "<div class='alert alert-danger'>Data tidak ditemukan.</div>";
        exit;
    }

    $data = mysqli_fetch_assoc($dataQuery);
    $isLocked = !empty($data['tanggal_keluar']);

    $merk   = mysqli_query($koneksi, "SELECT * FROM tb_merk");
    $tipe   = mysqli_query($koneksi, "SELECT * FROM tb_tipe");
    $jenis  = mysqli_query($koneksi, "SELECT * FROM tb_jenis");
    $branch = mysqli_query($koneksi, "SELECT * FROM tb_branch");
    $branch_tujuan = mysqli_query($koneksi, "SELECT * FROM tb_branch");

    $opsiStatusPengiriman = [
        'Belum dikirim',
        'Sedang dikemas',
        'Sedang perjalanan',
        'Sudah diterima'
    ];

    $statusPengirimanAktif = !empty($data['status_pengiriman'])
        ? $data['status_pengiriman']
        : 'Belum dikirim';
?>

    <form id="formUpdate" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= (int)$data['id'] ?>">

        <?php if ($isLocked): ?>
            <div class="alert alert-info mb-3">
                Data barang sudah keluar. Tab <b>Data Barang + Status</b> terkunci, tetapi tab <b>Logistik / Pengiriman</b> masih bisa diupdate.
            </div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <button type="button"
                    class="nav-link active"
                    data-bs-toggle="tab"
                    data-bs-target="#tabBarang">
                    Data Barang + Status
                </button>
            </li>

            <li class="nav-item">
                <button type="button"
                    class="nav-link"
                    data-bs-toggle="tab"
                    data-bs-target="#tabLogistik">
                    Logistik / Pengiriman
                </button>
            </li>
        </ul>

        <div class="tab-content">

            <div class="tab-pane fade show active" id="tabBarang">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label>No Asset</label>
                        <input type="text"
                            name="no_asset"
                            class="form-control"
                            value="<?= h($data['no_asset'] ?? '') ?>"
                            <?= $isLocked ? 'readonly' : '' ?>
                            required>
                    </div>

                    <div class="col-md-6">
                        <label>Serial Number</label>
                        <input type="text"
                            name="serial_number"
                            class="form-control"
                            value="<?= h($data['serial_number'] ?? '') ?>"
                            <?= $isLocked ? 'readonly' : '' ?>
                            required>
                    </div>

                    <div class="col-md-6">
                        <label>Merk</label>
                        <select name="id_merk" class="form-control select2" <?= $isLocked ? 'disabled' : '' ?>>
                            <?php while ($m = mysqli_fetch_assoc($merk)) { ?>
                                <option value="<?= $m['id_merk'] ?>"
                                    <?= $m['id_merk'] == $data['id_merk'] ? 'selected' : '' ?>>
                                    <?= h($m['nama_merk']) ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label>Tipe</label>
                        <select name="id_tipe" class="form-control select2" <?= $isLocked ? 'disabled' : '' ?>>
                            <?php while ($t = mysqli_fetch_assoc($tipe)) { ?>
                                <option value="<?= $t['id_tipe'] ?>"
                                    <?= $t['id_tipe'] == $data['id_tipe'] ? 'selected' : '' ?>>
                                    <?= h($t['nama_tipe']) ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label>Jenis</label>
                        <select name="id_jenis" class="form-control select2" <?= $isLocked ? 'disabled' : '' ?>>
                            <?php while ($j = mysqli_fetch_assoc($jenis)) { ?>
                                <option value="<?= $j['id_jenis'] ?>"
                                    <?= $j['id_jenis'] == $data['id_jenis'] ? 'selected' : '' ?>>
                                    <?= h($j['nama_jenis']) ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label>Branch</label>
                        <select name="id_branch" class="form-control select2" <?= $isLocked ? 'disabled' : '' ?>>
                            <?php while ($b = mysqli_fetch_assoc($branch)) { ?>
                                <option value="<?= $b['id_branch'] ?>"
                                    <?= $b['id_branch'] == $data['id_branch'] ? 'selected' : '' ?>>
                                    <?= h($b['nama_branch']) ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label>User</label>
                        <input type="text"
                            name="user"
                            class="form-control"
                            value="<?= h($data['user'] ?? '') ?>"
                            <?= $isLocked ? 'readonly' : '' ?>>
                    </div>

                    <div class="col-md-6">
                        <label>Bermasalah</label>

                        <?php if (($data['bermasalah'] ?? '') == "Iya") { ?>
                            <div class="alert alert-danger mt-2">
                                <i class="bi bi-exclamation-triangle"></i>
                                Barang ini bermasalah dan tidak boleh dikirim
                            </div>
                        <?php } ?>

                        <select name="bermasalah" class="form-control" <?= $isLocked ? 'disabled' : '' ?>>
                            <option value="Tidak" <?= ($data['bermasalah'] ?? '') == "Tidak" ? 'selected' : '' ?>>Tidak</option>
                            <option value="Iya" <?= ($data['bermasalah'] ?? '') == "Iya" ? 'selected' : '' ?>>Iya</option>
                        </select>
                    </div>

                    <div class="col-md-12">
                        <label>Keterangan Masalah</label>
                        <textarea name="keterangan_masalah"
                            class="form-control"
                            <?= $isLocked ? 'readonly' : '' ?>><?= h($data['keterangan_masalah'] ?? '') ?></textarea>
                    </div>

                    <div class="col-md-6">
                        <label>Foto Barang Baru</label>
                        <input type="file"
                            name="foto"
                            class="form-control"
                            <?= $isLocked ? 'disabled' : '' ?>>
                    </div>

                    <?php if (!empty($data['foto'])): ?>
                        <div class="col-md-6">
                            <label>Foto Barang Saat Ini</label>
                            <div>
                                <button type="button"
                                    class="btn btn-outline-secondary previewFoto"
                                    data-foto="../assets/images/<?= h($data['foto']) ?>">
                                    <i class="bi bi-image"></i> Lihat Foto Barang
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

            <div class="tab-pane fade" id="tabLogistik">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label>Tanggal Masuk</label>
                        <input type="date"
                            name="tanggal_masuk"
                            class="form-control"
                            value="<?= h($data['tanggal_masuk'] ?? '') ?>"
                            <?= $isLocked ? 'readonly' : '' ?>>
                    </div>

                    <div class="col-md-6">
                        <label>Tanggal Keluar</label>
                        <input type="date"
                            name="tanggal_keluar"
                            class="form-control"
                            value="<?= h($data['tanggal_keluar'] ?? '') ?>"
                            <?= (($data['bermasalah'] ?? '') == "Iya" && !$isLocked) ? 'disabled' : '' ?>>
                    </div>

                    <div class="col-md-6">
                        <label>Tujuan</label>
                        <select name="tujuan" class="form-control select2">
                            <option value="">-- Pilih Tujuan --</option>
                            <?php while ($b = mysqli_fetch_assoc($branch_tujuan)) { ?>
                                <option value="<?= $b['id_branch'] ?>"
                                    <?= $b['id_branch'] == ($data['tujuan'] ?? '') ? 'selected' : '' ?>>
                                    <?= h($b['nama_branch']) ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label>Jasa Pengiriman</label>
                        <input type="text"
                            name="jasa_pengiriman"
                            class="form-control"
                            placeholder="Contoh: Internal / JNE / TIKI"
                            value="<?= h($data['jasa_pengiriman'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label>Nomor Resi</label>
                        <input type="text"
                            name="nomor_resi"
                            class="form-control"
                            placeholder="Masukkan nomor resi"
                            value="<?= h($data['nomor_resi'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label>Status Pengiriman</label>
                        <select name="status_pengiriman" class="form-control">
                            <?php foreach ($opsiStatusPengiriman as $statusItem): ?>
                                <option value="<?= h($statusItem) ?>" <?= $statusPengirimanAktif == $statusItem ? 'selected' : '' ?>>
                                    <?= h($statusItem) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label>Estimasi Pengiriman</label>
                        <input type="text"
                            name="estimasi_pengiriman"
                            class="form-control"
                            placeholder="Contoh: 2-3 hari / Tiba besok"
                            value="<?= h($data['estimasi_pengiriman'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label>Foto Resi / Bukti Kirim</label>
                        <input type="file"
                            name="foto_resi"
                            class="form-control">
                    </div>

                    <?php if (!empty($data['foto_resi'])): ?>
                        <div class="col-md-6">
                            <label>Foto Resi Saat Ini</label>
                            <div>
                                <button type="button"
                                    class="btn btn-outline-secondary previewFoto"
                                    data-foto="../assets/images/<?= h($data['foto_resi']) ?>">
                                    <i class="bi bi-image"></i> Lihat Foto Resi
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="col-md-6">
                        <label>Tanggal Diterima</label>
                        <input type="date"
                            name="tanggal_diterima"
                            class="form-control"
                            value="<?= h($data['tanggal_diterima'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label>Nama Penerima</label>
                        <input type="text"
                            name="nama_penerima"
                            class="form-control"
                            placeholder="Nama penerima barang"
                            value="<?= h($data['nama_penerima'] ?? '') ?>">
                    </div>

                    <div class="col-md-12">
                        <label>Catatan Pengiriman</label>
                        <textarea name="catatan_pengiriman"
                            class="form-control"
                            rows="3"
                            placeholder="Catatan tambahan pengiriman"><?= h($data['catatan_pengiriman'] ?? '') ?></textarea>
                    </div>

                </div>
            </div>
        </div>

        <div class="mt-4 text-end">
            <button type="submit" class="btn btn-warning">
                <?= $isLocked ? 'Update Logistik' : 'Update Data' ?>
            </button>
        </div>
    </form>

<?php
    exit;
}

header('Content-Type: application/json');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    echo json_encode([
        "status" => "error",
        "message" => "ID data tidak valid."
    ]);
    exit;
}

$dataLamaQuery = mysqli_query($koneksi, "SELECT * FROM barang WHERE id='$id'");

if (!$dataLamaQuery || mysqli_num_rows($dataLamaQuery) === 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Data barang tidak ditemukan."
    ]);
    exit;
}

$lama = mysqli_fetch_assoc($dataLamaQuery);
$sudahKeluar = !empty($lama['tanggal_keluar']);

if ($sudahKeluar) {
    $no_asset   = esc($koneksi, $lama['no_asset']);
    $serial     = esc($koneksi, $lama['serial_number']);
    $id_merk    = (int)$lama['id_merk'];
    $id_tipe    = (int)$lama['id_tipe'];
    $id_jenis   = (int)$lama['id_jenis'];
    $id_branch  = (int)$lama['id_branch'];
    $user       = esc($koneksi, $lama['user']);
    $bermasalah = esc($koneksi, $lama['bermasalah']);
    $ket        = esc($koneksi, $lama['keterangan_masalah']);
    $tanggal_masuk = esc($koneksi, $lama['tanggal_masuk']);
} else {
    $no_asset  = esc($koneksi, $_POST['no_asset'] ?? '');
    $serial    = esc($koneksi, $_POST['serial_number'] ?? '');
    $id_merk   = isset($_POST['id_merk']) ? (int)$_POST['id_merk'] : 0;
    $id_tipe   = isset($_POST['id_tipe']) ? (int)$_POST['id_tipe'] : 0;
    $id_jenis  = isset($_POST['id_jenis']) ? (int)$_POST['id_jenis'] : 0;
    $id_branch = isset($_POST['id_branch']) ? (int)$_POST['id_branch'] : 0;
    $user      = esc($koneksi, $_POST['user'] ?? '');
    $bermasalah = esc($koneksi, $_POST['bermasalah'] ?? 'Tidak');
    $ket        = esc($koneksi, $_POST['keterangan_masalah'] ?? '');
    $tanggal_masuk = esc($koneksi, $_POST['tanggal_masuk'] ?? '');

    if ($no_asset === '' || $serial === '') {
        echo json_encode([
            "status" => "error",
            "message" => "No Asset dan Serial Number wajib diisi."
        ]);
        exit;
    }

    $cekDuplikat = mysqli_query($koneksi, "
        SELECT id, no_asset, serial_number
        FROM barang
        WHERE (no_asset = '$no_asset' OR serial_number = '$serial')
        AND id != $id
    ");

    if (!$cekDuplikat) {
        echo json_encode([
            "status" => "error",
            "message" => "Gagal cek data duplikat: " . mysqli_error($koneksi)
        ]);
        exit;
    }

    if (mysqli_num_rows($cekDuplikat) > 0) {
        $duplikat = mysqli_fetch_assoc($cekDuplikat);

        $pesan = "Data sudah digunakan oleh barang lain.";

        if ($duplikat['no_asset'] === $no_asset && $duplikat['serial_number'] === $serial) {
            $pesan = "No Asset dan Serial Number sudah digunakan oleh data lain.";
        } elseif ($duplikat['no_asset'] === $no_asset) {
            $pesan = "No Asset sudah digunakan oleh data lain.";
        } elseif ($duplikat['serial_number'] === $serial) {
            $pesan = "Serial Number sudah digunakan oleh data lain.";
        }

        echo json_encode([
            "status" => "error",
            "message" => $pesan
        ]);
        exit;
    }
}

$tanggal_keluar = esc($koneksi, $_POST['tanggal_keluar'] ?? '');

if ($sudahKeluar && $tanggal_keluar === '') {
    $tanggal_keluar = esc($koneksi, $lama['tanggal_keluar']);
}

$tujuan = (isset($_POST['tujuan']) && $_POST['tujuan'] !== '') ? (int)$_POST['tujuan'] : 0;
$jasa_pengiriman = esc($koneksi, $_POST['jasa_pengiriman'] ?? '');
$nomor_resi = esc($koneksi, $_POST['nomor_resi'] ?? '');
$estimasi_pengiriman = esc($koneksi, $_POST['estimasi_pengiriman'] ?? '');
$tanggal_diterima = esc($koneksi, $_POST['tanggal_diterima'] ?? '');
$nama_penerima = esc($koneksi, $_POST['nama_penerima'] ?? '');
$catatan_pengiriman = esc($koneksi, $_POST['catatan_pengiriman'] ?? '');

$allowedStatus = ['Belum dikirim', 'Sedang dikemas', 'Sedang perjalanan', 'Sudah diterima'];
$status_pengiriman = trim((string)($_POST['status_pengiriman'] ?? ''));

if (!in_array($status_pengiriman, $allowedStatus, true)) {
    $status_pengiriman = !empty($lama['status_pengiriman']) ? $lama['status_pengiriman'] : 'Belum dikirim';
}

if ($bermasalah === "Iya" && $tanggal_keluar !== '') {
    echo json_encode([
        "status" => "error",
        "message" => "Barang bermasalah tidak boleh dikirim."
    ]);
    exit;
}

if (
    $tanggal_keluar === '' &&
    (
        $tujuan > 0 ||
        $jasa_pengiriman !== '' ||
        $nomor_resi !== '' ||
        $estimasi_pengiriman !== '' ||
        $tanggal_diterima !== '' ||
        $nama_penerima !== '' ||
        $catatan_pengiriman !== '' ||
        $status_pengiriman !== 'Belum dikirim' ||
        (isset($_FILES['foto_resi']) && !empty($_FILES['foto_resi']['name']))
    )
) {
    echo json_encode([
        "status" => "error",
        "message" => "Isi tanggal keluar terlebih dahulu sebelum mengisi data pengiriman."
    ]);
    exit;
}

if ($tanggal_keluar !== '' && $tujuan <= 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Tujuan wajib dipilih saat barang dikirim."
    ]);
    exit;
}

if ($tanggal_keluar === '') {
    $status_pengiriman = 'Belum dikirim';
    $jasa_pengiriman = '';
    $nomor_resi = '';
    $estimasi_pengiriman = '';
    $tanggal_diterima = '';
    $nama_penerima = '';
    $catatan_pengiriman = '';
    $tujuan = 0;
}

if ($tanggal_keluar !== '' && $status_pengiriman === 'Belum dikirim') {
    $status_pengiriman = 'Sedang dikemas';
}

if ($status_pengiriman === 'Sudah diterima' && $tanggal_diterima === '') {
    echo json_encode([
        "status" => "error",
        "message" => "Tanggal diterima wajib diisi jika status pengiriman sudah diterima."
    ]);
    exit;
}

if ($bermasalah === "Iya") {
    $id_status = 5;
} elseif ($tanggal_keluar !== '') {
    $id_status = 3;
} else {
    $id_status = 4;
}

$fotoBarangBaru = '';
if (!$sudahKeluar) {
    $uploadFotoBarang = uploadImage('foto');

    if ($uploadFotoBarang['status'] === 'error') {
        echo json_encode([
            "status" => "error",
            "message" => $uploadFotoBarang['message']
        ]);
        exit;
    }

    if ($uploadFotoBarang['status'] === 'success') {
        $fotoBarangBaru = $uploadFotoBarang['filename'];
    }
}

$fotoResiBaru = '';
$uploadFotoResi = uploadImage('foto_resi');

if ($uploadFotoResi['status'] === 'error') {
    echo json_encode([
        "status" => "error",
        "message" => $uploadFotoResi['message']
    ]);
    exit;
}

if ($uploadFotoResi['status'] === 'success') {
    $fotoResiBaru = $uploadFotoResi['filename'];
}

$query = "
    UPDATE barang SET
        no_asset = '$no_asset',
        serial_number = '$serial',
        id_merk = '$id_merk',
        id_tipe = '$id_tipe',
        id_jenis = '$id_jenis',
        id_status = '$id_status',
        id_branch = '$id_branch',
        `user` = '$user',
        bermasalah = '$bermasalah',
        keterangan_masalah = " . sqlNullable($ket) . ",
        tanggal_masuk = " . sqlNullable($tanggal_masuk) . ",
        tanggal_keluar = " . sqlNullable($tanggal_keluar) . ",
        tujuan = " . ($tujuan > 0 ? "'$tujuan'" : "NULL") . ",
        jasa_pengiriman = " . sqlNullable($jasa_pengiriman) . ",
        nomor_resi = " . sqlNullable($nomor_resi) . ",
        status_pengiriman = '$status_pengiriman',
        estimasi_pengiriman = " . sqlNullable($estimasi_pengiriman) . ",
        tanggal_diterima = " . sqlNullable($tanggal_diterima) . ",
        nama_penerima = " . sqlNullable($nama_penerima) . ",
        catatan_pengiriman = " . sqlNullable($catatan_pengiriman);

if ($fotoBarangBaru !== '') {
    $query .= ", foto = '$fotoBarangBaru'";
}

if ($fotoResiBaru !== '') {
    $query .= ", foto_resi = '$fotoResiBaru'";
}

$query .= " WHERE id = '$id'";

$update = mysqli_query($koneksi, $query);

if ($update) {
    echo json_encode([
        "status" => "success",
        "message" => $sudahKeluar
            ? "Logistik / pengiriman berhasil diupdate."
            : "Data barang berhasil diupdate."
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Update gagal: " . mysqli_error($koneksi)
    ]);
}

exit;   