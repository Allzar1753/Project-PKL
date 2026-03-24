<?php

$host="localhost";
$username="root";
$password="";
$database="it_hexindo";

$koneksi=mysqli_connect($host,$username,$password,$database);
if (!$koneksi) {
    die("Koneksi Gagal: " . mysqli_connect_error());
}
?>