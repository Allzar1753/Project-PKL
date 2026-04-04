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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --orange-1: #ff7a00;
            --orange-2: #ff9800;
            --orange-3: #ffb000;
            --orange-4: #ffd166;
            --orange-5: #fff3e0;

            --dark-1: #111111;
            --dark-2: #1f1f1f;
            --dark-3: #2f2f2f;

            --text-main: #1e1e1e;
            --text-soft: #6b7280;
            --border-soft: rgba(255, 152, 0, 0.14);

            --surface: #ffffff;
            --surface-soft: #fffaf3;
            --shadow-soft: 0 16px 40px rgba(17, 17, 17, 0.08);
            --shadow-strong: 0 22px 54px rgba(255, 122, 0, 0.16);

            --radius-xl: 28px;
            --radius-lg: 22px;
            --radius-md: 16px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
            background:
                radial-gradient(circle at top left, rgba(255, 176, 0, 0.18), transparent 24%),
                radial-gradient(circle at bottom right, rgba(255, 122, 0, 0.10), transparent 20%),
                linear-gradient(180deg, #fff8f1 0%, #fffaf5 34%, #ffffff 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .login-wrap {
            width: 100%;
            max-width: 1120px;
        }

        .login-card {
            background: var(--surface);
            border: 1px solid rgba(255, 176, 0, 0.14);
            border-radius: var(--radius-xl);
            overflow: hidden;
            box-shadow: var(--shadow-soft);
        }

        .login-left {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(17, 17, 17, 0.96) 0%, rgba(42, 42, 42, 0.92) 34%, rgba(255, 122, 0, 0.96) 100%);
            color: #fff;
            padding: 52px 42px;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .login-left::before {
            content: "";
            position: absolute;
            top: -80px;
            right: -70px;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
        }

        .login-left::after {
            content: "";
            position: absolute;
            bottom: -70px;
            left: -60px;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: rgba(255, 209, 102, 0.18);
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
            padding: 9px 15px;
            border-radius: 999px;
            background: rgba(255, 193, 7, 0.12);
            color: #ffe082;
            font-size: .84rem;
            font-weight: 700;
            margin-bottom: 22px;
            border: 1px solid rgba(255, 193, 7, 0.18);
            backdrop-filter: blur(4px);
        }

        .brand-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: #ffc107;
            box-shadow: 0 0 0 4px rgba(255, 193, 7, 0.14);
        }

        .login-title {
            font-size: 2.1rem;
            font-weight: 800;
            line-height: 1.18;
            margin-bottom: 16px;
            letter-spacing: -0.03em;
            max-width: 470px;
        }

        .login-desc {
            color: rgba(255, 255, 255, 0.82);
            line-height: 1.72;
            max-width: 460px;
            font-size: .96rem;
            margin-bottom: 0;
        }

        .login-feature {
            margin-top: 30px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 13px;
            padding: 14px 15px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(8px);
        }

        .feature-icon {
            width: 40px;
            height: 40px;
            border-radius: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 193, 7, 0.14);
            color: #ffd166;
            flex-shrink: 0;
            font-size: 1rem;
            border: 1px solid rgba(255, 193, 7, 0.16);
        }

        .feature-title {
            font-weight: 700;
            margin-bottom: 3px;
            color: #fff;
        }

        .feature-text {
            color: rgba(255, 255, 255, 0.70);
            font-size: .9rem;
            line-height: 1.5;
        }

        .login-left-footer {
            margin-top: 28px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: rgba(255, 255, 255, 0.74);
            font-size: .88rem;
        }

        .footer-mark {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.09);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffd166;
            font-weight: 800;
            border: 1px solid rgba(255, 255, 255, 0.10);
        }

        .login-right {
            padding: 44px 38px;
            background:
                linear-gradient(180deg, #ffffff 0%, #fffdf9 100%);
        }

        .form-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 8px 14px;
            background: #fff6e8;
            color: #9a640b;
            font-size: .82rem;
            font-weight: 700;
            border: 1px solid rgba(255, 152, 0, 0.16);
            margin-bottom: 16px;
        }

        .form-title {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--dark-1);
            margin-bottom: 8px;
            letter-spacing: -0.02em;
        }

        .form-subtitle {
            color: var(--text-soft);
            margin-bottom: 28px;
            line-height: 1.65;
            font-size: .94rem;
        }

        .alert {
            border: none;
            border-radius: 16px;
            padding: 14px 16px;
            font-size: .92rem;
            box-shadow: 0 10px 24px rgba(17, 17, 17, 0.04);
        }

        .alert-danger {
            background: #fff1ef;
            color: #c2412d;
        }

        .alert-success {
            background: #eefaf0;
            color: #2f7d43;
        }

        .form-label {
            font-weight: 700;
            color: var(--dark-1);
            margin-bottom: .55rem;
        }

        .input-shell {
            position: relative;
        }

        .input-icon {
            position: absolute;
            top: 50%;
            left: 15px;
            transform: translateY(-50%);
            color: #c27e12;
            font-size: 1rem;
            z-index: 2;
        }

        .form-control {
            border-radius: 16px;
            padding: .95rem 1rem .95rem 2.85rem;
            border: 1px solid #e6dfd2;
            background: #fff;
            box-shadow: none;
            font-size: .95rem;
            transition: all .2s ease;
        }

        .form-control:focus {
            border-color: #f0c63d;
            box-shadow: 0 0 0 0.22rem rgba(255, 193, 7, 0.14);
            background: #fffdfa;
        }

        .btn-login {
            background: linear-gradient(135deg, var(--orange-1), var(--orange-3));
            border: none;
            color: #fff;
            font-weight: 800;
            border-radius: 16px;
            padding: .95rem 1rem;
            box-shadow: var(--shadow-strong);
            transition: all .22s ease;
            letter-spacing: .01em;
        }

        .btn-login:hover {
            color: #fff;
            transform: translateY(-1px);
            filter: brightness(.98);
        }

        .system-note {
            margin-top: 24px;
            background: linear-gradient(180deg, #fffaf3 0%, #fff6ea 100%);
            border: 1px solid rgba(255, 152, 0, 0.12);
            border-radius: 18px;
            padding: 15px 16px;
            color: var(--text-soft);
            font-size: .91rem;
            line-height: 1.6;
        }

        .note-text {
            color: var(--text-soft);
            font-size: .88rem;
            text-align: center;
            margin-top: 18px;
            font-weight: 600;
        }

        .note-text span {
            color: #9a640b;
        }

        @media (max-width: 991.98px) {
            .login-left {
                padding: 34px 28px;
            }

            .login-right {
                padding: 34px 28px;
            }

            .login-title {
                font-size: 1.75rem;
            }

            .form-title {
                font-size: 1.45rem;
            }
        }

        @media (max-width: 767.98px) {
            body {
                padding: 16px;
            }

            .login-card {
                border-radius: 22px;
            }

            .login-left,
            .login-right {
                padding: 26px 22px;
            }

            .login-title {
                font-size: 1.5rem;
            }

            .brand-badge,
            .form-badge {
                font-size: .78rem;
            }
        }
    </style>
</head>

<body>
    <div class="login-wrap">
        <div class="row g-0 login-card">

            <div class="col-lg-6">
                <div class="login-left">
                    <div class="login-left-content">
                        <div class="brand-badge">
                            <span class="brand-dot"></span>
                            <span>IT Asset Management System</span>
                        </div>

                        <div class="login-title">
                            Selamat Datang di Sistem Inventaris Perusahaan
                        </div>

                        <div class="login-desc">
                            Kelola data aset, pantau aktivitas inventaris, dan atur kontrol akses pengguna
                            dalam satu sistem yang lebih terpusat, modern, dan efisien untuk operasional harian.
                        </div>

                        <div class="login-feature">
                            <div class="feature-item">
                                <div class="feature-icon">✓</div>
                                <div>
                                    <div class="feature-title">Kontrol Akses Terpusat</div>
                                    <div class="feature-text">Hak akses pengguna dikelola berdasarkan role dan permission yang sudah ditentukan.</div>
                                </div>
                            </div>

                            <div class="feature-item">
                                <div class="feature-icon">✓</div>
                                <div>
                                    <div class="feature-title">Monitoring Inventaris</div>
                                    <div class="feature-text">Pantau barang masuk, barang keluar, kondisi aset, serta proses pengiriman secara lebih rapi.</div>
                                </div>
                            </div>

                            <div class="feature-item">
                                <div class="feature-icon">✓</div>
                                <div>
                                    <div class="feature-title">Siap untuk Operasional</div>
                                    <div class="feature-text">Dirancang untuk membantu proses inventaris perusahaan agar lebih tertib, cepat, dan terstruktur.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="login-left-footer">
                        <div class="footer-mark">IT</div>
                        <div>Sistem inventaris internal untuk pengelolaan aset teknologi perusahaan.</div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="login-right">
                    <div class="form-badge">
                        <span>Secure Access</span>
                    </div>

                    <div class="form-title">Masuk ke Akun</div>
                    <div class="form-subtitle">
                        Gunakan username atau email yang telah terdaftar untuk mengakses dashboard dan fitur sistem.
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
                            <div class="input-shell">
                                <span class="input-icon">@</span>
                                <input type="text" name="login" class="form-control" required autofocus placeholder="Masukkan username atau email">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <div class="input-shell">
                                <span class="input-icon">•</span>
                                <input type="password" name="password" class="form-control" required placeholder="Masukkan password">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-login w-100">
                            Masuk ke Dashboard
                        </button>
                    </form>

                    <div class="system-note">
                        Akun pengguna dibuat dan hak akses sistem dikelola oleh <b>Super Admin</b>.
                        Pastikan Anda menggunakan akun yang sudah terdaftar.
                    </div>

                    <div class="note-text">
                        <span>IT Asset Management</span> — Internal System Access
                    </div>
                </div>
            </div>

        </div>
    </div>
</body>

</html>