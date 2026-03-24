<?php
include '../config/auth.php';

if (is_logged_in()) {
    redirect_to(base_url('Barang/index.php'));
}

$error = get_flash('error');
$success = get_flash('success');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - IT Asset</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
        }

        .login-card {
            width: 100%;
            max-width: 460px;
            border: none;
            border-radius: 18px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, .08);
        }

        .brand-title {
            color: #ffc107;
            font-weight: 800;
        }

        .btn-login {
            background: #ffc107;
            border: none;
            color: #000;
            font-weight: 700;
        }

        .btn-login:hover {
            background: #e0a800;
            color: #000;
        }
    </style>
</head>

<body>
    <div class="card login-card p-4">
        <div class="text-center mb-4">
            <h2 class="brand-title mb-1">IT Asset</h2>
            <p class="text-muted mb-0">Login untuk masuk ke sistem inventaris</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>

        <form action="proses_login.php" method="POST">
            <div class="mb-3">
                <label class="form-label">Username atau Email</label>
                <input type="text" name="login" class="form-control" required autofocus>
            </div>

            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>

            <button type="submit" class="btn btn-login w-100 py-2">Masuk</button>
        </form>

        <div class="mt-3 text-center text-muted small">
            Akun dibuat dan diatur oleh Super Admin.
        </div>
    </div>
</body>

</html>
