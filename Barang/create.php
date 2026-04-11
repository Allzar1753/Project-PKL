<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $required = [
        'no_asset',
        'id_barang',
        'id_merk',
        'serial_number',
        'id_tipe',
        'id_jenis',
        'tanggal_masuk',
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
    $tanggal_masuk = esc($koneksi, $_POST['tanggal_masuk']);
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

    $cekDuplikat = mysqli_query($koneksi, "
        SELECT id, no_asset, serial_number
        FROM barang
        WHERE no_asset = '$no_asset' OR serial_number = '$serial_number'
        LIMIT 1
    ");

    if (!$cekDuplikat) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Gagal cek duplikasi: ' . mysqli_error($koneksi)
        ]);
        exit;
    }

    if (mysqli_num_rows($cekDuplikat) > 0) {
        $duplikat = mysqli_fetch_assoc($cekDuplikat);

        $pesan = 'Data sudah digunakan.';
        if ($duplikat['no_asset'] === $no_asset && $duplikat['serial_number'] === $serial_number) {
            $pesan = 'No Asset dan Serial Number sudah terdaftar';
        } elseif ($duplikat['no_asset'] === $no_asset) {
            $pesan = 'No Asset sudah terdaftar';
        } elseif ($duplikat['serial_number'] === $serial_number) {
            $pesan = 'Serial Number sudah terdaftar';
        }

        echo json_encode([
            'status' => 'error',
            'message' => $pesan
        ]);
        exit;
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
            tanggal_masuk,
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
            '$tanggal_masuk',
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

        mysqli_commit($koneksi);

        echo json_encode([
            'status' => 'success',
            'message' => 'Data barang berhasil ditambahkan.'
        ]);
    } catch (Exception $e) {
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
                <b>Catatan:</b> Form create hanya untuk simpan data barang masuk.
                Proses pengiriman / barang keluar dibuat dari menu pengiriman, bukan dari form create.
            </div>
        </div>

        <div class="col-md-6">
            <label>No Asset</label>
            <input type="text" name="no_asset" class="form-control" required>
        </div>

        <div class="col-md-6">
            <label>Serial Number</label>
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
            <label>Tanggal Masuk</label>
            <input type="date" name="tanggal_masuk" class="form-control" required>
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

        <div class="col-md-12 text-end">
            <button type="submit" class="btn btn-warning-custom">Simpan</button>
        </div>
    </div>
</form>

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