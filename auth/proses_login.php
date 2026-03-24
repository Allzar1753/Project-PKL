<?php
include '../config/koneksi.php';
include '../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(base_url('auth/login.php'));
}

$login = trim($_POST['login'] ?? '');
$password = (string) ($_POST['password'] ?? '');

if ($login === '' || $password === '') {
    set_flash('error', 'Username/email dan password wajib diisi.');
    redirect_to(base_url('auth/login.php'));
}

$user = find_user_by_login($koneksi, $login);

if (!$user) {
    set_flash('error', 'Akun tidak ditemukan.');
    redirect_to(base_url('auth/login.php'));
}

if (!verify_password_and_upgrade($koneksi, $user, $password)) {
    set_flash('error', 'Password salah.');
    redirect_to(base_url('auth/login.php'));
}

login_user($koneksi, $user);
set_flash('success', 'Login berhasil. Selamat datang, ' . $user['username'] . '.');
redirect_to(base_url('Barang/index.php'));
