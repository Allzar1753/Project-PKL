<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

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
        :root {
            --orange-1: #ff7a00;
            --orange-2: #ff9800;
            --orange-3: #ffb000;
            --dark-1: #111111;
            --text-main: #1e1e1e;
            --text-soft: #6b7280;
            --surface: #ffffff;
            --shadow-soft: 0 16px 40px rgba(17, 17, 17, 0.08);
            --shadow-strong: 0 22px 54px rgba(255, 122, 0, 0.16);
            --radius-xl: 28px;
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

        .login-title { font-size: 2.1rem; font-weight: 800; line-height: 1.18; margin-bottom: 16px; letter-spacing: -0.03em; max-width: 470px; }
        .login-desc { color: rgba(255,255,255,0.82); line-height: 1.72; max-width: 460px; font-size: .96rem; margin-bottom: 0; }

        .login-feature { margin-top: 30px; display: flex; flex-direction: column; gap: 14px; }
        .feature-item { display: flex; align-items: flex-start; gap: 13px; padding: 14px 15px; border-radius: 18px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.08); }
        .feature-icon { width: 40px; height: 40px; border-radius: 13px; display: inline-flex; align-items: center; justify-content: center; background: rgba(255,193,7,0.14); color: #ffd166; flex-shrink: 0; font-size: 1rem; border: 1px solid rgba(255,193,7,0.16); }
        .feature-title { font-weight: 700; margin-bottom: 3px; color: #fff; }
        .feature-text { color: rgba(255,255,255,0.70); font-size: .9rem; line-height: 1.5; }

        .login-left-footer { margin-top: 28px; display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.74); font-size: .88rem; }
        .footer-mark { width: 42px; height: 42px; border-radius: 14px; background: rgba(255,255,255,0.09); display: inline-flex; align-items: center; justify-content: center; color: #ffd166; font-weight: 800; border: 1px solid rgba(255,255,255,0.10); }

        /* ── RIGHT PANEL ── */
        .login-right { padding: 44px 38px; background: linear-gradient(180deg, #ffffff 0%, #fffdf9 100%); }

        .form-badge { display: inline-flex; align-items: center; gap: 8px; border-radius: 999px; padding: 8px 14px; background: #fff6e8; color: #9a640b; font-size: .82rem; font-weight: 700; border: 1px solid rgba(255,152,0,0.16); margin-bottom: 16px; }
        .form-title { font-size: 1.75rem; font-weight: 800; color: var(--dark-1); margin-bottom: 8px; letter-spacing: -0.02em; }
        .form-subtitle { color: var(--text-soft); margin-bottom: 28px; line-height: 1.65; font-size: .94rem; }

        .alert { border: none; border-radius: 16px; padding: 14px 16px; font-size: .92rem; box-shadow: 0 10px 24px rgba(17,17,17,0.04); }
        .alert-danger { background: #fff1ef; color: #c2412d; border-left: 4px solid #c2412d; }
        .alert-success { background: #eefaf0; color: #2f7d43; border-left: 4px solid #2f7d43; }

        .form-label { font-weight: 700; color: var(--dark-1); margin-bottom: .55rem; }

        .input-shell { position: relative; }
        .input-icon { position: absolute; top: 50%; left: 15px; transform: translateY(-50%); color: #c27e12; font-size: 1rem; z-index: 2; }
        .form-control { border-radius: 16px; padding: .95rem 1rem .95rem 2.85rem; border: 1px solid #e6dfd2; background: #fff; box-shadow: none; font-size: .95rem; transition: all .2s ease; }
        .form-control:focus { border-color: #f0c63d; box-shadow: 0 0 0 0.22rem rgba(255,193,7,0.14); background: #fffdfa; }

        .password-shell .form-control { padding-right: 3.2rem; }
        .password-toggle { position: absolute; top: 50%; right: 12px; transform: translateY(-50%); width: 38px; height: 38px; border: none; background: transparent; color: #c27e12; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all .2s ease; z-index: 3; }
        .password-toggle:hover { background: #fff3e0; color: #111; }

        /* Saran Password Box */
        .suggestion-box {
            background: #f8fbff;
            border: 1px dashed #a8c5ff;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .suggestion-text { font-size: 0.88rem; color: #3b5998; margin-bottom: 4px; font-weight: 600;}
        .suggestion-pass { font-family: monospace; font-weight: bold; color: #0d6efd; font-size: 1.2rem; letter-spacing: 2px; background: #e9f0ff; padding: 4px 10px; border-radius: 8px; user-select: all; display: inline-block;}
        .btn-use-suggestion { background: #0d6efd; color: white; border: none; padding: 8px 16px; border-radius: 12px; font-size: 0.85rem; font-weight: 700; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 6px;}
        .btn-use-suggestion:hover { background: #0b5ed7; transform: translateY(-1px); }

        .btn-login { background: linear-gradient(135deg, var(--orange-1), var(--orange-3)); border: none; color: #fff; font-weight: 800; border-radius: 16px; padding: .95rem 1rem; box-shadow: var(--shadow-strong); transition: all .22s ease; letter-spacing: .01em; }
        .btn-login:hover { color: #fff; transform: translateY(-1px); filter: brightness(.98); }

        .system-note { margin-top: 24px; background: linear-gradient(180deg, #fffaf3 0%, #fff6ea 100%); border: 1px solid rgba(255,152,0,0.12); border-radius: 18px; padding: 15px 16px; color: var(--text-soft); font-size: .91rem; line-height: 1.6; }

        /* strength bar */
        .strength-bar { height: 5px; border-radius: 999px; background: #f0ece4; margin-top: 8px; overflow: hidden; }
        .strength-fill { height: 100%; border-radius: 999px; width: 0%; transition: width .3s ease, background .3s ease; }
        .strength-label { font-size: .78rem; font-weight: 700; margin-top: 4px; color: var(--text-soft); }

        @media (max-width: 991.98px) { .login-left, .login-right { padding: 34px 28px; } .login-title { font-size: 1.75rem; } .form-title { font-size: 1.45rem; } }
        @media (max-width: 767.98px) { body { padding: 16px; } .login-card { border-radius: 22px; } .login-left, .login-right { padding: 26px 22px; } .suggestion-box { flex-direction: column; gap: 12px; align-items: stretch; text-align: center;} }
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
                            Wajib Ganti Password Sebelum Melanjutkan
                        </div>

                        <div class="login-desc">
                            Akun Anda menggunakan password default dari sistem.
                            Demi keamanan, harap buat password baru sebelum mengakses dashboard.
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
                                    <div class="feature-title">Password Kuat</div>
                                    <div class="feature-text">Gunakan kombinasi yang disarankan sistem agar akun Anda lebih aman dan sulit ditebak.</div>
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
                            <div class="suggestion-text">Saran Password Sangat Kuat:</div>
                            <div class="suggestion-pass" id="suggestedPasswordTxt">Memuat...</div>
                        </div>
                        <button type="button" class="btn-use-suggestion" id="btnUseSuggestion">
                            <i class="bi bi-magic"></i> Gunakan Password Ini
                        </button>
                    </div>

                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label">Password Baru</label>
                            <div class="input-shell password-shell">
                                <span class="input-icon"><i class="bi bi-lock"></i></span>
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

                        <div class="mb-4">
                            <label class="form-label">Konfirmasi Password Baru</label>
                            <div class="input-shell password-shell">
                                <span class="input-icon"><i class="bi bi-lock-fill"></i></span>
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
                            <div id="matchMsg" style="font-size:.8rem; margin-top:5px; font-weight:700;"></div>
                        </div>

                        <button type="submit" class="btn btn-login w-100">
                            <i class="bi bi-check2-circle me-2"></i>Simpan Password Baru
                        </button>
                    </form>

                    <div class="system-note mt-4">
                        <i class="bi bi-info-circle me-1"></i>
                        Halaman ini <b>tidak dapat dilewati</b>. Anda harus menyimpan password baru sebelum mengakses sistem.
                    </div>
                </div>
            </div>

        </div>
    </div>

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
                    { pct: '25%',  color: '#e53935',   label: 'Lemah (Minimal 8 Karakter)' },
                    { pct: '50%',  color: '#fb8c00',   label: 'Cukup (Tambahkan Angka / Huruf Besar)' },
                    { pct: '75%',  color: '#fdd835',   label: 'Baik (Tambahkan Simbol Spesial)' },
                    { pct: '100%', color: '#43a047',   label: 'Sangat Kuat' },
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
                    matchMsg.textContent = '✓ Password cocok';
                    matchMsg.style.color = '#43a047';
                } else {
                    matchMsg.textContent = '✗ Password tidak cocok';
                    matchMsg.style.color = '#e53935';
                }
            }

            newPasswordInput.addEventListener('input',     checkMatch);
            confirmPasswordInput.addEventListener('input', checkMatch);
        });
    </script>
</body>

</html>