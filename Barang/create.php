<?php
include '../config/koneksi.php';
require_once '../config/auth.php';
require_once '../config/mail.php';

require_admin();

$merk   = mysqli_query($koneksi, "SELECT * FROM tb_merk ORDER BY nama_merk ASC");
$tipe   = mysqli_query($koneksi, "SELECT * FROM tb_tipe ORDER BY nama_tipe ASC");
$jenis  = mysqli_query($koneksi, "SELECT * FROM tb_jenis ORDER BY nama_jenis ASC");
$branch = mysqli_query($koneksi, "SELECT * FROM tb_branch ORDER BY nama_branch ASC");
$barang = mysqli_query($koneksi, "SELECT * FROM tb_barang ORDER BY nama_barang ASC");

function esc(mysqli $koneksi, $value): string
{
    return mysqli_real_escape_string($koneksi, trim((string) $value));
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
        return ['status' => 'error', 'message' => "Gagal mengupload file {$fieldName}"];
    }

    return ['status' => 'success', 'filename' => $filename];
}

function ambilDetailBarangUntukEmail(mysqli $koneksi, int $idBarangBaru): ?array
{
    $query = mysqli_query($koneksi, "
        SELECT
            b.id,
            b.no_asset,
            b.serial_number,
            b.tanggal_kirim,
            b.bermasalah,
            b.keterangan_masalah,
            b.foto,
            b.`user`,
            tb.nama_barang,
            m.nama_merk,
            t.nama_tipe,
            j.nama_jenis,
            br.nama_branch
        FROM barang b
        LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang
        LEFT JOIN tb_merk m ON b.id_merk = m.id_merk
        LEFT JOIN tb_tipe t ON b.id_tipe = t.id_tipe
        LEFT JOIN tb_jenis j ON b.id_jenis = j.id_jenis
        LEFT JOIN tb_branch br ON b.id_branch = br.id_branch
        WHERE b.id = {$idBarangBaru}
        LIMIT 1
    ");

    if (!$query || mysqli_num_rows($query) === 0) {
        return null;
    }

    return mysqli_fetch_assoc($query);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $required = [
        'no_asset',
        'id_barang',
        'id_merk',
        'serial_number',
        'id_tipe',
        'id_jenis',
        'tanggal_kirim',
        'bermasalah',
        'id_branch',
        'user'
    ];

    foreach ($required as $field) {
        if (!isset($_POST[$field]) || trim((string) $_POST[$field]) === '') {
            echo json_encode([
                'status' => 'error',
                'message' => "Field {$field} wajib diisi"
            ]);
            exit;
        }
    }

    $no_asset      = esc($koneksi, $_POST['no_asset']);
    $id_barang     = (int) $_POST['id_barang'];
    $id_merk       = (int) $_POST['id_merk'];
    $serial_number = esc($koneksi, $_POST['serial_number']);
    $id_tipe       = (int) $_POST['id_tipe'];
    $id_jenis      = (int) $_POST['id_jenis'];
    $tanggal_kirim = esc($koneksi, $_POST['tanggal_kirim']);
    $bermasalah    = esc($koneksi, $_POST['bermasalah']);
    $id_branch     = (int) $_POST['id_branch'];
    $user          = esc($koneksi, $_POST['user']);

    $keterangan_masalah = null;

    if ($bermasalah === 'Iya') {
        if (empty($_POST['keterangan_masalah']) || trim((string) $_POST['keterangan_masalah']) === '') {
            echo json_encode([
                'status' => 'error',
                'message' => 'Keterangan masalah wajib diisi jika barang bermasalah'
            ]);
            exit;
        }

        $keterangan_masalah = esc($koneksi, $_POST['keterangan_masalah']);
    }

    // Ambil nama barang yang sedang dipilih
    $getBarangInput = mysqli_query($koneksi, "
    SELECT id_barang, nama_barang
    FROM tb_barang
    WHERE id_barang = $id_barang
    LIMIT 1
");

    if (!$getBarangInput || mysqli_num_rows($getBarangInput) === 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Data barang tidak valid'
        ]);
        exit;
    }

    $dataBarangInput = mysqli_fetch_assoc($getBarangInput);
    $namaBarangInput = strtolower(trim($dataBarangInput['nama_barang']));

    // 1. Serial number tetap unik global
    $cekSerial = mysqli_query($koneksi, "
    SELECT id
    FROM barang
    WHERE serial_number = '$serial_number'
    LIMIT 1
");

    if (!$cekSerial) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Gagal cek serial number: ' . mysqli_error($koneksi)
        ]);
        exit;
    }

    if (mysqli_num_rows($cekSerial) > 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Serial Number sudah terdaftar'
        ]);
        exit;
    }

    // 2. Cek no_asset berdasarkan aturan Monitor + CPU
    $barangPair = ['monitor', 'cpu'];

    $cekNoAsset = mysqli_query($koneksi, "
    SELECT b.id, b.no_asset, tb.nama_barang
    FROM barang b
    INNER JOIN tb_barang tb ON b.id_barang = tb.id_barang
    WHERE b.no_asset = '$no_asset'
");

    if (!$cekNoAsset) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Gagal cek no asset: ' . mysqli_error($koneksi)
        ]);
        exit;
    }

    if (mysqli_num_rows($cekNoAsset) > 0) {
        $barangSudahAda = [];
        $inputAdalahPair = in_array($namaBarangInput, $barangPair, true);

        while ($row = mysqli_fetch_assoc($cekNoAsset)) {
            $namaBarangDb = strtolower(trim($row['nama_barang']));
            $barangSudahAda[] = $namaBarangDb;

            // Kalau barang yang sama sudah ada, langsung tolak
            if ($namaBarangDb === $namaBarangInput) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Barang tersebut sudah tersedia di daftar barang untuk No Asset ini'
                ]);
                exit;
            }

            // Kalau data lama bukan monitor/cpu, maka no asset tidak boleh dipakai lagi
            if (!in_array($namaBarangDb, $barangPair, true)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'No Asset sudah terdaftar'
                ]);
                exit;
            }
        }

        $barangSudahAda = array_unique($barangSudahAda);

        // Kalau barang input bukan monitor/cpu, maka tidak boleh numpang no asset yang sudah ada
        if (!$inputAdalahPair) {
            echo json_encode([
                'status' => 'error',
                'message' => 'No Asset sudah terdaftar'
            ]);
            exit;
        }

        // Kalau sudah ada 2 jenis barang (monitor + cpu), jangan tambah lagi
        if (count($barangSudahAda) >= 2) {
            echo json_encode([
                'status' => 'error',
                'message' => 'No Asset ini sudah dipakai untuk pasangan Monitor dan CPU'
            ]);
            exit;
        }
    }

    $foto = null;

    $uploadFoto = uploadImage('foto');
    if ($uploadFoto['status'] === 'error') {
        echo json_encode([
            'status' => 'error',
            'message' => $uploadFoto['message']
        ]);
        exit;
    }

    $foto = $uploadFoto['status'] === 'success' ? $uploadFoto['filename'] : null;
    $id_status = $bermasalah === 'Iya' ? 5 : 4;

    $queryBarang = "
        INSERT INTO barang (
            no_asset,
            id_barang,
            id_merk,
            serial_number,
            id_tipe,
            id_jenis,
            tanggal_kirim,
            bermasalah,
            keterangan_masalah,
            id_status,
            id_branch,
            foto,
            `user`
        ) VALUES (
            '$no_asset',
            '$id_barang',
            '$id_merk',
            '$serial_number',
            '$id_tipe',
            '$id_jenis',
            '$tanggal_kirim',
            '$bermasalah',
            " . ($keterangan_masalah !== null ? "'$keterangan_masalah'" : "NULL") . ",
            '$id_status',
            '$id_branch',
            " . ($foto !== null ? "'$foto'" : "NULL") . ",
            '$user'
        )
    ";

    mysqli_begin_transaction($koneksi);

    try {
        if (!mysqli_query($koneksi, $queryBarang)) {
            throw new Exception('Gagal simpan data barang: ' . mysqli_error($koneksi));
        }

        $idBarangBaru = (int) mysqli_insert_id($koneksi);

        mysqli_commit($koneksi);

        $detailBarang = ambilDetailBarangUntukEmail($koneksi, $idBarangBaru);

        $mailResult = [
            'status'  => false,
            'message' => 'Detail barang untuk email tidak ditemukan.'
        ];

        if ($detailBarang) {
            $fotoPath = null;

            if (!empty($detailBarang['foto'])) {
                $calonPath = __DIR__ . '/../assets/images/' . $detailBarang['foto'];
                if (is_file($calonPath)) {
                    $fotoPath = $calonPath;
                }
            }

            $mailResult = kirimEmailKeBranchInti([
                'branch'        => $detailBarang['nama_branch'] ?? '-',
                'no_asset'      => $detailBarang['no_asset'] ?? '-',
                'serial_number' => $detailBarang['serial_number'] ?? '-',
                'nama_barang'   => $detailBarang['nama_barang'] ?? '-',
                'merk'          => $detailBarang['nama_merk'] ?? '-',
                'tipe'          => $detailBarang['nama_tipe'] ?? '-',
                'jenis'         => $detailBarang['nama_jenis'] ?? '-',
                'tanggal_kirim' => $detailBarang['tanggal_kirim'] ?? '-',
                'user'          => $detailBarang['user'] ?? '-',
                'bermasalah'    => $detailBarang['bermasalah'] ?? '-',
                'foto_path'     => $fotoPath
            ]);
        }

        $message = 'Data barang berhasil ditambahkan.';
        if ($mailResult['status']) {
            $message .= ' Email notifikasi berhasil dikirim ke Mailtrap.';
        } else {
            $message .= ' Namun email notifikasi gagal dikirim: ' . $mailResult['message'];
        }

        echo json_encode([
            'status' => 'success',
            'message' => $message
        ]);
    } catch (Throwable $e) {
        mysqli_rollback($koneksi);

        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}
