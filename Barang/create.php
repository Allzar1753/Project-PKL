<?php
include '../config/koneksi.php';
require_permission($koneksi, 'barang.create');


$merk    = mysqli_query($koneksi, "SELECT * FROM tb_merk");
$tipe    = mysqli_query($koneksi, "SELECT * FROM tb_tipe");
$jenis   = mysqli_query($koneksi, "SELECT * FROM tb_jenis");
$branch  = mysqli_query($koneksi, "SELECT * FROM tb_branch");
$barang  = mysqli_query($koneksi, "SELECT * FROM tb_barang");

if($_SERVER['REQUEST_METHOD'] === 'POST'){

header('Content-Type: application/json');

$required = [
'no_asset','id_barang','id_merk','serial_number',
'id_tipe','id_jenis','tanggal_masuk',
'bermasalah','id_branch','user'
];

foreach($required as $f){
if(empty($_POST[$f])){
echo json_encode([
'success'=>false,
'error'=>"Field $f wajib diisi"
]);
exit;
}
}

$no_asset       = mysqli_real_escape_string($koneksi,$_POST['no_asset']);
$id_barang      = mysqli_real_escape_string($koneksi,$_POST['id_barang']);
$id_merk        = mysqli_real_escape_string($koneksi,$_POST['id_merk']);
$serial_number  = mysqli_real_escape_string($koneksi,$_POST['serial_number']);
$id_tipe        = mysqli_real_escape_string($koneksi,$_POST['id_tipe']);
$id_jenis       = mysqli_real_escape_string($koneksi,$_POST['id_jenis']);
$tanggal_masuk  = mysqli_real_escape_string($koneksi,$_POST['tanggal_masuk']);
$bermasalah     = mysqli_real_escape_string($koneksi,$_POST['bermasalah']);
$id_branch      = mysqli_real_escape_string($koneksi,$_POST['id_branch']);
$user           = mysqli_real_escape_string($koneksi,$_POST['user']);

$keterangan_masalah = NULL;

if($bermasalah == 'Iya' && !empty($_POST['keterangan_masalah'])){
$keterangan_masalah = mysqli_real_escape_string($koneksi,$_POST['keterangan_masalah']);
}

$cek = mysqli_query($koneksi,"SELECT id FROM barang WHERE no_asset='$no_asset'");
if(mysqli_num_rows($cek) > 0){
echo json_encode([
'success'=>false,
'error'=>'No Asset sudah terdaftar'
]);
exit;
}

$id_status = ($bermasalah == 'Iya') ? 5 : 4;

$foto = NULL;

if(isset($_FILES['foto']) && $_FILES['foto']['error'] == 0){

$allowed = ['jpg','jpeg','png','gif'];
$ext = strtolower(pathinfo($_FILES['foto']['name'],PATHINFO_EXTENSION));

if(!in_array($ext,$allowed)){
echo json_encode([
'success'=>false,
'error'=>'Format foto harus JPG, PNG, GIF'
]);
exit;
}

if($_FILES['foto']['size'] > 2000000){
echo json_encode([
'success'=>false,
'error'=>'Ukuran foto maksimal 2MB'
]);
exit;
}

if(!is_dir("../assets/images")){
mkdir("../assets/images",0777,true);
}

$foto_name = uniqid().".".$ext;
$target = "../assets/images/".$foto_name;

if(move_uploaded_file($_FILES['foto']['tmp_name'],$target)){
$foto = $foto_name;
}
}

$query = "
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
user
) VALUES (
'$no_asset',
'$id_barang',
'$id_merk',
'$serial_number',
'$id_tipe',
'$id_jenis',
'$tanggal_masuk',
'$bermasalah',
".($keterangan_masalah ? "'$keterangan_masalah'" : "NULL").",
'$id_status',
'$id_branch',
".($foto ? "'$foto'" : "NULL").",
'$user'
)";

if(mysqli_query($koneksi,$query)){
echo json_encode(['success'=>true]);
}else{
echo json_encode([
'success'=>false,
'error'=>mysqli_error($koneksi)
]);
}

exit;
}
?>

<form id="formCreate" enctype="multipart/form-data">
    <div class="row g-3">

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
                <?php while($row=mysqli_fetch_assoc($barang)): ?>
                <option value="<?= $row['id_barang'] ?>"><?= $row['nama_barang'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label>Merk</label>
            <select name="id_merk" class="form-control select2" required>
                <option value="">Pilih Merk...</option>
                <?php while($row=mysqli_fetch_assoc($merk)): ?>
                <option value="<?= $row['id_merk'] ?>"><?= $row['nama_merk'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label>Tipe</label>
            <select name="id_tipe" class="form-control select2" required>
                <option value="">Pilih Tipe...</option>
                <?php while($row=mysqli_fetch_assoc($tipe)): ?>
                <option value="<?= $row['id_tipe'] ?>"><?= $row['nama_tipe'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label>Jenis</label>
            <select name="id_jenis" class="form-control select2" required>
                <option value="">Pilih Jenis...</option>
                <?php while($row=mysqli_fetch_assoc($jenis)): ?>
                <option value="<?= $row['id_jenis'] ?>"><?= $row['nama_jenis'] ?></option>
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
                <?php while($row=mysqli_fetch_assoc($branch)): ?>
                <option value="<?= $row['id_branch'] ?>"><?= $row['nama_branch'] ?></option>
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
            <label>Foto</label>
            <input type="file" name="foto" class="form-control" id="fotoInput">
            <img id="previewFoto" style="max-width:120px;margin-top:10px;display:none;">
        </div>

        <div class="col-md-12 text-end">
            <button type="submit" class="btn btn-warning-custom">Simpan</button>
        </div>
    </div>
</form>

<script>
$(document).ready(function(){
    $('#bermasalahSelect').on('change', function(){
    if($(this).val() === 'Iya'){
        $('#keteranganMasalahDiv').slideDown();
        $('textarea[name="keterangan_masalah"]').attr('required',true);
    } else {
        $('#keteranganMasalahDiv').slideUp();
        $('textarea[name="keterangan_masalah"]').removeAttr('required');
    }
});
});

$('#fotoInput').change(function(e){

let reader = new FileReader();

reader.onload = function(e){
$('#previewFoto')
.attr('src',e.target.result)
.show();
}

reader.readAsDataURL(this.files[0]);

});
</script>