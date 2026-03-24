<?php
include '../config/auth.php';

$message = 'Anda berhasil logout.';
logout_user();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

set_flash('success', $message);
redirect_to(base_url('auth/login.php'));
