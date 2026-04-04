<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

if (!function_exists('normalize_role')) {
    function normalize_role(string $role): string
    {
        $role = strtolower(trim($role));
        $allowedRoles = ['super_admin', 'admin', 'user'];

        return in_array($role, $allowedRoles, true) ? $role : 'admin';
    }
}

require_super_admin();
refresh_permissions($koneksi);

$editableRoles = ['admin', 'user'];
$currentRole = normalize_role($_GET['role'] ?? 'admin');
if (!in_array($currentRole, $editableRoles, true)) {
    $currentRole = 'admin';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = normalize_role($_POST['role'] ?? 'admin');

    if (!in_array($role, $editableRoles, true)) {
        set_flash('error', 'Role tidak valid.');
        redirect_to(base_url('users/role_permissions.php'));
    }

    $selectedPermissions = array_map('intval', $_POST['permissions'] ?? []);

    mysqli_begin_transaction($koneksi);

    try {
        $deleteStmt = mysqli_prepare($koneksi, "DELETE FROM rbac_role_permissions WHERE role = ?");
        mysqli_stmt_bind_param($deleteStmt, 's', $role);
        mysqli_stmt_execute($deleteStmt);
        mysqli_stmt_close($deleteStmt);

        if (!empty($selectedPermissions)) {
            $insertStmt = mysqli_prepare($koneksi, "INSERT INTO rbac_role_permissions (role, permission_id) VALUES (?, ?)");
            foreach ($selectedPermissions as $permissionId) {
                mysqli_stmt_bind_param($insertStmt, 'si', $role, $permissionId);
                mysqli_stmt_execute($insertStmt);
            }
            mysqli_stmt_close($insertStmt);
        }

        mysqli_commit($koneksi);
        set_flash('success', 'Hak akses role berhasil disimpan.');
    } catch (Throwable $e) {
        mysqli_rollback($koneksi);
        set_flash('error', 'Gagal menyimpan hak akses role: ' . $e->getMessage());
    }

    redirect_to(base_url('users/role_permissions.php?role=' . $role));
}

$assignedIds = [];
$stmt = mysqli_prepare($koneksi, "SELECT permission_id FROM rbac_role_permissions WHERE role = ?");
mysqli_stmt_bind_param($stmt, 's', $currentRole);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $assignedIds[] = (int) $row['permission_id'];
}
mysqli_stmt_close($stmt);

$permissionsQuery = mysqli_query($koneksi, "SELECT id, permission_key, permission_name, menu_group FROM rbac_permissions ORDER BY menu_group, permission_name");
$groupedPermissions = [];
while ($permission = mysqli_fetch_assoc($permissionsQuery)) {
    $group = $permission['menu_group'] ?: 'Lainnya';
    $groupedPermissions[$group][] = $permission;
}

$allPermissionsCount = 0;
foreach ($groupedPermissions as $items) {
    $allPermissionsCount += count($items);
}

$error = get_flash('error');
$success = get_flash('success');

function role_color_class(string $role): string
{
    return $role === 'admin' ? 'role-admin' : 'role-user';
}

