<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

if (is_logged_in()) {
    redirect_to(base_url('dashboard/index.php'));
}

$error   = get_flash('error');
$success = get_flash('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $email    = trim((string) ($_POST['email']    ?? ''));
    $alasan   = trim((string) ($_POST['alasan']   ?? ''));

    // Validasi input
    if ($username === '' || $email === '') {
        set_flash('error', 'Username dan email wajib diisi.');
        redirect_to(base_url('auth/forgot_password.php'));
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        set_flash('error', 'Format email tidak valid.');
        redirect_to(base_url('auth/forgot_password.php'));
    }

    // Cek apakah user dengan username + email tersebut ada
    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT id FROM users WHERE BINARY username = ? AND BINARY email = ? LIMIT 1"
    );

    if (!$stmt) {
        set_flash('error', 'Terjadi kesalahan pada sistem.');
        redirect_to(base_url('auth/forgot_password.php'));
    }

    mysqli_stmt_bind_param($stmt, 'ss', $username, $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user   = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$user) {
        set_flash('error', 'Username dan email tidak cocok. Pastikan data yang dimasukkan benar.');
        redirect_to(base_url('auth/forgot_password.php'));
    }

    $userId = (int) $user['id'];

    // Cek apakah sudah ada pengajuan pending dari user ini
    $stmtCek = mysqli_prepare(
        $koneksi,
        "SELECT id FROM password_reset_requests
         WHERE user_id = ? AND status = 'pending'
         LIMIT 1"
    );

    if ($stmtCek) {
        mysqli_stmt_bind_param($stmtCek, 'i', $userId);
        mysqli_stmt_execute($stmtCek);
        $resCek   = mysqli_stmt_get_result($stmtCek);
        $existing = mysqli_fetch_assoc($resCek);
        mysqli_stmt_close($stmtCek);

        if ($existing) {
            set_flash('error', 'Pengajuan reset password Anda masih dalam antrian. Silakan tunggu konfirmasi dari admin.');
            redirect_to(base_url('auth/forgot_password.php'));
        }
    }

    // Simpan pengajuan baru dengan status pending
    $stmtInsert = mysqli_prepare(
        $koneksi,
        "INSERT INTO password_reset_requests (user_id, alasan, status, requested_at)
         VALUES (?, ?, 'pending', NOW())"
    );

    if (!$stmtInsert) {
        set_flash('error', 'Terjadi kesalahan saat menyimpan pengajuan.');
        redirect_to(base_url('auth/forgot_password.php'));
    }

    mysqli_stmt_bind_param($stmtInsert, 'is', $userId, $alasan);
    $ok = mysqli_stmt_execute($stmtInsert);
    mysqli_stmt_close($stmtInsert);

    if (!$ok) {
        set_flash('error', 'Gagal menyimpan pengajuan. Coba lagi.');
        redirect_to(base_url('auth/forgot_password.php'));
    }

    set_flash('success', 'Pengajuan reset password berhasil dikirim. Silakan tunggu konfirmasi dari administrator.');
    redirect_to(base_url('auth/forgot_password.php'));
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Password - IT Asset Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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
            --shadow-soft: 0 16px 40px rgba(17, 17, 17, 0.08);
            --shadow-strong: 0 22px 54px rgba(255, 122, 0, 0.16);
            --radius-xl: 28px;
            --radius-lg: 22px;
            --radius-md: 16px;
        }

        * { box-sizing: border-box; }

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

        .login-wrap { width: 100%; max-width: 1120px; }

        .login-card {
            background: var(--surface);
            border: 1px solid rgba(255, 176, 0, 0.14);
            border-radius: var(--radius-xl);
            overflow: hidden;
            box-shadow: var(--shadow-soft);
        }

        /* ── LEFT PANEL ── */
        .login-left {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(17,17,17,0.96) 0%, rgba(42,42,42,0.92) 34%, rgba(255,122,0,0.96) 100%);
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
            top: -80px; right: -70px;
            width: 220px; height: 220px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
        }

        .login-left::after {
            content: "";
            position: absolute;
            bottom: -70px; left: -60px;
            width: 180px; height: 180px;
            border-radius: 50%;
            background: rgba(255,209,102,0.18);
        }

        .login-left-content,
        .login-left-footer { position: relative; z-index: 2; }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 9px 15px;
            border-radius: 999px;
            background: rgba(255,193,7,0.12);
            color: #ffe082;
            font-size: .84rem;
            font-weight: 700;
            margin-bottom: 22px;
            border: 1px solid rgba(255,193,7,0.18);
        }

        .brand-dot {
            width: 10px; height: 10px;
            border-radius: 999px;
            background: #ffc107;
            box-shadow: 0 0 0 4px rgba(255,193,7,0.14);
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
            color: rgba(255,255,255,0.82);
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
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .feature-icon {
            width: 40px; height: 40px;
            border-radius: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,193,7,0.14);
            color: #ffd166;
            flex-shrink: 0;
            font-size: 1rem;
            border: 1px solid rgba(255,193,7,0.16);
        }

        .feature-title { font-weight: 700; margin-bottom: 3px; color: #fff; }

        .feature-text {
            color: rgba(255,255,255,0.70);
            font-size: .9rem;
            line-height: 1.5;
        }

        .login-left-footer {
            margin-top: 28px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: rgba(255,255,255,0.74);
            font-size: .88rem;
        }

        .footer-mark {
            width: 42px; height: 42px;
            border-radius: 14px;
            background: rgba(255,255,255,0.09);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffd166;
            font-weight: 800;
            border: 1px solid rgba(255,255,255,0.10);
        }

        /* ── RIGHT PANEL ── */
        .login-right {
            padding: 44px 38px;
            background: linear-gradient(180deg, #ffffff 0%, #fffdf9 100%);
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
            border: 1px solid rgba(255,152,0,0.16);
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
            box-shadow: 0 10px 24px rgba(17,17,17,0.04);
        }

        .alert-danger  { background: #fff1ef; color: #c2412d; }
        .alert-success { background: #eefaf0; color: #2f7d43; }

        .form-label {
            font-weight: 700;
            color: var(--dark-1);
            margin-bottom: .55rem;
        }

        .input-shell { position: relative; }

        .input-icon {
            position: absolute;
            top: 50%; left: 15px;
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

        textarea.form-control {
            padding-top: .85rem;
            resize: none;
        }

        .form-control:focus {
            border-color: #f0c63d;
            box-shadow: 0 0 0 0.22rem rgba(255,193,7,0.14);
            background: #fffdfa;
        }

        /* Textarea tidak perlu padding kiri extra untuk icon */
        textarea.form-control {
            padding-left: 1rem;
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
            border: 1px solid rgba(255,152,0,0.12);
            border-radius: 18px;
            padding: 15px 16px;
            color: var(--text-soft);
            font-size: .91rem;
            line-height: 1.6;
        }

        /* Status tracker */
        .status-tracker {
            display: flex;
            align-items: center;
            gap: 0;
            margin-bottom: 28px;
        }

        .status-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            position: relative;
        }

        .status-step:not(:last-child)::after {
            content: "";
            position: absolute;
            top: 18px;
            left: 60%;
            width: 80%;
            height: 2px;
            background: #e9dfd0;
        }

        .status-dot {
            width: 36px; height: 36px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .88rem;
            font-weight: 800;
            background: #f3ece2;
            color: #9a8060;
            border: 2px solid #e4d8c5;
            margin-bottom: 6px;
            position: relative;
            z-index: 1;
        }

        .status-step.active .status-dot {
            background: linear-gradient(135deg, var(--orange-1), var(--orange-3));
            color: #fff;
            border-color: transparent;
            box-shadow: 0 8px 18px rgba(255,152,0,0.24);
        }

        .status-label {
            font-size: .74rem;
            font-weight: 700;
            color: #9a8060;
            text-align: center;
        }

        .status-step.active .status-label { color: var(--orange-1); }

        .back-link {
            color: #c27e12;
            font-weight: 700;
            text-decoration: none;
        }

        .back-link:hover { color: #a96708; }

        .note-text {
            color: var(--text-soft);
            font-size: .88rem;
            text-align: center;
            margin-top: 18px;
            font-weight: 600;
        }

        .note-text span { color: #9a640b; }

        @media (max-width: 991.98px) {
            .login-left  { padding: 34px 28px; }
            .login-right { padding: 34px 28px; }
            .login-title { font-size: 1.75rem; }
            .form-title  { font-size: 1.45rem; }
        }

        @media (max-width: 767.98px) {
            body { padding: 16px; }
            .login-card { border-radius: 22px; }
            .login-left, .login-right { padding: 26px 22px; }
            .login-title { font-size: 1.5rem; }
        }
    </style>
</head>

<body>
    <div class="login-wrap">
        <div class="row g-0 login-card">

            <!-- LEFT PANEL -->
            <div class="col-lg-6">
                <div class="login-left">
                    <div class="login-left-content">
                        <div class="brand-badge">
                            <span class="brand-dot"></span>
                            <span>IT Asset Management System</span>
                        </div>

                        <div class="login-title">
                            Lupa Password? Ajukan ke Administrator
                        </div>

                        <div class="login-desc">
                            Tidak perlu khawatir. Isi form pengajuan dan administrator akan
                            membuatkan password baru untuk akun Anda secepatnya.
                        </div>

                        <div class="login-feature">
                            <div class="feature-item">
                                <div class="feature-icon"><i class="bi bi-send"></i></div>
                                <div>
                                    <div class="feature-title">1. Kirim Pengajuan</div>
                                    <div class="feature-text">Isi username, email, dan alasan lupa password. Pengajuan akan langsung masuk ke administrator.</div>
                                </div>
                            </div>

                            <div class="feature-item">
                                <div class="feature-icon"><i class="bi bi-shield-check"></i></div>
                                <div>
                                    <div class="feature-title">2. Admin Proses</div>
                                    <div class="feature-text">Administrator akan memverifikasi dan membuatkan password baru untuk akun Anda.</div>
                                </div>
                            </div>

                            <div class="feature-item">
                                <div class="feature-icon"><i class="bi bi-key"></i></div>
                                <div>
                                    <div class="feature-title">3. Login & Ganti Password</div>
                                    <div class="feature-text">Gunakan password baru dari admin untuk login, lalu Anda akan diminta membuat password sendiri.</div>
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

            <!-- RIGHT PANEL -->
            <div class="col-lg-6">
                <div class="login-right">
                    <div class="form-badge">
                        <i class="bi bi-envelope-exclamation"></i>
                        <span>Pengajuan Reset Password</span>
                    </div>

                    <div class="form-title">Ajukan Reset Password</div>
                    <div class="form-subtitle">
                        Isi form berikut. Administrator akan memproses pengajuan Anda dan memberikan password baru.
                    </div>

                    <!-- Status Tracker -->
                    <div class="status-tracker">
                        <div class="status-step active">
                            <div class="status-dot"><i class="bi bi-send"></i></div>
                            <div class="status-label">Kirim<br>Pengajuan</div>
                        </div>
                        <div class="status-step">
                            <div class="status-dot"><i class="bi bi-hourglass"></i></div>
                            <div class="status-label">Tunggu<br>Admin</div>
                        </div>
                        <div class="status-step">
                            <div class="status-dot"><i class="bi bi-key"></i></div>
                            <div class="status-label">Dapat<br>Password</div>
                        </div>
                        <div class="status-step">
                            <div class="status-dot"><i class="bi bi-check2"></i></div>
                            <div class="status-label">Ganti<br>Password</div>
                        </div>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-circle me-2"></i><?= e($error) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle me-2"></i><?= e($success) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" id="formPengajuan">
                        <div class="mb-3">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <div class="input-shell">
                                <span class="input-icon"><i class="bi bi-person"></i></span>
                                <input
                                    type="text"
                                    name="username"
                                    class="form-control"
                                    required
                                    placeholder="Masukkan username akun Anda"
                                    autocomplete="username">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <div class="input-shell">
                                <span class="input-icon"><i class="bi bi-envelope"></i></span>
                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    required
                                    placeholder="Masukkan email akun Anda"
                                    autocomplete="email">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Alasan / Keterangan <span style="color:#9a8060; font-weight:600;">(opsional)</span></label>
                            <textarea
                                name="alasan"
                                class="form-control"
                                rows="3"
                                placeholder="Ceritakan kendala yang Anda alami (opsional)..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-login w-100" id="btnKirim">
                            <i class="bi bi-send me-2"></i>Kirim Pengajuan
                        </button>
                    </form>

                    <div class="text-center mt-3">
                        <a href="<?= e(base_url('auth/login.php')) ?>" class="back-link">
                            <i class="bi bi-arrow-left me-1"></i>Kembali ke Login
                        </a>
                    </div>

                    <div class="system-note">
                        <i class="bi bi-info-circle me-1"></i>
                        Pengajuan hanya dapat diproses oleh <b>Administrator</b>. Pastikan username dan email
                        yang dimasukkan sesuai dengan data akun terdaftar di sistem.
                    </div>

                    <div class="note-text">
                        <span>IT Asset Management</span> — Password Reset Request
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // Konfirmasi sebelum kirim pengajuan
            const form   = document.getElementById('formPengajuan');
            const btnKirim = document.getElementById('btnKirim');

            if (form && btnKirim) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();

                    const username = form.querySelector('[name="username"]').value.trim();
                    const email    = form.querySelector('[name="email"]').value.trim();

                    if (!username || !email) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Data belum lengkap',
                            text: 'Username dan email wajib diisi.',
                            confirmButtonColor: '#ff9800'
                        });
                        return;
                    }

                    Swal.fire({
                        title: 'Kirim Pengajuan?',
                        html: 'Pengajuan reset password untuk akun <b>' + username + '</b> akan dikirim ke administrator.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Ya, kirim',
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#ff9800',
                        cancelButtonColor: '#6c757d'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            form.submit();
                        }
                    });
                });
            }

            <?php if ($success): ?>
            // Tampilkan SweetAlert sukses setelah redirect
            Swal.fire({
                icon: 'success',
                title: 'Pengajuan Terkirim!',
                html: 'Pengajuan reset password Anda sudah diterima.<br><b>Silakan tunggu konfirmasi dari administrator.</b>',
                confirmButtonText: 'OK, mengerti',
                confirmButtonColor: '#ff9800',
                timer: 6000,
                timerProgressBar: true
            });
            <?php endif; ?>

            <?php if ($error): ?>
            Swal.fire({
                icon: 'error',
                title: 'Gagal',
                text: '<?= addslashes(e($error)) ?>',
                confirmButtonColor: '#ff9800'
            });
            <?php endif; ?>
        });
    </script>
</body>

</html>