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

    if (strlen($newPassword) < 8) {
        set_flash('error', 'Password baru minimal 8 karakter.');
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

        .password-shell .form-control { padding-right: 3.2rem; }
        .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            width: 32px;
            height: 32px;
            border: none;
            background: transparent;
            color: #9ca3af;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 3;
        }
        .password-toggle:hover { background: var(--bg-body); color: var(--dark-main); }
        .password-toggle:focus { outline: none; box-shadow: 0 0 0 2px rgba(230, 67, 18, 0.1); }

        /* Saran Password Box (Modernized) */
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
        .strength-bar { height: 6px; border-radius: 999px; background: var(--border-color); margin-top: 8px; overflow: hidden; }
        .strength-fill { height: 100%; border-radius: 999px; width: 0%; transition: width .3s ease, background .3s ease; }
        .strength-label { font-size: .8rem; font-weight: 700; margin-top: 4px; color: var(--text-muted); }

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

                    <!-- Kotak Saran Password Profesional -->
                    <div class="suggestion-box">
                        <div>
                            <div class="suggestion-text">Saran Kombinasi Kuat:</div>
                            <div class="suggestion-pass" id="suggestedPasswordTxt">Memuat...</div>
                        </div>
                        <button type="button" class="btn-use-suggestion" id="btnUseSuggestion">
                            <i class="bi bi-magic"></i> Gunakan
                        </button>
                    </div>

                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label">Password Baru</label>
                            <div class="input-shell password-shell">
                                <i class="bi bi-lock-fill input-icon"></i>
                                <input
                                    type="password"
                                    name="new_password"
                                    id="newPassword"
                                    class="form-control"
                                    required
                                    minlength="8"
                                    placeholder="Masukkan password baru"
                                    autocomplete="new-password">
                                <button type="button" class="password-toggle" data-target="newPassword" aria-label="Lihat password baru">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <!-- Strength indicator -->
                            <div class="strength-bar">
                                <div class="strength-fill" id="strengthFill"></div>
                            </div>
                            <div class="strength-label" id="strengthLabel"></div>
                        </div>

                        <div class="mb-4 mt-3">
                            <label class="form-label">Konfirmasi Password Baru</label>
                            <div class="input-shell password-shell">
                                <i class="bi bi-check-circle-fill input-icon"></i>
                                <input
                                    type="password"
                                    name="confirm_password"
                                    id="confirmPassword"
                                    class="form-control"
                                    required
                                    minlength="8"
                                    placeholder="Ulangi password baru"
                                    autocomplete="new-password">
                                <button type="button" class="password-toggle" data-target="confirmPassword" aria-label="Lihat konfirmasi password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div id="matchMsg" style="font-size:0.85rem; margin-top:6px; font-weight:700;"></div>
                        </div>

                        <button type="submit" class="btn btn-login w-100">
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

    <!-- SCRIPT (Tidak diubah logikanya, hanya penyesuaian fungsi visual) -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // --- 1. Fungsi Membuat Password Acak Kuat ---
            function generateStrongPassword() {
                const uppers = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
                const lowers = "abcdefghijklmnopqrstuvwxyz";
                const numbers = "0123456789";
                const symbols = "!@#$%^&*";
                
                let pass = "";
                // Paksa agar ada minimal 1 huruf besar, kecil, angka, dan simbol
                pass += uppers[Math.floor(Math.random() * uppers.length)];
                pass += lowers[Math.floor(Math.random() * lowers.length)];
                pass += numbers[Math.floor(Math.random() * numbers.length)];
                pass += symbols[Math.floor(Math.random() * symbols.length)];
                
                // Tambahkan 8 karakter sisanya secara acak (Total 12 Karakter)
                const all = uppers + lowers + numbers + symbols;
                for(let i=0; i<8; i++) {
                    pass += all[Math.floor(Math.random() * all.length)];
                }
                
                // Acak urutan stringnya
                return pass.split('').sort(() => 0.5 - Math.random()).join('');
            }

            // Tampilkan saran password di kotak UI
            const suggestedPass = generateStrongPassword();
            document.getElementById('suggestedPasswordTxt').textContent = suggestedPass;

            // Tombol "Gunakan Password Ini"
            document.getElementById('btnUseSuggestion').addEventListener('click', function() {
                const newPassInput = document.getElementById('newPassword');
                const confPassInput = document.getElementById('confirmPassword');
                
                // Jadikan type text agar kelihatan saat diisi otomatis
                newPassInput.type = 'text';
                confPassInput.type = 'text';

                // Isi form otomatis
                newPassInput.value = suggestedPass;
                confPassInput.value = suggestedPass;

                // Update icon mata jadi dicoret
                document.querySelectorAll('.password-toggle i').forEach(icon => {
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                });

                // Trigger input event agar bar warna dan tulisan "cocok" muncul
                newPassInput.dispatchEvent(new Event('input'));
                confPassInput.dispatchEvent(new Event('input'));
            });


            // --- 2. Toggle password visibility (Mata) ---
            document.querySelectorAll('.password-toggle').forEach(function (button) {
                button.addEventListener('click', function () {
                    const targetId = this.getAttribute('data-target');
                    const input    = document.getElementById(targetId);
                    const icon     = this.querySelector('i');
                    if (!input) return;

                    const isPassword = input.type === 'password';
                    input.type = isPassword ? 'text' : 'password';

                    if (icon) {
                        icon.classList.toggle('bi-eye',       !isPassword);
                        icon.classList.toggle('bi-eye-slash',  isPassword);
                    }
                });
            });

            // --- 3. Password strength indicator ---
            const newPasswordInput = document.getElementById('newPassword');
            const strengthFill     = document.getElementById('strengthFill');
            const strengthLabel    = document.getElementById('strengthLabel');

            newPasswordInput.addEventListener('input', function () {
                const val = this.value;
                let score = 0;

                if (val.length >= 8)              score++;
                if (/[A-Z]/.test(val))            score++;
                if (/[0-9]/.test(val))            score++;
                if (/[^A-Za-z0-9]/.test(val))     score++;

                const levels =[
                    { pct: '0%',   color: '',          label: '' },
                    { pct: '25%',  color: '#ef4444',   label: 'Lemah (Minimal 8 Karakter)' }, // Merah
                    { pct: '50%',  color: '#f59e0b',   label: 'Cukup (Tambahkan Angka/Huruf Besar)' }, // Oren kekuningan
                    { pct: '75%',  color: '#eab308',   label: 'Baik (Tambahkan Simbol Spesial)' }, // Kuning
                    { pct: '100%', color: '#22c55e',   label: 'Sangat Kuat' }, // Hijau
                ];

                const level = val.length === 0 ? levels[0] : levels[score];
                strengthFill.style.width      = level.pct;
                strengthFill.style.background = level.color;
                strengthLabel.textContent     = level.label;
                strengthLabel.style.color     = level.color;
            });

            // --- 4. Confirm password match indicator ---
            const confirmPasswordInput = document.getElementById('confirmPassword');
            const matchMsg             = document.getElementById('matchMsg');

            function checkMatch() {
                if (confirmPasswordInput.value === '') {
                    matchMsg.textContent = '';
                    return;
                }
                if (newPasswordInput.value === confirmPasswordInput.value) {
                    matchMsg.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Password cocok';
                    matchMsg.style.color = '#22c55e'; // Hijau
                } else {
                    matchMsg.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i> Password tidak cocok';
                    matchMsg.style.color = '#ef4444'; // Merah
                }
            }

            newPasswordInput.addEventListener('input',     checkMatch);
            confirmPasswordInput.addEventListener('input', checkMatch);
        });
    </script>
</body>

</html>