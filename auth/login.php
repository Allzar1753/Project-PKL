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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/password-fields.css')) ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        /* ==========================================
           TEMA BARU MODERN CLEAN (Dashboard Match)
           ========================================== */
        :root {
            --orange-primary: #E64312;
            --orange-hover: #F25C05;
            --dark-main: #231F20;
            --text-dark: #374151;
            --text-muted: #6b7280;
            --border-color: #e5e7eb;
            --bg-body: #F4F6F9;
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-dark);
            background-color: var(--bg-body);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .login-wrap {
            width: 100%;
            max-width: 1000px;
        }

        .login-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.04);
        }

        /* --- Sisi Kiri (Informasi) --- */
        .login-left {
            position: relative;
            background-color: var(--dark-main);
            color: #ffffff;
            padding: 48px 40px;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
        }

        /* Ornamen Background Kiri */
        .login-left::before {
            content: "";
            position: absolute;
            top: -50px;
            right: -50px;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: rgba(230, 67, 18, 0.1);
            z-index: 1;
        }
        .login-left::after {
            content: "";
            position: absolute;
            bottom: -80px;
            left: -40px;
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.03);
            z-index: 1;
        }

        .login-left-content,
        .login-left-footer {
            position: relative;
            z-index: 2;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.05);
            color: #ffffff;
            font-size: .85rem;
            font-weight: 700;
            margin-bottom: 24px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .brand-badge i { color: var(--orange-primary); font-size: 1.1rem; }

        .login-title {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 16px;
            color: #ffffff;
        }

        .login-desc {
            color: #9ca3af;
            line-height: 1.6;
            font-size: 0.95rem;
            margin-bottom: 30px;
        }

        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 20px;
        }
        .feature-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(230, 67, 18, 0.15);
            color: var(--orange-primary);
            flex-shrink: 0;
            font-size: 1.1rem;
        }
        .feature-title { font-weight: 700; margin-bottom: 4px; color: #f3f4f6; font-size: 0.95rem; }
        .feature-text { color: #9ca3af; font-size: 0.85rem; line-height: 1.5; }

        .login-left-footer {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            font-size: 0.85rem;
            color: #6b7280;
            font-weight: 500;
        }

        /* --- Sisi Kanan (Form) --- */
        .login-right {
            padding: 48px 40px;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            height: 100%;
        }

        .form-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--dark-main);
            margin-bottom: 6px;
        }

        .form-subtitle {
            color: var(--text-muted);
            margin-bottom: 28px;
            line-height: 1.5;
            font-size: 0.95rem;
        }

        /* Notifikasi Alert */
        .alert {
            border: none;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 24px;
        }
        .alert-danger { background: #fef2f2; color: #b91c1c; border-left: 4px solid #ef4444; }
        .alert-success { background: #f0fdf4; color: #15803d; border-left: 4px solid #22c55e; }

        /* Form Input Modern */
        .form-label {
            font-weight: 700;
            color: var(--dark-main);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .input-shell { position: relative; }
        .input-icon {
            position: absolute;
            top: 50%;
            left: 16px;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 1.1rem;
            z-index: 2;
            transition: color 0.2s;
        }

        .form-control {
            border-radius: 8px;
            padding: 0.75rem 1rem 0.75rem 2.8rem;
            border: 1px solid var(--border-color);
            background: #ffffff;
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--text-dark);
            transition: all 0.2s ease;
            box-shadow: none !important;
        }
        
        .form-control:focus {
            border-color: var(--orange-primary);
            box-shadow: 0 0 0 4px rgba(230, 67, 18, 0.1) !important;
        }
        
        /* Merubah warna icon saat input fokus */
        .form-control:focus + .input-icon,
        .input-shell:focus-within .input-icon {
            color: var(--orange-primary);
        }

        /* Tombol Modern */
        .btn-login {
            background-color: var(--orange-primary);
            color: #ffffff;
            font-weight: 700;
            border: none;
            border-radius: 8px;
            padding: 0.8rem 1rem;
            font-size: 1rem;
            transition: all 0.2s ease;
            margin-top: 10px;
        }
        .btn-login:hover {
            background-color: var(--orange-hover);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(230, 67, 18, 0.2);
        }

        .forgot-link {
            color: var(--orange-primary);
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            transition: 0.2s;
        }
        .forgot-link:hover { color: var(--orange-hover); text-decoration: underline; }

        .system-note {
            margin-top: 36px;
            background: var(--bg-body);
            border-radius: 8px;
            padding: 14px 16px;
            color: var(--text-muted);
            font-size: 0.85rem;
            line-height: 1.5;
            border-left: 4px solid var(--border-color);
        }

        @media (max-width: 991.98px) {
            .login-left, .login-right { padding: 32px 24px; }
            .login-title { font-size: 1.6rem; }
            .form-title { font-size: 1.4rem; }
        }
    </style>
</head>

<body>
    <div class="login-wrap">
        <div class="row g-0 login-card">

            <!-- BAGIAN KIRI (Branding & Info) -->
            <div class="col-lg-5 d-none d-lg-block">
                <div class="login-left">
                    <div class="login-left-content">
                        <div class="brand-badge">
                            <i class="bi bi-layers-fill"></i>
                            <span>IT Asset System</span>
                        </div>

                        <div class="login-title">
                            Kelola Inventaris Dengan Lebih Mudah
                        </div>

                        <div class="login-desc">
                            Platform terpusat untuk memantau data aset, aktivitas inventaris, dan proses logistik operasional perusahaan.
                        </div>

                        <div>
                            <div class="feature-item">
                                <div class="feature-icon"><i class="bi bi-shield-check"></i></div>
                                <div>
                                    <div class="feature-title">Akses Terjamin</div>
                                    <div class="feature-text">Keamanan data dengan sistem role dan permission terpusat.</div>
                                </div>
                            </div>
                            <div class="feature-item">
                                <div class="feature-icon"><i class="bi bi-display"></i></div>
                                <div>
                                    <div class="feature-title">Monitoring Real-time</div>
                                    <div class="feature-text">Pantau perpindahan dan kondisi perangkat kapan saja.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="login-left-footer">
                        &copy; <?= date('Y') ?> IT Asset Management Internal System.
                    </div>
                </div>
            </div>

            <!-- BAGIAN KANAN (Form Login) -->
            <div class="col-lg-7">
                <div class="login-right">
                    
                    <div class="form-title">Masuk ke Akun</div>
                    <div class="form-subtitle">
                        Gunakan username atau email yang telah terdaftar untuk mengakses dashboard.
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($error) ?></div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i><?= e($success) ?></div>
                    <?php endif; ?>

                    <form action="<?= h(base_url('auth/proses_login.php')) ?>" method="POST">
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <div class="input-shell">
                                <i class="bi bi-envelope-fill input-icon"></i>
                                <input type="email" name="login" class="form-control" required autofocus placeholder="Masukkan email terdaftar">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Password</label>
                            <div class="input-shell password-shell">
                                <i class="bi bi-lock-fill input-icon"></i>
                                <input type="password" name="password" class="form-control password-field" id="loginPassword" required placeholder="Masukkan password">
                                <button type="button" class="password-toggle" data-target="loginPassword" aria-label="Lihat password" title="Tampilkan Password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-login w-100">
                            Masuk ke Dashboard <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </form>

                    <div class="text-center mt-3">
                        <a href="<?= h(base_url('auth/forgot_password.php')) ?>" class="forgot-link">
                            Lupa password?
                        </a>
                    </div>

                    <div class="system-note">
                        <i class="bi bi-info-circle me-1 text-muted"></i> Akses sistem dan pembuatan akun dikelola penuh oleh <b>Super Admin</b>. Pastikan Anda memiliki kredensial yang sah.
                    </div>

                </div>
            </div>

        </div>
    </div>

    <script src="<?= e(base_url('assets/js/password-fields.js')) ?>"></script>
</body>

</html>