<?php
include '../config/koneksi.php';
require_once '../config/auth.php';
require_login();

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

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $filename = uniqid($fieldName . "_", true) . "." . $ext;

    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetDir . $filename)) {
        return ["status" => "error", "message" => "Gagal upload file {$fieldName}"];
    }

    return ["status" => "success", "filename" => $filename];
}

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    exit('ID tidak valid.');
}

$stmtBarang = mysqli_prepare($koneksi, "SELECT * FROM barang WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmtBarang, 'i', $id);
mysqli_stmt_execute($stmtBarang);
$resultBarang = mysqli_stmt_get_result($stmtBarang);
$barang = mysqli_fetch_assoc($resultBarang);

if (!$barang) {
    exit('Data barang tidak ditemukan.');
}

$stmtPengiriman = mysqli_prepare($koneksi, "
    SELECT *
    FROM barang_pengiriman
    WHERE id_barang = ?
    ORDER BY id_pengiriman DESC
    LIMIT 1
");
mysqli_stmt_bind_param($stmtPengiriman, 'i', $id);
mysqli_stmt_execute($stmtPengiriman);
$resultPengiriman = mysqli_stmt_get_result($stmtPengiriman);
$pengirimanTerakhir = mysqli_fetch_assoc($resultPengiriman);

$adaPengirimanAktif = !empty($pengirimanTerakhir) && (($pengirimanTerakhir['status_pengiriman'] ?? '') !== 'Sudah diterima');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($adaPengirimanAktif) {
        require_permission($koneksi, 'barang.kirim');
    } else {
        require_permission($koneksi, 'barang.update');
    }

    $data = $barang;

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

    // Kalau ada pengiriman aktif, field logistik diisi dari transaksi aktif.
    // Kalau tidak ada pengiriman aktif, field logistik dikosongkan agar tidak bikin transaksi dobel.
    $pengirimanForm = $adaPengirimanAktif ? $pengirimanTerakhir : null;

    $isLocked = $adaPengirimanAktif;

    $statusPengirimanAktif = !empty($pengirimanForm['status_pengiriman'])
        ? $pengirimanForm['status_pengiriman']
        : 'Belum dikirim';
?>
    <form id="formUpdate" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= (int)$data['id'] ?>">
        <input type="hidden" name="ada_pengiriman_aktif" value="<?= $adaPengirimanAktif ? '1' : '0' ?>">

        <?php if ($isLocked): ?>
            <div class="alert alert-info mb-3">
                Barang sedang dalam proses pengiriman. Tab <b>Data Barang + Status</b> terkunci, tetapi tab <b>Logistik / Pengiriman</b> masih bisa diupdate.
            </div>
        <?php elseif (!empty($pengirimanTerakhir)): ?>
            <div class="alert alert-secondary mb-3">
                Tidak ada pengiriman aktif saat ini. Transaksi terakhir sudah selesai / diterima.
                Jika ingin kirim lagi, isi bagian <b>Pengiriman Keluar</b> untuk membuat transaksi baru.
            </div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabBarang">
                    Data Barang + Status
                </button>
            </li>

            <li class="nav-item">
                <button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tabLogistik">
                    Logistik / Pengiriman
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade show active" id="tabBarang">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label>No Asset</label>
                        <input type="text" name="no_asset" class="form-control" value="<?= h($data['no_asset'] ?? '') ?>" <?= $isLocked ? 'readonly' : '' ?> required>
                    </div>

                    <div class="col-md-6">
                        <label>Serial Number</label>
                        <input type="text" name="serial_number" class="form-control" value="<?= h($data['serial_number'] ?? '') ?>" <?= $isLocked ? 'readonly' : '' ?> required>
                    </div>

                    <div class="col-md-6">
                        <label>Merk</label>
                        <select name="id_merk" class="form-control select2" <?= $isLocked ? 'disabled' : '' ?>>
                            <?php while ($m = mysqli_fetch_assoc($merk)) { ?>
                                <option value="<?= $m['id_merk'] ?>" <?= $m['id_merk'] == $data['id_merk'] ? 'selected' : '' ?>>
                                    <?= h($m['nama_merk']) ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label>Tipe</label>
                        <select name="id_tipe" class="form-control select2" <?= $isLocked ? 'disabled' : '' ?>>
                            <?php while ($t = mysqli_fetch_assoc($tipe)) { ?>
                                <option value="<?= $t['id_tipe'] ?>" <?= $t['id_tipe'] == $data['id_tipe'] ? 'selected' : '' ?>>
                                    <?= h($t['nama_tipe']) ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label>Jenis</label>
                        <select name="id_jenis" class="form-control select2" <?= $isLocked ? 'disabled' : '' ?>>
                            <?php while ($j = mysqli_fetch_assoc($jenis)) { ?>
                                <option value="<?= $j['id_jenis'] ?>" <?= $j['id_jenis'] == $data['id_jenis'] ? 'selected' : '' ?>>
                                    <?= h($j['nama_jenis']) ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label>Branch</label>
                        <select name="id_branch" class="form-control select2" <?= $isLocked ? 'disabled' : '' ?>>
                            <?php while ($b = mysqli_fetch_assoc($branch)) { ?>
                                <option value="<?= $b['id_branch'] ?>" <?= $b['id_branch'] == $data['id_branch'] ? 'selected' : '' ?>>
                                    <?= h($b['nama_branch']) ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label>User</label>
                        <input type="text" name="user" class="form-control" value="<?= h($data['user'] ?? '') ?>" <?= $isLocked ? 'readonly' : '' ?>>
                    </div>

                    <div class="col-md-6">
                        <label>Bermasalah</label>

                        <?php if (($data['bermasalah'] ?? '') === "Iya") { ?>
                            <div class="alert alert-danger mt-2">
                                <i class="bi bi-exclamation-triangle"></i>
                                Barang ini bermasalah dan tidak boleh dikirim.
                            </div>
                        <?php } ?>

                        <select name="bermasalah" class="form-control" <?= $isLocked ? 'disabled' : '' ?>>
                            <option value="Tidak" <?= ($data['bermasalah'] ?? '') === "Tidak" ? 'selected' : '' ?>>Tidak</option>
                            <option value="Iya" <?= ($data['bermasalah'] ?? '') === "Iya" ? 'selected' : '' ?>>Iya</option>
                        </select>
                    </div>

                    <div class="col-md-12">
                        <label>Keterangan Masalah</label>
                        <textarea name="keterangan_masalah" class="form-control" <?= $isLocked ? 'readonly' : '' ?>><?= h($data['keterangan_masalah'] ?? '') ?></textarea>
                    </div>

                    <div class="col-md-6">
                        <label>Foto Barang Baru</label>
                        <input type="file" name="foto" class="form-control" <?= $isLocked ? 'disabled' : '' ?>>
                    </div>

                    <?php if (!empty($data['foto'])): ?>
                        <div class="col-md-6">
                            <label>Foto Barang Saat Ini</label>
                            <div>
                                <button type="button" class="btn btn-outline-secondary previewFoto" data-foto="../assets/images/<?= h($data['foto']) ?>">
                                    <i class="bi bi-image"></i> Lihat Foto Barang
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tab-pane fade" id="tabLogistik">
                <div class="row g-3">
                    <div class="col-12">
                        <h6 class="mb-2">Pengiriman Keluar</h6>
                    </div>

                    <div class="col-md-6">
                        <label>Tanggal Masuk</label>
                        <input type="date" name="tanggal_masuk" class="form-control" value="<?= h($data['tanggal_masuk'] ?? '') ?>" <?= $isLocked ? 'readonly' : '' ?>>
                    </div>

                    <div class="col-md-6">
                        <label>Tanggal Keluar</label>
                        <input type="date" name="tanggal_keluar" class="form-control" value="<?= h($pengirimanForm['tanggal_keluar'] ?? '') ?>" <?= (($data['bermasalah'] ?? '') === "Iya" && !$isLocked) ? 'disabled' : '' ?>>
                    </div>

                    <div class="col-md-6">
                        <label>Tujuan</label>
                        <select name="tujuan" class="form-control select2">
                            <option value="">-- Pilih Tujuan --</option>
                            <?php while ($b = mysqli_fetch_assoc($branch_tujuan)) { ?>
                                <option value="<?= $b['id_branch'] ?>" <?= $b['id_branch'] == ($pengirimanForm['branch_tujuan'] ?? '') ? 'selected' : '' ?>>
                                    <?= h($b['nama_branch']) ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label>Jasa Pengiriman</label>
                        <input type="text" name="jasa_pengiriman" class="form-control" placeholder="Contoh: Internal / JNE / TIKI" value="<?= h($pengirimanForm['jasa_pengiriman'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label>Nomor Resi Keluar</label>
                        <input type="text" name="nomor_resi" class="form-control" placeholder="Masukkan nomor resi keluar" value="<?= h($pengirimanForm['nomor_resi_keluar'] ?? '') ?>">
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
                        <input type="text" name="estimasi_pengiriman" class="form-control" placeholder="Contoh: 2-3 hari / Tiba besok" value="<?= h($pengirimanForm['estimasi_pengiriman'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label>Foto Resi Keluar / Bukti Kirim</label>
                        <input type="file" name="foto_resi" class="form-control">
                    </div>

                    <?php if (!empty($pengirimanForm['foto_resi_keluar'])): ?>
                        <div class="col-md-6">
                            <label>Foto Resi Keluar Saat Ini</label>
                            <div>
                                <button type="button" class="btn btn-outline-secondary previewFoto" data-foto="../assets/images/<?= h($pengirimanForm['foto_resi_keluar']) ?>">
                                    <i class="bi bi-image"></i> Lihat Foto Resi Keluar
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="col-md-12">
                        <label>Catatan Pengiriman Keluar</label>
                        <textarea name="catatan_pengiriman" class="form-control" rows="3" placeholder="Catatan tambahan pengiriman keluar"><?= h($pengirimanForm['catatan_pengiriman_keluar'] ?? '') ?></textarea>
                    </div>

                    <div class="col-12 mt-3">
                        <hr>
                        <h6 class="mb-2">Penerimaan Barang</h6>
                    </div>

                    <div class="col-md-6">
                        <label>Tanggal Diterima</label>
                        <input type="date" name="tanggal_diterima" class="form-control" value="<?= h($pengirimanForm['tanggal_diterima'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label>Nama Penerima</label>
                        <input type="text" name="nama_penerima" class="form-control" placeholder="Nama penerima barang" value="<?= h($pengirimanForm['nama_penerima'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label>Nomor Resi Masuk</label>
                        <input type="text" name="nomor_resi_masuk" class="form-control" placeholder="Masukkan nomor resi masuk" value="<?= h($pengirimanForm['nomor_resi_masuk'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label>Foto Resi Masuk / Bukti Terima</label>
                        <input type="file" name="foto_resi_masuk" class="form-control">
                    </div>

                    <?php if (!empty($pengirimanForm['foto_resi_masuk'])): ?>
                        <div class="col-md-6">
                            <label>Foto Resi Masuk Saat Ini</label>
                            <div>
                                <button type="button" class="btn btn-outline-secondary previewFoto" data-foto="../assets/images/<?= h($pengirimanForm['foto_resi_masuk']) ?>">
                                    <i class="bi bi-image"></i> Lihat Foto Resi Masuk
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="col-md-12">
                        <label>Catatan Penerimaan</label>
                        <textarea name="catatan_penerimaan" class="form-control" rows="3" placeholder="Catatan penerimaan barang"><?= h($pengirimanForm['catatan_penerimaan'] ?? '') ?></textarea>
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

// Ambil ulang data barang dan transaksi terakhir untuk POST
$dataLamaQuery = mysqli_query($koneksi, "SELECT * FROM barang WHERE id='$id'");
if (!$dataLamaQuery || mysqli_num_rows($dataLamaQuery) === 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Data barang tidak ditemukan."
    ]);
    exit;
}
$lama = mysqli_fetch_assoc($dataLamaQuery);

$queryPengirimanTerakhirPost = mysqli_query($koneksi, "
    SELECT *
    FROM barang_pengiriman
    WHERE id_barang = '$id'
    ORDER BY id_pengiriman DESC
    LIMIT 1
");

$pengirimanTerakhirPost = null;
if ($queryPengirimanTerakhirPost && mysqli_num_rows($queryPengirimanTerakhirPost) > 0) {
    $pengirimanTerakhirPost = mysqli_fetch_assoc($queryPengirimanTerakhirPost);
}

$adaPengirimanAktif = !empty($pengirimanTerakhirPost) && (($pengirimanTerakhirPost['status_pengiriman'] ?? '') !== 'Sudah diterima');

// Ambil input master barang
if ($adaPengirimanAktif) {
    // saat ada pengiriman aktif, master dikunci
    require_permission($koneksi, 'barang.kirim');

    $no_asset      = esc($koneksi, $lama['no_asset']);
    $serial        = esc($koneksi, $lama['serial_number']);
    $id_merk       = (int)$lama['id_merk'];
    $id_tipe       = (int)$lama['id_tipe'];
    $id_jenis      = (int)$lama['id_jenis'];
    $id_branch     = (int)$lama['id_branch'];
    $user          = esc($koneksi, $lama['user']);
    $bermasalah    = esc($koneksi, $lama['bermasalah']);
    $ket           = esc($koneksi, $lama['keterangan_masalah']);
    $tanggal_masuk = esc($koneksi, $lama['tanggal_masuk']);
} else {
    $no_asset      = esc($koneksi, $_POST['no_asset'] ?? '');
    $serial        = esc($koneksi, $_POST['serial_number'] ?? '');
    $id_merk       = isset($_POST['id_merk']) ? (int)$_POST['id_merk'] : 0;
    $id_tipe       = isset($_POST['id_tipe']) ? (int)$_POST['id_tipe'] : 0;
    $id_jenis      = isset($_POST['id_jenis']) ? (int)$_POST['id_jenis'] : 0;
    $id_branch     = isset($_POST['id_branch']) ? (int)$_POST['id_branch'] : 0;
    $user          = esc($koneksi, $_POST['user'] ?? '');
    $bermasalah    = esc($koneksi, $_POST['bermasalah'] ?? 'Tidak');
    $ket           = esc($koneksi, $_POST['keterangan_masalah'] ?? '');
    $tanggal_masuk = esc($koneksi, $_POST['tanggal_masuk'] ?? '');

    $rawTanggalKeluarCheck  = trim((string)($_POST['tanggal_keluar'] ?? ''));
    $rawTujuanCheck         = trim((string)($_POST['tujuan'] ?? ''));
    $rawJasaCheck           = trim((string)($_POST['jasa_pengiriman'] ?? ''));
    $rawNomorResiCheck      = trim((string)($_POST['nomor_resi'] ?? ''));
    $rawEstimasiCheck       = trim((string)($_POST['estimasi_pengiriman'] ?? ''));
    $rawCatatanKirimCheck   = trim((string)($_POST['catatan_pengiriman'] ?? ''));
    $rawStatusCheck         = trim((string)($_POST['status_pengiriman'] ?? 'Belum dikirim'));

    $adaAksiLogistikBaru = (
        $rawTanggalKeluarCheck !== '' ||
        $rawTujuanCheck !== '' ||
        $rawJasaCheck !== '' ||
        $rawNomorResiCheck !== '' ||
        $rawEstimasiCheck !== '' ||
        $rawCatatanKirimCheck !== '' ||
        $rawStatusCheck !== 'Belum dikirim' ||
        (isset($_FILES['foto_resi']) && !empty($_FILES['foto_resi']['name']))
    );

    if ($adaAksiLogistikBaru) {
        require_permission($koneksi, 'barang.kirim');
    } else {
        require_permission($koneksi, 'barang.update');
    }

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

// Input logistik
$tanggal_keluar      = esc($koneksi, $_POST['tanggal_keluar'] ?? '');
$tujuan              = (isset($_POST['tujuan']) && $_POST['tujuan'] !== '') ? (int)$_POST['tujuan'] : 0;
$jasa_pengiriman     = esc($koneksi, $_POST['jasa_pengiriman'] ?? '');
$nomor_resi          = esc($koneksi, $_POST['nomor_resi'] ?? '');
$estimasi_pengiriman = esc($koneksi, $_POST['estimasi_pengiriman'] ?? '');
$catatan_pengiriman  = esc($koneksi, $_POST['catatan_pengiriman'] ?? '');

$tanggal_diterima    = esc($koneksi, $_POST['tanggal_diterima'] ?? '');
$nama_penerima       = esc($koneksi, $_POST['nama_penerima'] ?? '');
$nomor_resi_masuk    = esc($koneksi, $_POST['nomor_resi_masuk'] ?? '');
$catatan_penerimaan  = esc($koneksi, $_POST['catatan_penerimaan'] ?? '');

$allowedStatus = ['Belum dikirim', 'Sedang dikemas', 'Sedang perjalanan', 'Sudah diterima'];
$status_pengiriman = trim((string)($_POST['status_pengiriman'] ?? 'Belum dikirim'));

if (!in_array($status_pengiriman, $allowedStatus, true)) {
    $status_pengiriman = $adaPengirimanAktif && !empty($pengirimanTerakhirPost['status_pengiriman'])
        ? $pengirimanTerakhirPost['status_pengiriman']
        : 'Belum dikirim';
}

// Cek ada input pengiriman baru / update pengiriman aktif
$adaInputPengiriman = (
    $tanggal_keluar !== '' ||
    $tujuan > 0 ||
    $jasa_pengiriman !== '' ||
    $nomor_resi !== '' ||
    $estimasi_pengiriman !== '' ||
    $catatan_pengiriman !== '' ||
    $status_pengiriman !== 'Belum dikirim' ||
    (isset($_FILES['foto_resi']) && !empty($_FILES['foto_resi']['name']))
);

// Cek ada input penerimaan
$adaInputPenerimaan = (
    $tanggal_diterima !== '' ||
    $nama_penerima !== '' ||
    $nomor_resi_masuk !== '' ||
    $catatan_penerimaan !== '' ||
    (isset($_FILES['foto_resi_masuk']) && !empty($_FILES['foto_resi_masuk']['name']))
);

// Kalau ada pengiriman aktif dan field kosong, ambil nilai transaksi aktif sebagai fallback
if ($adaPengirimanAktif) {
    if ($tanggal_keluar === '') {
        $tanggal_keluar = esc($koneksi, $pengirimanTerakhirPost['tanggal_keluar'] ?? '');
    }
    if ($tujuan <= 0) {
        $tujuan = (int)($pengirimanTerakhirPost['branch_tujuan'] ?? 0);
    }
    if ($jasa_pengiriman === '') {
        $jasa_pengiriman = esc($koneksi, $pengirimanTerakhirPost['jasa_pengiriman'] ?? '');
    }
    if ($nomor_resi === '') {
        $nomor_resi = esc($koneksi, $pengirimanTerakhirPost['nomor_resi_keluar'] ?? '');
    }
    if ($estimasi_pengiriman === '') {
        $estimasi_pengiriman = esc($koneksi, $pengirimanTerakhirPost['estimasi_pengiriman'] ?? '');
    }
    if ($catatan_pengiriman === '') {
        $catatan_pengiriman = esc($koneksi, $pengirimanTerakhirPost['catatan_pengiriman_keluar'] ?? '');
    }
    if ($tanggal_diterima === '') {
        $tanggal_diterima = esc($koneksi, $pengirimanTerakhirPost['tanggal_diterima'] ?? '');
    }
    if ($nama_penerima === '') {
        $nama_penerima = esc($koneksi, $pengirimanTerakhirPost['nama_penerima'] ?? '');
    }
    if ($nomor_resi_masuk === '') {
        $nomor_resi_masuk = esc($koneksi, $pengirimanTerakhirPost['nomor_resi_masuk'] ?? '');
    }
    if ($catatan_penerimaan === '') {
        $catatan_penerimaan = esc($koneksi, $pengirimanTerakhirPost['catatan_penerimaan'] ?? '');
    }
}

$membuatPengirimanBaru = (!$adaPengirimanAktif && $adaInputPengiriman);

// Validasi
if ($bermasalah === "Iya" && ($adaPengirimanAktif || $membuatPengirimanBaru)) {
    echo json_encode([
        "status" => "error",
        "message" => "Barang bermasalah tidak boleh dikirim."
    ]);
    exit;
}

if ($membuatPengirimanBaru && $tanggal_keluar === '') {
    echo json_encode([
        "status" => "error",
        "message" => "Tanggal keluar wajib diisi saat membuat pengiriman baru."
    ]);
    exit;
}

if (($adaPengirimanAktif || $membuatPengirimanBaru) && $tujuan <= 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Tujuan wajib dipilih saat barang dikirim."
    ]);
    exit;
}

if ($adaInputPenerimaan && !$adaPengirimanAktif) {
    echo json_encode([
        "status" => "error",
        "message" => "Tidak ada pengiriman aktif yang bisa dikonfirmasi penerimaannya."
    ]);
    exit;
}

if ($status_pengiriman === 'Sudah diterima') {
    if ($tanggal_diterima === '') {
        echo json_encode([
            "status" => "error",
            "message" => "Tanggal diterima wajib diisi jika status pengiriman sudah diterima."
        ]);
        exit;
    }

    if ($nama_penerima === '') {
        echo json_encode([
            "status" => "error",
            "message" => "Nama penerima wajib diisi jika status pengiriman sudah diterima."
        ]);
        exit;
    }
}

if (($adaPengirimanAktif || $membuatPengirimanBaru) && $status_pengiriman === 'Belum dikirim') {
    $status_pengiriman = 'Sedang dikemas';
}

// Status master barang
$id_status = 4;
if ($bermasalah === "Iya") {
    $id_status = 5;
} elseif (($adaPengirimanAktif || $membuatPengirimanBaru) && $status_pengiriman !== 'Sudah diterima') {
    $id_status = 3;
}

// Upload
$fotoBarangBaru = '';
if (!$adaPengirimanAktif) {
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

$fotoResiKeluarBaru = '';
$uploadFotoResiKeluar = uploadImage('foto_resi');
if ($uploadFotoResiKeluar['status'] === 'error') {
    echo json_encode([
        "status" => "error",
        "message" => $uploadFotoResiKeluar['message']
    ]);
    exit;
}
if ($uploadFotoResiKeluar['status'] === 'success') {
    $fotoResiKeluarBaru = $uploadFotoResiKeluar['filename'];
}

$fotoResiMasukBaru = '';
$uploadFotoResiMasuk = uploadImage('foto_resi_masuk');
if ($uploadFotoResiMasuk['status'] === 'error') {
    echo json_encode([
        "status" => "error",
        "message" => $uploadFotoResiMasuk['message']
    ]);
    exit;
}
if ($uploadFotoResiMasuk['status'] === 'success') {
    $fotoResiMasukBaru = $uploadFotoResiMasuk['filename'];
}

// Branch asal transaksi
if ($adaPengirimanAktif) {
    $branch_asal_transaksi = (int)($pengirimanTerakhirPost['branch_asal'] ?? $lama['id_branch']);
} else {
    $branch_asal_transaksi = (int)$lama['id_branch'];
}

// Branch aktif barang
if ($status_pengiriman === 'Sudah diterima' && $tujuan > 0) {
    $id_branch_final = $tujuan;
} elseif ($adaPengirimanAktif || $membuatPengirimanBaru) {
    $id_branch_final = (int)$lama['id_branch'];
} else {
    $id_branch_final = $id_branch;
}

mysqli_begin_transaction($koneksi);

try {
    $queryBarangUpdate = "
        UPDATE barang SET
            no_asset = '$no_asset',
            serial_number = '$serial',
            id_merk = '$id_merk',
            id_tipe = '$id_tipe',
            id_jenis = '$id_jenis',
            id_status = '$id_status',
            id_branch = '$id_branch_final',
            `user` = '$user',
            bermasalah = '$bermasalah',
            keterangan_masalah = " . sqlNullable($ket) . ",
            tanggal_masuk = " . sqlNullable($tanggal_masuk);

    if ($fotoBarangBaru !== '') {
        $queryBarangUpdate .= ", foto = '$fotoBarangBaru'";
    }

    $queryBarangUpdate .= " WHERE id = '$id'";

    if (!mysqli_query($koneksi, $queryBarangUpdate)) {
        throw new Exception("Update barang gagal: " . mysqli_error($koneksi));
    }

    if ($adaPengirimanAktif && !empty($pengirimanTerakhirPost['id_pengiriman'])) {
        $idPengiriman = (int)$pengirimanTerakhirPost['id_pengiriman'];

        $queryLogistik = "
            UPDATE barang_pengiriman SET
                branch_asal = '$branch_asal_transaksi',
                branch_tujuan = '$tujuan',
                tanggal_keluar = " . sqlNullable($tanggal_keluar) . ",
                jasa_pengiriman = " . sqlNullable($jasa_pengiriman) . ",
                nomor_resi_keluar = " . sqlNullable($nomor_resi) . ",
                estimasi_pengiriman = " . sqlNullable($estimasi_pengiriman) . ",
                catatan_pengiriman_keluar = " . sqlNullable($catatan_pengiriman) . ",
                status_pengiriman = '$status_pengiriman',
                tanggal_diterima = " . sqlNullable($tanggal_diterima) . ",
                nama_penerima = " . sqlNullable($nama_penerima) . ",
                nomor_resi_masuk = " . sqlNullable($nomor_resi_masuk) . ",
                catatan_penerimaan = " . sqlNullable($catatan_penerimaan);

        if ($fotoResiKeluarBaru !== '') {
            $queryLogistik .= ", foto_resi_keluar = '$fotoResiKeluarBaru'";
        }

        if ($fotoResiMasukBaru !== '') {
            $queryLogistik .= ", foto_resi_masuk = '$fotoResiMasukBaru'";
        }

        $queryLogistik .= " WHERE id_pengiriman = '$idPengiriman'";

        if (!mysqli_query($koneksi, $queryLogistik)) {
            throw new Exception("Update logistik gagal: " . mysqli_error($koneksi));
        }
    } elseif ($membuatPengirimanBaru) {
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
                '$status_pengiriman',
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

    $message = "Data barang berhasil diupdate.";
    if ($adaPengirimanAktif) {
        $message = "Logistik / pengiriman berhasil diupdate.";
    } elseif ($membuatPengirimanBaru) {
        $message = "Data barang berhasil diupdate dan pengiriman baru berhasil dibuat.";
    }

    echo json_encode([
        "status" => "success",
        "message" => $message
    ]);
} catch (Exception $e) {
    mysqli_rollback($koneksi);

    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}

exit;