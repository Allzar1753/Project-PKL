<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_super_admin();
refresh_permissions($koneksi);

if (!function_exists('normalize_role')) {
    function normalize_role(string $role): string
    {
        $role = strtolower(trim($role));
        $allowedRoles = ['super_admin', 'admin', 'user'];

        return in_array($role, $allowedRoles, true) ? $role : 'user';
    }
}

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
    $user = mysqli_fetch_assoc($result) ?: null;
    mysqli_stmt_close($stmt);

    return $user;
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
    $exists = (bool) mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return $exists;
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
    $exists = (bool) mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return $exists;
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
        mysqli_stmt_close($stmt);

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
        mysqli_stmt_close($stmt);

        if ($isSelf && isset($_SESSION['user'])) {
            $_SESSION['user']['username'] = $username;
            $_SESSION['user']['email'] = $email;
            $_SESSION['user']['role'] = $role;
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
        mysqli_stmt_close($stmt);

        set_flash('success', 'User berhasil dihapus.');
        redirect_to(base_url('users/index.php'));
    }
}

$alertError = get_flash('error');
$alertSuccess = get_flash('success');

$usersResult = mysqli_query(
    $koneksi,
    "SELECT id, username, email, role
     FROM users
     ORDER BY FIELD(role, 'super_admin', 'admin', 'user'), username ASC"
);

$users = [];
while ($row = mysqli_fetch_assoc($usersResult)) {
    $users[] = $row;
}

