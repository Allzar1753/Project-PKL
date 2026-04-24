<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim((string) ($_POST['login'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($login === '' || $password === '') {
        set_flash('error', 'Username/Email dan Password wajib diisi.');
        redirect_to(base_url('auth/login.php'));
    }

    // Cek user berdasarkan username ATAU email
    $stmt = mysqli_prepare($koneksi, "SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ss', $login, $login);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user   = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$user) {
        set_flash('error', 'Username atau email tidak ditemukan.');
        redirect_to(base_url('auth/login.php'));
    }

    // Verifikasi hash password
    if (!password_verify($password, $user['password'])) {
        set_flash('error', 'Password salah.');
        redirect_to(base_url('auth/login.php'));
    }

    // Login Sukses! Set data ke session
    $_SESSION['user'] =[
        'id'                   => (int) $user['id'],
        'username'             => $user['username'],
        'email'                => $user['email'],
        'role'                 => $user['role'],
        'id_branch'            => $user['id_branch'],
        'must_change_password' => (int) $user['must_change_password']
    ];

    // ============================================================
    // LOGIC WAJIB GANTI PASSWORD
    // ============================================================
    if ((int) $user['must_change_password'] === 1) {
        // Jika wajib ganti password, arahkan ke force_change_password
        redirect_to(base_url('auth/force_change_password.php'));
    }

    // Jika tidak ada tanggungan ganti password, arahkan ke dashboard
    set_flash('success', 'Selamat datang kembali, ' . $user['username'] . '!');
    redirect_to(base_url('dashboard/index.php'));
}

redirect_to(base_url('auth/login.php'));