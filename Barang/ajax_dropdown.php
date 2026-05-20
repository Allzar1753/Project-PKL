<?php
/** @var mysqli $koneksi */
include '../config/koneksi.php';

$action = $_POST['action'] ?? '';

if ($action == 'get_merk') {
    $id_barang = (int)$_POST['id_barang'];

    $query = "SELECT DISTINCT m.id_merk, m.nama_merk
              FROM tb_tipe t
              JOIN tb_merk m ON t.id_merk = m.id_merk
              WHERE t.id_barang = $id_barang
              ORDER BY m.nama_merk ASC";

    $result = mysqli_query($koneksi, $query);

    echo '<option value="">Pilih Merk...</option>';
    while ($row = mysqli_fetch_assoc($result)) {
        echo '<option value="' . $row['id_merk'] . '">' . $row['nama_merk'] . '</option>';
    }
}
elseif ($action == 'get_tipe') {
    $id_barang = (int)$_POST['id_barang'];
    $id_merk = (int)$_POST['id_merk'];

    $query = "SELECT id_tipe, nama_tipe 
              FROM tb_tipe 
              WHERE id_barang = $id_barang AND id_merk = $id_merk
              ORDER BY nama_tipe ASC";
    
    $result = mysqli_query($koneksi, $query);

    echo '<option value="">Pilih Tipe...</option>';
    while ($row = mysqli_fetch_assoc($result)) {
        echo '<option value="' . $row['id_tipe'] . '">' . $row['nama_tipe'] . '</option>';
    }
} 