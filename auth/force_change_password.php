<?php
include '../config/koneksi.php';
require_once '../config/auth.php';
/** @var mysqli $koneksi */
if (!is_logged_in()) {
    redirect_to(base_url('auth/login.php'));
}

$userId = (int) current_user_id();

// Ambil password saat ini dan status wajib ganti password
$stmt = mysqli_prepare($koneksi, "SELECT password, must_change_password FROM users WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt); 
$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$row || (int) $row['must_change_password'] === 0) {
    redirect_to(base_url('dashboard/index.php'));
}

$error   = get_flash('error');
$success = get_flash('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword     = (string) ($_POST['new_password']     ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($newPassword === '' || $confirmPassword === '') {
        set_flash('error', 'Semua field wajib diisi.');
        redirect_to(base_url('auth/force_change_password.php'));
    }

    // --- VALIDASI KOMBINASI PASSWORD ---
    require_once __DIR__ . '/../config/validation_helper.php';
    $passwordError = validate_password_strength($newPassword);
    if ($passwordError !== null) {
        set_flash('error', $passwordError);
        redirect_to(base_url('auth/force_change_password.php'));
    }

    if ($newPassword !== $confirmPassword) {
        set_flash('error', 'Konfirmasi password tidak sama.');
        redirect_to(base_url('auth/force_change_password.php'));
    }

    // --- PENGECEKAN PASSWORD LAMA ---
    $isReused = false;
    
    // 1. Cek apakah password sama dengan password saat ini (di tabel users)
    if (password_verify($newPassword, $row['password'])) {
        $isReused = true;
    }

    // 2. Cek apakah password sama dengan riwayat sebelumnya (ambil 5 password terakhir)
    if (!$isReused) {
        $stmtHist = mysqli_prepare($koneksi, "SELECT password_hash FROM user_password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        mysqli_stmt_bind_param($stmtHist, 'i', $userId);
        mysqli_stmt_execute($stmtHist);
        $resHist = mysqli_stmt_get_result($stmtHist);
        while ($rowHist = mysqli_fetch_assoc($resHist)) {
            if (password_verify($newPassword, $rowHist['password_hash'])) {
                $isReused = true;
                break;
            }
        }
        mysqli_stmt_close($stmtHist);
    }

    // Jika ketahuan menggunakan password lama
    if ($isReused) {
        set_flash('error', 'Password ini sebelumnya sudah pernah digunakan. Demi keamanan, silakan gunakan kombinasi password yang baru.');
        redirect_to(base_url('auth/force_change_password.php'));
    }

    // --- PROSES SIMPAN PASSWORD BARU ---
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $mustChangePassword = 0;

    // Update tabel users
    $stmtUpdate = mysqli_prepare(
        $koneksi,
        "UPDATE users SET password = ?, must_change_password = ?, password_changed_at = NOW() WHERE id = ?"
    );

    if (!$stmtUpdate) {
        set_flash('error', 'Terjadi kesalahan pada sistem saat update password.');
        redirect_to(base_url('auth/force_change_password.php'));
    }

    mysqli_stmt_bind_param($stmtUpdate, 'sii', $hash, $mustChangePassword, $userId);
    mysqli_stmt_execute($stmtUpdate);
    mysqli_stmt_close($stmtUpdate);

    // Simpan password baru ke tabel riwayat (agar tidak bisa dipakai lagi kedepannya)
    $stmtInsertHist = mysqli_prepare($koneksi, "INSERT INTO user_password_history (user_id, password_hash) VALUES (?, ?)");
    if ($stmtInsertHist) {
        mysqli_stmt_bind_param($stmtInsertHist, 'is', $userId, $hash);
        mysqli_stmt_execute($stmtInsertHist);
        mysqli_stmt_close($stmtInsertHist);
    }

    // Update session agar tidak redirect lagi
    if (isset($_SESSION['user'])) {
        $_SESSION['user']['must_change_password'] = 0;
    }

    set_flash('success', 'Password berhasil diperbarui. Selamat datang!');
    redirect_to(base_url('dashboard/index.php'));
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ganti Password - IT Asset Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/password-fields.css')) ?>">
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

        .form-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 8px;
            padding: 6px 12px;
            background: rgba(230, 67, 18, 0.1);
            color: var(--orange-primary);
            font-size: .82rem;
            font-weight: 700;
            margin-bottom: 16px;
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
        
        .form-control:focus + .input-icon,
        .input-shell:focus-within .input-icon {
            color: var(--orange-primary);
        }

        /* Saran Password Box */
        .suggestion-box {
            background: var(--bg-body);
            border: 1px dashed var(--border-color);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .suggestion-text { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 4px; font-weight: 600;}
        .suggestion-pass { 
            font-family: monospace; 
            font-weight: 800; 
            color: var(--dark-main); 
            font-size: 1.2rem; 
            letter-spacing: 2px; 
            background: #ffffff; 
            padding: 4px 10px; 
            border-radius: 8px; 
            border: 1px solid var(--border-color); 
            user-select: all; 
            display: inline-block;
        }
        .btn-use-suggestion { 
            background: var(--orange-primary); 
            color: white; 
            border: none; 
            padding: 8px 16px; 
            border-radius: 8px; 
            font-size: 0.85rem; 
            font-weight: 700; 
            cursor: pointer; 
            transition: 0.2s; 
            display: flex; 
            align-items: center; 
            gap: 6px;
        }
        .btn-use-suggestion:hover { background: var(--orange-hover); transform: translateY(-1px); }

        /* Peringatan Aturan Password Baru (List Checklist) */
        .password-rules-box {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
        }
        .rule-title {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 8px;
        }
        .rule-item {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        .rule-item:last-child { margin-bottom: 0; }
        .rule-item i { font-size: 1rem; transition: color 0.3s ease; }
        /* Warna saat terpenuhi */
        .rule-item.valid { color: #15803d; }
        .rule-item.valid i { color: #22c55e; }

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

        .system-note {
            margin-top: 24px;
            background: var(--bg-body);
            border-radius: 8px;
            padding: 14px 16px;
            color: var(--text-muted);
            font-size: 0.85rem;
            line-height: 1.5;
            border-left: 4px solid var(--border-color);
        }

        /* strength bar */
        .strength-bar { height: 6px; border-radius: 999px; background: var(--border-color); margin-top: 12px; overflow: hidden; }
        .strength-fill { height: 100%; border-radius: 999px; width: 0%; transition: width .3s ease, background .3s ease; }

        @media (max-width: 991.98px) { 
            .login-left, .login-right { padding: 32px 24px; } 
            .login-title { font-size: 1.6rem; } 
            .form-title { font-size: 1.4rem; } 
        }
        @media (max-width: 767.98px) { 
            .suggestion-box { flex-direction: column; gap: 12px; align-items: stretch; text-align: center;} 
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
                            Wajib Ganti Password
                        </div>

                        <div class="login-desc">
                            Akun Anda saat ini menggunakan password bawaan dari sistem. Demi keamanan, buat password baru sebelum mengakses dashboard.
                        </div>

                        <div class="login-feature">
                            <div class="feature-item">
                                <div class="feature-icon"><i class="bi bi-shield-lock"></i></div>
                                <div>
                                    <div class="feature-title">Keamanan Ketat</div>
                                    <div class="feature-text">Password Anda tidak boleh sama dengan password yang pernah digunakan sebelumnya.</div>
                                </div>
                            </div>

                            <div class="feature-item">
                                <div class="feature-icon"><i class="bi bi-key"></i></div>
                                <div>
                                    <div class="feature-title">Kombinasi Kuat</div>
                                    <div class="feature-text">Gunakan kombinasi yang disarankan sistem agar akun Anda lebih aman dan sulit ditebak.</div>
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
                    <div class="form-badge">
                        <i class="bi bi-shield-exclamation"></i>
                        <span>Ganti Password Wajib</span>
                    </div>

                    <div class="form-title">Buat Password Baru</div>
                    <div class="form-subtitle">
                        Buat password baru. Password lama tidak dapat digunakan kembali.
                    </div>

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

                    <!-- Kotak Saran Password -->
                    <div class="suggestion-box">
                        <div>
                            <div class="suggestion-text">Saran Kombinasi Kuat:</div>
                            <div class="suggestion-pass" id="suggestedPasswordTxt">Memuat...</div>
                        </div>
                        <button type="button" class="btn-use-suggestion" id="btnUseSuggestion">
                            <i class="bi bi-magic"></i> Gunakan
                        </button>
                    </div>

                    <form method="POST" action="" id="passwordForm">
                        <div class="mb-3">
                            <label class="form-label">Password Baru</label>
                            <div class="input-shell password-shell">
                                <i class="bi bi-lock-fill input-icon"></i>
                                <input
                                    type="text"
                                    name="new_password"
                                    id="newPassword"
                                    class="form-control password-field pw-masked"
                                    required
                                    minlength="8"
                                    placeholder="Masukkan password baru"
                                    autocomplete="new-password"
                                    spellcheck="false">
                                <button type="button" class="password-toggle" data-target="newPassword" aria-label="Lihat password baru">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            
                            <!-- Strength Indicator Bar -->
                            <div class="strength-bar">
                                <div class="strength-fill" id="strengthFill"></div>
                            </div>

                            <!-- CHECKLIST ATURAN PASSWORD -->
                            <div class="password-rules-box">
                                <div class="rule-title">Password harus mengandung:</div>
                                <div class="rule-item" id="rule-length">
                                    <i class="bi bi-x-circle text-danger"></i> Minimal 8 Karakter
                                </div>
                                <div class="rule-item" id="rule-upper">
                                    <i class="bi bi-x-circle text-danger"></i> Minimal 1 Huruf Besar (A-Z)
                                </div>
                                <div class="rule-item" id="rule-lower">
                                    <i class="bi bi-x-circle text-danger"></i> Minimal 1 Huruf Kecil (a-z)
                                </div>
                                <div class="rule-item" id="rule-number">
                                    <i class="bi bi-x-circle text-danger"></i> Minimal 1 Angka (0-9)
                                </div>
                                <div class="rule-item" id="rule-symbol">
                                    <i class="bi bi-x-circle text-danger"></i> Minimal 1 Simbol Khusus (!@#$%^&*)
                                </div>
                            </div>
                        </div>

                        <div class="mb-4 mt-4">
                            <label class="form-label">Konfirmasi Password Baru</label>
                            <div class="input-shell password-shell">
                                <i class="bi bi-check-circle-fill input-icon"></i>
                                <input
                                    type="text"
                                    name="confirm_password"
                                    id="confirmPassword"
                                    class="form-control password-field pw-masked"
                                    required
                                    minlength="8"
                                    placeholder="Ulangi password baru"
                                    autocomplete="new-password"
                                    spellcheck="false">
                                <button type="button" class="password-toggle" data-target="confirmPassword" aria-label="Lihat konfirmasi password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div id="matchMsg" style="font-size:0.85rem; margin-top:6px; font-weight:700;"></div>
                        </div>

                        <button type="submit" class="btn btn-login w-100" id="btnSubmit">
                            Simpan Password Baru <i class="bi bi-arrow-right ms-2"></i>
                        </button>
                    </form>

                    <div class="system-note">
                        <i class="bi bi-info-circle me-1 text-muted"></i>
                        Halaman ini <b>tidak dapat dilewati</b>. Anda diwajibkan untuk menyimpan password baru terlebih dahulu sebelum mengakses sistem utama.
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="<?= e(base_url('assets/js/password-fields.js')) ?>"></script>
    <!-- SCRIPT LOGIKA CHECKLIST DAN VALIDASI -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // --- 1. Fungsi Membuat Password Acak Kuat ---
            const suggestedPass = PasswordFields.generateStrongPassword();
            document.getElementById('suggestedPasswordTxt').textContent = suggestedPass;

            // Tombol "Gunakan Password Ini"
            document.getElementById('btnUseSuggestion').addEventListener('click', function() {
                const newPassInput = document.getElementById('newPassword');
                const confPassInput = document.getElementById('confirmPassword');
                
                newPassInput.type = 'text';
                confPassInput.type = 'text';
                newPassInput.classList.remove('pw-masked');
                confPassInput.classList.remove('pw-masked');

                newPassInput.value = suggestedPass;
                confPassInput.value = suggestedPass;

                document.querySelectorAll('.password-toggle i').forEach(icon => {
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                });

                newPassInput.dispatchEvent(new Event('input'));
                confPassInput.dispatchEvent(new Event('input'));
            });

            PasswordFields.init(document);

            // --- 2. LOGIKA CHECKLIST ATURAN PASSWORD & STRENGTH BAR ---
            const newPasswordInput = document.getElementById('newPassword');
            const strengthFill     = document.getElementById('strengthFill');
            
            // Definisikan regex untuk setiap rule
            const rules = {
                length: val => val.length >= 8,
                upper: val => /[A-Z]/.test(val),
                lower: val => /[a-z]/.test(val),
                number: val => /[0-9]/.test(val),
                symbol: val => /[^A-Za-z0-9]/.test(val)
            };

            let isPasswordStrong = false; // Flag status kombinasi

            newPasswordInput.addEventListener('input', function () {
                const val = this.value;
                let score = 0;

                // Cek masing-masing aturan
                for (const key in rules) {
                    const el = document.getElementById(`rule-${key}`);
                    const icon = el.querySelector('i');
                    
                    if (rules[key](val)) {
                        el.classList.add('valid');
                        icon.className = 'bi bi-check-circle-fill';
                        score++;
                    } else {
                        el.classList.remove('valid');
                        icon.className = 'bi bi-x-circle text-danger';
                    }
                }

                // Update Status Global (True jika semua 5 syarat terpenuhi)
                isPasswordStrong = (score === 5);

                // Update Bar Kekuatan
                const levels =[
                    { pct: '0%',   color: '' },
                    { pct: '20%',  color: '#ef4444' }, // 1 Syarat: Merah
                    { pct: '40%',  color: '#f59e0b' }, // 2 Syarat: Oren
                    { pct: '60%',  color: '#eab308' }, // 3 Syarat: Kuning
                    { pct: '80%',  color: '#84cc16' }, // 4 Syarat: Hijau Muda
                    { pct: '100%', color: '#22c55e' }  // 5 Syarat: Hijau Full
                ];

                const level = val.length === 0 ? levels[0] : levels[score];
                strengthFill.style.width      = level.pct;
                strengthFill.style.background = level.color;
            });

            // --- 3. LOGIKA KECOCOKAN PASSWORD (CONFIRM) ---
            const confirmPasswordInput = document.getElementById('confirmPassword');
            const matchMsg             = document.getElementById('matchMsg');
            let isPasswordMatched      = false;

            function checkMatch() {
                if (confirmPasswordInput.value === '') {
                    matchMsg.textContent = '';
                    isPasswordMatched = false;
                    return;
                }
                if (newPasswordInput.value === confirmPasswordInput.value) {
                    matchMsg.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Password cocok';
                    matchMsg.style.color = '#22c55e'; // Hijau
                    isPasswordMatched = true;
                } else {
                    matchMsg.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i> Password tidak cocok';
                    matchMsg.style.color = '#ef4444'; // Merah
                    isPasswordMatched = false;
                }
            }

            newPasswordInput.addEventListener('input', checkMatch);
            confirmPasswordInput.addEventListener('input', checkMatch);

            // --- 4. CEGAT SUBMIT FORM JIKA SYARAT BELUM TERPENUHI ---
            document.getElementById('passwordForm').addEventListener('submit', function(e) {
                if (!isPasswordStrong) {
                    e.preventDefault(); // Hentikan proses simpan
                    Swal.fire({
                        icon: 'warning',
                        title: 'Kombinasi Lemah',
                        text: 'Pastikan password memenuhi semua persyaratan: minimal 8 karakter, huruf besar, huruf kecil, angka, dan simbol.',
                        confirmButtonColor: '#E64312'
                    });
                    return;
                }

                if (!isPasswordMatched) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Password Tidak Cocok',
                        text: 'Konfirmasi password harus sama dengan password baru.',
                        confirmButtonColor: '#E64312'
                    });
                    return;
                }
            });
        });
    </script>
</body>

</html>