function role_button_class(string $role, string $currentRole): string
{
    if ($role === $currentRole) {
        return $role === 'admin' ? 'role-switch-btn is-active admin' : 'role-switch-btn is-active user';
    }

    return $role === 'admin' ? 'role-switch-btn admin' : 'role-switch-btn user';
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atur Hak Akses Role - IT Asset</title>

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
            --dark-3: #2d2d2d;
            --text-main: #1e1e1e;
            --text-soft: #6b7280;

            --surface: #ffffff;
            --surface-soft: #fffaf3;
            --border-soft: rgba(255, 152, 0, 0.14);

            --shadow-soft: 0 14px 40px rgba(17, 17, 17, 0.08);
            --shadow-hover: 0 18px 46px rgba(255, 122, 0, 0.14);

            --radius-xl: 28px;
            --radius-lg: 22px;
            --radius-md: 16px;
            --radius-sm: 12px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background:
                radial-gradient(circle at top left, rgba(255, 176, 0, 0.16), transparent 26%),
                radial-gradient(circle at bottom right, rgba(255, 122, 0, 0.09), transparent 18%),
                linear-gradient(180deg, #fff8f1 0%, #fffaf5 35%, #ffffff 100%);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
        }

        .page-wrap {
            padding: 28px;
        }

        .hero-card {
            position: relative;
            overflow: hidden;
            border-radius: var(--radius-xl);
            background: linear-gradient(135deg, rgba(17, 17, 17, 0.95) 0%, rgba(42, 42, 42, 0.90) 30%, rgba(255, 122, 0, 0.96) 100%);
            box-shadow: 0 18px 45px rgba(255, 122, 0, 0.20);
            padding: 1.55rem 1.6rem;
            margin-bottom: 1.35rem;
        }

        .hero-card::before {
            content: "";
            position: absolute;
            width: 240px;
            height: 240px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            top: -90px;
            right: -65px;
        }

        .hero-card::after {
            content: "";
            position: absolute;
            width: 170px;
            height: 170px;
            border-radius: 50%;
            background: rgba(255, 209, 102, 0.16);
            left: -55px;
            bottom: -65px;
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .page-title {
            color: #fff;
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: .35rem;
            letter-spacing: -0.02em;
        }

        .page-subtitle {
            color: rgba(255, 255, 255, 0.84);
            margin-bottom: 0;
            line-height: 1.7;
            max-width: 760px;
            font-size: .94rem;
        }

        .hero-action {
            border: none;
            background: #fff;
            color: #111;
            font-weight: 800;
            border-radius: 999px;
            padding: .82rem 1.2rem;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.12);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }

        .hero-action:hover {
            background: #fff7ea;
            color: #111;
        }

        .alert {
            border: none;
            border-radius: 18px;
            padding: 14px 16px;
            box-shadow: 0 10px 24px rgba(17, 17, 17, 0.04);
        }

        .summary-card,
        .info-card,
        .permission-card {
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-soft);
            background: #fff;
            overflow: hidden;
        }

        .summary-card {
            position: relative;
            padding: 1.15rem;
            height: 100%;
            background: linear-gradient(180deg, #ffffff 0%, #fffaf3 100%);
        }

        .summary-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 5px;
            background: linear-gradient(90deg, var(--orange-1), var(--orange-3));
        }

        .summary-label {
            font-size: .84rem;
            color: var(--text-soft);
            font-weight: 700;
            margin-bottom: .4rem;
        }

        .summary-value {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: .35rem;
            color: var(--dark-1);
        }

        .summary-note {
            font-size: .82rem;
            color: var(--text-soft);
        }

        .summary-icon {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
            color: #fff;
            background: linear-gradient(135deg, var(--orange-1), var(--orange-3));
            box-shadow: 0 10px 24px rgba(255, 152, 0, 0.20);
        }

        .role-admin {
            color: #2563eb !important;
        }

        .role-user {
            color: #16a34a !important;
        }

        .info-card {
            padding: 1.25rem;
            margin-bottom: 1.35rem;
            background: linear-gradient(180deg, #fffdf9 0%, #fff8ef 100%);
        }

        .info-title {
            font-size: 1.02rem;
            font-weight: 800;
            color: var(--dark-1);
            margin-bottom: .35rem;
        }

        .info-text {
            color: var(--text-soft);
            line-height: 1.7;
            margin-bottom: 0;
            font-size: .92rem;
        }

        .role-switch {
            display: flex;
            gap: .65rem;
            flex-wrap: wrap;
        }

        .role-switch-btn {
            border-radius: 999px;
            font-weight: 800;
            padding: .72rem 1rem;
            text-decoration: none;
            border: 1px solid #dfe5ec;
            transition: all .2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 130px;
            background: #fff;
        }

        .role-switch-btn.admin {
            color: #2563eb;
            border-color: rgba(37, 99, 235, 0.18);
        }

        .role-switch-btn.user {
            color: #16a34a;
            border-color: rgba(22, 163, 74, 0.18);
        }

        .role-switch-btn:hover {
            transform: translateY(-1px);
        }

        .role-switch-btn.is-active.admin {
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.18);
        }

        .role-switch-btn.is-active.user {
            background: linear-gradient(135deg, #16a34a, #22c55e);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 10px 24px rgba(22, 163, 74, 0.18);
        }

        .permission-card-header {
            padding: 1.1rem 1.2rem;
            background: linear-gradient(135deg, #111111 0%, #2c2c2c 40%, #ff8f00 100%);
            color: #fff;
        }

        .permission-card-title {
            font-size: 1rem;
            font-weight: 800;
            margin-bottom: .2rem;
        }

        .section-subinfo {
            font-size: .84rem;
            color: rgba(255, 255, 255, 0.82);
        }

        .permission-card-body {
            padding: 1.25rem;
            background: linear-gradient(180deg, #fffdf9 0%, #fff8ef 100%);
        }

        .permission-group {
            border: 1px solid rgba(255, 176, 0, 0.14);
            border-radius: 18px;
            padding: 1rem;
            background: #fff;
            height: 100%;
            box-shadow: 0 8px 20px rgba(17, 17, 17, 0.04);
        }

        .permission-group-title {
            font-weight: 800;
            color: var(--dark-1);
            margin-bottom: .95rem;
            font-size: .96rem;
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        .permission-group-title i {
            color: var(--orange-2);
        }

        .permission-item {
            padding: .82rem .88rem;
            border: 1px solid #f0ede8;
            border-radius: 14px;
            background: #fffdfa;
            height: 100%;
            transition: all .2s ease;
        }

        .permission-item:hover {
            border-color: rgba(255, 152, 0, 0.20);
            background: #fff9f2;
        }

        .permission-item .form-check {
            margin: 0;
            display: flex;
            align-items: flex-start;
            gap: .7rem;
        }

        .permission-item .form-check-input {
            margin-top: .22rem;
            width: 18px;
            height: 18px;
            border-radius: 6px;
            cursor: pointer;
        }

        .permission-item .form-check-input:checked {
            background-color: #ff9800;
            border-color: #ff9800;
        }

        .permission-item .form-check-label {
            cursor: pointer;
            line-height: 1.5;
        }

        .permission-name {
            font-size: .92rem;
            font-weight: 700;
            color: var(--dark-1);
            display: block;
            margin-bottom: .18rem;
        }

        .permission-key {
            color: var(--text-soft);
            font-size: .82rem;
            word-break: break-word;
        }

        .sticky-action {
            position: sticky;
            bottom: 0;
            background: linear-gradient(180deg, rgba(255, 253, 249, 0.2) 0%, #fff 22%);
            padding-top: 1rem;
            margin-top: 1.2rem;
        }

        .action-btn {
            border-radius: 14px;
            font-weight: 800;
            padding: .8rem 1rem;
            min-width: 140px;
            transition: all .2s ease;
        }

        .btn-soft {
            background: #fff;
            border: 1px solid #e3e1dc;
            color: #3c3c3c;
        }

        .btn-soft:hover {
            background: #fff7ea;
            color: #111;
            border-color: rgba(255, 152, 0, 0.18);
        }

        .btn-save {
            border: none;
            color: #fff;
            background: linear-gradient(135deg, var(--orange-1), var(--orange-3));
            box-shadow: 0 12px 28px rgba(255, 152, 0, 0.18);
        }

        .btn-save:hover {
            color: #fff;
            transform: translateY(-1px);
            filter: brightness(.98);
        }

        @media (max-width: 991.98px) {
            .page-wrap {
                padding: 18px;
            }

            .hero-card {
                padding: 1.3rem 1.15rem;
            }

            .page-title {
                font-size: 1.4rem;
            }

            .summary-value {
                font-size: 1.7rem;
            }

            .permission-card-body,
            .permission-card-header,
            .info-card {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .sticky-action {
                position: static;
                background: transparent;
            }
        }

        @media (max-width: 575.98px) {
            .page-title {
                font-size: 1.2rem;
            }

            .page-subtitle {
                font-size: .9rem;
            }

            .hero-action,
            .role-switch-btn,
            .action-btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <?php require_once '../layout/sidebar.php'; ?>

            <div class="col-md-9 col-lg-10">
                <div class="page-wrap">

                    <div class="hero-card">
                        <div class="hero-content d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div>
                                <h1 class="page-title">Atur Hak Akses Role</h1>
                                <p class="page-subtitle">
                                    Kelola permission untuk role Admin dan User secara lebih rapi, terpusat, dan mudah dipantau dalam satu halaman.
                                </p>
                            </div>

                            <a href="<?= e(base_url('users/index.php')) ?>" class="hero-action">
                                <i class="bi bi-people me-2"></i>Kelola User
                            </a>
                        </div>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= e($error) ?></div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= e($success) ?></div>
                    <?php endif; ?>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Role Dipilih</div>
                                        <div class="summary-value <?= role_color_class($currentRole) ?>">
                                            <?= strtoupper(e($currentRole)) ?>
                                        </div>
                                        <div class="summary-note">Role yang sedang dikonfigurasi</div>
                                    </div>
                                    <div class="summary-icon">
                                        <i class="bi bi-person-badge"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Permission Aktif</div>
                                        <div class="summary-value"><?= count($assignedIds) ?></div>
                                        <div class="summary-note">Permission yang sedang dipilih</div>
                                    </div>
                                    <div class="summary-icon">
                                        <i class="bi bi-check2-square"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Total Permission Sistem</div>
                                        <div class="summary-value"><?= $allPermissionsCount ?></div>
                                        <div class="summary-note">Seluruh permission yang tersedia</div>
                                    </div>
                                    <div class="summary-icon">
                                        <i class="bi bi-diagram-3"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="row g-3 align-items-center">
                            <div class="col-lg-8">
                                <div class="info-title">Informasi Pengaturan Role</div>
                                <p class="info-text">
                                    Super Admin memiliki akses penuh secara otomatis. Halaman ini digunakan untuk mengatur hak akses role
                                    <b>Admin</b> dan <b>User</b> agar sesuai dengan kebutuhan operasional.
                                </p>
                            </div>

                            <div class="col-lg-4">
                                <div class="role-switch justify-content-lg-end">
                                    <a href="<?= e(base_url('users/role_permissions.php?role=admin')) ?>"
                                       class="<?= role_button_class('admin', $currentRole) ?>">
                                        Role Admin
                                    </a>

                                    <a href="<?= e(base_url('users/role_permissions.php?role=user')) ?>"
                                       class="<?= role_button_class('user', $currentRole) ?>">
                                        Role User
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <form method="POST" id="formRolePermissions" class="permission-card">
                        <input type="hidden" name="role" value="<?= e($currentRole) ?>">

                        <div class="permission-card-header">
                            <div class="permission-card-title">
                                Permission untuk Role: <?= strtoupper(e($currentRole)) ?>
                            </div>
                            <div class="section-subinfo">
                                Centang permission yang diizinkan untuk role ini.
                            </div>
                        </div>

                        <div class="permission-card-body">
                            <div class="row g-3">
                                <?php foreach ($groupedPermissions as $group => $items): ?>
                                    <div class="col-xl-6">
                                        <div class="permission-group">
                                            <div class="permission-group-title">
                                                <i class="bi bi-folder2-open"></i><?= e($group) ?>
                                            </div>

                                            <div class="row g-3">
                                                <?php foreach ($items as $item): ?>
                                                    <div class="col-12">
                                                        <div class="permission-item">
                                                            <div class="form-check">
                                                                <input class="form-check-input"
                                                                    type="checkbox"
                                                                    name="permissions[]"
                                                                    value="<?= (int) $item['id'] ?>"
                                                                    id="permission<?= (int) $item['id'] ?>"
                                                                    <?= in_array((int) $item['id'], $assignedIds, true) ? 'checked' : '' ?>>

                                                                <label class="form-check-label" for="permission<?= (int) $item['id'] ?>">
                                                                    <span class="permission-name"><?= e($item['permission_name']) ?></span>
                                                                    <span class="permission-key"><?= e($item['permission_key']) ?></span>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="sticky-action">
                                <div class="d-flex justify-content-end gap-2 flex-wrap">
                                    <button type="button" id="btnCheckAll" class="btn action-btn btn-soft">
                                        Pilih Semua
                                    </button>

                                    <button type="button" id="btnUncheckAll" class="btn action-btn btn-soft">
                                        Hapus Semua
                                    </button>

                                    <button type="submit" class="btn action-btn btn-save">
                                        Simpan Hak Akses
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('btnCheckAll').addEventListener('click', function () {
            document.querySelectorAll('#formRolePermissions input[type="checkbox"]').forEach(function (checkbox) {
                checkbox.checked = true;
            });
        });

        document.getElementById('btnUncheckAll').addEventListener('click', function () {
            document.querySelectorAll('#formRolePermissions input[type="checkbox"]').forEach(function (checkbox) {
                checkbox.checked = false;
            });
        });

        document.getElementById('formRolePermissions').addEventListener('submit', function (e) {
            e.preventDefault();

            const role = document.querySelector('input[name="role"]').value;
            const checkedCount = document.querySelectorAll('#formRolePermissions input[type="checkbox"]:checked').length;

            Swal.fire({
                title: 'Simpan hak akses?',
                text: 'Permission untuk role "' + role + '" akan diperbarui. Total permission dipilih: ' + checkedCount + '.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, simpan',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#ff9800',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    e.target.submit();
                }
            });
        });
    </script>
</body>

</html>