<?php
require_once '../config/auth.php';

if (is_logged_in()) {
    redirect_to(base_url('dashboard/index.php'));
}

$error = get_flash('error');
$success = get_flash('success');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - IT Asset Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #ffc107;
            --dark: #212529;
            --muted: #6c757d;
            --surface: #ffffff;
            --bg-soft: #f5f7fb;
            --shadow-soft: 0 12px 32px rgba(0, 0, 0, 0.08);
        }

        body {
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #f5f7fb 0%, #eef2f7 100%);
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .login-wrap {
            width: 100%;
            max-width: 1040px;
        }

        .login-card {
            border: none;
            border-radius: 24px;
            overflow: hidden;
            background: var(--surface);
            box-shadow: var(--shadow-soft);
        }

        .login-left {
            background: linear-gradient(135deg, #212529 0%, #343a40 100%);
            color: #fff;
            padding: 48px 40px;
            height: 100%;
            position: relative;
        }

        .login-left::after {
            content: "";
            position: absolute;
            right: -60px;
            bottom: -60px;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: rgba(255, 193, 7, 0.13);
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255, 193, 7, 0.12);
            color: #ffe082;
            font-size: 0.86rem;
            font-weight: 700;
            margin-bottom: 20px;
            border: 1px solid rgba(255, 193, 7, 0.16);
        }

        .login-title {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 14px;
        }

        .login-desc {
            color: rgba(255, 255, 255, 0.78);
            line-height: 1.65;
            max-width: 420px;
        }

        .login-feature {
            margin-top: 28px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            position: relative;
            z-index: 1;
        }

        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .feature-icon {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.08);
            color: #ffc107;
            flex-shrink: 0;
            font-size: 1rem;
        }

        .feature-title {
            font-weight: 700;
            margin-bottom: 2px;
        }

        .feature-text {
            color: rgba(255, 255, 255, 0.68);
            font-size: 0.92rem;
            line-height: 1.45;
        }

        .login-right {
            padding: 42px 38px;
            background: #fff;
        }

        .form-title {
            font-size: 1.55rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 6px;
        }

        .form-subtitle {
            color: var(--muted);
            margin-bottom: 26px;
        }

        .form-label {
            font-weight: 700;
            color: var(--dark);
        }

        .form-control {
            border-radius: 14px;
            padding: 0.82rem 0.95rem;
            border: 1px solid #dde3ea;
            box-shadow: none;
        }

        .form-control:focus {
            border-color: #f0c63d;
            box-shadow: 0 0 0 0.2rem rgba(255, 193, 7, 0.15);
        }

        .btn-login {
            background: var(--primary);
            border: none;
            color: #212529;
            font-weight: 800;
            border-radius: 14px;
            padding: 0.9rem 1rem;
        }

        .btn-login:hover {
            background: #e0a800;
            color: #212529;
        }

        .note-text {
            color: var(--muted);
            font-size: 0.9rem;
            text-align: center;
            margin-top: 18px;
        }

        .system-note {
            margin-top: 24px;
            background: #f8f9fa;
            border: 1px solid #ebedf0;
            border-radius: 16px;
            padding: 14px 16px;
            color: var(--muted);
            font-size: 0.92rem;
        }

        @media (max-width: 991.98px) {
            .login-left {
                padding: 32px 26px;
            }

            .login-right {
                padding: 32px 26px;
            }

            .login-title {
                font-size: 1.7rem;
            }
        }
    </style>
</head>

<body>
    <div class="login-wrap">
        <div class="row g-0 login-card">
            <div class="col-lg-6">
                <div class="login-left">
                    <div class="brand-badge">
                        <span>IT Asset Management</span>
                    </div>

                    <div class="login-title">
                        Selamat Datang di Sistem Inventaris
                    </div>

                    <div class="login-desc">
                        Kelola data aset, pantau riwayat aktivitas, dan kontrol akses pengguna
                        dalam satu sistem yang terpusat, rapi, dan aman.
                    </div>

                    <div class="login-feature">
                        <div class="feature-item">
                            <div class="feature-icon">✓</div>
                            <div>
                                <div class="feature-title">Kontrol Akses Terpusat</div>
                                <div class="feature-text">Hak akses pengguna dikelola sesuai role dan permission yang diberikan.</div>
                            </div>
                        </div>

                        <div class="feature-item">
                            <div class="feature-icon">✓</div>
                            <div>
                                <div class="feature-title">Monitoring Inventaris</div>
                                <div class="feature-text">Pantau barang masuk, barang keluar, kondisi aset, dan pengiriman.</div>
                            </div>
                        </div>

                        <div class="feature-item">
                            <div class="feature-icon">✓</div>
                            <div>
                                <div class="feature-title">Siap untuk Operasional</div>
                                <div class="feature-text">Dirancang untuk membantu proses kerja inventaris perusahaan dengan lebih terstruktur.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="login-right">
                    <div class="form-title">Masuk ke Akun</div>
                    <div class="form-subtitle">
                        Gunakan username atau email yang telah terdaftar untuk mengakses sistem.
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger rounded-4"><?= e($error) ?></div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success rounded-4"><?= e($success) ?></div>
                    <?php endif; ?>

                    <form action="<?= h(base_url('auth/proses_login.php')) ?>" method="POST">
                        <div class="mb-3">
                            <label class="form-label">Username atau Email</label>
                            <input type="text" name="login" class="form-control" required autofocus>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>

                        <button type="submit" class="btn btn-login w-100">
                            Masuk ke Dashboard
                        </button>
                    </form>

                    <div class="system-note">
                        Akun dibuat dan hak akses pengguna diatur oleh <b>Super Admin</b>.
                    </div>

                    <div class="note-text">
                        IT Asset Management System
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>