<?php
include 'config/koneksi.php';

// Check barang table structure
$result = mysqli_query($koneksi, "DESC barang");
echo "=== STRUKTUR TABLE BARANG ===\n";
while ($row = mysqli_fetch_assoc($result)) {
    echo "{$row['Field']} - {$row['Type']} - {$row['Null']} - {$row['Key']} - {$row['Default']}\n";
}

echo "\n=== DATA BARANG TERBARU (LIMIT 5) ===\n";
$data = mysqli_query($koneksi, "SELECT id, serial_number, id_branch, status, user FROM barang ORDER BY id DESC LIMIT 5");
while ($row = mysqli_fetch_assoc($data)) {
    print_r($row);
}

echo "\n=== BRANCH YANG ADA ===\n";
$branch = mysqli_query($koneksi, "SELECT id_branch, nama_branch FROM tb_branch");
while ($row = mysqli_fetch_assoc($branch)) {
    echo "ID: {$row['id_branch']} - {$row['nama_branch']}\n";
}
?>
