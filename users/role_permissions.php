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
    return $role === 'admin' ? 'text-primary' : 'text-success';
}

function role_button_class(string $role, string $currentRole): string
{
    if ($role === $currentRole) {
        return $role === 'admin' ? 'btn-primary' : 'btn-success';
    }

    return $role === 'admin' ? 'btn-outline-primary' : 'btn-outline-success';
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

    <style>
        :root {
            --primary: #ffc107;
            --dark: #212529;
            --muted: #6c757d;
        }

        body {
            background: #f5f7fb;
            font-family: 'Inter', sans-serif;
        }

        .text-warning-custom {
            color: var(--primary);
        }

        .summary-card,
        .info-card,
        .permission-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.05);
            background: #fff;
        }

        .summary-card {
            height: 100%;
            padding: 1.25rem;
        }

        .summary-card h3 {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 0;
        }

        .permission-card .card-header {
            border-bottom: none;
            padding: 1rem 1.25rem;
            border-radius: 16px 16px 0 0;
            background: var(--primary);
        }

        .section-subinfo {
            color: var(--muted);
            font-size: 0.9rem;
        }

        .role-switch .btn {
            border-radius: 10px;
            font-weight: 700;
        }

        .permission-group {
            border: 1px solid #e9ecef;
            border-radius: 14px;
            padding: 1rem;
            background: #fff;
            height: 100%;
        }

        .permission-group-title {
            font-weight: 800;
            color: #212529;
            margin-bottom: 1rem;
            font-size: 1rem;
        }

        .permission-item {
            padding: .75rem;
            border: 1px solid #eef1f4;
            border-radius: 12px;
            background: #fafbfc;
            height: 100%;
        }

        .permission-item .form-check-input {
            margin-top: .35rem;
        }

        .permission-item .form-check-label {
            cursor: pointer;
        }

        .permission-key {
            color: var(--muted);
            font-size: .84rem;
        }

        .alert-note {
            border-radius: 14px;
            border: none;
        }

        .sticky-action {
            position: sticky;
            bottom: 0;
            background: #fff;
            padding-top: 1rem;
        }

        @media (max-width: 991.98px) {
            .sticky-action {
                position: static;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <?php require_once '../layout/sidebar.php'; ?>

            <div class="col-md-9 col-lg-10 p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <h3 class="fw-bold text-warning-custom mb-1">Atur Hak Akses Role</h3>
                        <p class="text-muted mb-0">Kelola permission untuk role Admin dan User secara terpusat.</p>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?= e(base_url('users/index.php')) ?>" class="btn btn-outline-dark">
                            <i class="bi bi-people me-1"></i>Kelola User
                        </a>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-note"><?= e($error) ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-note"><?= e($success) ?></div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="summary-card">
                            <h6 class="text-muted">Role Dipilih</h6>
                            <h3 class="<?= role_color_class($currentRole) ?>">
                                <?= strtoupper(e($currentRole)) ?>
                            </h3>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="summary-card">
                            <h6 class="text-muted">Permission Aktif</h6>
                            <h3><?= count($assignedIds) ?></h3>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="summary-card">
                            <h6 class="text-muted">Total Permission Sistem</h6>
                            <h3 class="text-warning-custom"><?= $allPermissionsCount ?></h3>
                        </div>
                    </div>
                </div>

                <div class="info-card p-4 mb-4">
                    <div class="row g-3 align-items-center">
                        <div class="col-lg-8">
                            <h5 class="fw-bold mb-2">Informasi Pengaturan Role</h5>
                            <p class="text-muted mb-0">
                                Super Admin memiliki akses penuh secara otomatis. Halaman ini digunakan untuk mengatur
                                hak akses role <b>Admin</b> dan <b>User</b>.
                            </p>
                        </div>
                        <div class="col-lg-4">
                            <div class="d-flex gap-2 justify-content-lg-end role-switch flex-wrap">
                                <a href="<?= e(base_url('users/role_permissions.php?role=admin')) ?>"
                                   class="btn <?= role_button_class('admin', $currentRole) ?>">
                                    Role Admin
                                </a>
                                <a href="<?= e(base_url('users/role_permissions.php?role=user')) ?>"
                                   class="btn <?= role_button_class('user', $currentRole) ?>">
                                    Role User
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="POST" id="formRolePermissions" class="permission-card">
                    <input type="hidden" name="role" value="<?= e($currentRole) ?>">

                    <div class="card-header">
                        <div>
                            <h6 class="fw-bold mb-1">
                                Permission untuk Role: <?= strtoupper(e($currentRole)) ?>
                            </h6>
                            <div class="section-subinfo">
                                Centang permission yang diizinkan untuk role ini.
                            </div>
                        </div>
                    </div>

                    <div class="card-body p-4">
                        <div class="row g-3">
                            <?php foreach ($groupedPermissions as $group => $items): ?>
                                <div class="col-xl-6">
                                    <div class="permission-group">
                                        <div class="permission-group-title">
                                            <i class="bi bi-folder2-open me-2"></i><?= e($group) ?>
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
                                                                <strong><?= e($item['permission_name']) ?></strong><br>
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

                        <div class="sticky-action mt-4">
                            <div class="d-flex justify-content-end gap-2 flex-wrap">
                                <button type="button" id="btnCheckAll" class="btn btn-outline-secondary">
                                    Pilih Semua
                                </button>
                                <button type="button" id="btnUncheckAll" class="btn btn-outline-secondary">
                                    Hapus Semua
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    Simpan Hak Akses
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

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
                confirmButtonColor: '#0d6efd'
            }).then((result) => {
                if (result.isConfirmed) {
                    e.target.submit();
                }
            });
        });
    </script>
</body>

</html>