function role_badge_class(string $role): string
{
    if ($role === 'super_admin') {
        return 'bg-dark';
    }

    if ($role === 'admin') {
        return 'bg-warning text-dark';
    }

    return 'bg-primary';
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola User - IT Asset</title>
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

        .page-card,
        .summary-card,
        .table-card,
        .form-card {
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

        .bg-warning-custom {
            background: var(--primary);
        }

        .table-card .card-header {
            border-bottom: none;
            padding: 1rem 1.25rem;
            border-radius: 16px 16px 0 0;
        }

        .btn-warning-custom {
            background: var(--primary);
            border: none;
            color: #212529;
            font-weight: 700;
            border-radius: 10px;
        }

        .btn-warning-custom:hover {
            background: #e0a800;
            color: #212529;
        }

        .table thead th {
            background: #212529;
            color: #fff;
            font-weight: 700;
            white-space: nowrap;
            border-bottom: none;
            padding-top: 1rem;
            padding-bottom: 1rem;
        }

        .table tbody td {
            vertical-align: top;
            padding: 1rem 0.85rem;
        }

        .section-subinfo {
            color: var(--muted);
            font-size: 0.9rem;
        }

        .form-control,
        .form-select {
            border-radius: 12px;
            min-height: 46px;
        }

        .form-label {
            font-weight: 700;
            margin-bottom: .45rem;
        }

        .form-card {
            padding: 1.5rem;
        }

        .table-form-control {
            min-width: 180px;
        }

        .table-password {
            min-width: 220px;
        }

        .role-stack {
            min-width: 180px;
        }

        .action-stack {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
        }

        .title-bar {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .table-wrap {
            overflow-x: auto;
        }

        @media (max-width: 1200px) {

            .table-form-control,
            .table-password,
            .role-stack {
                min-width: 160px;
            }
        }
    </style>
</head>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.btnUpdateUser').forEach(function (button) {
        button.addEventListener('click', function () {
            const formId = this.dataset.formId;
            const username = this.dataset.username;
            const form = document.getElementById(formId);

            Swal.fire({
                title: 'Update user?',
                text: 'Data user "' + username + '" akan diperbarui.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, update',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#0d6efd'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });

    document.querySelectorAll('.btnDeleteUser').forEach(function (button) {
        button.addEventListener('click', function () {
            const form = this.closest('.formDeleteUser');
            const usernameInput = form.querySelector('input[name="username"]');
            const username = usernameInput ? usernameInput.value : 'user ini';

            Swal.fire({
                title: 'Hapus user?',
                text: 'User "' + username + '" akan dihapus dan tidak bisa dikembalikan.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Ya, hapus',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#dc3545'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
});
</script>

<body>
    <div class="container-fluid">
        <div class="row">
            <?php require_once '../layout/sidebar.php'; ?>

            <div class="col-md-10 p-4">
                <div class="title-bar mb-4">
                    <div>
                        <h3 class="fw-bold text-warning-custom mb-1">Kelola User</h3>
                        <p class="text-muted mb-0">Manajemen akun pengguna sistem dan kontrol role akses.</p>
                    </div>

                    <a href="<?= e(base_url('users/role_permissions.php')) ?>" class="btn btn-outline-dark">
                        Atur Hak Akses Role
                    </a>
                </div>

                <?php if ($alertError): ?>
                    <div class="alert alert-danger"><?= e($alertError) ?></div>
                <?php endif; ?>

                <?php if ($alertSuccess): ?>
                    <div class="alert alert-success"><?= e($alertSuccess) ?></div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="summary-card">
                            <h6 class="text-muted">Total User</h6>
                            <h3><?= count($users) ?></h3>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="summary-card">
                            <h6 class="text-muted">Super Admin</h6>
                            <h3 class="text-dark"><?= count_super_admins($koneksi) ?></h3>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="summary-card">
                            <h6 class="text-muted">Role Aktif Sistem</h6>
                            <h3 class="text-warning-custom">3</h3>
                        </div>
                    </div>
                </div>

                <div class="form-card mb-4">
                    <h4 class="fw-bold mb-4">Tambah User Baru</h4>

                    <form method="POST">
                        <input type="hidden" name="action" value="create">

                        <div class="row g-3 align-items-end">
                            <div class="col-lg-3 col-md-6">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>

                            <div class="col-lg-3 col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>

                            <div class="col-lg-3 col-md-6">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>

                            <div class="col-lg-2 col-md-4">
                                <label class="form-label">Role</label>
                                <select name="role" class="form-select">
                                    <option value="user">User</option>
                                    <option value="admin">Admin</option>
                                    <option value="super_admin">Super Admin</option>
                                </select>
                            </div>

                            <div class="col-lg-1 col-md-2 d-grid">
                                <button type="submit" class="btn btn-success">Simpan</button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="table-card">
                    <div class="card-header bg-warning-custom">
                        <div>
                            <h6 class="fw-bold mb-1">Daftar User</h6>
                            <div class="section-subinfo">Kelola akun pengguna, ubah role, reset password, dan hapus user bila diperlukan.</div>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width:70px;">ID</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th style="width:220px;">Role</th>
                                    <th style="width:250px;">Password Baru</th>
                                    <th style="width:170px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <?php $updateFormId = 'updateForm' . (int) $user['id']; ?>
                                    <tr>
                                        <td class="fw-bold pt-4"><?= (int) $user['id'] ?></td>

                                        <td>
                                            <form id="<?= e($updateFormId)  ?>" method="POST" class="formUpdateUser">
                                                <input type="hidden" name="action" value="update">
                                                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                            </form>
                                            <input type="text" name="username" class="form-control table-form-control" value="<?= e($user['username']) ?>" required form="<?= e($updateFormId) ?>">
                                        </td>

                                        <td>
                                            <input type="email" name="email" class="form-control table-form-control" value="<?= e($user['email']) ?>" required form="<?= e($updateFormId) ?>">
                                        </td>

                                        <td>
                                            <div class="role-stack">
                                                <select name="role" class="form-select" form="<?= e($updateFormId) ?>">
                                                    <option value="super_admin" <?= $user['role'] === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                                                    <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                    <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                                </select>
                                            </div>
                                        </td>

                                        <td>
                                            <input type="password" name="new_password" class="form-control table-password" placeholder="Kosongkan jika tidak diubah" form="<?= e($updateFormId) ?>">
                                        </td>

                                        <td>
                                            <div class="action-stack">
                                                <button type="button"
                                                    class="btn btn-primary btn-sm btnUpdateUser"
                                                    data-form-id="<?= e($updateFormId) ?>"
                                                    data-username="<?= e($user['username']) ?>">
                                                    Update
                                                </button>

                                                <form method="POST" class="formDeleteUser d-inline">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                                    <input type="hidden" name="username" value="<?= e($user['username']) ?>">
                                                    <button type="button" class="btn btn-danger btn-sm btnDeleteUser">
                                                        Hapus
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-5">
                                            Tidak ada data user.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</body>

</html>