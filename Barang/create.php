<?php
include '../config/koneksi.php';
require_once '../config/auth.php';
require_once '../config/mail.php';

require_permission($koneksi, 'barang.create');

$merk   = mysqli_query($koneksi, "SELECT * FROM tb_merk");
$tipe   = mysqli_query($koneksi, "SELECT * FROM tb_tipe");
$jenis  = mysqli_query($koneksi, "SELECT * FROM tb_jenis");
$branch = mysqli_query($koneksi, "SELECT * FROM tb_branch");
$barang = mysqli_query($koneksi, "SELECT * FROM tb_barang");

function esc($koneksi, $value)
{
    return mysqli_real_escape_string($koneksi, trim((string)$value));
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
        return ["status" => "error", "message" => "Gagal mengupload file {$fieldName}"];
    }

    return ["status" => "success", "filename" => $filename];
}

function ambilDetailBarangUntukEmail($koneksi, $idBarangBaru)
{
    $idBarangBaru = (int) $idBarangBaru;

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
        WHERE b.id = $idBarangBaru
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

    foreach ($required as $f) {
        if (!isset($_POST[$f]) || trim((string)$_POST[$f]) === '') {
            echo json_encode([
                'status' => 'error',
                'message' => "Field $f wajib diisi"
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
        if (empty($_POST['keterangan_masalah']) || trim((string)$_POST['keterangan_masalah']) === '') {
            echo json_encode([
                'status' => 'error',
                'message' => 'Keterangan masalah wajib diisi jika barang bermasalah'
            ]);
            exit;
        }

        $keterangan_masalah = esc($koneksi, $_POST['keterangan_masalah']);
    }

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

    $getBarangDipilih = mysqli_query($koneksi, "
        SELECT id_barang, nama_barang
        FROM tb_barang
        WHERE id_barang = '$id_barang'
        LIMIT 1
    ");

    if (!$getBarangDipilih) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Gagal ambil data barang: ' . mysqli_error($koneksi)
        ]);
        exit;
    }

    if (!$getBarangDipilih || mysqli_num_rows($getBarangDipilih) === 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Barang yang dipilih tidak valid'
        ]);
        exit;
    }

    $dataBarangDipilih = mysqli_fetch_assoc($getBarangDipilih);
    $namaBarangInput = strtolower(trim($dataBarangDipilih['nama_barang']));

    $pasanganBarang = ['monitor', 'cpu'];

    $cekNoAsset = mysqli_query($koneksi, "
    SELECT 
        b.id,
        b.no_asset,
        tb.nama_barang
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

    $barangSudahAda = [];
    $inputTermasukPasangan = in_array($namaBarangInput, $pasanganBarang, true);

    if (mysqli_num_rows($cekNoAsset) > 0) {
        while ($row = mysqli_fetch_assoc($cekNoAsset)) {
            $namaBarangDb = strtolower(trim($row['nama_barang']));
            $barangSudahAda[] = $namaBarangDb;

            // jika barang yang sama sudah ada untuk no asset yang sama
            if ($namaBarangDb === $namaBarangInput) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Barang tersebut sudah tersedia di daftar barang'
                ]);
                exit;
            }

            // jika no asset sudah dipakai barang selain monitor/cpu
            if (!in_array($namaBarangDb, $pasanganBarang, true)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'No Asset sudah terdaftar'
                ]);
                exit;
            }
        }

        $barangSudahAda = array_unique($barangSudahAda);

        // jika input sekarang bukan monitor/cpu, tidak boleh pakai no_asset yang sudah ada
        if (!$inputTermasukPasangan) {
            echo json_encode([
                'status' => 'error',
                'message' => 'No Asset sudah terdaftar'
            ]);
            exit;
        }

        // jika sudah lengkap monitor + cpu, jangan tambah lagi
        if (count($barangSudahAda) >= 2) {
            echo json_encode([
                'status' => 'error',
                'message' => 'No Asset ini sudah dipakai untuk Monitor dan CPU'
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

    if ($uploadFoto['status'] === 'success') {
        $foto = $uploadFoto['filename'];
    }

    /*
    Status barang:
    5 = bermasalah
    4 = aktif / masuk
    */
    if ($bermasalah === 'Iya') {
        $id_status = 5;
    } else {
        $id_status = 4;
    }

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

        $idBarangBaru = mysqli_insert_id($koneksi);

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
            'status'  => 'success',
            'message' => $message
        ]);
    } catch (Exception $e) {
        mysqli_rollback($koneksi);

        echo json_encode([
            'status'  => 'error',
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
                <b>Catatan:</b> Form create hanya untuk simpan data barang masuk.
                Proses pengiriman / barang keluar dibuat dari menu pengiriman, bukan dari form create.
            </div>
        </div>

        <div class="col-md-6">
            <label>No Asset</label>
            <input type="text" name="no_asset" class="form-control" required>
        </div>

        <div class="col-md-6">
            <label>Serial Number / Service tag</label>
            <input type="text" name="serial_number" class="form-control" required>
        </div>

        <div class="col-md-6">
            <label>Barang</label>
            <select name="id_barang" class="form-control select2" required>
                <option value="">Pilih Barang...</option>
                <?php while ($row = mysqli_fetch_assoc($barang)): ?>
                    <option value="<?= $row['id_barang'] ?>"><?= htmlspecialchars($row['nama_barang']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label>Merk</label>
            <select name="id_merk" class="form-control select2" required>
                <option value="">Pilih Merk...</option>
                <?php while ($row = mysqli_fetch_assoc($merk)): ?>
                    <option value="<?= $row['id_merk'] ?>"><?= htmlspecialchars($row['nama_merk']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label>Tipe</label>
            <select name="id_tipe" class="form-control select2" required>
                <option value="">Pilih Tipe...</option>
                <?php while ($row = mysqli_fetch_assoc($tipe)): ?>
                    <option value="<?= $row['id_tipe'] ?>"><?= htmlspecialchars($row['nama_tipe']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label>Jenis</label>
            <select name="id_jenis" class="form-control select2" required>
                <option value="">Pilih Jenis...</option>
                <?php while ($row = mysqli_fetch_assoc($jenis)): ?>
                    <option value="<?= $row['id_jenis'] ?>"><?= htmlspecialchars($row['nama_jenis']) ?></option>
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
                    <option value="<?= $row['id_branch'] ?>"><?= htmlspecialchars($row['nama_branch']) ?></option>
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