<?php
include '../config/koneksi.php';
include '../config/auth.php';

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

        if (!empty($selectedPermissions)) {
            $insertStmt = mysqli_prepare($koneksi, "INSERT INTO rbac_role_permissions (role, permission_id) VALUES (?, ?)");
            foreach ($selectedPermissions as $permissionId) {
                mysqli_stmt_bind_param($insertStmt, 'si', $role, $permissionId);
                mysqli_stmt_execute($insertStmt);
            }
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

$permissionsQuery = mysqli_query($koneksi, "SELECT id, permission_key, permission_name, menu_group FROM rbac_permissions ORDER BY menu_group, permission_name");
$groupedPermissions = [];
while ($permission = mysqli_fetch_assoc($permissionsQuery)) {
    $group = $permission['menu_group'] ?: 'Lainnya';
    $groupedPermissions[$group][] = $permission;
}

$error = get_flash('error');
$success = get_flash('success');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atur Hak Akses Role</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
        }

        .page-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, .05);
        }
    </style>
</head>

<body>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1">Atur Hak Akses Role</h2>
                <p class="text-muted mb-0">Super Admin selalu memiliki full access. Halaman ini dipakai untuk mengatur role Admin dan User.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= e(base_url('users/index.php')) ?>" class="btn btn-outline-dark">Kelola User</a>
                <a href="<?= e(base_url('Barang/index.php')) ?>" class="btn btn-warning">Kembali</a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>

        <div class="alert alert-dark">
            <strong>Super Admin</strong> tidak perlu dicentang satu per satu karena sistem otomatis memberi akses penuh ke semua menu dan aksi.
        </div>

        <div class="mb-3 d-flex gap-2">
            <a href="<?= e(base_url('users/role_permissions.php?role=admin')) ?>" class="btn <?= $currentRole === 'admin' ? 'btn-primary' : 'btn-outline-primary' ?>">Role Admin</a>
            <a href="<?= e(base_url('users/role_permissions.php?role=user')) ?>" class="btn <?= $currentRole === 'user' ? 'btn-success' : 'btn-outline-success' ?>">Role User</a>
        </div>

        <form method="POST" class="card page-card">
            <input type="hidden" name="role" value="<?= e($currentRole) ?>">
            <div class="card-body">
                <h5 class="mb-3">Permission untuk role: <?= strtoupper(e($currentRole)) ?></h5>

                <?php foreach ($groupedPermissions as $group => $items): ?>
                    <div class="border rounded p-3 mb-3 bg-white">
                        <h6 class="mb-3"><?= e($group) ?></h6>
                        <div class="row g-2">
                            <?php foreach ($items as $item): ?>
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input"
                                            type="checkbox"
                                            name="permissions[]"
                                            value="<?= (int) $item['id'] ?>"
                                            id="permission<?= (int) $item['id'] ?>"
                                            <?= in_array((int) $item['id'], $assignedIds, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="permission<?= (int) $item['id'] ?>">
                                            <strong><?= e($item['permission_name']) ?></strong><br>
                                            <small class="text-muted"><?= e($item['permission_key']) ?></small>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <button type="submit" class="btn btn-primary">Simpan Hak Akses</button>
            </div>
        </form>
    </div>
</body>

</html>
