<?php
include '../config/koneksi.php';
include '../config/auth.php';

require_super_admin();
refresh_permissions($koneksi);

function count_super_admins(mysqli $koneksi): int
{
    $result = mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM users WHERE role = 'super_admin'");
    $row = mysqli_fetch_assoc($result);
    return (int) ($row['total'] ?? 0);
}

function find_user_by_id(mysqli $koneksi, int $id): ?array
{
    $stmt = mysqli_prepare($koneksi, "SELECT id, username, email, password, role FROM users WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($result) ?: null;
}

function username_exists(mysqli $koneksi, string $username, ?int $ignoreId = null): bool
{
    if ($ignoreId) {
        $stmt = mysqli_prepare($koneksi, "SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'si', $username, $ignoreId);
    } else {
        $stmt = mysqli_prepare($koneksi, "SELECT id FROM users WHERE username = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $username);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return (bool) mysqli_fetch_assoc($result);
}

function email_exists(mysqli $koneksi, string $email, ?int $ignoreId = null): bool
{
    if ($ignoreId) {
        $stmt = mysqli_prepare($koneksi, "SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'si', $email, $ignoreId);
    } else {
        $stmt = mysqli_prepare($koneksi, "SELECT id FROM users WHERE email = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $email);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return (bool) mysqli_fetch_assoc($result);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $role = normalize_role($_POST['role'] ?? 'user');

        if ($username === '' || $email === '' || $password === '') {
            set_flash('error', 'Username, email, dan password wajib diisi.');
            redirect_to(base_url('users/index.php'));
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Format email tidak valid.');
            redirect_to(base_url('users/index.php'));
        }

        if (username_exists($koneksi, $username)) {
            set_flash('error', 'Username sudah digunakan.');
            redirect_to(base_url('users/index.php'));
        }

        if (email_exists($koneksi, $email)) {
            set_flash('error', 'Email sudah digunakan.');
            redirect_to(base_url('users/index.php'));
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($koneksi, "INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'ssss', $username, $email, $hash, $role);
        mysqli_stmt_execute($stmt);

        set_flash('success', 'User baru berhasil dibuat.');
        redirect_to(base_url('users/index.php'));
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $role = normalize_role($_POST['role'] ?? 'user');

        $targetUser = find_user_by_id($koneksi, $id);
        if (!$targetUser) {
            set_flash('error', 'User tidak ditemukan.');
            redirect_to(base_url('users/index.php'));
        }

        if ($username === '' || $email === '') {
            set_flash('error', 'Username dan email wajib diisi.');
            redirect_to(base_url('users/index.php'));
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'Format email tidak valid.');
            redirect_to(base_url('users/index.php'));
        }

        if (username_exists($koneksi, $username, $id)) {
            set_flash('error', 'Username sudah digunakan user lain.');
            redirect_to(base_url('users/index.php'));
        }

        if (email_exists($koneksi, $email, $id)) {
            set_flash('error', 'Email sudah digunakan user lain.');
            redirect_to(base_url('users/index.php'));
        }

        $superAdminCount = count_super_admins($koneksi);
        $isSelf = current_user_id() === $id;
        $isLastSuperAdmin = $targetUser['role'] === 'super_admin' && $superAdminCount <= 1;

        if ($isSelf && $targetUser['role'] === 'super_admin' && $role !== 'super_admin') {
            set_flash('error', 'Anda tidak boleh menurunkan role diri sendiri dari Super Admin.');
            redirect_to(base_url('users/index.php'));
        }

        if ($isLastSuperAdmin && $role !== 'super_admin') {
            set_flash('error', 'Tidak bisa mengubah role Super Admin terakhir.');
            redirect_to(base_url('users/index.php'));
        }

        if ($newPassword !== '') {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($koneksi, "UPDATE users SET username = ?, email = ?, role = ?, password = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'ssssi', $username, $email, $role, $hash, $id);
        } else {
            $stmt = mysqli_prepare($koneksi, "UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'sssi', $username, $email, $role, $id);
        }

        mysqli_stmt_execute($stmt);

        if ($isSelf) {
            $_SESSION['auth']['username'] = $username;
            $_SESSION['auth']['email'] = $email;
            $_SESSION['auth']['role'] = $role;
            refresh_permissions($koneksi);
        }

        set_flash('success', 'Data user berhasil diperbarui.');
        redirect_to(base_url('users/index.php'));
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $targetUser = find_user_by_id($koneksi, $id);

        if (!$targetUser) {
            set_flash('error', 'User tidak ditemukan.');
            redirect_to(base_url('users/index.php'));
        }

        if (current_user_id() === $id) {
            set_flash('error', 'Anda tidak bisa menghapus akun sendiri.');
            redirect_to(base_url('users/index.php'));
        }

        if ($targetUser['role'] === 'super_admin' && count_super_admins($koneksi) <= 1) {
            set_flash('error', 'Super Admin terakhir tidak boleh dihapus.');
            redirect_to(base_url('users/index.php'));
        }

        $stmt = mysqli_prepare($koneksi, "DELETE FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);

        set_flash('success', 'User berhasil dihapus.');
        redirect_to(base_url('users/index.php'));
    }
}

$alertError = get_flash('error');
$alertSuccess = get_flash('success');
$users = mysqli_query($koneksi, "SELECT id, username, email, role FROM users ORDER BY FIELD(role, 'super_admin', 'admin', 'user'), username ASC");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola User</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .page-card { border: none; border-radius: 16px; box-shadow: 0 12px 30px rgba(0,0,0,.05); }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1">Kelola User</h2>
                <p class="text-muted mb-0">Hanya Super Admin yang dapat menambah, mengubah, dan menghapus user.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= e(base_url('users/role_permissions.php')) ?>" class="btn btn-outline-dark">Atur Hak Akses Role</a>
                <a href="<?= e(base_url('Barang/index.php')) ?>" class="btn btn-warning">Kembali</a>
            </div>
        </div>

        <?php if ($alertError): ?>
            <div class="alert alert-danger"><?= e($alertError) ?></div>
        <?php endif; ?>

        <?php if ($alertSuccess): ?>
            <div class="alert alert-success"><?= e($alertSuccess) ?></div>
        <?php endif; ?>

        <div class="card page-card mb-4">
            <div class="card-body">
                <h5 class="mb-3">Tambah User Baru</h5>
                <form method="POST" class="row g-3">
                    <input type="hidden" name="action" value="create">
                    <div class="col-md-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select">
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-grid">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-success">Simpan</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card page-card">
            <div class="card-body">
                <h5 class="mb-3">Daftar User</h5>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Password Baru</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($user = mysqli_fetch_assoc($users)): ?>
                                <?php $updateFormId = 'updateForm' . (int)$user['id']; ?>
                                <tr>
                                    <td>
                                        <?= (int) $user['id'] ?>
                                        <form id="<?= e($updateFormId) ?>" method="POST">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                        </form>
                                    </td>
                                    <td><input type="text" name="username" class="form-control" value="<?= e($user['username']) ?>" required form="<?= e($updateFormId) ?>"></td>
                                    <td><input type="email" name="email" class="form-control" value="<?= e($user['email']) ?>" required form="<?= e($updateFormId) ?>"></td>
                                    <td>
                                        <select name="role" class="form-select" form="<?= e($updateFormId) ?>">
                                            <option value="super_admin" <?= $user['role'] === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                            <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                        </select>
                                    </td>
                                    <td><input type="password" name="new_password" class="form-control" placeholder="Kosongkan jika tidak diubah" form="<?= e($updateFormId) ?>"></td>
                                    <td>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <button type="submit" class="btn btn-primary btn-sm" form="<?= e($updateFormId) ?>">Update</button>
                                            <form method="POST" onsubmit="return confirm('Yakin ingin menghapus user ini?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
