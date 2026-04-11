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
    $stmt = mysqli_prepare($koneksi, "SELECT id, username, email, password, role, id_branch FROM users WHERE id = ? LIMIT 1");
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

function normalize_bulk_users(array $rows): array
{
    $normalized = [];

    foreach (array_values($rows) as $row) {
        $normalized[] = [
            'username' => trim((string) ($row['username'] ?? '')),
            'email'    => trim((string) ($row['email'] ?? '')),
            'password' => (string) ($row['password'] ?? ''),
            'role'     => normalize_role((string) ($row['role'] ?? 'user')),
            'id_branch' => (int) ($row['id_branch'] ?? 0),
        ];
    }

    return $normalized;
}

function bulk_row_has_input(array $row): bool
{
    return $row['username'] !== '' || $row['email'] !== '' || $row['password'] !== '' || (int) ($row['id_branch'] ?? 0) > 0;
}

function set_bulk_form_state(array $rows, array $errors): void
{
    $_SESSION['bulk_create_old'] = $rows;
    $_SESSION['bulk_create_errors'] = $errors;
}

function clear_bulk_form_state(): void
{
    unset($_SESSION['bulk_create_old'], $_SESSION['bulk_create_errors']);
}

function pull_bulk_form_old(): array
{
    $rows = $_SESSION['bulk_create_old'] ?? [];
    unset($_SESSION['bulk_create_old']);

    if (!is_array($rows)) {
        return [];
    }

    foreach ($rows as &$row) {
        $row['username'] = trim((string) ($row['username'] ?? ''));
        $row['email'] = trim((string) ($row['email'] ?? ''));
        $row['password'] = '';
        $row['role'] = normalize_role((string) ($row['role'] ?? 'user'));
        $row['id_branch'] = (int) ($row['id_branch'] ?? 0);
    }
    unset($row);

    return $rows;
}

function pull_bulk_form_errors(): array
{
    $errors = $_SESSION['bulk_create_errors'] ?? [];
    unset($_SESSION['bulk_create_errors']);

    return is_array($errors) ? $errors : [];
}

function bulk_field_error(array $errors, int $rowIndex, string $field): string
{
    return (string) ($errors[$rowIndex][$field] ?? '');
}

function default_password_for_role(string $role): string
{
    $role = normalize_role($role);

    if ($role === 'admin') {
        return 'Admin@123';
    }

    if ($role === 'super_admin') {
        return 'SuperAdmin@123';
    }

    return 'user@123';
}

