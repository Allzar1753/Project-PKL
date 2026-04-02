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

// Update 
function normalize_bulk_users(array $rows): array
{
    $normalized = [];

    foreach (array_values($rows) as $row) {
        $normalized[] = [
            'username' => trim((string) ($row['username'] ?? '')),
            'email'    => trim((string) ($row['email'] ?? '')),
            'password' => (string) ($row['password'] ?? ''),
            'role'     => normalize_role((string) ($row['role'] ?? 'user')),
        ];
    }

    return $normalized;
}

function bulk_row_has_input(array $row): bool
{
    return $row['username'] !== '' || $row['email'] !== '' || $row['password'] !== '';
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
        $row['password'] = ''; // password tidak diisi ulang demi keamanan
        $row['role'] = normalize_role((string) ($row['role'] ?? 'user'));
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

            if ($row['password'] === '') {
                $rowErrors[$index]['password'] = 'Password wajib diisi.';
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
            $stmt = mysqli_prepare($koneksi, "INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");

            $insertedCount = 0;

            foreach ($filledRows as $row) {
                $hash = password_hash($row['password'], PASSWORD_DEFAULT);

                mysqli_stmt_bind_param(
                    $stmt,
                    'ssss',
                    $row['username'],
                    $row['email'],
                    $hash,
                    $row['role']
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

    // Update 

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

            if ($row['password'] === '') {
                $rowErrors[$index]['password'] = 'Password wajib diisi.';
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
            $stmt = mysqli_prepare($koneksi, "INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");

            $insertedCount = 0;

            foreach ($filledRows as $row) {
                $hash = password_hash($row['password'], PASSWORD_DEFAULT);

                mysqli_stmt_bind_param(
                    $stmt,
                    'ssss',
                    $row['username'],
                    $row['email'],
                    $hash,
                    $row['role']
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
$bulkOldRows = pull_bulk_form_old();
$bulkErrors = pull_bulk_form_errors();

if (empty($bulkOldRows)) {
    $bulkOldRows = [
        ['username' => '', 'email' => '', 'password' => '', 'role' => 'user']
    ];
}



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

        .bulk-card-header {
            display: flex;
            justify-content: space-between;
            align-item: start;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .bulk-card-info {
            color: var(--muted);
            font-size: 0.92rem;
            margin-bottom: 0;
        }

        .bluk-counter-badge {
            background: #fff3cb;
            color: #856404;
            border: 1px solid #ffe69c;
            border-radius: 999px;
            padding: .45rem .8rem;
            font-weight: 700;
            font-size: .85rem;
        }

        .bulk-user-row {
            border: 1px solid #dee2e6;
            border-radius: 16px;
            padding: 1rem;
            background: #fafbfc;
            margin-bottom: 1rem;
        }

        .bulk-user-row:last-child {
            margin-bottom: 0;
        }

        .bulk-row-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .75rem;
            flex-wrap: wrap;
            margin-bottom: .75rem;
        }

        .bulk-row-note {
            font-size: .85rem;
            color: var(--muted);
            margin-top: .5rem;
        }

        .invalid-feedback {
            display: block;
        }
    </style>
</head>

<script>
    document.addEventListener('DOMContentLoaded', function() {
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
                    confirmButtonColor: '#0d6efd'
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
        const bulkRowsContainer = document.getElementById('bulkUserRows');
        const bulkTemplate = document.getElementById('bulkUserRowTemplate');
        const addBulkRowButton = document.getElementById('btnAddBulkRow');
        const bulkRowCounter = document.getElementById('bulkRowCounter');
        const bulkMaxRows = 10;

        if (!bulkForm || !bulkRowsContainer || !bulkTemplate || !addBulkRowButton || !bulkRowCounter) {
            return;
        }

        function updateBulkRows() {
            const rows = bulkRowsContainer.querySelectorAll('.bulk-user-row');

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

            bulkRowCounter.textContent = rows.length;
            addBulkRowButton.disabled = rows.length >= bulkMaxRows;
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
            if (!field) {
                return;
            }

            field.classList.add('is-invalid');

            const feedback = field.parentElement.querySelector('.invalid-feedback');
            if (feedback) {
                feedback.textContent = message;
            }
        }

        function getRowData(row) {
            return {
                username: row.querySelector('[data-field="username"]').value.trim(),
                email: row.querySelector('[data-field="email"]').value.trim(),
                password: row.querySelector('[data-field="password"]').value,
                role: row.querySelector('[data-field="role"]').value
            };
        }

        function addBulkRow(data = {}) {
            const currentRows = bulkRowsContainer.querySelectorAll('.bulk-user-row').length;
            if (currentRows >= bulkMaxRows) {
                return;
            }

            const fragment = bulkTemplate.content.cloneNode(true);
            const row = fragment.querySelector('.bulk-user-row');

            row.querySelector('[data-field="username"]').value = data.username || '';
            row.querySelector('[data-field="email"]').value = data.email || '';
            row.querySelector('[data-field="password"]').value = '';
            row.querySelector('[data-field="role"]').value = data.role || 'user';

            bulkRowsContainer.appendChild(row);
            updateBulkRows();
        }

        function validateBulkForm() {
            let isValid = true;
            let filledCount = 0;

            const rows = Array.from(bulkRowsContainer.querySelectorAll('.bulk-user-row'));
            const usernameMap = {};
            const emailMap = {};

            rows.forEach(function(row) {
                clearRowErrors(row);

                const data = getRowData(row);
                const hasInput = data.username !== '' || data.email !== '' || data.password !== '';

                if (!hasInput) {
                    return;
                }

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

                if (data.password === '') {
                    setFieldError(row, 'password', 'Password wajib diisi.');
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

            if (filledCount === 0 && rows.length > 0) {
                setFieldError(rows[0], 'username', 'Isi minimal 1 user.');
                isValid = false;
            }

            Object.keys(usernameMap).forEach(function(key) {
                if (usernameMap[key].length > 1) {
                    usernameMap[key].forEach(function(row) {
                        setFieldError(row, 'username', 'Username duplikat di form.');
                    });
                    isValid = false;
                }
            });

            Object.keys(emailMap).forEach(function(key) {
                if (emailMap[key].length > 1) {
                    emailMap[key].forEach(function(row) {
                        setFieldError(row, 'email', 'Email duplikat di form.');
                    });
                    isValid = false;
                }
            });

            return {
                isValid: isValid,
                filledCount: filledCount
            };
        }

        addBulkRowButton.addEventListener('click', function() {
            addBulkRow();
        });

        bulkRowsContainer.addEventListener('click', function(e) {
            const removeButton = e.target.closest('.btnRemoveBulkRow');
            if (!removeButton) {
                return;
            }

            const row = removeButton.closest('.bulk-user-row');
            if (!row) {
                return;
            }

            const totalRows = bulkRowsContainer.querySelectorAll('.bulk-user-row').length;
            if (totalRows <= 1) {
                return;
            }

            row.remove();
            updateBulkRows();
        });

        bulkRowsContainer.addEventListener('input', function(e) {
            const field = e.target.closest('.form-control, .form-select');
            if (!field) {
                return;
            }

            field.classList.remove('is-invalid');
            const feedback = field.parentElement.querySelector('.invalid-feedback');
            if (feedback) {
                feedback.textContent = '';
            }
        });

        bulkForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const result = validateBulkForm();
            if (!result.isValid) {
                return;
            }

            Swal.fire({
                title: 'Simpan banyak user?',
                text: result.filledCount + ' user akan dibuat sekaligus.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, simpan',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#198754'
            }).then((swalResult) => {
                if (swalResult.isConfirmed) {
                    bulkForm.submit();
                }
            });
        });

        updateBulkRows();
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

                <!-- UpdateBulk Actions -->
                <div class="form-card mb-4">
                    <div class="bulk-card-header">
                        <div>
                            <h4 class="fw-bold mb-2">Tambah Banyak User</h4>
                            <p class="bulk-card-info mb-0">
                                Buat banyak user sekaligus dalam satu proses. Maksimal 10 user.
                            </p>
                            <div class="bulk-row-note">
                                Catatan: bila proses gagal, password perlu diisi ulang.
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="bulk-counter-badge">
                                <span id="bulkRowCounter"><?= count($bulkOldRows) ?></span> / 10 baris
                            </span>
                            <button type="button" id="btnAddBulkRow" class="btn btn-outline-primary">
                                <i class="bi bi-plus-circle me-1"></i>Tambah Baris
                            </button>
                        </div>
                    </div>

                    <form method="POST" id="bulkCreateForm" novalidate>
                        <input type="hidden" name="action" value="bulk_create">

                        <div id="bulkUserRows">
                            <?php foreach ($bulkOldRows as $index => $bulkRow): ?>
                                <div class="bulk-user-row" data-row-index="<?= $index ?>">
                                    <div class="bulk-row-top">
                                        <h6 class="bulk-row-title">Baris <span class="bulk-row-number"><?= $index + 1 ?></span></h6>
                                        <button type="button" class="btn btn-outline-danger btn-sm btnRemoveBulkRow" <?= count($bulkOldRows) === 1 ? 'disabled' : '' ?>>
                                            Hapus Baris
                                        </button>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-lg-3 col-md-6">
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

                                        <div class="col-lg-3 col-md-6">
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

                                        <div class="col-lg-3 col-md-6">
                                            <label class="form-label">Password</label>
                                            <input
                                                type="password"
                                                class="form-control <?= bulk_field_error($bulkErrors, $index, 'password') ? 'is-invalid' : '' ?>"
                                                value=""
                                                data-field="password"
                                                data-name-template="bulk_users[__INDEX__][password]"
                                                name="bulk_users[<?= $index ?>][password]">
                                            <div class="invalid-feedback"><?= e(bulk_field_error($bulkErrors, $index, 'password')) ?></div>
                                        </div>

                                        <div class="col-lg-3 col-md-6">
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
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn btn-success">
                                Simpan Banyak User
                            </button>
                        </div>
                    </form>

                    <template id="bulkUserRowTemplate">
                        <div class="bulk-user-row" data-row-index="0">
                            <div class="bulk-row-top">
                                <h6 class="bulk-row-title">Baris <span class="bulk-row-number">1</span></h6>
                                <button type="button" class="btn btn-outline-danger btn-sm btnRemoveBulkRow">
                                    Hapus Baris
                                </button>
                            </div>

                            <div class="row g-3">
                                <div class="col-lg-3 col-md-6">
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

                                <div class="col-lg-3 col-md-6">
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

                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">Password</label>
                                    <input
                                        type="password"
                                        class="form-control"
                                        value=""
                                        data-field="password"
                                        data-name-template="bulk_users[__INDEX__][password]"
                                        name="">
                                    <div class="invalid-feedback"></div>
                                </div>

                                <div class="col-lg-3 col-md-6">
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
                            </div>
                        </div>
                    </template>
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

                                                <!-- Tambahana untuk Aksi -->
                                                <?php if ($user['role'] !== 'super_admin'): ?>
                                                    <a href="<?= e(base_url('users/user_permissions.php?user_id=' . (int) $user['id'])) ?>"
                                                        class="btn btn-warning btn-sm">
                                                        Hak Akses
                                                    </a>
                                                <?php endif; ?>

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