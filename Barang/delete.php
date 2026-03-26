<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'barang.delete');

header('Content-Type: application/json');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    echo json_encode([
        "status" => "error",
        "message" => "ID tidak valid"
    ]);
    exit;
}

$delete = mysqli_query($koneksi, "DELETE FROM barang WHERE id = $id");

if ($delete) {
    echo json_encode([
        "status" => "success",
        "message" => "Data berhasil dihapus"
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Data gagal dihapus: " . mysqli_error($koneksi)
    ]);
}