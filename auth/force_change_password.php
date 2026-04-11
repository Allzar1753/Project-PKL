<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_login();
$user = current_user();

if (!$user) {
    redirect_to(base_url('auth/login.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (strlen($password) < 8) {
        set_flash('error', 'Password minimal 8 karakter.');
        redirect_to(base_url('auth/force_change_password.php'));
    }

    if ($password !== $confirmPassword) {
        set_flash('error', 'Konfirmasi password tidak sama.');
        redirect_to(base_url('auth/force_change_password.php'));
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $userId = (int) $user['id'];

    $stmt = mysqli_prepare($koneksi, "UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'si', $hash, $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    set_flash('success', 'Password berhasil diubah.');
    redirect_to(base_url('dashboard/index.php'));
}

$error = get_flash('error');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ganti Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h4 class="mb-3">Buat Password Baru</h4>
                        <p class="text-muted">Untuk keamanan akun, silakan ganti password default terlebih dahulu.</p>

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= e($error) ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Password Baru</label>
                                <input type="password" name="password" class="form-control" required minlength="8">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Konfirmasi Password</label>
                                <input type="password" name="confirm_password" class="form-control" required minlength="8">
                            </div>
                            <button type="submit" class="btn btn-warning w-100">Simpan Password</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