function resolve_password_input(string $role, ?string $inputPassword): string
{
    $inputPassword = trim((string) $inputPassword);

    if ($inputPassword !== '') {
        return $inputPassword;
    }

    return default_password_for_role($role);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = normalize_role($_POST['role'] ?? 'user');
        $password = resolve_password_input($role, $_POST['password'] ?? '');
        $idBranch = (int) ($_POST['id_branch'] ?? 0);
        if ($idBranch <= 0) {
            $idBranch = null;
        }

        if ($username === '' || $email === '') {
            set_flash('error', 'Username dan email wajib diisi.');
            redirect_to(base_url('users/index.php'));
        }

        if ($idBranch === null) {
            set_flash('error', 'Cabang wajib dipilih.');
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
        $mustChangePassword = 1;

        $stmt = mysqli_prepare($koneksi, "INSERT INTO users (username, email, password, role, must_change_password, id_branch) VALUES (?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'ssssii', $username, $email, $hash, $role, $mustChangePassword, $idBranch);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        set_flash('success', 'User berhasil dibuat.');
        redirect_to(base_url('users/index.php'));
    }

    if ($action === 'save_users') {
        $rawRows = $_POST['users'] ?? [];

        if (!is_array($rawRows)) {
            $rawRows = [];
        }

        if (count($rawRows) > 10) {
            set_create_user_form_state(
                normalize_create_user_rows(array_slice($rawRows, 0, 10)),
                [],
                'Maksimal 10 baris user dalam satu proses.'
            );
            redirect_to(base_url('users/index.php'));
        }

        $rows = normalize_create_user_rows($rawRows);
        $rowErrors = [];
        $filledRows = [];
        $generalError = '';

        foreach ($rows as $index => $row) {
            if (!create_user_row_has_value($row)) {
                continue;
            }

            $filledRows[$index] = $row;

            if ($row['username'] === '') {
                $rowErrors[$index]['username'] = 'Username wajib diisi.';
            }

            if ($row['email'] === '') {
                $rowErrors[$index]['email'] = 'Email wajib diisi.';
            } elseif (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $rowErrors[$index]['email'] = 'Format email tidak valid.';
            }

            if ((int) ($row['id_branch'] ?? 0) <= 0) {
                $rowErrors[$index]['id_branch'] = 'Cabang wajib dipilih.';
            }
        }

        if (empty($filledRows)) {
            $generalError = 'Isi minimal 1 baris user.';
            set_create_user_form_state($rows, $rowErrors, $generalError);
            redirect_to(base_url('users/index.php'));
        }

        $usernameGroups = [];
        $emailGroups = [];

        foreach ($filledRows as $index => $row) {
            $usernameKey = strtolower($row['username']);
            $emailKey = strtolower($row['email']);

            if ($row['username'] !== '') {
                $usernameGroups[$usernameKey][] = $index;
            }

            if ($row['email'] !== '') {
                $emailGroups[$emailKey][] = $index;
            }
        }

        foreach ($usernameGroups as $indexes) {
            if (count($indexes) > 1) {
                foreach ($indexes as $index) {
                    $rowErrors[$index]['username'] = 'Username duplikat.';
                }
            }
        }

        foreach ($emailGroups as $indexes) {
            if (count($indexes) > 1) {
                foreach ($indexes as $index) {
                    $rowErrors[$index]['email'] = 'Email duplikat.';
                }
            }
        }

        foreach ($filledRows as $index => $row) {
            if ($row['username'] !== '' && !isset($rowErrors[$index]['username']) && username_exists($koneksi, $row['username'])) {
                $rowErrors[$index]['username'] = 'Username sudah digunakan.';
            }

            if ($row['email'] !== '' && !isset($rowErrors[$index]['email']) && email_exists($koneksi, $row['email'])) {
                $rowErrors[$index]['email'] = 'Email sudah digunakan.';
            }
        }

        if (!empty($rowErrors) || $generalError !== '') {
            set_create_user_form_state($rows, $rowErrors, $generalError);
            redirect_to(base_url('users/index.php'));
        }

        mysqli_begin_transaction($koneksi);

        try {
            $stmt = mysqli_prepare(
                $koneksi,
                "INSERT INTO users (username, email, password, role, must_change_password, id_branch) VALUES (?, ?, ?, ?, ?, ?)"
            );

            $insertedCount = 0;

            foreach ($filledRows as $row) {
                $plainPassword = resolve_password_input($row['role'], $row['password'] ?? '');
                $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
                $mustChangePassword = 1;
                $idBranch = (int) ($row['id_branch'] ?? 0);

                mysqli_stmt_bind_param($stmt, 'ssssii', $row['username'], $row['email'], $hash, $row['role'], $mustChangePassword, $idBranch);

                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception(mysqli_stmt_error($stmt));
                }

                $insertedCount++;
            }

            mysqli_stmt_close($stmt);
            mysqli_commit($koneksi);

            clear_create_user_form_state();
            set_flash('success', $insertedCount . ' user berhasil dibuat.');
        } catch (Throwable $e) {
            mysqli_rollback($koneksi);
            set_create_user_form_state($rows, [], 'Terjadi kesalahan saat menyimpan data user.');
        }

        redirect_to(base_url('users/index.php'));
    }

    if ($action === 'bulk_create') {
        $rawRows = $_POST['bulk_users'] ?? [];

        if (!is_array($rawRows)) {
            $rawRows = [];
        }

        if (count($rawRows) > 10) {
            set_flash('error', 'Maksimal 10 user dalam satu proses.');
            redirect_to(base_url('users/index.php'));
        }

        $rows = normalize_bulk_users($rawRows);
        $rowErrors = [];
        $filledRows = [];

        foreach ($rows as $index => $row) {
            if (!bulk_row_has_input($row)) {
                continue;
            }

            $filledRows[$index] = $row;

            if ($row['username'] === '') {
                $rowErrors[$index]['username'] = 'Username wajib diisi.';
            }

            if ($row['email'] === '') {
                $rowErrors[$index]['email'] = 'Email wajib diisi.';
            } elseif (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $rowErrors[$index]['email'] = 'Format email tidak valid.';
            }

            if ((int) ($row['id_branch'] ?? 0) <= 0) {
                $rowErrors[$index]['id_branch'] = 'Cabang wajib dipilih.';
            }
        }

        if (empty($filledRows)) {
            set_bulk_form_state($rows, $rowErrors);
            set_flash('error', 'Isi minimal 1 user pada form tambah banyak.');
            redirect_to(base_url('users/index.php'));
        }

        $usernameGroups = [];
        $emailGroups = [];

        foreach ($filledRows as $index => $row) {
            $usernameKey = strtolower($row['username']);
            $emailKey = strtolower($row['email']);

            if ($row['username'] !== '') {
                $usernameGroups[$usernameKey][] = $index;
            }

            if ($row['email'] !== '') {
                $emailGroups[$emailKey][] = $index;
            }
        }

        foreach ($usernameGroups as $indexes) {
            if (count($indexes) > 1) {
                foreach ($indexes as $index) {
                    $rowErrors[$index]['username'] = 'Username duplikat di form.';
                }
            }
        }

        foreach ($emailGroups as $indexes) {
            if (count($indexes) > 1) {
                foreach ($indexes as $index) {
                    $rowErrors[$index]['email'] = 'Email duplikat di form.';
                }
            }
        }

        foreach ($filledRows as $index => $row) {
            if ($row['username'] !== '' && !isset($rowErrors[$index]['username']) && username_exists($koneksi, $row['username'])) {
                $rowErrors[$index]['username'] = 'Username sudah digunakan.';
            }

            if ($row['email'] !== '' && !isset($rowErrors[$index]['email']) && email_exists($koneksi, $row['email'])) {
                $rowErrors[$index]['email'] = 'Email sudah digunakan.';
            }
        }

        if (!empty($rowErrors)) {
            set_bulk_form_state($rows, $rowErrors);
            set_flash('error', 'Periksa kembali form tambah banyak user.');
            redirect_to(base_url('users/index.php'));
        }

        mysqli_begin_transaction($koneksi);

        try {
            $stmt = mysqli_prepare($koneksi, "INSERT INTO users (username, email, password, role, must_change_password, id_branch) VALUES (?, ?, ?, ?, ?, ?)");
            $insertedCount = 0;

            foreach ($filledRows as $row) {
                $plainPassword = resolve_password_input($row['role'], $row['password'] ?? '');
                $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
                $mustChangePassword = 1;
                $idBranch = (int) ($row['id_branch'] ?? 0);

                mysqli_stmt_bind_param(
                    $stmt,
                    'ssssii',
                    $row['username'],
                    $row['email'],
                    $hash,
                    $row['role'],
                    $mustChangePassword,
                    $idBranch
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception(mysqli_stmt_error($stmt));
                }

                $insertedCount++;
            }

            mysqli_stmt_close($stmt);
            mysqli_commit($koneksi);

            clear_bulk_form_state();
            set_flash('success', $insertedCount . ' user berhasil dibuat.');
        } catch (Throwable $e) {
            mysqli_rollback($koneksi);
            set_bulk_form_state($rows, []);
            set_flash('error', 'Gagal membuat banyak user sekaligus.');
        }

        redirect_to(base_url('users/index.php'));
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $role = normalize_role($_POST['role'] ?? 'user');
        $idBranch = (int) ($_POST['id_branch'] ?? 0);

        if ($idBranch <= 0) {
            $idBranch = null;
        }

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
            $mustChangePassword = 0;
            $stmt = mysqli_prepare($koneksi, "UPDATE users SET username = ?, email = ?, role = ?, id_branch = ?, password = ?, must_change_password = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'sssissi', $username, $email, $role, $idBranch, $hash, $mustChangePassword, $id);
        } else {
            $stmt = mysqli_prepare($koneksi, "UPDATE users SET username = ?, email = ?, role = ?, id_branch = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'sssii', $username, $email, $role, $idBranch, $id);
        }

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if ($isSelf && isset($_SESSION['user'])) {
            $_SESSION['user']['username'] = $username;
            $_SESSION['user']['email'] = $email;
            $_SESSION['user']['role'] = $role;
            $_SESSION['user']['id_branch'] = $idBranch;
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

function default_create_user_rows(): array
{
    return [
        ['username' => '', 'email' => '', 'password' => '', 'role' => 'user', 'id_branch' => 0]
    ];
}

function normalize_create_user_rows(array $rows): array
{
    $normalized = [];

    foreach ($rows as $row) {
        $normalized[] = [
            'username' => trim((string) ($row['username'] ?? '')),
            'email' => trim((string) ($row['email'] ?? '')),
            'password' => (string) ($row['password'] ?? ''),
            'role' => normalize_role((string) ($row['role'] ?? 'user')),
            'id_branch' => (int) ($row['id_branch'] ?? 0)
        ];
    }

    return $normalized;
}

function create_user_row_has_value(array $row): bool
{
    return $row['username'] !== '' || $row['email'] !== '' || $row['password'] !== '' || (int) ($row['id_branch'] ?? 0) > 0;
}

function set_create_user_form_state(array $rows, array $errors = [], string $generalError = ''): void
{
    $_SESSION['create_user_old'] = $rows;
    $_SESSION['create_user_errors'] = $errors;
    $_SESSION['create_user_general_error'] = $generalError;
}

function clear_create_user_form_state(): void
{
    unset($_SESSION['create_user_old'], $_SESSION['create_user_errors'], $_SESSION['create_user_general_error']);
}

function pull_create_user_rows_old(): array
{
    $rows = $_SESSION['create_user_old'] ?? [];
    unset($_SESSION['create_user_old']);

    if (!is_array($rows) || empty($rows)) {
        return default_create_user_rows();
    }

    $rows = normalize_create_user_rows($rows);

    foreach ($rows as &$row) {
        $row['password'] = '';
    }
    unset($row);

    return $rows;
}

function pull_create_user_rows_errors(): array
{
    $errors = $_SESSION['create_user_errors'] ?? [];
    unset($_SESSION['create_user_errors']);

    return is_array($errors) ? $errors : [];
}

function pull_create_user_general_error(): string
{
    $message = $_SESSION['create_user_general_error'] ?? '';
    unset($_SESSION['create_user_general_error']);

    return $message;
}

function create_user_field_error(array $errors, int $rowIndex, string $field): string
{
    return (string) ($errors[$rowIndex][$field] ?? '');
}

$alertError = get_flash('error');
$alertSuccess = get_flash('success');
$bulkOldRows = pull_bulk_form_old();
$bulkErrors = pull_bulk_form_errors();
$createUserRowsOld = pull_create_user_rows_old();
$createUserRowsErrors = pull_create_user_rows_errors();
$createUserGeneralError = pull_create_user_general_error();

if (empty($bulkOldRows)) {
    $bulkOldRows = [
        ['username' => '', 'email' => '', 'password' => '', 'role' => 'user', 'id_branch' => 0]
    ];
}

$allowed_limits = [5, 25, 50, 100];
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
if (!in_array($limit, $allowed_limits, true)) {
    $limit = 25;
}

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

$summaryQuery = mysqli_query(
    $koneksi,
    "SELECT 
        COUNT(*) AS total_users,
        SUM(CASE WHEN role = 'super_admin' THEN 1 ELSE 0 END) AS total_super_admin,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS total_admin,
        SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END) AS total_user
     FROM users"
);
$summaryData = mysqli_fetch_assoc($summaryQuery) ?: [];

$totalUsers = (int) ($summaryData['total_users'] ?? 0);
$superAdminTotal = (int) ($summaryData['total_super_admin'] ?? 0);
$adminTotal = (int) ($summaryData['total_admin'] ?? 0);
$userTotal = (int) ($summaryData['total_user'] ?? 0);

$totalPages = max(1, (int) ceil($totalUsers / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $limit;
$displayFrom = $totalUsers > 0 ? $offset + 1 : 0;
$displayTo = min($offset + $limit, $totalUsers);

$usersResult = mysqli_query(
    $koneksi,
    "SELECT users.id, users.username, users.email, users.role, users.id_branch, tb_branch.nama_branch
     FROM users
     LEFT JOIN tb_branch ON tb_branch.id_branch = users.id_branch
     ORDER BY FIELD(role, 'super_admin', 'admin', 'user'), username ASC
     LIMIT $offset, $limit"
);

$users = [];
while ($row = mysqli_fetch_assoc($usersResult)) {
    $users[] = $row;
}

$branches = [];
$branchesResult = mysqli_query($koneksi, "SELECT id_branch, nama_branch FROM tb_branch ORDER BY nama_branch ASC");
if ($branchesResult) {
    while ($branchRow = mysqli_fetch_assoc($branchesResult)) {
        $branches[] = $branchRow;
    }
}

function role_badge_class(string $role): string
{
    if ($role === 'super_admin') {
        return 'role-badge super-admin';
    }

    if ($role === 'admin') {
        return 'role-badge admin';
    }

    return 'role-badge user';
}

function role_label(string $role): string
{
    if ($role === 'super_admin') {
        return 'Super Admin';
    }

    if ($role === 'admin') {
        return 'Admin';
    }

    return 'User';
}

function role_icon(string $role): string
{
    if ($role === 'super_admin') {
        return 'bi-shield-lock';
    }

    if ($role === 'admin') {
        return 'bi-person-gear';
    }

    return 'bi-person';
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
            font-size: 1.85rem;
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

        .section-card {
            background: #fff;
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-soft);
        }

        .summary-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(180deg, #ffffff 0%, #fffaf3 100%);
            border: 1px solid rgba(255, 176, 0, 0.15);
            border-radius: 22px;
            box-shadow: var(--shadow-soft);
            height: 100%;
            padding: 1.15rem;
            transition: all .25s ease;
        }

        .summary-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 5px;
            background: linear-gradient(90deg, var(--orange-1), var(--orange-3));
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
        }

        .summary-label {
            font-size: .84rem;
            color: var(--text-soft);
            font-weight: 700;
            margin-bottom: .38rem;
        }

        .summary-value {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
            color: var(--dark-1);
            margin-bottom: .35rem;
        }

        .summary-note {
            font-size: .82rem;
            color: var(--text-soft);
            line-height: 1.5;
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

        .form-shell,
        .list-shell {
            background: #fff;
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-soft);
            overflow: hidden;
        }

        .shell-header {
            padding: 1.1rem 1.2rem;
            background: linear-gradient(135deg, #111111 0%, #2c2c2c 40%, #ff8f00 100%);
            color: #fff;
        }

        .shell-title {
            font-size: 1rem;
            font-weight: 800;
            margin-bottom: .2rem;
        }

        .shell-subtitle {
            font-size: .84rem;
            color: rgba(255, 255, 255, 0.82);
        }

        .shell-body {
            padding: 1.2rem;
            background: linear-gradient(180deg, #fffdf9 0%, #fff8ef 100%);
        }

        .form-label {
            font-weight: 700;
            color: var(--dark-1);
            margin-bottom: .45rem;
            font-size: .88rem;
        }

        .form-control,
        .form-select {
            border-radius: 14px;
            min-height: 46px;
            border: 1px solid #e9dcc8;
            box-shadow: none;
            padding: .8rem .95rem;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #f0c63d;
            box-shadow: 0 0 0 .2rem rgba(255, 193, 7, 0.14);
        }

        .btn-main {
            border: none;
            border-radius: 14px;
            font-weight: 800;
            padding: .82rem 1rem;
            background: linear-gradient(135deg, var(--orange-1), var(--orange-3));
            color: #fff;
            box-shadow: 0 12px 28px rgba(255, 152, 0, 0.18);
            transition: all .22s ease;
        }

        .btn-main:hover {
            color: #fff;
            transform: translateY(-1px);
            filter: brightness(.98);
        }

        .btn-soft {
            border-radius: 14px;
            font-weight: 700;
            padding: .78rem 1rem;
            border: 1px solid #e3dfd8;
            background: #fff;
            color: #333;
        }

        .btn-soft:hover {
            background: #fff7ea;
            color: #111;
            border-color: rgba(255, 152, 0, 0.18);
        }

        .nav-tabs.custom-tabs {
            border-bottom: none;
            gap: .65rem;
        }

        .nav-tabs.custom-tabs .nav-link {
            border: 1px solid #e8ddca;
            border-radius: 999px;
            padding: .72rem 1rem;
            font-weight: 800;
            color: #444;
            background: #fff;
        }

        .nav-tabs.custom-tabs .nav-link.active {
            background: linear-gradient(135deg, #111111, #ff8f00);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 10px 24px rgba(255, 143, 0, 0.16);
        }

        .helper-note {
            background: linear-gradient(180deg, #fffaf3 0%, #fff6ea 100%);
            border: 1px solid rgba(255, 152, 0, 0.12);
            border-radius: 16px;
            padding: .95rem 1rem;
            color: var(--text-soft);
            font-size: .88rem;
            line-height: 1.6;
        }

        .row-badge {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            border-radius: 999px;
            background: #fff3de;
            color: #8b4f00;
            border: 1px solid rgba(255, 152, 0, 0.16);
            padding: .35rem .7rem;
            font-size: .8rem;
            font-weight: 800;
        }

        .user-card {
            border: 1px solid rgba(255, 176, 0, 0.12);
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 8px 20px rgba(17, 17, 17, 0.04);
            overflow: hidden;
        }

        .user-card+.user-card {
            margin-top: 1rem;
        }

        .user-top {
            padding: 1rem 1rem;
            background: linear-gradient(180deg, #fffdf9 0%, #fff7ee 100%);
        }

        .user-avatar {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: linear-gradient(135deg, #111111, #ff8f00);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            font-weight: 800;
            box-shadow: 0 10px 24px rgba(255, 143, 0, 0.16);
            flex-shrink: 0;
        }

        .user-name {
            font-size: 1rem;
            font-weight: 800;
            color: var(--dark-1);
            margin-bottom: .15rem;
        }

        .user-email {
            font-size: .88rem;
            color: var(--text-soft);
            line-height: 1.5;
        }

        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            border-radius: 999px;
            padding: .42rem .75rem;
            font-size: .76rem;
            font-weight: 800;
        }

        .role-badge.super-admin {
            background: #222;
            color: #fff;
        }

        .role-badge.admin {
            background: #fff3de;
            color: #8b4f00;
            border: 1px solid rgba(255, 152, 0, 0.16);
        }

        .role-badge.user {
            background: #eef6ff;
            color: #285ea8;
            border: 1px solid rgba(40, 94, 168, 0.15);
        }

        .user-actions {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
        }

        .btn-pill {
            border-radius: 999px;
            font-weight: 800;
            padding: .68rem 1rem;
        }

        .btn-edit {
            background: linear-gradient(135deg, #111111, #2d2d2d);
            color: #fff;
            border: none;
        }

        .btn-delete {
            background: #fff;
            color: #c62828;
            border: 1px solid rgba(198, 40, 40, 0.18);
        }

        .btn-edit:hover,
        .btn-delete:hover {
            transform: translateY(-1px);
        }

        .edit-area {
            padding: 1rem;
            border-top: 1px solid #f0ece4;
            background: #fff;
        }

        .table-lite {
            width: 100%;
            min-width: 980px;
        }

        .table-lite th {
            white-space: nowrap;
            font-size: .85rem;
            color: #444;
            padding-bottom: .85rem;
        }

        .table-lite td {
            vertical-align: top;
            padding-top: .8rem;
            padding-bottom: .8rem;
            border-top: 1px solid #f2ece2;
        }

        .table-lite .form-control,
        .table-lite .form-select {
            min-width: 160px;
        }

        .invalid-feedback {
            display: block;
        }

        .empty-state {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--text-soft);
        }

        .empty-state i {
            display: block;
            font-size: 1.7rem;
            margin-bottom: .45rem;
            color: var(--orange-2);
        }

        .limit-box {
            display: flex;
            align-items: center;
            gap: .6rem;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 999px;
            padding: .45rem .55rem .45rem .85rem;
        }

        .limit-box .form-select {
            min-width: 86px;
            border-radius: 999px;
            border: none;
            box-shadow: none;
            font-weight: 700;
        }

        .limit-box .section-label {
            color: rgba(255, 255, 255, 0.88);
            font-size: .82rem;
            font-weight: 700;
            margin: 0;
        }

        .pagination {
            gap: .35rem;
        }

        .pagination .page-link {
            border-radius: 12px;
            color: #2a2a2a;
            border: 1px solid #ead9bf;
            padding: .6rem .85rem;
            font-weight: 700;
            box-shadow: none;
        }

        .pagination .page-link:hover {
            background: #fff4de;
            color: #111;
        }

        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, #111111, #ff8f00);
            border-color: transparent;
            color: #fff;
        }

        .select2-container {
            width: 100% !important;
        }

        .select2-container--default .select2-selection--single {
            min-height: 46px;
            border: 1px solid #e9dcc8 !important;
            border-radius: 14px !important;
            padding: .45rem .75rem;
            display: flex;
            align-items: center;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 1.6 !important;
            padding-left: 0 !important;
            color: #1e1e1e;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 100% !important;
            right: 10px !important;
        }

        .select2-dropdown {
            border: 1px solid #e9dcc8 !important;
            border-radius: 14px !important;
            overflow: hidden;
        }

        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: #f0c63d !important;
            box-shadow: 0 0 0 .2rem rgba(255, 193, 7, 0.14) !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__placeholder {
            color: #6b7280 !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__clear {
            margin-right: 10px;
        }

        .form-select.is-invalid+.select2 .select2-selection {
            border-color: #dc3545 !important;
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

            .shell-body,
            .shell-header {
                padding-left: 1rem;
                padding-right: 1rem;
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
            .btn-main,
            .btn-soft,
            .btn-pill {
                width: 100%;
                justify-content: center;
            }

            .nav-tabs.custom-tabs .nav-link {
                width: 100%;
                text-align: center;
            }
        }

        .password-group {
            position: relative;
        }

        .password-group .form-control {
            padding-right: 48px;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #6b7280;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all .2s ease;
        }

        .password-toggle:hover {
            background: #fff3e0;
            color: #111;
        }

        .password-toggle:focus {
            outline: none;
            box-shadow: 0 0 0 .2rem rgba(255, 193, 7, 0.14);
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <?php require_once '../layout/sidebar.php'; ?>

            <div class="col-md-10">
                <div class="page-wrap">

                    <div class="hero-card">
                        <div class="hero-content d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div>
                                <h1 class="page-title">Kelola User</h1>
                                <p class="page-subtitle">
                                    Manajemen akun pengguna, pengaturan role, dan kontrol akses sistem dalam tampilan yang lebih rapi, modern, dan nyaman digunakan.
                                </p>
                            </div>

                            <a href="<?= e(base_url('users/role_permissions.php')) ?>" class="hero-action">
                                <i class="bi bi-shield-check me-2"></i>Hak Akses Role
                            </a>
                        </div>
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
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Total User</div>
                                        <div class="summary-value"><?= $totalUsers ?></div>
                                        <div class="summary-note">Semua akun yang terdaftar di sistem</div>
                                    </div>
                                    <div class="summary-icon">
                                        <i class="bi bi-people"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Super Admin</div>
                                        <div class="summary-value"><?= $superAdminTotal ?></div>
                                        <div class="summary-note">Akun dengan akses penuh sistem</div>
                                    </div>
                                    <div class="summary-icon">
                                        <i class="bi bi-shield-lock"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Admin & User</div>
                                        <div class="summary-value"><?= $adminTotal + $userTotal ?></div>
                                        <div class="summary-note">Akun operasional aktif di sistem</div>
                                    </div>
                                    <div class="summary-icon">
                                        <i class="bi bi-person-gear"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4 mb-4">
                        <div class="col-lg-4">
                            <div class="form-shell h-100">
                                <div class="shell-header">
                                    <div class="shell-title">Tambah User Baru</div>
                                    <div class="shell-subtitle">Buat 1 akun dengan cepat dan langsung simpan.</div>
                                </div>

                                <div class="shell-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="create">

                                        <div class="mb-3">
                                            <label class="form-label">Username</label>
                                            <input type="text" name="username" class="form-control" required>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Email</label>
                                            <input type="email" name="email" class="form-control" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Password</label>
                                            <div class="password-group">
                                                <input type="password" name="password" class="form-control password-field" placeholder="Kosongkan jika ingin password default">
                                                <button type="button" class="password-toggle toggle-password" aria-label="Lihat password">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Role</label>
                                            <select name="role" class="form-select">
                                                <option value="user">User</option>
                                                <option value="admin">Admin</option>
                                                <option value="super_admin">Super Admin</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Cabang</label>
                                            <select name="id_branch" class="form-select branch-select" required>
                                                <option value=""></option>
                                                <?php foreach ($branches as $branch): ?>
                                                    <option value="<?= (int) $branch['id_branch'] ?>"><?= e($branch['nama_branch']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <button type="submit" class="btn btn-main w-100">
                                            <i class="bi bi-plus-circle me-2"></i>Simpan User
                                        </button>
                                    </form>

                                    <div class="helper-note mt-3">
                                        Form ini cocok untuk menambah satu akun secara cepat tanpa membuka tabel massal.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-8">
                            <div class="form-shell h-100">
                                <div class="shell-header">
                                    <div class="shell-title">Tambah Banyak User</div>
                                    <div class="shell-subtitle">Pilih tampilan form yang paling nyaman untuk input banyak akun sekaligus.</div>
                                </div>

                                <div class="shell-body">
                                    <ul class="nav nav-tabs custom-tabs mb-4" id="userFormTabs" role="tablist">
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabBulkCard" type="button">
                                                Form Kartu
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabBulkTable" type="button">
                                                Form Tabel
                                            </button>
                                        </li>
                                    </ul>

                                    <div class="tab-content">
                                        <div class="tab-pane fade show active" id="tabBulkCard">
                                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                                <div class="helper-note flex-grow-1">
                                                    Isi banyak user dalam format kartu. Cocok untuk input bertahap dan lebih nyaman di layar kecil.
                                                </div>

                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <span class="row-badge">
                                                        <span id="bulkRowCounter"><?= count($bulkOldRows) ?></span> / 10 baris
                                                    </span>
                                                    <button type="button" id="btnAddBulkRow" class="btn btn-soft">
                                                        <i class="bi bi-plus-circle me-1"></i>Tambah Baris
                                                    </button>
                                                </div>
                                            </div>

                                            <form method="POST" id="bulkCreateForm" novalidate>
                                                <input type="hidden" name="action" value="bulk_create">

                                                <div id="bulkUserRows">
                                                    <?php foreach ($bulkOldRows as $index => $bulkRow): ?>
                                                        <div class="section-card p-3 mb-3 bulk-user-row" data-row-index="<?= $index ?>">
                                                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                                                <div class="row-badge">
                                                                    Baris <span class="bulk-row-number"><?= $index + 1 ?></span>
                                                                </div>

                                                                <button type="button" class="btn btn-outline-danger btn-sm btnRemoveBulkRow" <?= count($bulkOldRows) === 1 ? 'disabled' : '' ?>>
                                                                    Hapus Baris
                                                                </button>
                                                            </div>

                                                            <div class="row g-3">
                                                                <div class="col-lg-6">
                                                                    <label class="form-label">Username</label>
                                                                    <input
                                                                        type="text"
                                                                        class="form-control <?= bulk_field_error($bulkErrors, $index, 'username') ? 'is-invalid' : '' ?>"
                                                                        value="<?= e($bulkRow['username']) ?>"
                                                                        data-field="username"
                                                                        data-name-template="bulk_users[__INDEX__][username]"
                                                                        name="bulk_users[<?= $index ?>][username]">
                                                                    <div class="invalid-feedback"><?= e(bulk_field_error($bulkErrors, $index, 'username')) ?></div>
                                                                </div>

                                                                <div class="col-lg-6">
                                                                    <label class="form-label">Email</label>
                                                                    <input
                                                                        type="email"
                                                                        class="form-control <?= bulk_field_error($bulkErrors, $index, 'email') ? 'is-invalid' : '' ?>"
                                                                        value="<?= e($bulkRow['email']) ?>"
                                                                        data-field="email"
                                                                        data-name-template="bulk_users[__INDEX__][email]"
                                                                        name="bulk_users[<?= $index ?>][email]">
                                                                    <div class="invalid-feedback"><?= e(bulk_field_error($bulkErrors, $index, 'email')) ?></div>
                                                                </div>

                                                                <div class="col-lg-6">
                                                                    <label class="form-label">Password</label>
                                                                    <div class="password-group">
                                                                        <input
                                                                            type="password"
                                                                            class="form-control password-field <?= bulk_field_error($bulkErrors, $index, 'password') ? 'is-invalid' : '' ?>"
                                                                            value=""
                                                                            data-field="password"
                                                                            data-name-template="bulk_users[__INDEX__][password]"
                                                                            name="bulk_users[<?= $index ?>][password]"
                                                                            placeholder="Kosongkan jika ingin password default">
                                                                        <button type="button" class="password-toggle toggle-password" aria-label="Lihat password">
                                                                            <i class="bi bi-eye"></i>
                                                                        </button>
                                                                    </div>
                                                                    <div class="invalid-feedback"><?= e(bulk_field_error($bulkErrors, $index, 'password')) ?></div>
                                                                </div>

                                                                <div class="col-lg-6">
                                                                    <label class="form-label">Role</label>
                                                                    <select
                                                                        class="form-select"
                                                                        data-field="role"
                                                                        data-name-template="bulk_users[__INDEX__][role]"
                                                                        name="bulk_users[<?= $index ?>][role]">
                                                                        <option value="user" <?= $bulkRow['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                                                        <option value="admin" <?= $bulkRow['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                                        <option value="super_admin" <?= $bulkRow['role'] === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                                                                    </select>
                                                                    <div class="invalid-feedback"></div>
                                                                </div>

                                                                <div class="col-lg-6">
                                                                    <label class="form-label">Cabang</label>
                                                                    <select
                                                                        class="form-select branch-select <?= bulk_field_error($bulkErrors, $index, 'id_branch') ? 'is-invalid' : '' ?>"
                                                                        data-field="id_branch"
                                                                        data-name-template="bulk_users[__INDEX__][id_branch]"
                                                                        name="bulk_users[<?= $index ?>][id_branch]">
                                                                        <option value=""></option>
                                                                        <?php foreach ($branches as $branch): ?>
                                                                            <option value="<?= (int) $branch['id_branch'] ?>" <?= (int) ($bulkRow['id_branch'] ?? 0) === (int) $branch['id_branch'] ? 'selected' : '' ?>>
                                                                                <?= e($branch['nama_branch']) ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                    <div class="invalid-feedback"><?= e(bulk_field_error($bulkErrors, $index, 'id_branch')) ?></div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>

                                                <div class="d-flex justify-content-end">
                                                    <button type="submit" class="btn btn-main">
                                                        <i class="bi bi-check2-circle me-2"></i>Simpan Banyak User
                                                    </button>
                                                </div>
                                            </form>

                                            <template id="bulkUserRowTemplate">
                                                <div class="section-card p-3 mb-3 bulk-user-row" data-row-index="0">
                                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                                        <div class="row-badge">
                                                            Baris <span class="bulk-row-number">1</span>
                                                        </div>

                                                        <button type="button" class="btn btn-outline-danger btn-sm btnRemoveBulkRow">
                                                            Hapus Baris
                                                        </button>
                                                    </div>

                                                    <div class="row g-3">
                                                        <div class="col-lg-6">
                                                            <label class="form-label">Username</label>
                                                            <input
                                                                type="text"
                                                                class="form-control"
                                                                value=""
                                                                data-field="username"
                                                                data-name-template="bulk_users[__INDEX__][username]"
                                                                name="">
                                                            <div class="invalid-feedback"></div>
                                                        </div>

                                                        <div class="col-lg-6">
                                                            <label class="form-label">Email</label>
                                                            <input
                                                                type="email"
                                                                class="form-control"
                                                                value=""
                                                                data-field="email"
                                                                data-name-template="bulk_users[__INDEX__][email]"
                                                                name="">
                                                            <div class="invalid-feedback"></div>
                                                        </div>

                                                        <div class="col-lg-6">
                                                            <label class="form-label">Password</label>
                                                            <div class="password-group">
                                                                <input
                                                                    type="password"
                                                                    class="form-control password-field"
                                                                    value=""
                                                                    data-field="password"
                                                                    data-name-template="bulk_users[__INDEX__][password]"
                                                                    name=""
                                                                    placeholder="Kosongkan jika ingin password default">
                                                                <button type="button" class="password-toggle toggle-password" aria-label="Lihat password">
                                                                    <i class="bi bi-eye"></i>
                                                                </button>
                                                            </div>
                                                            <div class="invalid-feedback"></div>
                                                        </div>

                                                        <div class="col-lg-6">
                                                            <label class="form-label">Role</label>
                                                            <select
                                                                class="form-select"
                                                                data-field="role"
                                                                data-name-template="bulk_users[__INDEX__][role]"
                                                                name="">
                                                                <option value="user" selected>User</option>
                                                                <option value="admin">Admin</option>
                                                                <option value="super_admin">Super Admin</option>
                                                            </select>
                                                            <div class="invalid-feedback"></div>
                                                        </div>

                                                        <div class="col-lg-6">
                                                            <label class="form-label">Cabang</label>
                                                            <select
                                                                class="form-select branch-select"
                                                                data-field="id_branch"
                                                                data-name-template="bulk_users[__INDEX__][id_branch]"
                                                                name="">
                                                                <option value=""></option>
                                                                <?php foreach ($branches as $branch): ?>
                                                                    <option value="<?= (int) $branch['id_branch'] ?>"><?= e($branch['nama_branch']) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <div class="invalid-feedback"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>

                                        <div class="tab-pane fade" id="tabBulkTable">
                                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                                <div class="helper-note flex-grow-1">
                                                    Form tabel cocok untuk input cepat saat ingin mengisi banyak user sekaligus dalam satu pandangan.
                                                </div>

                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <span class="row-badge">
                                                        <span id="userRowCounter"><?= count($createUserRowsOld) ?></span> / 10 baris
                                                    </span>

                                                    <button type="button" id="btnAddUserRow" class="btn btn-soft">
                                                        <i class="bi bi-plus-circle me-1"></i>Tambah Baris
                                                    </button>
                                                </div>
                                            </div>

                                            <?php if ($createUserGeneralError !== ''): ?>
                                                <div class="alert alert-danger py-2 px-3 mb-3">
                                                    <?= e($createUserGeneralError) ?>
                                                </div>
                                            <?php endif; ?>

                                            <form method="POST" id="multiUserCreateForm" novalidate>
                                                <input type="hidden" name="action" value="save_users">

                                                <div class="table-responsive">
                                                    <table class="table-lite">
                                                        <thead>
                                                            <tr>
                                                                <th style="width:90px;">Baris</th>
                                                                <th>Username</th>
                                                                <th>Email</th>
                                                                <th>Password</th>
                                                                <th style="width:180px;">Role</th>
                                                                <th style="width:220px;">Cabang</th>
                                                                <th style="width:140px;">Aksi</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody id="userRowContainer">
                                                            <?php foreach ($createUserRowsOld as $index => $row): ?>
                                                                <tr class="user-create-row" data-row-index="<?= $index ?>">
                                                                    <td>
                                                                        <span class="row-badge user-row-number"><?= $index + 1 ?></span>
                                                                    </td>

                                                                    <td>
                                                                        <input
                                                                            type="text"
                                                                            class="form-control <?= create_user_field_error($createUserRowsErrors, $index, 'username') ? 'is-invalid' : '' ?>"
                                                                            value="<?= e($row['username']) ?>"
                                                                            data-field="username"
                                                                            data-name-template="users[__INDEX__][username]"
                                                                            name="users[<?= $index ?>][username]">
                                                                        <div class="invalid-feedback"><?= e(create_user_field_error($createUserRowsErrors, $index, 'username')) ?></div>
                                                                    </td>

                                                                    <td>
                                                                        <input
                                                                            type="email"
                                                                            class="form-control <?= create_user_field_error($createUserRowsErrors, $index, 'email') ? 'is-invalid' : '' ?>"
                                                                            value="<?= e($row['email']) ?>"
                                                                            data-field="email"
                                                                            data-name-template="users[__INDEX__][email]"
                                                                            name="users[<?= $index ?>][email]">
                                                                        <div class="invalid-feedback"><?= e(create_user_field_error($createUserRowsErrors, $index, 'email')) ?></div>
                                                                    </td>

                                                                    <td>
                                                                        <div class="password-group">
                                                                            <input
                                                                                type="password"
                                                                                class="form-control password-field <?= create_user_field_error($createUserRowsErrors, $index, 'password') ? 'is-invalid' : '' ?>"
                                                                                value=""
                                                                                data-field="password"
                                                                                data-name-template="users[__INDEX__][password]"
                                                                                name="users[<?= $index ?>][password]"
                                                                                placeholder="Password default">
                                                                            <button type="button" class="password-toggle toggle-password" aria-label="Lihat password">
                                                                                <i class="bi bi-eye"></i>
                                                                            </button>
                                                                        </div>
                                                                        <div class="invalid-feedback"><?= e(create_user_field_error($createUserRowsErrors, $index, 'password')) ?></div>
                                                                    </td>

                                                                    <td>
                                                                        <select
                                                                            class="form-select"
                                                                            data-field="role"
                                                                            data-name-template="users[__INDEX__][role]"
                                                                            name="users[<?= $index ?>][role]">
                                                                            <option value="user" <?= $row['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                                                            <option value="admin" <?= $row['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                                            <option value="super_admin" <?= $row['role'] === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                                                                        </select>
                                                                        <div class="invalid-feedback"></div>
                                                                    </td>

                                                                    <td>
                                                                        <select
                                                                            class="form-select branch-select <?= create_user_field_error($createUserRowsErrors, $index, 'id_branch') ? 'is-invalid' : '' ?>"
                                                                            data-field="id_branch"
                                                                            data-name-template="users[__INDEX__][id_branch]"
                                                                            name="users[<?= $index ?>][id_branch]">
                                                                            <option value=""></option>
                                                                            <?php foreach ($branches as $branch): ?>
                                                                                <option value="<?= (int) $branch['id_branch'] ?>" <?= (int) ($row['id_branch'] ?? 0) === (int) $branch['id_branch'] ? 'selected' : '' ?>>
                                                                                    <?= e($branch['nama_branch']) ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                        <div class="invalid-feedback"><?= e(create_user_field_error($createUserRowsErrors, $index, 'id_branch')) ?></div>
                                                                    </td>

                                                                    <td>
                                                                        <button
                                                                            type="button"
                                                                            class="btn btn-outline-danger btn-sm btnRemoveUserRow"
                                                                            <?= count($createUserRowsOld) === 1 ? 'disabled' : '' ?>>
                                                                            Hapus
                                                                        </button>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>

                                                <div class="d-flex justify-content-end mt-3">
                                                    <button type="submit" class="btn btn-main">
                                                        <i class="bi bi-check2-circle me-2"></i>Simpan User
                                                    </button>
                                                </div>
                                            </form>

                                            <template id="userRowTemplate">
                                                <tr class="user-create-row" data-row-index="0">
                                                    <td>
                                                        <span class="row-badge user-row-number">1</span>
                                                    </td>

                                                    <td>
                                                        <input
                                                            type="text"
                                                            class="form-control"
                                                            value=""
                                                            data-field="username"
                                                            data-name-template="users[__INDEX__][username]"
                                                            name="">
                                                        <div class="invalid-feedback"></div>
                                                    </td>

                                                    <td>
                                                        <input
                                                            type="email"
                                                            class="form-control"
                                                            value=""
                                                            data-field="email"
                                                            data-name-template="users[__INDEX__][email]"
                                                            name="">
                                                        <div class="invalid-feedback"></div>
                                                    </td>

                                                    <td>
                                                        <div class="password-group">
                                                            <input
                                                                type="password"
                                                                class="form-control password-field"
                                                                value=""
                                                                data-field="password"
                                                                data-name-template="users[__INDEX__][password]"
                                                                name=""
                                                                placeholder="Password default">
                                                            <button type="button" class="password-toggle toggle-password" aria-label="Lihat password">
                                                                <i class="bi bi-eye"></i>
                                                            </button>
                                                        </div>
                                                        <div class="invalid-feedback"></div>
                                                    </td>

                                                    <td>
                                                        <select
                                                            class="form-select"
                                                            data-field="role"
                                                            data-name-template="users[__INDEX__][role]"
                                                            name="">
                                                            <option value="user" selected>User</option>
                                                            <option value="admin">Admin</option>
                                                            <option value="super_admin">Super Admin</option>
                                                        </select>
                                                        <div class="invalid-feedback"></div>
                                                    </td>

                                                    <td>
                                                        <select
                                                            class="form-select branch-select"
                                                            data-field="id_branch"
                                                            data-name-template="users[__INDEX__][id_branch]"
                                                            name="">
                                                            <option value=""></option>
                                                            <?php foreach ($branches as $branch): ?>
                                                                <option value="<?= (int) $branch['id_branch'] ?>"><?= e($branch['nama_branch']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <div class="invalid-feedback"></div>
                                                    </td>

                                                    <td>
                                                        <button type="button" class="btn btn-outline-danger btn-sm btnRemoveUserRow">
                                                            Hapus
                                                        </button>
                                                    </td>
                                                </tr>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="list-shell">
                        <div class="shell-header">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                                <div>
                                    <div class="shell-title">Daftar User Sistem</div>
                                    <div class="shell-subtitle">
                                        Menampilkan <?= $displayFrom ?> - <?= $displayTo ?> dari <?= $totalUsers ?> user
                                    </div>
                                </div>

                                <form method="GET" class="mb-0">
                                    <div class="limit-box">
                                        <span class="section-label">Tampilkan</span>
                                        <select name="limit" onchange="this.form.submit()" class="form-select form-select-sm">
                                            <option value="5" <?= $limit == 5 ? 'selected' : '' ?>>5</option>
                                            <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                                            <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                                            <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                                        </select>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="shell-body">
                            <?php if (!empty($users)): ?>
                                <?php foreach ($users as $user): ?>
                                    <?php $collapseId = 'editUserCollapse' . (int) $user['id']; ?>
                                    <div class="user-card">
                                        <div class="user-top">
                                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                                <div class="d-flex align-items-start gap-3">
                                                    <div class="user-avatar">
                                                        <?= strtoupper(substr($user['username'], 0, 1)) ?>
                                                    </div>

                                                    <div>
                                                        <div class="user-name"><?= e($user['username']) ?></div>
                                                        <div class="user-email"><?= e($user['email']) ?></div>
                                                        <div class="user-email mt-1">
                                                            <i class="bi bi-geo-alt me-1"></i><?= e($user['nama_branch'] ?? 'Belum ditentukan') ?>
                                                        </div>
                                                        <div class="mt-2">
                                                            <span class="<?= role_badge_class($user['role']) ?>">
                                                                <i class="bi <?= role_icon($user['role']) ?>"></i>
                                                                <?= role_label($user['role']) ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="user-actions">
                                                    <button
                                                        type="button"
                                                        class="btn btn-pill btn-edit"
                                                        data-bs-toggle="collapse"
                                                        data-bs-target="#<?= $collapseId ?>">
                                                        <i class="bi bi-pencil-square me-2"></i>Edit User
                                                    </button>

                                                    <form method="POST" class="formDeleteUser">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                                        <input type="hidden" name="username" value="<?= e($user['username']) ?>">

                                                        <button type="button" class="btn btn-pill btn-delete btnDeleteUser">
                                                            <i class="bi bi-trash3 me-2"></i>Hapus
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="collapse" id="<?= $collapseId ?>">
                                            <div class="edit-area">
                                                <form method="POST" id="updateUserForm<?= (int) $user['id'] ?>">
                                                    <input type="hidden" name="action" value="update">
                                                    <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">

                                                    <div class="row g-3">
                                                        <div class="col-lg-4">
                                                            <label class="form-label">Username</label>
                                                            <input type="text" name="username" class="form-control" value="<?= e($user['username']) ?>" required>
                                                        </div>

                                                        <div class="col-lg-4">
                                                            <label class="form-label">Email</label>
                                                            <input type="email" name="email" class="form-control" value="<?= e($user['email']) ?>" required>
                                                        </div>

                                                        <div class="col-lg-4">
                                                            <label class="form-label">Role</label>
                                                            <select name="role" class="form-select">
                                                                <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                                <option value="super_admin" <?= $user['role'] === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                                                            </select>
                                                        </div>

                                                        <div class="col-lg-6">
                                                            <label class="form-label">Cabang</label>
                                                            <select name="id_branch" class="form-select branch-select" required>
                                                                <option value=""></option>
                                                                <?php foreach ($branches as $branch): ?>
                                                                    <option value="<?= (int) $branch['id_branch'] ?>" <?= (int) ($user['id_branch'] ?? 0) === (int) $branch['id_branch'] ? 'selected' : '' ?>>
                                                                        <?= e($branch['nama_branch']) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="col-lg-6">
                                                            <label class="form-label">Password Baru</label>
                                                            <div class="password-group">
                                                                <input type="password" name="new_password" class="form-control password-field" placeholder="Kosongkan jika tidak ingin mengganti password">
                                                                <button type="button" class="password-toggle toggle-password" aria-label="Lihat password">
                                                                    <i class="bi bi-eye"></i>
                                                                </button>
                                                            </div>
                                                        </div>

                                                        <div class="col-lg-6 d-flex align-items-end">
                                                            <button
                                                                type="button"
                                                                class="btn btn-main w-100 btnUpdateUser"
                                                                data-form-id="updateUserForm<?= (int) $user['id'] ?>"
                                                                data-username="<?= e($user['username']) ?>">
                                                                <i class="bi bi-check2-circle me-2"></i>Simpan Perubahan
                                                            </button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="bi bi-inbox"></i>
                                    Belum ada data user.
                                </div>
                            <?php endif; ?>

                            <?php if ($totalPages > 1): ?>
                                <div class="mt-4">
                                    <nav>
                                        <ul class="pagination mb-0 flex-wrap">
                                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                                <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                                    <a class="page-link" href="?page=<?= $i ?>&limit=<?= $limit ?>">
                                                        <?= $i ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function initBranchSelect(scope = document) {
                const selects = scope.querySelectorAll('select.branch-select');

                selects.forEach(function(select) {
                    if ($(select).hasClass('select2-hidden-accessible')) {
                        return;
                    }

                    $(select).select2({
                        width: '100%',
                        placeholder: 'Cari cabang...',
                        allowClear: true
                    });
                });
            }

            document.addEventListener('click', function(e) {
                const toggleButton = e.target.closest('.toggle-password');
                if (!toggleButton) return;

                const wrapper = toggleButton.closest('.password-group');
                if (!wrapper) return;

                const input = wrapper.querySelector('input');
                const icon = toggleButton.querySelector('i');

                if (!input) return;

                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';

                if (icon) {
                    icon.classList.toggle('bi-eye', !isPassword);
                    icon.classList.toggle('bi-eye-slash', isPassword);
                }
            });

            initBranchSelect();

            document.querySelectorAll('.btnUpdateUser').forEach(function(button) {
                button.addEventListener('click', function() {
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
                        confirmButtonColor: '#ff9800'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            form.submit();
                        }
                    });
                });
            });

            document.querySelectorAll('.btnDeleteUser').forEach(function(button) {
                button.addEventListener('click', function() {
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

            const bulkForm = document.getElementById('bulkCreateForm');
            const bulkContainer = document.getElementById('bulkUserRows');
            const bulkTemplate = document.getElementById('bulkUserRowTemplate');
            const bulkAddButton = document.getElementById('btnAddBulkRow');
            const bulkCounter = document.getElementById('bulkRowCounter');
            const bulkMaxRows = 10;

            function updateBulkRows() {
                if (!bulkContainer) return;

                const rows = bulkContainer.querySelectorAll('.bulk-user-row');

                rows.forEach(function(row, index) {
                    row.dataset.rowIndex = index;

                    const numberEl = row.querySelector('.bulk-row-number');
                    if (numberEl) {
                        numberEl.textContent = index + 1;
                    }

                    row.querySelectorAll('[data-name-template]').forEach(function(field) {
                        field.name = field.dataset.nameTemplate.replace(/__INDEX__/g, index);
                    });

                    const removeButton = row.querySelector('.btnRemoveBulkRow');
                    if (removeButton) {
                        removeButton.disabled = rows.length === 1;
                    }
                });

                if (bulkCounter) {
                    bulkCounter.textContent = rows.length;
                }

                if (bulkAddButton) {
                    bulkAddButton.disabled = rows.length >= bulkMaxRows;
                }
            }

            function clearBulkRowErrors(row) {
                row.querySelectorAll('.form-control, .form-select').forEach(function(field) {
                    field.classList.remove('is-invalid');
                });

                row.querySelectorAll('.invalid-feedback').forEach(function(feedback) {
                    feedback.textContent = '';
                });
            }

            function setBulkFieldError(row, fieldName, message) {
                const field = row.querySelector('[data-field="' + fieldName + '"]');
                if (!field) return;

                field.classList.add('is-invalid');

                const feedback = field.parentElement.querySelector('.invalid-feedback');
                if (feedback) {
                    feedback.textContent = message;
                }
            }

            function getBulkRowData(row) {
                return {
                    username: row.querySelector('[data-field="username"]').value.trim(),
                    email: row.querySelector('[data-field="email"]').value.trim(),
                    password: row.querySelector('[data-field="password"]').value,
                    role: row.querySelector('[data-field="role"]').value,
                    id_branch: row.querySelector('[data-field="id_branch"]').value
                };
            }

            function addBulkRow(data = {}) {
                if (!bulkContainer || !bulkTemplate) return;

                const currentRows = bulkContainer.querySelectorAll('.bulk-user-row').length;
                if (currentRows >= bulkMaxRows) return;

                const fragment = bulkTemplate.content.cloneNode(true);
                const row = fragment.querySelector('.bulk-user-row');

                row.querySelector('[data-field="username"]').value = data.username || '';
                row.querySelector('[data-field="email"]').value = data.email || '';
                row.querySelector('[data-field="password"]').value = '';
                row.querySelector('[data-field="role"]').value = data.role || 'user';
                row.querySelector('[data-field="id_branch"]').value = data.id_branch || '';

                bulkContainer.appendChild(row);
                updateBulkRows();
                initBranchSelect(row);
            }

            function validateBulkForm() {
                let isValid = true;
                let filledCount = 0;

                if (!bulkContainer) {
                    return {
                        isValid: false,
                        filledCount: 0
                    };
                }

                const rows = Array.from(bulkContainer.querySelectorAll('.bulk-user-row'));
                const usernameMap = {};
                const emailMap = {};

                rows.forEach(function(row) {
                    clearBulkRowErrors(row);
                    const data = getBulkRowData(row);
                    const hasValue = data.username !== '' || data.email !== '' || data.password !== '';

                    if (!hasValue) return;
                    filledCount++;

                    if (data.username === '') {
                        setBulkFieldError(row, 'username', 'Username wajib diisi.');
                        isValid = false;
                    }

                    if (data.email === '') {
                        setBulkFieldError(row, 'email', 'Email wajib diisi.');
                        isValid = false;
                    } else {
                        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        if (!emailPattern.test(data.email)) {
                            setBulkFieldError(row, 'email', 'Format email tidak valid.');
                            isValid = false;
                        }
                    }

                    if (!data.id_branch) {
                        setBulkFieldError(row, 'id_branch', 'Cabang wajib dipilih.');
                        isValid = false;
                    }

                    if (data.username !== '') {
                        const usernameKey = data.username.toLowerCase();
                        if (!usernameMap[usernameKey]) usernameMap[usernameKey] = [];
                        usernameMap[usernameKey].push(row);
                    }

                    if (data.email !== '') {
                        const emailKey = data.email.toLowerCase();
                        if (!emailMap[emailKey]) emailMap[emailKey] = [];
                        emailMap[emailKey].push(row);
                    }
                });

                if (filledCount === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Form kosong',
                        text: 'Isi minimal 1 user pada form tambah banyak.'
                    });
                    return {
                        isValid: false,
                        filledCount: 0
                    };
                }

                Object.keys(usernameMap).forEach(function(key) {
                    if (usernameMap[key].length > 1) {
                        usernameMap[key].forEach(function(row) {
                            setBulkFieldError(row, 'username', 'Username duplikat.');
                        });
                        isValid = false;
                    }
                });

                Object.keys(emailMap).forEach(function(key) {
                    if (emailMap[key].length > 1) {
                        emailMap[key].forEach(function(row) {
                            setBulkFieldError(row, 'email', 'Email duplikat.');
                        });
                        isValid = false;
                    }
                });

                return {
                    isValid: isValid,
                    filledCount: filledCount
                };
            }

            if (bulkAddButton) {
                bulkAddButton.addEventListener('click', function() {
                    addBulkRow();
                });
            }

            if (bulkContainer) {
                bulkContainer.addEventListener('click', function(e) {
                    const removeButton = e.target.closest('.btnRemoveBulkRow');
                    if (!removeButton) return;

                    const row = removeButton.closest('.bulk-user-row');
                    if (!row) return;

                    const totalRows = bulkContainer.querySelectorAll('.bulk-user-row').length;
                    if (totalRows <= 1) return;

                    row.remove();
                    updateBulkRows();
                });

                bulkContainer.addEventListener('input', function(e) {
                    const field = e.target.closest('.form-control, .form-select');
                    if (!field) return;

                    field.classList.remove('is-invalid');
                    const feedback = field.parentElement.querySelector('.invalid-feedback');
                    if (feedback) {
                        feedback.textContent = '';
                    }
                });
            }

            if (bulkForm) {
                bulkForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    const result = validateBulkForm();
                    if (!result.isValid) return;

                    Swal.fire({
                        title: 'Simpan user?',
                        text: result.filledCount + ' user akan dibuat.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Ya, simpan',
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#16a34a'
                    }).then((swalResult) => {
                        if (swalResult.isConfirmed) {
                            bulkForm.submit();
                        }
                    });
                });

                updateBulkRows();
            }

            const form = document.getElementById('multiUserCreateForm');
            const rowContainer = document.getElementById('userRowContainer');
            const rowTemplate = document.getElementById('userRowTemplate');
            const addRowButton = document.getElementById('btnAddUserRow');
            const rowCounter = document.getElementById('userRowCounter');
            const maxRows = 10;

            function updateRows() {
                if (!rowContainer) return;

                const rows = rowContainer.querySelectorAll('.user-create-row');

                rows.forEach(function(row, index) {
                    row.dataset.rowIndex = index;

                    const numberEl = row.querySelector('.user-row-number');
                    if (numberEl) {
                        numberEl.textContent = index + 1;
                    }

                    row.querySelectorAll('[data-name-template]').forEach(function(field) {
                        field.name = field.dataset.nameTemplate.replace(/__INDEX__/g, index);
                    });

                    const removeButton = row.querySelector('.btnRemoveUserRow');
                    if (removeButton) {
                        removeButton.disabled = rows.length === 1;
                    }
                });

                if (rowCounter) {
                    rowCounter.textContent = rows.length;
                }

                if (addRowButton) {
                    addRowButton.disabled = rows.length >= maxRows;
                }
            }

            function clearGeneralError() {
                if (!form) return;

                const generalError = form.parentElement.querySelector('.alert.alert-danger');
                if (generalError && generalError.textContent.includes('Isi minimal 1 baris user.')) {
                    generalError.remove();
                }
            }

            function clearRowErrors(row) {
                row.querySelectorAll('.form-control, .form-select').forEach(function(field) {
                    field.classList.remove('is-invalid');
                });

                row.querySelectorAll('.invalid-feedback').forEach(function(feedback) {
                    feedback.textContent = '';
                });
            }

            function setFieldError(row, fieldName, message) {
                const field = row.querySelector('[data-field="' + fieldName + '"]');
                if (!field) return;

                field.classList.add('is-invalid');

                const feedback = field.parentElement.querySelector('.invalid-feedback');
                if (feedback) {
                    feedback.textContent = message;
                }
            }

            function setGeneralError(message) {
                if (!form) return;

                clearGeneralError();

                const alert = document.createElement('div');
                alert.className = 'alert alert-danger py-2 px-3 mb-3';
                alert.textContent = message;

                form.parentElement.insertBefore(alert, form);
            }

            function getRowData(row) {
                return {
                    username: row.querySelector('[data-field="username"]').value.trim(),
                    email: row.querySelector('[data-field="email"]').value.trim(),
                    password: row.querySelector('[data-field="password"]').value,
                    role: row.querySelector('[data-field="role"]').value,
                    id_branch: row.querySelector('[data-field="id_branch"]').value
                };
            }

            function addRow(data = {}) {
                if (!rowContainer || !rowTemplate) return;

                const currentRows = rowContainer.querySelectorAll('.user-create-row').length;
                if (currentRows >= maxRows) return;

                const fragment = rowTemplate.content.cloneNode(true);
                const row = fragment.querySelector('.user-create-row');

                row.querySelector('[data-field="username"]').value = data.username || '';
                row.querySelector('[data-field="email"]').value = data.email || '';
                row.querySelector('[data-field="password"]').value = '';
                row.querySelector('[data-field="role"]').value = data.role || 'user';
                row.querySelector('[data-field="id_branch"]').value = data.id_branch || '';

                rowContainer.appendChild(row);
                updateRows();
                initBranchSelect(row);
            }

            function validateForm() {
                let isValid = true;
                let filledCount = 0;

                clearGeneralError();

                if (!rowContainer) {
                    return {
                        isValid: false,
                        filledCount: 0
                    };
                }

                const rows = Array.from(rowContainer.querySelectorAll('.user-create-row'));
                const usernameMap = {};
                const emailMap = {};

                rows.forEach(function(row) {
                    clearRowErrors(row);

                    const data = getRowData(row);
                    const hasValue = data.username !== '' || data.email !== '' || data.password !== '';

                    if (!hasValue) return;

                    filledCount++;

                    if (data.username === '') {
                        setFieldError(row, 'username', 'Username wajib diisi.');
                        isValid = false;
                    }

                    if (data.email === '') {
                        setFieldError(row, 'email', 'Email wajib diisi.');
                        isValid = false;
                    } else {
                        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        if (!emailPattern.test(data.email)) {
                            setFieldError(row, 'email', 'Format email tidak valid.');
                            isValid = false;
                        }
                    }

                    if (!data.id_branch) {
                        setFieldError(row, 'id_branch', 'Cabang wajib dipilih.');
                        isValid = false;
                    }

                    if (data.username !== '') {
                        const usernameKey = data.username.toLowerCase();
                        if (!usernameMap[usernameKey]) {
                            usernameMap[usernameKey] = [];
                        }
                        usernameMap[usernameKey].push(row);
                    }

                    if (data.email !== '') {
                        const emailKey = data.email.toLowerCase();
                        if (!emailMap[emailKey]) {
                            emailMap[emailKey] = [];
                        }
                        emailMap[emailKey].push(row);
                    }
                });

                if (filledCount === 0) {
                    setGeneralError('Isi minimal 1 baris user.');
                    isValid = false;
                }

                Object.keys(usernameMap).forEach(function(key) {
                    if (usernameMap[key].length > 1) {
                        usernameMap[key].forEach(function(row) {
                            setFieldError(row, 'username', 'Username duplikat.');
                        });
                        isValid = false;
                    }
                });

                Object.keys(emailMap).forEach(function(key) {
                    if (emailMap[key].length > 1) {
                        emailMap[key].forEach(function(row) {
                            setFieldError(row, 'email', 'Email duplikat.');
                        });
                        isValid = false;
                    }
                });

                return {
                    isValid: isValid,
                    filledCount: filledCount
                };
            }

            if (addRowButton) {
                addRowButton.addEventListener('click', function() {
                    addRow();
                });
            }

            if (rowContainer) {
                rowContainer.addEventListener('click', function(e) {
                    const removeButton = e.target.closest('.btnRemoveUserRow');
                    if (!removeButton) return;

                    const row = removeButton.closest('.user-create-row');
                    if (!row) return;

                    const totalRows = rowContainer.querySelectorAll('.user-create-row').length;
                    if (totalRows <= 1) return;

                    row.remove();
                    updateRows();
                    clearGeneralError();
                });

                rowContainer.addEventListener('input', function(e) {
                    const field = e.target.closest('.form-control, .form-select');
                    if (!field) return;

                    field.classList.remove('is-invalid');

                    const feedback = field.parentElement.querySelector('.invalid-feedback');
                    if (feedback) {
                        feedback.textContent = '';
                    }

                    clearGeneralError();
                });

                rowContainer.addEventListener('change', function() {
                    clearGeneralError();
                });
            }

            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();

                    const result = validateForm();
                    if (!result.isValid) return;

                    Swal.fire({
                        title: 'Simpan user?',
                        text: result.filledCount + ' user akan dibuat.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Ya, simpan',
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#16a34a'
                    }).then((swalResult) => {
                        if (swalResult.isConfirmed) {
                            form.submit();
                        }
                    });
                });

                updateRows();
            }
        });
    </script>
</body>

</html>