<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_super_admin();
refresh_permissions($koneksi);

$userId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);

if ($userId <= 0) {
    set_flash('error', 'User tidak valid.');
    redirect_to(base_url('users/index.php'));
}

$stmt = mysqli_prepare($koneksi, "SELECT users.id, users.username, users.email, users.role, tb_branch.nama_branch FROM users LEFT JOIN tb_branch ON tb_branch.id_branch = users.id_branch WHERE users.id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$targetUser = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$targetUser) {
    set_flash('error', 'User tidak ditemukan.');
    redirect_to(base_url('users/index.php'));
}

if ($targetUser['role'] === 'super_admin') {
    set_flash('error', 'Hak akses Super Admin tidak dapat diubah.');
    redirect_to(base_url('users/index.php'));
}

function get_permission_ids_by_role(mysqli $koneksi, string $role): array
{
    $ids = [];
    $stmt = mysqli_prepare($koneksi, "SELECT permission_id FROM rbac_role_permissions WHERE role = ?");
    mysqli_stmt_bind_param($stmt, 's', $role);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $ids[] = (int) $row['permission_id'];
    }

    mysqli_stmt_close($stmt);
    return $ids;
}

function get_user_allow_permission_ids(mysqli $koneksi, int $userId): array
{
    $ids = [];
    $stmt = mysqli_prepare($koneksi, "SELECT permission_id FROM rbac_user_permissions WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $ids[] = (int) $row['permission_id'];
    }

    mysqli_stmt_close($stmt);
    return $ids;
}

function get_user_denied_permission_ids(mysqli $koneksi, int $userId): array
{
    $ids = [];
    $stmt = mysqli_prepare($koneksi, "SELECT permission_id FROM rbac_user_denied_permissions WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $ids[] = (int) $row['permission_id'];
    }

    mysqli_stmt_close($stmt);
    return $ids;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedPermissions = array_map('intval', $_POST['permissions'] ?? []);
    $selectedPermissions = array_values(array_unique($selectedPermissions));

    $rolePermissionIds = get_permission_ids_by_role($koneksi, $targetUser['role']);

    $roleMap = array_fill_keys($rolePermissionIds, true);
    $selectedMap = array_fill_keys($selectedPermissions, true);

    $customAllow = [];
    $customDeny = [];

    foreach ($selectedPermissions as $permissionId) {
        if (!isset($roleMap[$permissionId])) {
            $customAllow[] = $permissionId;
        }
    }

    foreach ($rolePermissionIds as $permissionId) {
        if (!isset($selectedMap[$permissionId])) {
            $customDeny[] = $permissionId;
        }
    }

    mysqli_begin_transaction($koneksi);

    try {
        $stmtDeleteAllow = mysqli_prepare($koneksi, "DELETE FROM rbac_user_permissions WHERE user_id = ?");
        mysqli_stmt_bind_param($stmtDeleteAllow, 'i', $userId);
        mysqli_stmt_execute($stmtDeleteAllow);
        mysqli_stmt_close($stmtDeleteAllow);

        $stmtDeleteDeny = mysqli_prepare($koneksi, "DELETE FROM rbac_user_denied_permissions WHERE user_id = ?");
        mysqli_stmt_bind_param($stmtDeleteDeny, 'i', $userId);
        mysqli_stmt_execute($stmtDeleteDeny);
        mysqli_stmt_close($stmtDeleteDeny);

        if (!empty($customAllow)) {
            $stmtInsertAllow = mysqli_prepare($koneksi, "INSERT INTO rbac_user_permissions (user_id, permission_id) VALUES (?, ?)");
            foreach ($customAllow as $permissionId) {
                mysqli_stmt_bind_param($stmtInsertAllow, 'ii', $userId, $permissionId);
                mysqli_stmt_execute($stmtInsertAllow);
            }
            mysqli_stmt_close($stmtInsertAllow);
        }

        if (!empty($customDeny)) {
            $stmtInsertDeny = mysqli_prepare($koneksi, "INSERT INTO rbac_user_denied_permissions (user_id, permission_id) VALUES (?, ?)");
            foreach ($customDeny as $permissionId) {
                mysqli_stmt_bind_param($stmtInsertDeny, 'ii', $userId, $permissionId);
                mysqli_stmt_execute($stmtInsertDeny);
            }
            mysqli_stmt_close($stmtInsertDeny);
        }

        mysqli_commit($koneksi);

        if (current_user_id() === $userId) {
            refresh_permissions($koneksi);
        }

        set_flash('success', 'Hak akses user berhasil disimpan.');
    } catch (Throwable $e) {
        mysqli_rollback($koneksi);
        set_flash('error', 'Gagal menyimpan hak akses user: ' . $e->getMessage());
    }

    redirect_to(base_url('users/user_permissions.php?user_id=' . $userId));
}

$rolePermissionIds = get_permission_ids_by_role($koneksi, $targetUser['role']);
$userAllowIds = get_user_allow_permission_ids($koneksi, $userId);
$userDeniedIds = get_user_denied_permission_ids($koneksi, $userId);

$roleMap = array_fill_keys($rolePermissionIds, true);
$userAllowMap = array_fill_keys($userAllowIds, true);
$userDeniedMap = array_fill_keys($userDeniedIds, true);

