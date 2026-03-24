<?php
include '../config/koneksi.php';
require_permission($koneksi, 'barang.delete');

header('Content-Type: application/json');

if(!isset($_GET['id'])){
    echo json_encode([
        "status" => "error",
        "message" => "ID tidak ditemukan"
    ]);
    exit;
}

$id = $_GET['id'];

$delete = mysqli_query($koneksi, "DELETE FROM barang WHERE id='$id'");

if($delete){
    echo json_encode([
        "status" => "success",
        "message" => "Data berhasil dihapus"
    ]);
}else{
    echo json_encode([
        "status" => "error",
        "message" => "Data gagal dihapus"
    ]);
}
?>