?>

<form id="formCreate" enctype="multipart/form-data">
    <div class="row g-3">
        <div class="col-12">
            <div class="alert alert-info mb-0">
                <b>Catatan:</b> Form create digunakan untuk menyimpan data barang yang akan dikirim ke branch inti.
                Tanggal yang diinput adalah tanggal kirim.
            </div>
        </div>

        <div class="col-md-6">
            <label>No Asset</label>
            <input type="text" name="no_asset" class="form-control" required placeholder="Wajib diisi!">
        </div>

        <div class="col-md-6">
            <label>Serial Number / Service tag</label>
            <input type="text" name="serial_number" class="form-control" required placeholder="Wajib diisi!">
        </div>

        <div class="col-md-6">
            <label>Barang</label>
            <select name="id_barang" class="form-control select2" required>
                <option value="">Pilih Barang...</option>
                <?php while ($row = mysqli_fetch_assoc($barang)): ?>
                    <option value="<?= (int) $row['id_barang'] ?>"><?= h($row['nama_barang']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label>Merk</label>
            <select name="id_merk" class="form-control select2" required>
                <option value="">Pilih Merk...</option>
                <?php while ($row = mysqli_fetch_assoc($merk)): ?>
                    <option value="<?= (int) $row['id_merk'] ?>"><?= h($row['nama_merk']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label>Tipe</label>
            <select name="id_tipe" class="form-control select2" required>
                <option value="">Pilih Tipe...</option>
                <?php while ($row = mysqli_fetch_assoc($tipe)): ?>
                    <option value="<?= (int) $row['id_tipe'] ?>"><?= h($row['nama_tipe']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label>Jenis</label>
            <select name="id_jenis" class="form-control select2" required>
                <option value="">Pilih Jenis...</option>
                <?php while ($row = mysqli_fetch_assoc($jenis)): ?>
                    <option value="<?= (int) $row['id_jenis'] ?>"><?= h($row['nama_jenis']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label>Tanggal Kirim</label>
            <input type="date" name="tanggal_kirim" class="form-control" required>
        </div>

        <div class="col-md-6">
            <label>Branch</label>
            <select name="id_branch" class="form-control select2" required>
                <option value="">Pilih Branch...</option>
                <?php while ($row = mysqli_fetch_assoc($branch)): ?>
                    <option value="<?= (int) $row['id_branch'] ?>"><?= h($row['nama_branch']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label>User</label>
            <input type="text" name="user" class="form-control" required>
        </div>

        <div class="col-md-6">
            <label>Bermasalah</label>
            <select name="bermasalah" class="form-control select2" id="bermasalahSelect" required>
                <option value="">Pilih...</option>
                <option value="Tidak">Tidak</option>
                <option value="Iya">Iya</option>
            </select>
        </div>

        <div class="col-md-12" id="keteranganMasalahDiv" style="display:none;">
            <label>Keterangan Masalah</label>
            <textarea name="keterangan_masalah" class="form-control" placeholder="Isi jika bermasalah"></textarea>
        </div>

        <div class="col-md-6">
            <label>Foto Barang</label>
            <input type="file" name="foto" class="form-control" id="fotoInput" accept=".jpg,.jpeg,.png,.gif,.webp">
            <img id="previewFoto" style="max-width:120px;margin-top:10px;display:none;">
        </div>

        <!-- <div class="col-md-12 text-end">
            <button type="submit" class="btn btn-warning-custom">Simpan</button>
        </div>
    </div>
</form> -->
        <div class="col-md-12 text-end">
            <button type="submit" class="btn btn-warning-custom" id="btnSimpanBarang">
                <span class="btn-text">Simpan</span>
                <span class="btn-loading d-none">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                    Menyimpan...
                </span>
            </button>
        </div>

        <script>
            $(document).ready(function() {
                $('#bermasalahSelect').on('change', function() {
                    if ($(this).val() === 'Iya') {
                        $('#keteranganMasalahDiv').slideDown();
                        $('textarea[name="keterangan_masalah"]').attr('required', true);
                    } else {
                        $('#keteranganMasalahDiv').slideUp();
                        $('textarea[name="keterangan_masalah"]').removeAttr('required').val('');
                    }
                });
            });

            $('#fotoInput').change(function() {
                if (!this.files || !this.files[0]) return;

                let reader = new FileReader();
                reader.onload = function(e) {
                    $('#previewFoto').attr('src', e.target.result).show();
                };
                reader.readAsDataURL(this.files[0]);
            });
        </script>