$finalSelectedMap = $roleMap;
foreach ($userAllowIds as $id) {
    $finalSelectedMap[$id] = true;
}
foreach ($userDeniedIds as $id) {
    unset($finalSelectedMap[$id]);
}

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

function role_color_class_user(string $role): string
{
    if ($role === 'admin') {
        return 'text-primary';
    }

    return 'text-success';
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hak Akses User - IT Asset</title>
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

        .permission-badges {
            display: flex;
            gap: .35rem;
            flex-wrap: wrap;
            margin-top: .5rem;
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
                        <h3 class="fw-bold text-warning-custom mb-1">Atur Hak Akses User</h3>
                        <p class="text-muted mb-0">Kelola permission khusus untuk user tertentu di luar permission role default.</p>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?= e(base_url('users/index.php')) ?>" class="btn btn-outline-dark">
                            <i class="bi bi-people me-1"></i>Kelola User
                        </a>
                        <a href="<?= e(base_url('users/role_permissions.php?role=' . $targetUser['role'])) ?>" class="btn btn-outline-primary">
                            <i class="bi bi-shield-lock me-1"></i>Hak Akses Role
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
                    <div class="col-md-3">
                        <div class="summary-card">
                            <h6 class="text-muted">Username</h6>
                            <h3><?= e($targetUser['username']) ?></h3>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="summary-card">
                            <h6 class="text-muted">Role</h6>
                            <h3 class="<?= role_color_class_user($targetUser['role']) ?>">
                                <?= strtoupper(e($targetUser['role'])) ?>
                            </h3>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="summary-card">
                            <h6 class="text-muted">Permission Aktif Final</h6>
                            <h3><?= count($finalSelectedMap) ?></h3>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="summary-card">
                            <h6 class="text-muted">Total Permission Sistem</h6>
                            <h3 class="text-warning-custom"><?= $allPermissionsCount ?></h3>
                        </div>
                    </div>
                </div>

                <div class="info-card p-4 mb-4">
                    <div class="row g-3 align-items-center">
                        <div class="col-lg-8">
                            <h5 class="fw-bold mb-2">Informasi User</h5>
                            <p class="text-muted mb-1"><b>Email:</b> <?= e($targetUser['email']) ?></p>
                            <p class="text-muted mb-1"><b>Cabang:</b> <?= e($targetUser['nama_branch'] ?? '-') ?></p>
                            <p class="text-muted mb-0">
                                Permission final user dihitung dari <b>role default</b>, ditambah <b>custom allow</b>,
                                lalu dikurangi <b>custom deny</b>.
                            </p>
                        </div>
                        <div class="col-lg-4">
                            <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                                <span class="badge bg-primary">Default Role</span>
                                <span class="badge bg-success">Custom Allow</span>
                                <span class="badge bg-danger">Custom Deny</span>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="POST" id="formUserPermissions" class="permission-card">
                    <input type="hidden" name="user_id" value="<?= (int) $targetUser['id'] ?>">

                    <div class="card-header">
                        <div>
                            <h6 class="fw-bold mb-1">
                                Permission untuk User: <?= e($targetUser['username']) ?>
                            </h6>
                            <div class="section-subinfo">
                                Centang permission yang ingin aktif untuk user ini.
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
                                                <?php $permissionId = (int) $item['id']; ?>
                                                <div class="col-12">
                                                    <div class="permission-item">
                                                        <div class="form-check">
                                                            <input class="form-check-input"
                                                                type="checkbox"
                                                                name="permissions[]"
                                                                value="<?= $permissionId ?>"
                                                                id="permission<?= $permissionId ?>"
                                                                <?= isset($finalSelectedMap[$permissionId]) ? 'checked' : '' ?>>

                                                            <label class="form-check-label" for="permission<?= $permissionId ?>">
                                                                <strong><?= e($item['permission_name']) ?></strong><br>
                                                                <span class="permission-key"><?= e($item['permission_key']) ?></span>

                                                                <div class="permission-badges">
                                                                    <?php if (isset($userDeniedMap[$permissionId])): ?>
                                                                        <span class="badge bg-danger">Custom Deny</span>
                                                                    <?php elseif (isset($userAllowMap[$permissionId])): ?>
                                                                        <span class="badge bg-success">Custom Allow</span>
                                                                    <?php elseif (isset($roleMap[$permissionId])): ?>
                                                                        <span class="badge bg-primary">Default Role</span>
                                                                    <?php endif; ?>
                                                                </div>
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
                                    Simpan Hak Akses User
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
            document.querySelectorAll('#formUserPermissions input[type="checkbox"]').forEach(function (checkbox) {
                checkbox.checked = true;
            });
        });

        document.getElementById('btnUncheckAll').addEventListener('click', function () {
            document.querySelectorAll('#formUserPermissions input[type="checkbox"]').forEach(function (checkbox) {
                checkbox.checked = false;
            });
        });

        document.getElementById('formUserPermissions').addEventListener('submit', function (e) {
            e.preventDefault();

            const checkedCount = document.querySelectorAll('#formUserPermissions input[type="checkbox"]:checked').length;

            Swal.fire({
                title: 'Simpan hak akses user?',
                text: 'Total permission aktif yang dipilih: ' + checkedCount + '.',
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