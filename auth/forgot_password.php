<?php
include '../config/koneksi.php';
require_once '../config/auth.php';
/** @var mysqli $koneksi */
if (is_logged_in()) {
    redirect_to(base_url('dashboard/index.php'));
}

$error   = get_flash('error');
$success = get_flash('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? 'submit') === 'check_status') {
    $email = trim((string) ($_POST['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        set_flash('error', 'Masukkan email valid untuk cek status pengajuan.');
        redirect_to(base_url('auth/forgot_password.php'));
    }

    $_SESSION['forgot_password_track_email'] = $email;
    redirect_to(base_url('auth/forgot_password.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // HANYA MENGGUNAKAN EMAIL DAN ALASAN
    $email    = trim((string) ($_POST['email']    ?? ''));
    $alasan   = trim((string) ($_POST['alasan']   ?? ''));

    // Validasi input
    if ($email === '') {
        set_flash('error', 'Email wajib diisi.');
        redirect_to(base_url('auth/forgot_password.php'));
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        set_flash('error', 'Format email tidak valid.');
        redirect_to(base_url('auth/forgot_password.php'));
    }

    // Cek apakah user dengan email tersebut ada
    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT id, employment_status FROM users WHERE BINARY email = ? LIMIT 1"
    );

    if (!$stmt) {
        set_flash('error', 'Terjadi kesalahan pada sistem.');
        redirect_to(base_url('auth/forgot_password.php'));
    }

    // Bind parameter hanya untuk email ('s')
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user   = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$user) {
        set_flash('error', 'Email tidak terdaftar. Pastikan email yang dimasukkan benar.');
        redirect_to(base_url('auth/forgot_password.php'));
    }

    if (!is_employment_active($user['employment_status'] ?? 'active')) {
        set_flash('error', 'Akun ini sudah dinonaktifkan karena status tidak bekerja lagi. Hubungi administrator HO.');
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
            $_SESSION['forgot_password_track_email'] = $email;
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

    $_SESSION['forgot_password_track_email'] = $email;
    set_flash('success', 'Pengajuan reset password berhasil dikirim. Silakan tunggu konfirmasi dari administrator.');
    redirect_to(base_url('auth/forgot_password.php'));
}

$trackEmail = trim((string) ($_SESSION['forgot_password_track_email'] ?? ''));
$trackerState = null;
$currentStep = 1;
$formLocked = false;
$statusTitle = '';
$statusMessage = '';
$statusType = 'info';

if ($trackEmail !== '') {
    $stmtTrack = mysqli_prepare(
        $koneksi,
        "SELECT prr.id, prr.status, prr.alasan, prr.requested_at, prr.processed_at,
                u.username, u.must_change_password
         FROM users u
         INNER JOIN password_reset_requests prr ON prr.user_id = u.id
         WHERE BINARY u.email = ?
         ORDER BY prr.requested_at DESC
         LIMIT 1"
    );

    if ($stmtTrack) {
        mysqli_stmt_bind_param($stmtTrack, 's', $trackEmail);
        mysqli_stmt_execute($stmtTrack);
        $trackerState = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtTrack)) ?: null;
        mysqli_stmt_close($stmtTrack);
    }
}

if ($trackEmail !== '' && !$trackerState) {
    $statusType = 'info';
    $statusTitle = 'Belum Ada Pengajuan';
    $statusMessage = 'Tidak ditemukan pengajuan reset password untuk email <b>' . e($trackEmail) . '</b>. Silakan kirim pengajuan baru jika Anda membutuhkan reset password.';
} elseif ($trackerState) {
    $requestStatus = (string) ($trackerState['status'] ?? '');
    $mustChange = (int) ($trackerState['must_change_password'] ?? 0);
    $requestedAt = $trackerState['requested_at'] ?? null;
    $processedAt = $trackerState['processed_at'] ?? null;
    $requestedLabel = $requestedAt ? date('d M Y H:i', strtotime($requestedAt)) : '-';
    $processedLabel = $processedAt ? date('d M Y H:i', strtotime($processedAt)) : '-';

    if ($requestStatus === 'pending') {
        $currentStep = 2;
        $formLocked = true;
        $statusType = 'warning';
        $statusTitle = 'Menunggu Proses Admin';
        $statusMessage = 'Pengajuan untuk <b>' . e($trackEmail) . '</b> sudah dikirim pada <b>' . e($requestedLabel) . '</b>. Administrator sedang memverifikasi permintaan Anda.';
    } elseif ($requestStatus === 'selesai' && $mustChange === 1) {
        $currentStep = 3;
        $formLocked = true;
        $statusType = 'success';
        $statusTitle = 'Password Baru Sudah Dibuat';
        $statusMessage = 'Admin memproses pengajuan pada <b>' . e($processedLabel) . '</b>. Silakan login dengan password yang diberikan admin, lalu ganti password saat diminta sistem.';
    } elseif ($requestStatus === 'selesai') {
        $currentStep = 4;
        $formLocked = true;
        $statusType = 'success';
        $statusTitle = 'Akun Siap Digunakan';
        $statusMessage = 'Password akun Anda sudah aktif. Anda dapat login ke sistem seperti biasa.';
        unset($_SESSION['forgot_password_track_email']);
    }
}

if (!function_exists('forgot_password_step_class')) {
    function forgot_password_step_class(int $step, int $currentStep): string
    {
        if ($step < $currentStep) {
            return 'completed';
        }
        if ($step === $currentStep) {
            return 'active';
        }

        return 'pending';
    }
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
            font-size: 1.8rem;
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
            margin-bottom: 16px;
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
            margin-bottom: 24px;
            line-height: 1.5;
            font-size: 0.95rem;
        }

        /* Tracker Lupa Password */
        .status-tracker {
            display: flex;
            align-items: flex-start;
            gap: 0;
            margin-bottom: 24px;
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
            top: 16px;
            left: 55%;
            width: 90%;
            height: 2px;
            background: var(--border-color);
            transition: all 0.25s ease;
        }
        .status-step.completed:not(:last-child)::after,
        .status-step.active:not(:last-child)::after {
            background: var(--orange-primary);
            opacity: 0.45;
        }

        .status-dot {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .85rem;
            background: #ffffff;
            color: var(--text-muted);
            border: 2px solid var(--border-color);
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
            transition: all 0.25s ease;
        }
        .status-label {
            font-size: .72rem;
            font-weight: 700;
            color: var(--text-muted);
            text-align: center;
            line-height: 1.2;
        }
        .status-step.pending .status-dot {
            background: #f9fafb;
            color: #9ca3af;
            border-color: #e5e7eb;
        }
        .status-step.pending .status-label {
            color: #9ca3af;
            font-weight: 600;
        }
        .status-step.active .status-dot {
            background: var(--orange-primary);
            color: #ffffff;
            border-color: var(--orange-primary);
            box-shadow: 0 0 0 4px rgba(230, 67, 18, 0.15);
        }
        .status-step.active .status-label {
            color: var(--orange-primary);
        }
        .status-step.completed .status-dot {
            background: rgba(230, 67, 18, 0.12);
            color: var(--orange-primary);
            border-color: rgba(230, 67, 18, 0.35);
        }
        .status-step.completed .status-label {
            color: var(--orange-primary);
            opacity: 0.85;
        }

        .status-info-box {
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 24px;
            font-size: 0.9rem;
            line-height: 1.55;
            border-left: 4px solid var(--border-color);
        }
        .status-info-box.info {
            background: #f8fafc;
            color: var(--text-dark);
            border-left-color: #94a3b8;
        }
        .status-info-box.warning {
            background: #fff7ed;
            color: #9a3412;
            border-left-color: var(--orange-primary);
        }
        .status-info-box.success {
            background: #f0fdf4;
            color: #166534;
            border-left-color: #22c55e;
        }
        .status-info-title {
            font-weight: 800;
            margin-bottom: 4px;
            color: inherit;
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
        
        textarea.form-control {
            padding: 0.75rem 1rem; /* Textarea tidak butuh icon padding kiri */
            resize: vertical;
        }

        .form-control:focus {
            border-color: var(--orange-primary);
            box-shadow: 0 0 0 4px rgba(230, 67, 18, 0.1) !important;
        }
        
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

        .back-link {
            color: var(--orange-primary);
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            transition: 0.2s;
        }
        .back-link:hover { color: var(--orange-hover); text-decoration: underline; }

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

            <!-- LEFT PANEL -->
            <div class="col-lg-5 d-none d-lg-block">
                <div class="login-left">
                    <div class="login-left-content">
                        <div class="brand-badge">
                            <i class="bi bi-layers-fill"></i>
                            <span>IT Asset System</span>
                        </div>

                        <div class="login-title">
                            Ajukan Pemulihan Password
                        </div>

                        <div class="login-desc">
                            Isi form pengajuan dan administrator akan membuatkan password baru untuk akun Anda secepatnya.
                        </div>

                        <div>
                            <div class="feature-item">
                                <div class="feature-icon"><i class="bi bi-send"></i></div>
                                <div>
                                    <div class="feature-title">1. Kirim Pengajuan</div>
                                    <div class="feature-text">Isi data email. Pengajuan langsung dikirim ke admin.</div>
                                </div>
                            </div>

                            <div class="feature-item">
                                <div class="feature-icon"><i class="bi bi-person-check"></i></div>
                                <div>
                                    <div class="feature-title">2. Admin Proses</div>
                                    <div class="feature-text">Admin akan memverifikasi dan mereset password Anda.</div>
                                </div>
                            </div>

                            <div class="feature-item">
                                <div class="feature-icon"><i class="bi bi-key"></i></div>
                                <div>
                                    <div class="feature-title">3. Ganti Password</div>
                                    <div class="feature-text">Gunakan password baru dari admin untuk login kembali.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="login-left-footer">
                        &copy; <?= date('Y') ?> IT Asset Management Internal System.
                    </div>
                </div>
            </div>

            <!-- RIGHT PANEL -->
            <div class="col-lg-7">
                <div class="login-right">

                    <div class="form-title">Lupa Password</div>
                    <div class="form-subtitle">
                        Isi form berikut. Administrator akan memproses pengajuan Anda.
                    </div>

                    <!-- Status Tracker (dinamis sesuai progres pengajuan) -->
                    <div class="status-tracker">
                        <div class="status-step <?= forgot_password_step_class(1, $currentStep) ?>">
                            <div class="status-dot">
                                <i class="bi <?= $currentStep > 1 ? 'bi-check-lg' : 'bi-send' ?>"></i>
                            </div>
                            <div class="status-label">Kirim<br>Pengajuan</div>
                        </div>
                        <div class="status-step <?= forgot_password_step_class(2, $currentStep) ?>">
                            <div class="status-dot">
                                <i class="bi <?= $currentStep > 2 ? 'bi-check-lg' : 'bi-hourglass-split' ?>"></i>
                            </div>
                            <div class="status-label">Tunggu<br>Admin</div>
                        </div>
                        <div class="status-step <?= forgot_password_step_class(3, $currentStep) ?>">
                            <div class="status-dot">
                                <i class="bi <?= $currentStep > 3 ? 'bi-check-lg' : 'bi-envelope-paper' ?>"></i>
                            </div>
                            <div class="status-label">Dapat<br>Password</div>
                        </div>
                        <div class="status-step <?= forgot_password_step_class(4, $currentStep) ?>">
                            <div class="status-dot">
                                <i class="bi bi-check2"></i>
                            </div>
                            <div class="status-label">Siap<br>Digunakan</div>
                        </div>
                    </div>

                    <?php if ($statusTitle !== ''): ?>
                        <div class="status-info-box <?= e($statusType) ?>">
                            <div class="status-info-title"><?= e($statusTitle) ?></div>
                            <div><?= $statusMessage ?></div>
                            <?php if (!empty($trackerState['alasan'])): ?>
                                <div class="mt-2 pt-2" style="border-top: 1px dashed rgba(0,0,0,0.08); font-size: 0.85rem;">
                                    <i class="bi bi-chat-left-text me-1"></i>
                                    <em><?= e($trackerState['alasan']) ?></em>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($error) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill me-2"></i><?= e($success) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($formLocked): ?>
                        <div class="mb-3">
                            <label class="form-label">Email Terdaftar</label>
                            <div class="input-shell">
                                <i class="bi bi-envelope-fill input-icon"></i>
                                <input
                                    type="email"
                                    class="form-control"
                                    value="<?= e($trackEmail) ?>"
                                    readonly
                                    style="background: #f9fafb;">
                            </div>
                        </div>

                        <?php if ($currentStep === 3): ?>
                            <a href="<?= e(base_url('auth/login.php')) ?>" class="btn btn-login w-100">
                                Login Sekarang <i class="bi bi-box-arrow-in-right ms-2"></i>
                            </a>
                        <?php elseif ($currentStep === 4): ?>
                            <a href="<?= e(base_url('auth/login.php')) ?>" class="btn btn-login w-100">
                                Ke Halaman Login <i class="bi bi-box-arrow-in-right ms-2"></i>
                            </a>
                        <?php else: ?>
                            <button type="button" class="btn btn-login w-100" disabled style="opacity: 0.65; cursor: not-allowed;">
                                Menunggu Proses Admin <i class="bi bi-hourglass-split ms-2"></i>
                            </button>
                        <?php endif; ?>
                    <?php else: ?>
                    <form method="POST" action="" id="formPengajuan">
                        <div class="mb-3">
                            <label class="form-label">Email Terdaftar <span class="text-danger">*</span></label>
                            <div class="input-shell">
                                <i class="bi bi-envelope-fill input-icon"></i>
                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    required
                                    value="<?= e($trackEmail) ?>"
                                    placeholder="Masukkan email akun Anda"
                                    autocomplete="email">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Keterangan Tambahan <span class="text-muted fw-normal">(Opsional)</span></label>
                            <textarea
                                name="alasan"
                                class="form-control"
                                rows="2"
                                placeholder="Ceritakan alasan pengajuan (opsional)..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-login w-100" id="btnKirim">
                            Kirim Pengajuan <i class="bi bi-send-fill ms-2"></i>
                        </button>
                    </form>
                    <?php endif; ?>

                    <div class="text-center mt-3 d-flex flex-column gap-2">
                        <?php if (!$formLocked): ?>
                            <form method="POST" class="d-inline" id="formCekStatus">
                                <input type="hidden" name="action" value="check_status">
                                <input type="hidden" name="email" id="cekStatusEmail" value="<?= e($trackEmail) ?>">
                                <button type="submit" class="btn btn-link back-link p-0 border-0">
                                    <i class="bi bi-search me-1"></i> Cek Status Pengajuan
                                </button>
                            </form>
                        <?php endif; ?>
                        <a href="<?= e(base_url('auth/login.php')) ?>" class="back-link">
                            <i class="bi bi-arrow-left me-1"></i> Kembali ke Login
                        </a>
                    </div>

                    <div class="system-note">
                        <i class="bi bi-info-circle me-1 text-muted"></i>
                        Pengajuan hanya dapat diproses oleh <b>Administrator</b>. Pastikan email yang Anda masukkan valid.
                    </div>

                </div>
            </div>

        </div>
    </div>

    <!-- SCRIPT ALERT -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // Konfirmasi sebelum kirim pengajuan
            const form   = document.getElementById('formPengajuan');
            const btnKirim = document.getElementById('btnKirim');

            const formCekStatus = document.getElementById('formCekStatus');
            const cekStatusEmail = document.getElementById('cekStatusEmail');
            const emailInput = document.querySelector('#formPengajuan [name="email"]');

            if (formCekStatus && cekStatusEmail && emailInput) {
                formCekStatus.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const email = emailInput.value.trim();
                    if (!email) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Email Belum Diisi',
                            text: 'Isi email terdaftar terlebih dahulu untuk cek status.',
                            confirmButtonColor: '#E64312'
                        });
                        return;
                    }
                    cekStatusEmail.value = email;
                    formCekStatus.submit();
                });
            }

            if (form && btnKirim) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();

                    // Hanya mengambil nilai email
                    const email = form.querySelector('[name="email"]').value.trim();

                    if (!email) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Data Belum Lengkap',
                            text: 'Email wajib diisi.',
                            confirmButtonColor: '#E64312'
                        });
                        return;
                    }

                    Swal.fire({
                        title: 'Kirim Pengajuan?',
                        html: 'Pengajuan reset password untuk email <b>' + email + '</b> akan dikirim ke administrator.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Ya, Kirim',
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#E64312',
                        cancelButtonColor: '#6c757d',
                        reverseButtons: true
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
                confirmButtonText: 'OK, Mengerti',
                confirmButtonColor: '#E64312',
                timer: 6000,
                timerProgressBar: true
            });
            <?php endif; ?>

            <?php if ($error): ?>
            Swal.fire({
                icon: 'error',
                title: 'Gagal',
                text: '<?= addslashes(e($error)) ?>',
                confirmButtonColor: '#E64312'
            });
            <?php endif; ?>
        });
    </script>
</body>

</html>