<?php

require_once __DIR__ . '/../vendor/autoload.php';

/** @var mysqli $koneksi */ //
include '../config/koneksi.php';
require_once '../config/auth.php';
require_once '../config/activity_logger.php';
require_once '../config/validation_helper.php';
require_once '../config/password_helper.php';

require_admin();
refresh_permissions($koneksi);

if (!function_exists('normalize_role')) {
    function normalize_role(string $role): string
    {
        $role = strtolower(trim($role));
        $allowedRoles = ['admin', 'user'];

        return in_array($role, $allowedRoles, true) ? $role : 'user';
    }
}

if (!function_exists('redirect_users_index')) {
    function redirect_users_index(): void
    {
        redirect_to(base_url('users/index.php'));
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime(?string $datetime): string
    {
        if (!$datetime) {
            return 'Belum pernah';
        }

        $bulan = [
            1 => 'Jan',
            'Feb',
            'Mar',
            'Apr',
            'Mei',
            'Jun',
            'Jul',
            'Agu',
            'Sep',
            'Okt',
            'Nov',
            'Des'
        ];

        $timestamp = strtotime($datetime);

        $tgl = date('d', $timestamp);
        $bln = $bulan[(int)date('m', $timestamp)];
        $thn = date('Y', $timestamp);
        $jam = date('H:i', $timestamp);

        return "$tgl-$bln-$thn $jam";
    }
}


function find_user_by_id(mysqli $koneksi, int $id): ?array
{
    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT id, username, email, password, role, id_branch, created_at, password_changed_at, employment_status
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result) ?: null;
    mysqli_stmt_close($stmt);

    return $user;
}

function username_exists(mysqli $koneksi, string $username, ?int $ignoreId = null): bool
{
    if ($ignoreId !== null) {
        $stmt = mysqli_prepare($koneksi, "SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'si', $username, $ignoreId);
    } else {
        $stmt = mysqli_prepare($koneksi, "SELECT id FROM users WHERE username = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
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
    if ($ignoreId !== null) {
        $stmt = mysqli_prepare($koneksi, "SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'si', $email, $ignoreId);
    } else {
        $stmt = mysqli_prepare($koneksi, "SELECT id FROM users WHERE email = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 's', $email);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exists = (bool) mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return $exists;
}

function resolve_password_input(string $role, ?string $inputPassword): string
{
    $inputPassword = trim((string) $inputPassword);

    if ($inputPassword !== '') {
        return $inputPassword;
    }

    return generate_strong_password();
}

function validate_single_user_input(
    mysqli $koneksi,
    string $username,
    string $email,
    ?int $idBranch,
    ?int $ignoreId = null
): ?string {
    if ($username === '' || $email === '') {
        return 'Username dan email wajib diisi.';
    }

    if (!preg_match('/^[a-zA-Z\s]+$/', $username)) {
        return 'Username hanya boleh berisi huruf.';
    }

    if ($idBranch === null || $idBranch <= 0) {
        return 'Cabang wajib dipilih.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Format email tidak valid.';
    }

    if (username_exists($koneksi, $username, $ignoreId)) {
        return $ignoreId !== null ? 'Username sudah digunakan user lain.' : 'Username sudah digunakan.';
    }

    if (email_exists($koneksi, $email, $ignoreId)) {
        return $ignoreId !== null ? 'Email sudah digunakan user lain.' : 'Email sudah digunakan.';
    }

    return null;
}

function insert_user(
    mysqli $koneksi,
    string $username,
    string $email,
    string $role,
    string $plainPassword,
    int $idBranch
): bool {
    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
    $mustChangePassword = 1;

    $stmt = mysqli_prepare(
        $koneksi,
        "INSERT INTO users (username, email, password, role, must_change_password, id_branch, employment_status)
         VALUES (?, ?, ?, ?, ?, ?, 'active')"
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'ssssii', $username, $email, $hash, $role, $mustChangePassword, $idBranch);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}

function normalize_bulk_users(array $rows): array
{
    $normalized = [];

    foreach (array_values($rows) as $row) {
        $normalized[] = [
            'username'  => trim((string) ($row['username'] ?? '')),
            'email'     => trim((string) ($row['email'] ?? '')),
            'password'  => (string) ($row['password'] ?? ''),
            'role'      => normalize_role((string) ($row['role'] ?? 'user')),
            'id_branch' => (int) ($row['id_branch'] ?? 0),
        ];
    }

    return $normalized;
}

function bulk_row_has_input(array $row): bool
{
    return $row['username'] !== ''
        || $row['email'] !== ''
        || (int) ($row['id_branch'] ?? 0) > 0;
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
            'username'  => trim((string) ($row['username'] ?? '')),
            'email'     => trim((string) ($row['email'] ?? '')),
            'password'  => (string) ($row['password'] ?? ''),
            'role'      => normalize_role((string) ($row['role'] ?? 'user')),
            'id_branch' => (int) ($row['id_branch'] ?? 0),
        ];
    }

    return $normalized;
}

function create_user_row_has_value(array $row): bool
{
    return $row['username'] !== ''
        || $row['email'] !== ''
        || (int) ($row['id_branch'] ?? 0) > 0;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'reset_password_request') {
        $requestId   = (int) ($_POST['request_id'] ?? 0);
        $newPassword = trim((string) ($_POST['new_password'] ?? '')); // Ditrim agar tidak ada spasi bocor
        $adminId     = (int) current_user_id();

        if ($requestId <= 0 || $newPassword === '') {
            set_flash('error', 'Data tidak lengkap.');
            redirect_users_index();
        }

        $passwordError = validate_password_strength($newPassword);
        if ($passwordError !== null) {
            set_flash('error', $passwordError);
            redirect_users_index();
        }

        // Ambil data request + data user (username & email)
        $stmtReq = mysqli_prepare(
            $koneksi,
            "SELECT prr.id, prr.user_id, u.username, u.email
             FROM password_reset_requests prr
             JOIN users u ON prr.user_id = u.id
             WHERE prr.id = ? AND prr.status = 'pending'
             LIMIT 1"
        );
        mysqli_stmt_bind_param($stmtReq, 'i', $requestId);
        mysqli_stmt_execute($stmtReq);
        $resultReq = mysqli_stmt_get_result($stmtReq);
        $reqData   = mysqli_fetch_assoc($resultReq);
        mysqli_stmt_close($stmtReq);

        if (!$reqData) {
            set_flash('error', 'Permintaan tidak ditemukan atau sudah diproses.');
            redirect_users_index();
        }

        $targetUserId = (int) $reqData['user_id'];
        $hash         = password_hash($newPassword, PASSWORD_DEFAULT);
        $now          = date('Y-m-d H:i:s');
        $mustChange   = 1;

        mysqli_begin_transaction($koneksi);

        try {
            // Update password user
            $stmtUpdateUser = mysqli_prepare(
                $koneksi,
                "UPDATE users
                 SET password = ?,
                     must_change_password = ?,
                     password_reset_at = ?,
                     password_reset_by = ?
                 WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmtUpdateUser, 'sisii', $hash, $mustChange, $now, $adminId, $targetUserId);
            mysqli_stmt_execute($stmtUpdateUser);
            mysqli_stmt_close($stmtUpdateUser);

            // Update status request menjadi selesai
            $stmtUpdateReq = mysqli_prepare(
                $koneksi,
                "UPDATE password_reset_requests
                 SET status = 'selesai',
                     processed_by = ?,
                     processed_at = ?
                 WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmtUpdateReq, 'isi', $adminId, $now, $requestId);
            mysqli_stmt_execute($stmtUpdateReq);
            mysqli_stmt_close($stmtUpdateReq);

            mysqli_commit($koneksi);

            // =========================================================
            // TRIGGER UNTUK MEMUNCULKAN POP-UP SUKSES
            // =========================================================
            stash_user_credentials_flash([[
                'username' => $reqData['username'], 
                'email'    => $reqData['email'],
                'password' => $newPassword,
            ]]);
            
            // Set tanda bahwa ini adalah popup dari proses RESET
            $_SESSION['popup_type'] = 'reset';

            set_flash('success', 'Password user berhasil direset.');
        } catch (Throwable $e) {
            mysqli_rollback($koneksi);
            set_flash('error', 'Gagal memproses reset password.');
        }

        redirect_users_index();
    }

    if ($action === 'create') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = normalize_role((string) ($_POST['role'] ?? 'user'));
        $password = resolve_password_input($role, $_POST['password'] ?? '');
        $idBranch = (int) ($_POST['id_branch'] ?? 0);
        $idBranch = $idBranch > 0 ? $idBranch : null;

        $errorMessage = validate_single_user_input($koneksi, $username, $email, $idBranch);

        if ($errorMessage !== null) {
            set_flash('error', $errorMessage);
            redirect_users_index();
        }

        if (!insert_user($koneksi, $username, $email, $role, $password, (int) $idBranch)) {
            set_flash('error', 'Gagal menyimpan user.');
            redirect_users_index();
        }

        log_activity($koneksi, 'create_user', "Admin tambah user - Username: {$username}, Role: {$role}, Status: " . employment_status_label('active'), [
            'username' => $username,
            'email' => $email,
            'role' => $role,
            'id_branch' => $idBranch,
        ]);

        stash_user_credentials_flash([[
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ]]);
        set_flash('success', 'User berhasil dibuat.');
        redirect_users_index();
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
            redirect_users_index();
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
            } elseif (!preg_match('/^[a-zA-Z\s]+$/', $row['username'])) {
                $rowErrors[$index]['username'] = 'Username hanya boleh berisi huruf.';
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
            redirect_users_index();
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
            redirect_users_index();
        }

        mysqli_begin_transaction($koneksi);

        try {
            $insertedCount = 0;
            $createdCredentials = [];

            foreach ($filledRows as $row) {
                $plainPassword = resolve_password_input($row['role'], $row['password'] ?? '');
                $idBranch = (int) ($row['id_branch'] ?? 0);

                if (!insert_user($koneksi, $row['username'], $row['email'], $row['role'], $plainPassword, $idBranch)) {
                    throw new Exception('Gagal menyimpan salah satu user.');
                }

                log_activity($koneksi, 'create_user', "Admin tambah user (bulk) - Username: {$row['username']}, Role: {$row['role']}", [
                    'username' => $row['username'],
                    'email' => $row['email'],
                    'role' => $row['role'],
                    'id_branch' => $idBranch,
                ]);

                $createdCredentials[] = [
                    'username' => $row['username'],
                    'email' => $row['email'],
                    'password' => $plainPassword,
                ];
                $insertedCount++;
            }

            mysqli_commit($koneksi);

            clear_create_user_form_state();
            stash_user_credentials_flash($createdCredentials);
            set_flash('success', $insertedCount . ' user berhasil dibuat.');
        } catch (Throwable $e) {
            mysqli_rollback($koneksi);
            set_create_user_form_state($rows, [], 'Terjadi kesalahan saat menyimpan data user.');
        }

        redirect_users_index();
    }

    if ($action === 'bulk_create') {
        $rawRows = $_POST['bulk_users'] ?? [];

        if (!is_array($rawRows)) {
            $rawRows = [];
        }

        if (count($rawRows) > 10) {
            set_flash('error', 'Maksimal 10 user dalam satu proses.');
            redirect_users_index();
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
            } elseif (!preg_match('/^[a-zA-Z\s]+$/', $row['username'])) {
                $rowErrors[$index]['username'] = 'Username hanya boleh berisi huruf.';
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
            redirect_users_index();
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
            redirect_users_index();
        }

        mysqli_begin_transaction($koneksi);

        try {
            $insertedCount = 0;
            $createdCredentials = [];

            foreach ($filledRows as $row) {
                $plainPassword = resolve_password_input($row['role'], $row['password'] ?? '');
                $idBranch = (int) ($row['id_branch'] ?? 0);

                if (!insert_user($koneksi, $row['username'], $row['email'], $row['role'], $plainPassword, $idBranch)) {
                    throw new Exception('Gagal membuat salah satu user.');
                }

                log_activity($koneksi, 'create_user', "Admin tambah user (bulk) - Username: {$row['username']}, Role: {$row['role']}", [
                    'username' => $row['username'],
                    'email' => $row['email'],
                    'role' => $row['role'],
                    'id_branch' => $idBranch,
                ]);

                $createdCredentials[] = [
                    'username' => $row['username'],
                    'email' => $row['email'],
                    'password' => $plainPassword,
                ];
                $insertedCount++;
            }

            mysqli_commit($koneksi);

            clear_bulk_form_state();
            stash_user_credentials_flash($createdCredentials);
            set_flash('success', $insertedCount . ' user berhasil dibuat.');
        } catch (Throwable $e) {
            mysqli_rollback($koneksi);
            set_bulk_form_state($rows, []);
            set_flash('error', 'Gagal membuat banyak user sekaligus.');
        }

        redirect_users_index();
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $role = normalize_role((string) ($_POST['role'] ?? 'user'));
        $idBranch = (int) ($_POST['id_branch'] ?? 0);
        $idBranch = $idBranch > 0 ? $idBranch : null;
        $employmentStatus = normalize_employment_status($_POST['employment_status'] ?? 'active');

        $targetUser = find_user_by_id($koneksi, $id);
        if (!$targetUser) {
            set_flash('error', 'User tidak ditemukan.');
            redirect_users_index();
        }

        $errorMessage = validate_single_user_input($koneksi, $username, $email, $idBranch, $id);

        if ($errorMessage !== null) {
            set_flash('error', $errorMessage);
            redirect_users_index();
        }

        $isSelf = current_user_id() === $id;

        if ($isSelf && !is_employment_active($employmentStatus)) {
            set_flash('error', 'Anda tidak bisa menonaktifkan akun sendiri.');
            redirect_users_index();
        }

        if ($newPassword !== '') {
            $passwordError = validate_password_strength($newPassword);
            if ($passwordError !== null) {
                set_flash('error', $passwordError);
                redirect_users_index();
            }

            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $mustChangePassword = 1;

            $stmt = mysqli_prepare(
                $koneksi,
                "UPDATE users
                 SET username = ?, email = ?, role = ?, id_branch = ?, password = ?, must_change_password = ?, employment_status = ?
                 WHERE id = ?"
            );

            mysqli_stmt_bind_param($stmt, 'sssisisi', $username, $email, $role, $idBranch, $hash, $mustChangePassword, $employmentStatus, $id);
        } else {
            $stmt = mysqli_prepare(
                $koneksi,
                "UPDATE users
                 SET username = ?, email = ?, role = ?, id_branch = ?, employment_status = ?
                 WHERE id = ?"
            );

            mysqli_stmt_bind_param($stmt, 'sssisi', $username, $email, $role, $idBranch, $employmentStatus, $id);
        }

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if ($newPassword !== '') {
            stash_user_credentials_flash([[
                'username' => $username,
                'email' => $email,
                'password' => $newPassword,
            ]]);
        }

        if ($isSelf && isset($_SESSION['user'])) {
            $_SESSION['user']['username'] = $username;
            $_SESSION['user']['email'] = $email;
            $_SESSION['user']['role'] = $role;
            $_SESSION['user']['id_branch'] = $idBranch;
            refresh_permissions($koneksi);
        }

        $oldEmploymentStatus = normalize_employment_status($targetUser['employment_status'] ?? 'active');

        log_activity($koneksi, 'update_user', "Admin edit user - ID: {$id}, Username: {$username}, Status: " . employment_status_label($employmentStatus), [
            'target_user_id' => $id,
            'username' => $username,
            'email' => $email,
            'role' => $role,
            'id_branch' => $idBranch,
            'employment_status' => $employmentStatus,
        ]);

        if ($oldEmploymentStatus !== $employmentStatus) {
            log_activity($koneksi, 'update_user_status', "Ubah status kerja {$username}: " . employment_status_label($oldEmploymentStatus) . ' → ' . employment_status_label($employmentStatus), [
                'target_user_id' => $id,
                'username' => $username,
                'from' => $oldEmploymentStatus,
                'to' => $employmentStatus,
            ]);
        }

        set_flash('success', 'Data user berhasil diperbarui.');
        redirect_users_index();
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $targetUser = find_user_by_id($koneksi, $id);

        if (!$targetUser) {
            set_flash('error', 'User tidak ditemukan.');
            redirect_users_index();
        }

        if (current_user_id() === $id) {
            set_flash('error', 'Anda tidak bisa menghapus akun sendiri.');
            redirect_users_index();
        }

        $stmt = mysqli_prepare($koneksi, "DELETE FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        log_activity($koneksi, 'delete_user', "Admin hapus user - Username: {$targetUser['username']}, ID: {$id}", [
            'target_user_id' => $id,
            'username' => $targetUser['username'],
            'email' => $targetUser['email'] ?? '',
        ]);

        set_flash('success', 'User berhasil dihapus.');
        redirect_users_index();
    }
}

if (isset($_GET['export'])) {
    $exportType = $_GET['export'];

    $exportQuery = mysqli_query(
        $koneksi,
        "SELECT users.username, users.email, users.role, tb_branch.nama_branch, users.created_at, users.password_changed_at
         FROM users
         LEFT JOIN tb_branch ON tb_branch.id_branch = users.id_branch
         ORDER BY FIELD(role, 'admin', 'user'), username ASC"
    );

    $bulanIndo = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember'
    ];

    $bulanBulanIni = $bulanIndo[(int)date('m')];
    $tahunBulanIni = date('Y');

    $periodeBulan = $bulanBulanIni . ' ' . $tahunBulanIni;

    $tanggalCetak = date('d') . ' ' . $bulanBulanIni . ' ' . date('Y H:i');

    if ($exportType === 'excel') {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('B1', 'PT HEXINDO ADIPEKARSA TBK');
        $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(14);
        
        $sheet->setCellValue('B2', 'Data Akun User IT System');
        $sheet->getStyle('B2')->getFont()->setBold(true)->setSize(12);
        
        $sheet->setCellValue('B3', 'Periode: Bulan ' . $periodeBulan);
        $sheet->setCellValue('B4', 'Tanggal Cetak: ' . $tanggalCetak);

        $sheet->setCellValue('A6', 'No');
        $sheet->setCellValue('B6', 'Username');
        $sheet->setCellValue('C6', 'Email');
        $sheet->setCellValue('D6', 'Role');
        $sheet->setCellValue('E6', 'Cabang');
        $sheet->setCellValue('F6', 'Tanggal Dibuat');
        $sheet->setCellValue('G6', 'Tanggal Password Diubah');

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E64312']], // Oranye Hexindo
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
        ];
        $sheet->getStyle('A6:G6')->applyFromArray($headerStyle);

        $rowNum = 7;
        $no = 1;
        while ($row = mysqli_fetch_assoc($exportQuery)) {
            $sheet->setCellValue('A' . $rowNum, $no++);
            $sheet->setCellValue('B' . $rowNum, $row['username']);
            $sheet->setCellValue('C' . $rowNum, $row['email']);
            $sheet->setCellValue('D' . $rowNum, $row['role'] === 'admin' ? 'Administrator' : 'User');
            $sheet->setCellValue('E' . $rowNum, $row['nama_branch'] ?? 'Belum ditentukan');
            $sheet->setCellValue('F' . $rowNum, format_datetime($row['created_at']));
            $sheet->setCellValue('G' . $rowNum, format_datetime($row['password_changed_at']));
            $rowNum++;
        }

        $dataStyle = ['borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]];
        $sheet->getStyle('A7:G' . ($rowNum - 1))->applyFromArray($dataStyle);

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Data_User_' . date('Y-m-d') . '.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    if ($exportType === 'pdf') {
        echo '<!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <title>Dokumen Valid - Laporan User</title>
            <style>
                @import url("https://fonts.googleapis.com/css2?family=Helvetica+Neue:wght@400;700&display=swap");
                body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 12px; color: #222; padding: 20px; background: #fff; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important;}
                
                /* Box Kop Surat Kiri & Kanan */
                .doc-header { display: flex; border: 2px solid #231F20; margin-bottom: 20px; background: #fff; }
                .doc-left { width: 65%; padding: 25px; border-right: 2px solid #231F20; }
                .doc-right { width: 35%; padding: 25px; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #F4F6F9;}
                
                .doc-title { font-family: "Times New Roman", serif; font-size: 32px; font-weight: bold; color: #111; margin: 0 0 5px 0; letter-spacing: -0.5px; }
                .doc-ref { font-size: 11px; color: #888; margin-bottom: 25px; }
                .report-name { font-size: 14px; font-weight: bold; margin-bottom: 5px; color: #111; }
                .report-desc { font-size: 12px; color: #444; line-height: 1.6; }
                
                /* Logo Hexindo Buatan */
                .triangle-logo { width: 0; height: 0; border-left: 14px solid transparent; border-right: 14px solid transparent; border-bottom: 24px solid #E64312; margin-bottom: 10px; position: relative; }
                .triangle-logo::after { content: ""; position: absolute; top: 11px; left: -5px; width: 0; height: 0; border-left: 5px solid transparent; border-right: 5px solid transparent; border-bottom: 10px solid #fff; }
                .company-name { font-size: 16px; font-weight: 900; color: #231F20; margin-bottom: 3px; letter-spacing: 1px;}
                .unit-name { font-size: 12px; font-weight: bold; color: #666; }

                /* Kotak Info (Periode, Tgl, Status) */
                .info-grid { display: flex; border: 1px solid #E0E4E8; margin-bottom: 30px; background: #F4F6F9; }
                .info-col { flex: 1; padding: 15px 20px; border-right: 1px solid #E0E4E8; }
                .info-col:last-child { border-right: none; }
                .info-label { font-size: 10px; color: #E64312; font-weight: 800; text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.5px; }
                .info-value { font-size: 14px; font-weight: bold; color: #111; }

                /* Tabel Data */
                table { width: 100%; border-collapse: collapse; margin-bottom: 40px; }
                th { background-color: #E64312; color: #ffffff; padding: 12px; text-align: left; font-size: 12px; border: 1px solid #000; text-transform: uppercase;}
                td { border: 1px solid #000; padding: 10px 12px; font-size: 12px; vertical-align: middle; }
                tr:nth-child(even) { background-color: #F4F6F9; }
                
                /* Footer Bawah */
                .footer { text-align: center; border-top: 1px dotted #ccc; padding-top: 15px; font-size: 10px; color: #888; }
            </style>
        </head>
        <body>

            <!-- BAGIAN KOP DOKUMEN -->
            <div class="doc-header">
                <div class="doc-left">
                    <h1 class="doc-title">IT SYSTEM REPORT</h1>
                    <div class="doc-ref">No File: ITS/USER/'.date('Ymd').'/'.strtoupper(substr(md5(time()), 0, 4)).'</div>
                    
                    <div class="report-name">Internal Report:</div>
                    <div class="report-desc">
                        PT HEXINDO ADIPERKASA TBK<br>
                        Unit: IT System<br>
                        Dokumen: Data Akun User Operasional
                    </div>
                </div>
                <div class="doc-right">
                    <div class="triangle-logo"></div>
                    <div class="company-name">PT HEXINDO</div>
                    <div class="unit-name">IT System</div>
                </div>
            </div>

            <!-- BAGIAN INFO DOKUMEN -->
            <div class="info-grid">
                <div class="info-col">
                    <div class="info-label">PERIODE LAPORAN</div>
                    <div class="info-value">'.$periodeBulan.'</div>
                </div>
                <div class="info-col">
                    <div class="info-label">TANGGAL CETAK</div>
                    <div class="info-value">'.$tanggalCetak.'</div>
                </div>
                <div class="info-col">
                    <div class="info-label">STATUS DOKUMEN</div>
                    <div class="info-value">Valid / Rahasia Internal</div>
                </div>
            </div>

            <!-- TABEL DATA -->
            <table>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 16%;">Username</th>
                    <th style="width: 21%;">Email</th>
                    <th style="width: 10%;">Role</th>
                    <th style="width: 16%;">Cabang</th>
                    <th style="width: 16%;">Tanggal Dibuat</th>
                    <th style="width: 16%;">Tanggal Password Diubah</th>
                </tr>';
        $no = 1;
        while ($row = mysqli_fetch_assoc($exportQuery)) {
            echo '<tr>
                    <td style="text-align:center;">' . $no++ . '</td>
                    <td><strong>' . htmlspecialchars($row['username']) . '</strong></td>
                    <td>' . htmlspecialchars($row['email']) . '</td>
                    <td>' . ($row['role'] === 'admin' ? 'Administrator' : 'User') . '</td>
                    <td>' . htmlspecialchars($row['nama_branch'] ?? 'Belum ditentukan') . '</td>
                    <td>' . format_datetime($row['created_at']) . '</td>
                    <td>' . format_datetime($row['password_changed_at']) . '</td>
                  </tr>';
        }
        echo '</table>
            
            <!-- FOOTER BAWAH -->
            <div class="footer">
                PT HEXINDO ADIPERKASA TBK &middot; IT System User Management &middot; Dokumen Valid Tanpa Tanda Tangan Fisik.
            </div>

            <script>
                // Otomatis print saat dibuka
                window.onload = function() { setTimeout(function() { window.print(); }, 500); }
            </script>
        </body>
        </html>';
        exit;
    }
}

$alertError = get_flash('error');
$alertSuccess = get_flash('success');
$userCredentialsFlash = get_flash('user_credentials_batch');

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
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS total_admin,
        SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END) AS total_user,
        SUM(CASE WHEN COALESCE(employment_status, 'active') = 'active' THEN 1 ELSE 0 END) AS total_active,
        SUM(CASE WHEN COALESCE(employment_status, 'active') = 'inactive' THEN 1 ELSE 0 END) AS total_inactive
     FROM users"
);

$summaryData = $summaryQuery ? (mysqli_fetch_assoc($summaryQuery) ?: []) : [];

$totalUsers = (int) ($summaryData['total_users'] ?? 0);
$adminTotal = (int) ($summaryData['total_admin'] ?? 0);
$userTotal = (int) ($summaryData['total_user'] ?? 0);
$activeTotal = (int) ($summaryData['total_active'] ?? 0);
$inactiveTotal = (int) ($summaryData['total_inactive'] ?? 0);

$employmentFilter = $_GET['employment'] ?? 'all';
if (!in_array($employmentFilter, ['all', 'active', 'inactive'], true)) {
    $employmentFilter = 'all';
}

$employmentWhere = '';
if ($employmentFilter === 'active') {
    $employmentWhere = "WHERE COALESCE(users.employment_status, 'active') = 'active'";
} elseif ($employmentFilter === 'inactive') {
    $employmentWhere = "WHERE COALESCE(users.employment_status, 'active') = 'inactive'";
}

$countUsersResult = mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM users $employmentWhere");
$filteredTotalUsers = $countUsersResult ? (int) (mysqli_fetch_assoc($countUsersResult)['total'] ?? 0) : $totalUsers;

$totalPages = max(1, (int) ceil($filteredTotalUsers / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $limit;
$displayFrom = $filteredTotalUsers > 0 ? $offset + 1 : 0;
$displayTo = min($offset + $limit, $filteredTotalUsers);

$users = [];
$usersResult = mysqli_query(
    $koneksi,
    "SELECT users.id, users.username, users.email, users.role, users.id_branch, users.created_at, users.password_changed_at, users.employment_status, tb_branch.nama_branch
     FROM users
     LEFT JOIN tb_branch ON tb_branch.id_branch = users.id_branch
     $employmentWhere
     ORDER BY FIELD(COALESCE(users.employment_status, 'active'), 'active', 'inactive'), FIELD(role, 'admin', 'user'), username ASC
     LIMIT $offset, $limit"
);

if ($usersResult) {
    while ($row = mysqli_fetch_assoc($usersResult)) {
        $users[] = $row;
    }
}

$branches = [];
$branchesResult = mysqli_query($koneksi, "SELECT id_branch, nama_branch FROM tb_branch ORDER BY nama_branch ASC");
if ($branchesResult) {
    while ($branchRow = mysqli_fetch_assoc($branchesResult)) {
        $branches[] = $branchRow;
    }
}

$resetRequests = [];
$resetRequestsResult = mysqli_query(
    $koneksi,
    "SELECT 
        prr.id,
        prr.user_id,
        prr.alasan,
        prr.status,
        prr.requested_at,
        prr.processed_by,
        prr.processed_at,
        u.username,
        u.email,
        tb_branch.nama_branch
        FROM password_reset_requests prr 
        INNER JOIN users u ON u.id = prr.user_id
        LEFT JOIN tb_branch ON tb_branch.id_branch = u.id_branch
        WHERE prr.status = 'pending'
        ORDER BY prr.requested_at ASC"
);

if ($resetRequestsResult) {
    while ($row = mysqli_fetch_assoc($resetRequestsResult)) {
        $resetRequests[] = $row;
    }
}
$totalPendingReset = count($resetRequests);

function role_badge_class(string $role): string
{
    return $role === 'admin' ? 'role-badge admin' : 'role-badge user';
}

function role_label(string $role): string
{
    return $role === 'admin' ? 'Administrator' : 'User';
}

function role_icon(string $role): string
{
    return $role === 'admin' ? 'bi-shield-check' : 'bi-person';
}

function employment_badge_class(?string $status): string
{
    return is_employment_active($status) ? 'employment-badge active' : 'employment-badge inactive';
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
    <link rel="stylesheet" href="<?= e(base_url('assets/css/password-fields.css')) ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- SINKRONISASI TEMA HEXINDO -->
    <style>
        :root {
            /* TEMA HEXINDO */
            --orange-1: #E64312; 
            --orange-2: #F25C05;
            --dark-1: #231F20;
            --text-main: #333333;
            --text-soft: #666666;
            --surface-bg: #F4F6F9;
            --border-soft: #E0E4E8;
            --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.04);
            --shadow-hover: 0 10px 25px rgba(0,0,0,0.08);
            --radius-box: 8px; /* Industrial Sharp Edges */
        }

        * { box-sizing: border-box; }

        body {
            background-color: var(--surface-bg);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
        }

        .page-shell { padding: 24px 32px; }
        @media (max-width: 991.98px) { .page-shell { padding: 18px; } }

        /* Hero Banner Hexindo */
        .hero-card {
            position: relative;
            background: var(--dark-1);
            border-top: 4px solid var(--orange-1);
            border-radius: var(--radius-box);
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-soft);
        }

        .hero-content { position: relative; z-index: 2; }

        .page-title {
            color: #fff; font-size: 1.6rem; font-weight: 700; margin-bottom: 0.25rem;
        }

        .page-subtitle {
            color: #9ca3af; margin-bottom: 0; font-size: 0.95rem; max-width: 760px;
        }

        /* Tombol Aksi Header */
        .hero-action {
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.1);
            color: #fff; font-weight: 600;
            border-radius: var(--radius-box);
            padding: 0.6rem 1.2rem;
            text-decoration: none;
            display: inline-flex; align-items: center;
            transition: all 0.2s; font-size: 0.9rem;
        }
        .hero-action:hover { background: rgba(255, 255, 255, 0.2); color: #fff; }
        
        .btn-export-excel { background: #059669; border-color: #059669; color: #fff;}
        .btn-export-excel:hover { background: #047857; color: #fff; }
        
        .btn-export-pdf { background: #DC2626; border-color: #DC2626; color: #fff;}
        .btn-export-pdf:hover { background: #B91C1C; color: #fff; }

        /* Alert Bawaan */
        .alert { border: none; border-radius: var(--radius-box); padding: 14px 16px; box-shadow: var(--shadow-soft); }

        /* General Card Base */
        .section-card {
            background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-box); box-shadow: var(--shadow-soft);
        }

        /* Summary Cards Hexindo */
        .summary-card {
            position: relative; overflow: hidden; height: 100%; padding: 1.25rem 1.5rem; transition: all 0.2s ease;
            background: #fff; border: 1px solid var(--border-soft); border-left: 4px solid var(--orange-1); border-radius: var(--radius-box); box-shadow: var(--shadow-soft);
        }
        .summary-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-hover); }
        .summary-label { font-size: 0.85rem; color: var(--text-soft); font-weight: 600; margin-bottom: 0.35rem; }
        .summary-value { font-size: 1.8rem; font-weight: 800; color: var(--dark-1); margin-bottom: 0.2rem; }
        .summary-note { font-size: 0.8rem; color: #9ca3af; }
        .summary-icon {
            width: 45px; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem; color: var(--orange-1); background: rgba(230, 67, 18, 0.1);
        }

        /* Form Shells (Kotak Tambah User) */
        .form-shell, .list-shell {
            background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-box); box-shadow: var(--shadow-soft); overflow: hidden;
        }

        .shell-header {
            padding: 1.2rem 1.5rem; background: #fff; color: var(--dark-1); border-bottom: 1px solid var(--border-soft);
        }

        .shell-title { font-size: 1.05rem; font-weight: 700; margin-bottom: 0.2rem; }
        .shell-subtitle { font-size: 0.85rem; color: var(--text-soft); }
        .shell-body { padding: 1.5rem; background: #fff; }

        /* Form Inputs */
        .form-label { font-weight: 600; color: var(--dark-1); margin-bottom: 0.5rem; font-size: 0.85rem; }
        .form-control, .form-select {
            border-radius: 6px; min-height: 42px; border: 1px solid var(--border-soft); box-shadow: none; padding: 0.5rem 0.8rem; font-size: 0.95rem;
        }
        .form-control:focus, .form-select:focus { border-color: var(--orange-1); box-shadow: 0 0 0 0.25rem rgba(230, 67, 18, 0.1); }

        /* Tombol Utama Hexindo */
        .btn-main {
            border: none; border-radius: 6px; font-weight: 600; padding: 0 1.2rem; min-height: 42px;
            background-color: var(--orange-1); color: #fff; transition: all 0.2s ease; display: inline-flex; align-items: center; justify-content: center; cursor: pointer;
        }
        .btn-main:hover { background-color: var(--orange-2); color: #fff; transform: translateY(-1px); }

        .btn-soft {
            border-radius: 6px; font-weight: 600; padding: 0.5rem 1rem; border: 1px solid var(--border-soft); background: #fff; color: var(--text-main);
        }
        .btn-soft:hover { background: var(--surface-bg); color: var(--dark-1); }

        /* Custom Tabs (Form Tabel/Kartu) */
        .nav-tabs.custom-tabs { border-bottom: 1px solid var(--border-soft); gap: 0.5rem; margin-bottom: 1.5rem;}
        .nav-tabs.custom-tabs .nav-link {
            border: none; border-bottom: 3px solid transparent; padding: 0.8rem 1.2rem; font-weight: 600; color: var(--text-soft); background: transparent; border-radius: 0;
        }
        .nav-tabs.custom-tabs .nav-link.active {
            color: var(--orange-1); border-bottom: 3px solid var(--orange-1); background: transparent; font-weight: 700;
        }

        .helper-note {
            background: #F9FAFB; border: 1px dashed var(--border-soft); border-radius: 6px; padding: 1rem 1.2rem; color: var(--text-main); font-size: 0.85rem;
        }

        .row-badge {
            display: inline-flex; align-items: center; gap: 0.35rem; border-radius: 6px; background: #F9FAFB; color: var(--text-main); border: 1px solid var(--border-soft); padding: 0.3rem 0.8rem; font-size: 0.8rem; font-weight: 600;
        }

        /* User Card (List Daftar User / Reset Password) */
        .user-card {
            border: 1px solid var(--border-soft); border-radius: var(--radius-box); background: #fff; transition: all 0.2s; overflow: hidden;
        }
        .user-card + .user-card { margin-top: 1rem; }
        .user-card:hover { border-color: var(--orange-1); box-shadow: var(--shadow-soft);}

        .user-top { padding: 1.2rem 1.5rem; background: #fff; }

        .user-avatar {
            width: 48px; height: 48px; border-radius: 8px; background-color: rgba(230, 67, 18, 0.1); color: var(--orange-1);
            display: inline-flex; align-items: center; justify-content: center; font-size: 1.2rem; font-weight: 700; flex-shrink: 0; border: 1px solid rgba(230, 67, 18, 0.2);
        }

        .user-name { font-size: 1rem; font-weight: 700; color: var(--dark-1); margin-bottom: 0.15rem; }
        .user-email { font-size: 0.85rem; color: var(--text-soft); }

        /* Soft Badges for Roles */
        .role-badge { display: inline-flex; align-items: center; gap: 0.35rem; border-radius: 6px; padding: 0.2rem 0.6rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;}
        .role-badge.admin { background: rgba(230, 67, 18, 0.1); color: var(--orange-1); border: 1px solid rgba(230, 67, 18, 0.2); }
        .role-badge.user { background: rgba(59, 130, 246, 0.1); color: #1d4ed8; border: 1px solid rgba(59, 130, 246, 0.2); }

        .employment-badge {
            display: inline-flex; align-items: center; gap: 0.35rem; border-radius: 6px;
            padding: 0.2rem 0.6rem; font-size: 0.75rem; font-weight: 600;
        }
        .employment-badge.active { background: rgba(16, 185, 129, 0.12); color: #059669; border: 1px solid rgba(16, 185, 129, 0.2); }
        .employment-badge.inactive { background: rgba(107, 114, 128, 0.12); color: #4b5563; border: 1px solid rgba(107, 114, 128, 0.2); }

        .user-card.inactive-user { opacity: 0.88; background: #fafafa; }

        .user-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }

        .btn-pill { border-radius: 6px; font-weight: 600; padding: 0.5rem 1rem; font-size: 0.85rem; border: 1px solid var(--border-soft); background: #fff; transition: 0.2s;}
        .btn-edit { color: var(--text-main); }
        .btn-edit:hover { background: var(--surface-bg); color: var(--orange-1); border-color: var(--orange-1);}
        .btn-delete { color: #D32F2F; }
        .btn-delete:hover { background: rgba(239, 68, 68, 0.05); border-color: #D32F2F; }

        .edit-area { padding: 1.5rem; border-top: 1px solid var(--border-soft); background: #F9FAFB; }

        /* Table Lite (Bulk Table) */
        .table-lite { width: 100%; min-width: 980px; }
        .table-lite th { white-space: nowrap; font-size: 0.85rem; color: var(--text-soft); padding-bottom: 0.85rem; font-weight: 700; text-transform: uppercase;}
        .table-lite td { vertical-align: top; padding-top: 0.8rem; padding-bottom: 0.8rem; border-top: 1px solid var(--border-soft); }
        .table-lite .form-control, .table-lite .form-select { min-width: 160px; min-height: 38px; } /* Sedikit dikecilkan untuk tabel */

        .invalid-feedback { display: block; }
        .empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-soft); border: 1px dashed var(--border-soft); border-radius: var(--radius-box); background: #F9FAFB;}
        .empty-state i { display: block; font-size: 2.5rem; margin-bottom: 0.5rem; color: #d1d5db; }

        .limit-box { display: flex; align-items: center; gap: 0.5rem; background: #F9FAFB; border: 1px solid var(--border-soft); border-radius: 6px; padding: 0.3rem 0.5rem 0.3rem 0.8rem; }
        .limit-box .form-select { border: none; background: transparent; font-weight: 600; padding: 0.2rem 1.5rem 0.2rem 0.5rem; color: var(--dark-1); box-shadow: none; min-height: auto;}
        .limit-box .section-label { color: var(--text-soft); font-size: 0.85rem; font-weight: 600; margin: 0; }

        .pagination { gap: 0.35rem; }
        .pagination .page-link { border-radius: 6px; color: var(--text-main); border: 1px solid var(--border-soft); padding: 0.5rem 0.8rem; font-weight: 600; margin: 0 2px; }
        .pagination .page-item.active .page-link { background: var(--dark-1); border-color: var(--dark-1); color: #fff; }

        .select2-container { width: 100% !important; }
        .select2-container--default .select2-selection--single { min-height: 42px; border: 1px solid var(--border-soft) !important; border-radius: 6px !important; padding: 0.2rem 0.5rem; display: flex; align-items: center; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { color: #333; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 100% !important; right: 10px !important; }
        .select2-dropdown { border: 1px solid var(--orange-1) !important; border-radius: 6px !important; overflow: hidden; }
        .select2-container--default.select2-container--focus .select2-selection--single { border-color: var(--orange-1) !important; box-shadow: 0 0 0 0.25rem rgba(230, 67, 18, 0.1) !important; }
        .form-select.is-invalid+.select2 .select2-selection { border-color: #dc3545 !important; }

    </style>
</head>

<body>

    <div class="container-fluid p-0">
        <div class="d-flex flex-nowrap w-100 overflow-hidden">

            <?php include '../layout/sidebar.php'; ?>

            <div id="mainContent" class="flex-grow-1" style="transition: all 0.28s ease; min-width: 0;">

                <div class="page-shell">

                    <div class="hero-card">
                        <div class="hero-content d-flex justify-content-between align-items-center flex-wrap gap-3">
                            
                            <div>
                                <h1 class="page-title">Kelola User Sistem</h1>
                                <p class="page-subtitle">Manajemen akun pengguna, pengaturan role, dan kontrol akses sistem.</p>
                            </div>

                            <div class="d-flex align-items-center flex-wrap gap-2">
                                <a href="<?= h(base_url('users/role_permissions.php')) ?>" class="hero-action">
                                    <i class="bi bi-shield-check me-2"></i>Hak Akses Role
                                </a>

                                <div class="d-flex gap-2">
                                    <a href="?export=excel" class="hero-action btn-export-excel">
                                        <i class="bi bi-file-earmark-excel-fill me-2"></i> Excel
                                    </a>
                                    <a href="?export=pdf" target="_blank" class="hero-action btn-export-pdf">
                                        <i class="bi bi-file-earmark-pdf-fill me-2"></i> PDF
                                    </a>
                                </div>
                            </div>

                        </div>
                    </div>

                    <?php if ($alertError): ?>
                        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i> <?= h($alertError) ?></div>
                    <?php endif; ?>

                    <?php if ($alertSuccess): ?>
                        <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i> <?= h($alertSuccess) ?></div>
                    <?php endif; ?>

                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Total User</div>
                                        <div class="summary-value"><?= $totalUsers ?></div>
                                        <div class="summary-note">Semua akun terdaftar</div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-people"></i></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Masih Bekerja</div>
                                        <div class="summary-value text-success"><?= $activeTotal ?></div>
                                        <div class="summary-note">Akun aktif bisa login</div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-person-check"></i></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="summary-card" style="border-left-color:#6b7280;">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Tidak Bekerja Lagi</div>
                                        <div class="summary-value text-muted"><?= $inactiveTotal ?></div>
                                        <div class="summary-note">Akun ada, akses diblokir</div>
                                    </div>
                                    <div class="summary-icon" style="color:#6b7280;background:rgba(107,114,128,.12);"><i class="bi bi-person-x"></i></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">User Cabang</div>
                                        <div class="summary-value"><?= $userTotal ?></div>
                                        <div class="summary-note">Admin HO: <?= $adminTotal ?></div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-person-gear"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($totalPendingReset > 0): ?>
                        <div class="list-shell mb-4">
                            <div class="shell-header">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div>
                                        <div class="shell-title"><i class="bi bi-envelope-exclamation me-2 text-danger"></i> Permintaan Reset Password</div>
                                        <div class="shell-subtitle"><?= $totalPendingReset ?> pengajuan menunggu diproses</div>
                                    </div>
                                    <span class="role-badge" style="background: rgba(245, 158, 11, 0.15); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.2);">
                                        <i class="bi bi-clock-history"></i> <?= $totalPendingReset ?> Pending
                                    </span>
                                </div>
                            </div>

                            <div class="shell-body">
                                <?php foreach ($resetRequests as $req): ?>
                                    <?php $formId = 'formResetReq' . (int) $req['id']; ?>
                                    <div class="user-card mb-3">
                                        <div class="user-top">
                                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                                <div class="d-flex align-items-start gap-3">

                                                    <div class="user-avatar" style="background: rgba(239, 68, 68, 0.1); color: #b91c1c; border-color: rgba(239, 68, 68, 0.2);">
                                                        <?= strtoupper(substr($req['username'], 0, 1)) ?>
                                                    </div>

                                                    <div>
                                                        <div class="user-name"><?= h($req['username']) ?></div>
                                                        <div class="user-email"><?= h($req['email']) ?></div>
                                                        <div class="user-email mt-1"><i class="bi bi-geo-alt me-1 text-muted"></i><?= h($req['nama_branch'] ?? 'Belum ditentukan') ?></div>

                                                        <div class="mt-2 d-flex flex-wrap gap-3" style="font-size: 0.8rem; color: var(--text-soft);">
                                                            <span><i class="bi bi-calendar me-1"></i> Diajukan: <strong class="text-dark"><?= format_datetime($req['requested_at']) ?></strong></span>
                                                        </div>

                                                        <?php if (!empty($req['alasan'])): ?>
                                                            <div class="mt-2" style="background: #F9FAFB; border: 1px dashed var(--border-soft); border-radius: 6px; padding: 0.6rem 0.85rem; font-size: 0.85rem; color: #555; max-width: 500px;">
                                                                <i class="bi bi-chat-left-text me-1 text-muted"></i> <?= h($req['alasan']) ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="mt-2" style="font-size: 0.8rem; color: #aaa; font-style: italic;">Tidak ada alasan yang diisi.</div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <div>
                                                    <button type="button" class="btn btn-pill btn-edit btnOpenResetForm" data-request-id="<?= (int) $req['id'] ?>" data-username="<?= h($req['username']) ?>" data-email="<?= h($req['email']) ?>" data-form-id="<?= $formId ?>">
                                                        <i class="bi bi-key me-1"></i> Proses Reset
                                                    </button>
                                                </div>
                                            </div>
                                        </div>

                                        <form method="POST" id="<?= $formId ?>" style="display:none;">
                                            <input type="hidden" name="action" value="reset_password_request">
                                            <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                                            <input type="hidden" name="new_password" id="newPassInput_<?= (int) $req['id'] ?>">
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="row g-4 mb-4">
                        <div class="col-lg-4">
                            <div class="form-shell h-100">
                                <div class="shell-header">
                                    <div class="shell-title">Tambah User Baru</div>
                                    <div class="shell-subtitle">Buat 1 akun cepat dan simpan.</div>
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
                                        <div class="alert alert-light border mb-3 py-2 px-3" style="font-size:0.85rem;">
                                            <i class="bi bi-magic me-1 text-muted"></i>
                                            Password dibuat <strong>otomatis</strong> oleh sistem setelah simpan. Email & password bisa disalin untuk diberitahukan ke user.
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Role Akses</label>
                                            <select name="role" class="form-select">
                                                <option value="user">User Cabang</option>
                                                <option value="admin">Admin HO</option>
                                            </select>
                                        </div>
                                        <div class="mb-4">
                                            <label class="form-label">Lokasi Cabang</label>
                                            <select name="id_branch" class="form-select branch-select" required>
                                                <option value=""></option>
                                                <?php foreach ($branches as $branch): ?>
                                                    <option value="<?= (int) $branch['id_branch'] ?>"><?= h($branch['nama_branch']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <button type="submit" class="btn btn-main w-100"><i class="bi bi-save me-2"></i> Simpan User</button>
                                    </form>

                                    <div class="helper-note mt-3">
                                        <i class="bi bi-info-circle text-muted me-1"></i> Form ini cocok untuk menambah satu akun secara cepat.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-8">
                            <div class="form-shell h-100">
                                <div class="shell-header">
                                    <div class="shell-title">Tambah Banyak User (Bulk Input)</div>
                                    <div class="shell-subtitle">Pilih mode tampilan yang nyaman untuk input sekaligus.</div>
                                </div>

                                <div class="shell-body">
                                    <ul class="nav nav-tabs custom-tabs" id="userFormTabs" role="tablist">
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabBulkCard" type="button">Mode Kartu</button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabBulkTable" type="button">Mode Tabel</button>
                                        </li>
                                    </ul>

                                    <div class="tab-content">
                                        <div class="tab-pane fade show active" id="tabBulkCard">
                                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                                <div class="helper-note flex-grow-1"><i class="bi bi-info-circle text-muted me-1"></i> Cocok untuk layar kecil / input bertahap. Password dibuat otomatis per user.</div>
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <span class="row-badge"><span id="bulkRowCounter"><?= count($bulkOldRows) ?></span> / 10 baris</span>
                                                    <button type="button" id="btnAddBulkRow" class="btn btn-soft"><i class="bi bi-plus-lg me-1"></i> Tambah Baris</button>
                                                </div>
                                            </div>

                                            <form method="POST" id="bulkCreateForm" novalidate>
                                                <input type="hidden" name="action" value="bulk_create">

                                                <div id="bulkUserRows">
                                                    <?php foreach ($bulkOldRows as $index => $bulkRow): ?>
                                                        <div class="section-card p-4 mb-3 bulk-user-row" data-row-index="<?= $index ?>">
                                                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 border-bottom pb-2">
                                                                <div class="row-badge fw-bold">Baris <span class="bulk-row-number"><?= $index + 1 ?></span></div>
                                                                <button type="button" class="btn btn-sm btn-light border text-danger btnRemoveBulkRow" <?= count($bulkOldRows) === 1 ? 'disabled' : '' ?>><i class="bi bi-trash3 me-1"></i> Hapus Baris</button>
                                                            </div>

                                                            <div class="row g-3">
                                                                <div class="col-lg-6">
                                                                    <label class="form-label">Username</label>
                                                                    <input type="text" class="form-control <?= bulk_field_error($bulkErrors, $index, 'username') ? 'is-invalid' : '' ?>" value="<?= h($bulkRow['username']) ?>" data-field="username" data-name-template="bulk_users[__INDEX__][username]" name="bulk_users[<?= $index ?>][username]">
                                                                    <div class="invalid-feedback"><?= h(bulk_field_error($bulkErrors, $index, 'username')) ?></div>
                                                                </div>

                                                                <div class="col-lg-6">
                                                                    <label class="form-label">Email</label>
                                                                    <input type="email" class="form-control <?= bulk_field_error($bulkErrors, $index, 'email') ? 'is-invalid' : '' ?>" value="<?= h($bulkRow['email']) ?>" data-field="email" data-name-template="bulk_users[__INDEX__][email]" name="bulk_users[<?= $index ?>][email]">
                                                                    <div class="invalid-feedback"><?= h(bulk_field_error($bulkErrors, $index, 'email')) ?></div>
                                                                </div>

                                                                <div class="col-lg-6">
                                                                    <label class="form-label">Role</label>
                                                                    <select class="form-select" data-field="role" data-name-template="bulk_users[__INDEX__][role]" name="bulk_users[<?= $index ?>][role]">
                                                                        <option value="user" <?= $bulkRow['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                                                        <option value="admin" <?= $bulkRow['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                                    </select>
                                                                    <div class="invalid-feedback"></div>
                                                                </div>

                                                                <div class="col-lg-12">
                                                                    <label class="form-label">Cabang</label>
                                                                    <select class="form-select branch-select <?= bulk_field_error($bulkErrors, $index, 'id_branch') ? 'is-invalid' : '' ?>" data-field="id_branch" data-name-template="bulk_users[__INDEX__][id_branch]" name="bulk_users[<?= $index ?>][id_branch]">
                                                                        <option value=""></option>
                                                                        <?php foreach ($branches as $branch): ?>
                                                                            <option value="<?= (int) $branch['id_branch'] ?>" <?= (int) ($bulkRow['id_branch'] ?? 0) === (int) $branch['id_branch'] ? 'selected' : '' ?>><?= h($branch['nama_branch']) ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                    <div class="invalid-feedback"><?= h(bulk_field_error($bulkErrors, $index, 'id_branch')) ?></div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>

                                                <div class="d-flex justify-content-end mt-4 border-top pt-3">
                                                    <button type="submit" class="btn btn-main"><i class="bi bi-save me-2"></i> Simpan Semua User</button>
                                                </div>
                                            </form>

                                            <!-- Template HTML JS Card -->
                                            <template id="bulkUserRowTemplate">
                                                <div class="section-card p-4 mb-3 bulk-user-row" data-row-index="0">
                                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 border-bottom pb-2">
                                                        <div class="row-badge fw-bold">Baris <span class="bulk-row-number">1</span></div>
                                                        <button type="button" class="btn btn-sm btn-light border text-danger btnRemoveBulkRow"><i class="bi bi-trash3 me-1"></i> Hapus</button>
                                                    </div>
                                                    <div class="row g-3">
                                                        <div class="col-lg-6"><label class="form-label">Username</label><input type="text" class="form-control" value="" data-field="username" data-name-template="bulk_users[__INDEX__][username]" name=""><div class="invalid-feedback"></div></div>
                                                        <div class="col-lg-6"><label class="form-label">Email</label><input type="email" class="form-control" value="" data-field="email" data-name-template="bulk_users[__INDEX__][email]" name=""><div class="invalid-feedback"></div></div>
                                                        <div class="col-lg-6"><label class="form-label">Role</label><select class="form-select" data-field="role" data-name-template="bulk_users[__INDEX__][role]" name=""><option value="user" selected>User</option><option value="admin">Admin</option></select><div class="invalid-feedback"></div></div>
                                                        <div class="col-lg-12"><label class="form-label">Cabang</label><select class="form-select branch-select" data-field="id_branch" data-name-template="bulk_users[__INDEX__][id_branch]" name=""><option value=""></option><?php foreach ($branches as $branch): ?><option value="<?= (int) $branch['id_branch'] ?>"><?= h($branch['nama_branch']) ?></option><?php endforeach; ?></select><div class="invalid-feedback"></div></div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>

                                        <div class="tab-pane fade" id="tabBulkTable">
                                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                                <div class="helper-note flex-grow-1"><i class="bi bi-info-circle text-muted me-1"></i> Cocok untuk input sangat cepat dari Excel. Password dibuat otomatis per user.</div>
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <span class="row-badge"><span id="userRowCounter"><?= count($createUserRowsOld) ?></span> / 10 baris</span>
                                                    <button type="button" id="btnAddUserRow" class="btn btn-soft"><i class="bi bi-plus-lg me-1"></i> Tambah Baris</button>
                                                </div>
                                            </div>

                                            <?php if ($createUserGeneralError !== ''): ?>
                                                <div class="alert alert-danger py-2 px-3 mb-3"><?= h($createUserGeneralError) ?></div>
                                            <?php endif; ?>

                                            <form method="POST" id="multiUserCreateForm" novalidate>
                                                <input type="hidden" name="action" value="save_users">

                                                <div class="table-responsive border rounded-3 pb-2" style="border-color: var(--border-soft) !important;">
                                                    <table class="table-lite">
                                                        <thead style="background: #F9FAFB; border-bottom: 1px solid var(--border-soft);">
                                                            <tr>
                                                                <th class="ps-3 pt-3" style="width:70px;">Baris</th>
                                                                <th class="pt-3">Username</th>
                                                                <th class="pt-3">Email</th>
                                                                <th class="pt-3" style="width:160px;">Role</th>
                                                                <th class="pt-3" style="width:200px;">Cabang</th>
                                                                <th class="pt-3 pe-3 text-center" style="width:100px;">Aksi</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody id="userRowContainer">
                                                            <?php foreach ($createUserRowsOld as $index => $row): ?>
                                                                <tr class="user-create-row" data-row-index="<?= $index ?>">
                                                                    <td class="ps-3"><span class="row-badge user-row-number bg-light border-0 text-muted"><?= $index + 1 ?></span></td>
                                                                    <td>
                                                                        <input type="text" class="form-control <?= create_user_field_error($createUserRowsErrors, $index, 'username') ? 'is-invalid' : '' ?>" value="<?= h($row['username']) ?>" data-field="username" data-name-template="users[__INDEX__][username]" name="users[<?= $index ?>][username]">
                                                                        <div class="invalid-feedback"><?= h(create_user_field_error($createUserRowsErrors, $index, 'username')) ?></div>
                                                                    </td>
                                                                    <td>
                                                                        <input type="email" class="form-control <?= create_user_field_error($createUserRowsErrors, $index, 'email') ? 'is-invalid' : '' ?>" value="<?= h($row['email']) ?>" data-field="email" data-name-template="users[__INDEX__][email]" name="users[<?= $index ?>][email]">
                                                                        <div class="invalid-feedback"><?= h(create_user_field_error($createUserRowsErrors, $index, 'email')) ?></div>
                                                                    </td>
                                                                    <td>
                                                                        <select class="form-select" data-field="role" data-name-template="users[__INDEX__][role]" name="users[<?= $index ?>][role]">
                                                                            <option value="user" <?= $row['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                                                            <option value="admin" <?= $row['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                                        </select>
                                                                    </td>
                                                                    <td>
                                                                        <select class="form-select branch-select <?= create_user_field_error($createUserRowsErrors, $index, 'id_branch') ? 'is-invalid' : '' ?>" data-field="id_branch" data-name-template="users[__INDEX__][id_branch]" name="users[<?= $index ?>][id_branch]">
                                                                            <option value=""></option>
                                                                            <?php foreach ($branches as $branch): ?>
                                                                                <option value="<?= (int) $branch['id_branch'] ?>" <?= (int) ($row['id_branch'] ?? 0) === (int) $branch['id_branch'] ? 'selected' : '' ?>><?= h($branch['nama_branch']) ?></option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                        <div class="invalid-feedback"><?= h(create_user_field_error($createUserRowsErrors, $index, 'id_branch')) ?></div>
                                                                    </td>
                                                                    <td class="pe-3 text-center">
                                                                        <button type="button" class="btn btn-light border text-danger btn-sm btnRemoveUserRow" title="Hapus" <?= count($createUserRowsOld) === 1 ? 'disabled' : '' ?>><i class="bi bi-trash3"></i></button>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>

                                                <div class="d-flex justify-content-end mt-4 pt-3 border-top">
                                                    <button type="submit" class="btn btn-main"><i class="bi bi-save me-2"></i> Simpan Tabel User</button>
                                                </div>
                                            </form>

                                            <!-- Template HTML JS Table -->
                                            <template id="userRowTemplate">
                                                <tr class="user-create-row" data-row-index="0">
                                                    <td class="ps-3"><span class="row-badge user-row-number bg-light border-0 text-muted">1</span></td>
                                                    <td><input type="text" class="form-control" value="" data-field="username" data-name-template="users[__INDEX__][username]" name=""><div class="invalid-feedback"></div></td>
                                                    <td><input type="email" class="form-control" value="" data-field="email" data-name-template="users[__INDEX__][email]" name=""><div class="invalid-feedback"></div></td>
                                                    <td><select class="form-select" data-field="role" data-name-template="users[__INDEX__][role]" name=""><option value="user" selected>User</option><option value="admin">Admin</option></select><div class="invalid-feedback"></div></td>
                                                    <td><select class="form-select branch-select" data-field="id_branch" data-name-template="users[__INDEX__][id_branch]" name=""><option value=""></option><?php foreach ($branches as $branch): ?><option value="<?= (int) $branch['id_branch'] ?>"><?= h($branch['nama_branch']) ?></option><?php endforeach; ?></select><div class="invalid-feedback"></div></td>
                                                    <td class="pe-3 text-center"><button type="button" class="btn btn-light border text-danger btn-sm btnRemoveUserRow"><i class="bi bi-trash3"></i></button></td>
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
                                    <div class="shell-title"><i class="bi bi-list-ul me-2" style="color: var(--orange-1);"></i>Daftar User Sistem</div>
                                    <div class="shell-subtitle">Menampilkan <?= $displayFrom ?> - <?= $displayTo ?> dari <?= $filteredTotalUsers ?> user</div>
                                </div>

                                <form method="GET" class="mb-0 d-flex flex-wrap gap-2 align-items-center">
                                    <input type="hidden" name="limit" value="<?= $limit ?>">
                                    <div class="limit-box">
                                        <span class="section-label">Status User:</span>
                                        <select name="employment" onchange="this.form.submit()" class="form-select form-select-sm">
                                            <option value="all" <?= $employmentFilter === 'all' ? 'selected' : '' ?>>Semua</option>
                                            <option value="active" <?= $employmentFilter === 'active' ? 'selected' : '' ?>>Masih Bekerja</option>
                                            <option value="inactive" <?= $employmentFilter === 'inactive' ? 'selected' : '' ?>>Tidak Bekerja Lagi</option>
                                        </select>
                                    </div>
                                </form>

                                <form method="GET" class="mb-0">
                                    <?php if ($employmentFilter !== 'all'): ?>
                                        <input type="hidden" name="employment" value="<?= h($employmentFilter) ?>">
                                    <?php endif; ?>
                                    <div class="limit-box">
                                        <span class="section-label">Baris:</span>
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

                        <div class="shell-body p-4">
                            <?php if (!empty($users)): ?>
                                <?php foreach ($users as $user): ?>
                                    <?php
                                    $collapseId = 'editUserCollapse' . (int) $user['id'];
                                    $userEmployment = normalize_employment_status($user['employment_status'] ?? 'active');
                                    ?>
                                    <div class="user-card <?= $userEmployment === 'inactive' ? 'inactive-user' : '' ?>">
                                        <div class="user-top">
                                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="user-avatar">
                                                        <?= strtoupper(substr($user['username'], 0, 1)) ?>
                                                    </div>

                                                    <div>
                                                        <div class="user-name"><?= h($user['username']) ?></div>
                                                        <div class="user-email"><i class="bi bi-envelope me-1 text-muted"></i><?= h($user['email']) ?> &nbsp;|&nbsp; <i class="bi bi-geo-alt me-1 text-muted"></i><?= h($user['nama_branch'] ?? 'Belum ditentukan') ?></div>
                                                        <div class="mt-2 d-flex flex-wrap gap-2 align-items-center">
                                                            <span class="<?= role_badge_class($user['role']) ?>"><i class="bi <?= role_icon($user['role']) ?>"></i> <?= role_label($user['role']) ?></span>
                                                            <span class="<?= employment_badge_class($userEmployment) ?>">
                                                                <i class="bi <?= $userEmployment === 'active' ? 'bi-briefcase-fill' : 'bi-briefcase' ?>"></i>
                                                                <?= employment_status_label($userEmployment) ?>
                                                            </span>
                                                            <span class="text-muted" style="font-size: 0.75rem;"><i class="bi bi-clock"></i> Dibuat: <?= format_datetime($user['created_at'] ?? null) ?></span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="user-actions">
                                                    <button type="button" class="btn btn-pill btn-edit" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>">
                                                        <i class="bi bi-pencil-square me-1"></i> Edit Data
                                                    </button>

                                                    <form method="POST" class="formDeleteUser mb-0">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                                        <input type="hidden" name="username" value="<?= h($user['username']) ?>">
                                                        <button type="button" class="btn btn-pill btn-delete btnDeleteUser">
                                                            <i class="bi bi-trash3 me-1"></i> Hapus
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
                                                            <input type="text" name="username" class="form-control" value="<?= h($user['username']) ?>" required>
                                                        </div>

                                                        <div class="col-lg-4">
                                                            <label class="form-label">Email</label>
                                                            <input type="email" name="email" class="form-control" value="<?= h($user['email']) ?>" required>
                                                        </div>

                                                        <div class="col-lg-4">
                                                            <label class="form-label">Role Akses</label>
                                                            <select name="role" class="form-select">
                                                                <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User Cabang</option>
                                                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin HO</option>
                                                            </select>
                                                        </div>

                                                        <div class="col-lg-4">
                                                            <label class="form-label">Status User</label>
                                                            <select name="employment_status" class="form-select">
                                                                <option value="active" <?= $userEmployment === 'active' ? 'selected' : '' ?>>Masih Bekerja (bisa login)</option>
                                                                <option value="inactive" <?= $userEmployment === 'inactive' ? 'selected' : '' ?>>Tidak Bekerja Lagi (akses diblokir)</option>
                                                            </select>
                                                            <div class="form-text">User tidak bekerja lagi tetap tersimpan, tapi tidak bisa masuk sistem.</div>
                                                        </div>

                                                        <div class="col-lg-4">
                                                            <label class="form-label">Lokasi Cabang</label>
                                                            <select name="id_branch" class="form-select branch-select" required>
                                                                <option value=""></option>
                                                                <?php foreach ($branches as $branch): ?>
                                                                    <option value="<?= (int) $branch['id_branch'] ?>" <?= (int) ($user['id_branch'] ?? 0) === (int) $branch['id_branch'] ? 'selected' : '' ?>>
                                                                        <?= h($branch['nama_branch']) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="col-lg-4">
                                                            <label class="form-label">Password Baru</label>
                                                            <div class="d-flex gap-2">
                                                                <input
                                                                    type="text"
                                                                    name="new_password"
                                                                    id="editNewPass_<?= (int) $user['id'] ?>"
                                                                    class="form-control pw-plain"
                                                                    data-pw-plain="1"
                                                                    readonly
                                                                    placeholder="Kosongkan jika tidak diganti">
                                                                <button type="button" class="btn btn-soft btn-generate-password" data-target="editNewPass_<?= (int) $user['id'] ?>" title="Generate otomatis">
                                                                    <i class="bi bi-magic"></i>
                                                                </button>
                                                                <button type="button" class="btn btn-light border btn-clear-generated-password" data-target="editNewPass_<?= (int) $user['id'] ?>" title="Kosongkan">
                                                                    <i class="bi bi-x-lg"></i>
                                                                </button>
                                                            </div>
                                                            <div class="form-text">Klik magic untuk buat password otomatis, lalu salin untuk user.</div>
                                                        </div>

                                                        <div class="col-12 mt-4 pt-3 border-top text-end">
                                                            <button type="button" class="btn btn-main px-5 btnUpdateUser" data-form-id="updateUserForm<?= (int) $user['id'] ?>" data-username="<?= h($user['username']) ?>">
                                                                <i class="bi bi-save me-2"></i> Update User
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
                                    <i class="bi bi-person-x"></i>
                                    <span>Belum ada data user di sistem.</span>
                                </div>
                            <?php endif; ?>

                            <?php if ($totalPages > 1): ?>
                                <div class="mt-4 pt-3 border-top d-flex justify-content-end">
                                    <nav>
                                        <ul class="pagination mb-0 flex-wrap">
                                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                                <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                                    <a class="page-link" href="?page=<?= $i ?>&limit=<?= $limit ?>&employment=<?= h($employmentFilter) ?>"><?= $i ?></a>
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
    <script src="<?= e(base_url('assets/js/password-fields.js')) ?>"></script>

    <!-- SCRIPT TIDAK ADA YANG DIUBAH SAMA SEKALI KECUALI WARNA HEXINDO DI SWEETALERT -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function initBranchSelect(scope = document) {
                const selects = scope.querySelectorAll('select.branch-select');
                selects.forEach(function(select) {
                    if ($(select).hasClass('select2-hidden-accessible')) { return; }
                    $(select).select2({ width: '100%', placeholder: 'Pilih cabang...', allowClear: true });
                });
            }

            initBranchSelect();

            document.querySelectorAll('.btnUpdateUser').forEach(function(button) {
                button.addEventListener('click', function() {
                    const formId = this.dataset.formId;
                    const username = this.dataset.username;
                    const form = document.getElementById(formId);
                    Swal.fire({
                        title: 'Simpan Perubahan?',
                        text: 'Data user "' + username + '" akan diperbarui.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Ya, Simpan',
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#E64312'
                    }).then((result) => {
                        if (result.isConfirmed) { form.submit(); }
                    });
                });
            });

            document.querySelectorAll('.btnDeleteUser').forEach(function(button) {
                button.addEventListener('click', function() {
                    const form = this.closest('.formDeleteUser');
                    const usernameInput = form.querySelector('input[name="username"]');
                    const username = usernameInput ? usernameInput.value : 'user ini';
                    Swal.fire({
                        title: 'Hapus User?',
                        text: 'User "' + username + '" akan dihapus permanen dari sistem.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Ya, Hapus',
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#D32F2F'
                    }).then((result) => {
                        if (result.isConfirmed) { form.submit(); }
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
                    if (numberEl) { numberEl.textContent = index + 1; }
                    row.querySelectorAll('[data-name-template]').forEach(function(field) {
                        field.name = field.dataset.nameTemplate.replace(/__INDEX__/g, index);
                    });
                    const removeButton = row.querySelector('.btnRemoveBulkRow');
                    if (removeButton) { removeButton.disabled = rows.length === 1; }
                });
                if (bulkCounter) { bulkCounter.textContent = rows.length; }
                if (bulkAddButton) { bulkAddButton.disabled = rows.length >= bulkMaxRows; }
            }

            function clearBulkRowErrors(row) {
                row.querySelectorAll('.form-control, .form-select').forEach(function(field) { field.classList.remove('is-invalid'); });
                row.querySelectorAll('.invalid-feedback').forEach(function(feedback) { feedback.textContent = ''; });
            }

            function setBulkFieldError(row, fieldName, message) {
                const field = row.querySelector('[data-field="' + fieldName + '"]');
                if (!field) return;
                field.classList.add('is-invalid');
                const feedback = field.parentElement.querySelector('.invalid-feedback');
                if (feedback) { feedback.textContent = message; }
            }

            function getBulkRowData(row) {
                return {
                    username: row.querySelector('[data-field="username"]').value.trim(),
                    email: row.querySelector('[data-field="email"]').value.trim(),
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
                row.querySelector('[data-field="role"]').value = data.role || 'user';
                row.querySelector('[data-field="id_branch"]').value = data.id_branch || '';
                bulkContainer.appendChild(row);
                updateBulkRows();
                initBranchSelect(row);
            }

            function validateBulkForm() {
                let isValid = true;
                let filledCount = 0;
                if (!bulkContainer) { return { isValid: false, filledCount: 0 }; }
                const rows = Array.from(bulkContainer.querySelectorAll('.bulk-user-row'));
                const usernameMap = {};
                const emailMap = {};

                rows.forEach(function(row) {
                    clearBulkRowErrors(row);
                    const data = getBulkRowData(row);
                    const hasValue = data.username !== '' || data.email !== '';
                    if (!hasValue) return;
                    filledCount++;

                    if (data.username === '') {
                        setBulkFieldError(row, 'username', 'Username wajib diisi.');
                        isValid = false;
                    } else if (!/^[a-zA-Z\s]+$/.test(data.username)) {
                        setBulkFieldError(row, 'username', 'Username hanya boleh berisi huruf.');
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
                    Swal.fire({ icon: 'warning', title: 'Form kosong', text: 'Isi minimal 1 user pada form tambah banyak.', confirmButtonColor: '#E64312' });
                    return { isValid: false, filledCount: 0 };
                }

                Object.keys(usernameMap).forEach(function(key) {
                    if (usernameMap[key].length > 1) {
                        usernameMap[key].forEach(function(row) { setBulkFieldError(row, 'username', 'Username duplikat.'); });
                        isValid = false;
                    }
                });

                Object.keys(emailMap).forEach(function(key) {
                    if (emailMap[key].length > 1) {
                        emailMap[key].forEach(function(row) { setBulkFieldError(row, 'email', 'Email duplikat.'); });
                        isValid = false;
                    }
                });

                return { isValid: isValid, filledCount: filledCount };
            }

            if (bulkAddButton) { bulkAddButton.addEventListener('click', function() { addBulkRow(); }); }

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
                    if (feedback) { feedback.textContent = ''; }
                });
            }

            if (bulkForm) {
                bulkForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const result = validateBulkForm();
                    if (!result.isValid) return;
                    Swal.fire({
                        title: 'Simpan User?',
                        text: result.filledCount + ' user baru akan dibuat.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Ya, Simpan',
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#059669'
                    }).then((swalResult) => {
                        if (swalResult.isConfirmed) { bulkForm.submit(); }
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
                    if (numberEl) { numberEl.textContent = index + 1; }
                    row.querySelectorAll('[data-name-template]').forEach(function(field) {
                        field.name = field.dataset.nameTemplate.replace(/__INDEX__/g, index);
                    });
                    const removeButton = row.querySelector('.btnRemoveUserRow');
                    if (removeButton) { removeButton.disabled = rows.length === 1; }
                });
                if (rowCounter) { rowCounter.textContent = rows.length; }
                if (addRowButton) { addRowButton.disabled = rows.length >= maxRows; }
            }

            function clearGeneralError() {
                if (!form) return;
                const generalError = form.parentElement.querySelector('.alert.alert-danger');
                if (generalError && generalError.textContent.includes('Isi minimal 1 baris user.')) { generalError.remove(); }
            }

            function clearRowErrors(row) {
                row.querySelectorAll('.form-control, .form-select').forEach(function(field) { field.classList.remove('is-invalid'); });
                row.querySelectorAll('.invalid-feedback').forEach(function(feedback) { feedback.textContent = ''; });
            }

            function setFieldError(row, fieldName, message) {
                const field = row.querySelector('[data-field="' + fieldName + '"]');
                if (!field) return;
                field.classList.add('is-invalid');
                const feedback = field.parentElement.querySelector('.invalid-feedback');
                if (feedback) { feedback.textContent = message; }
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
                if (!rowContainer) { return { isValid: false, filledCount: 0 }; }
                const rows = Array.from(rowContainer.querySelectorAll('.user-create-row'));
                const usernameMap = {};
                const emailMap = {};

                rows.forEach(function(row) {
                    clearRowErrors(row);
                    const data = getRowData(row);
                    const hasValue = data.username !== '' || data.email !== '';
                    if (!hasValue) return;
                    filledCount++;

                    if (data.username === '') {
                        setFieldError(row, 'username', 'Username wajib diisi.');
                        isValid = false;
                    } else if (!/^[a-zA-Z\s]+$/.test(data.username)) {
                        setFieldError(row, 'username', 'Username hanya boleh berisi huruf.');
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
                        if (!usernameMap[usernameKey]) { usernameMap[usernameKey] = []; }
                        usernameMap[usernameKey].push(row);
                    }
                    if (data.email !== '') {
                        const emailKey = data.email.toLowerCase();
                        if (!emailMap[emailKey]) { emailMap[emailKey] = []; }
                        emailMap[emailKey].push(row);
                    }
                });

                if (filledCount === 0) {
                    setGeneralError('Isi minimal 1 baris user.');
                    isValid = false;
                }

                Object.keys(usernameMap).forEach(function(key) {
                    if (usernameMap[key].length > 1) {
                        usernameMap[key].forEach(function(row) { setFieldError(row, 'username', 'Username duplikat.'); });
                        isValid = false;
                    }
                });

                Object.keys(emailMap).forEach(function(key) {
                    if (emailMap[key].length > 1) {
                        emailMap[key].forEach(function(row) { setFieldError(row, 'email', 'Email duplikat.'); });
                        isValid = false;
                    }
                });

                return { isValid: isValid, filledCount: filledCount };
            }

            if (addRowButton) { addRowButton.addEventListener('click', function() { addRow(); }); }

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
                    if (feedback) { feedback.textContent = ''; }
                    clearGeneralError();
                });
                rowContainer.addEventListener('change', function() { clearGeneralError(); });
            }

            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const result = validateForm();
                    if (!result.isValid) return;
                    Swal.fire({
                        title: 'Simpan Tabel User?',
                        text: result.filledCount + ' user akan dibuat.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Ya, Simpan',
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#059669'
                    }).then((swalResult) => {
                        if (swalResult.isConfirmed) { form.submit(); }
                    });
                });
                updateRows();
            }
        });

        document.querySelectorAll('.btnOpenResetForm').forEach(function(button) {
            button.addEventListener('click', function() {
                const requestId = this.dataset.requestId;
                const username = this.dataset.username;
                const email = this.dataset.email || '';
                const formId = this.dataset.formId;
                const form = document.getElementById(formId);

                PasswordFields.showResetCredentialModal(username, email, PasswordFields.generateStrongPassword(), function(newPassword) {
                    document.getElementById('newPassInput_' + requestId).value = newPassword;
                    form.submit();
                });
            });
        });

        <?php 
        $popupType = $_SESSION['popup_type'] ?? 'create';
        unset($_SESSION['popup_type']); 
        ?>

        <?php if ($userCredentialsFlash): ?>
        // 1. Munculkan modal bawaan sistem
        PasswordFields.showCredentialsModal(<?= $userCredentialsFlash ?>);
        
        <?php if ($popupType === 'reset'): ?>
        // 2. Jika ini aksi RESET, perbaiki tampilan & tombol salin
        setTimeout(function() {
            const modal = document.querySelector('.swal2-popup');
            if (!modal) return;

            // -- UBAH JUDUL --
            const title = modal.querySelector('.swal2-title, h2');
            if (title) title.textContent = 'Password Berhasil Direset';

            // -- UBAH DESKRIPSI (Tanpa merusak fungsi tombol) --
            // Kita cari elemen teks spesifik tanpa menyentuh tombol di bawahnya
            const allElements = modal.querySelectorAll('*');
            allElements.forEach(el => {
                if (el.childNodes.length === 1 && el.childNodes[0].nodeType === 3) { // Hanya elemen berisi teks
                    if (el.textContent.includes('Salin email dan password berikut')) {
                        el.textContent = 'Salin email dan password baru berikut untuk diberikan ke user.';
                    }
                }
            });

            // -- SEMBUNYIKAN USERNAME & AMBIL NILAI INPUT --
            let emailValue = '';
            let passValue = '';

            const labels = modal.querySelectorAll('label, div.mb-3, div.mb-2');
            labels.forEach(function(label) {
                const text = label.textContent.trim().toLowerCase();
                
                // Sembunyikan Username
                if (text.includes('username')) {
                    if (label.parentElement && label.tagName === 'LABEL') {
                        label.parentElement.style.display = 'none'; 
                    } else {
                        label.style.display = 'none';
                    }
                }
                
                // Ambil nilai Email untuk dicopy
                if (text.includes('email')) {
                    const input = label.parentElement.querySelector('input') || label.querySelector('input');
                    if (input) emailValue = input.value;
                }
                
                // Ambil nilai Password untuk dicopy
                if (text.includes('password')) {
                    const input = label.parentElement.querySelector('input') || label.querySelector('input');
                    if (input) passValue = input.value;
                }
            });

            // -- PERBAIKI TOMBOL SALIN SEMUA --
            // Cari tombol yang mengandung teks "Salin"
            const buttons = modal.querySelectorAll('button');
            let copyBtn = Array.from(buttons).find(btn => btn.textContent.toLowerCase().includes('salin'));

            if (copyBtn) {
                // Kloning tombol untuk membersihkan fungsi klik bawaan yang rusak/lama
                const newCopyBtn = copyBtn.cloneNode(true);
                copyBtn.parentNode.replaceChild(newCopyBtn, copyBtn);

                // Buat fungsi salin yang baru dan bersih (Hanya Email & Password)
                newCopyBtn.addEventListener('click', function() {
                    // Fallback ambil value realtime jika telat terload
                    const inputs = modal.querySelectorAll('input');
                    const finalEmail = emailValue || (inputs[1] ? inputs[1].value : '');
                    const finalPass = passValue || (inputs[2] ? inputs[2].value : '');

                    const textToCopy = `Email: ${finalEmail}\nPassword: ${finalPass}`;

                    navigator.clipboard.writeText(textToCopy).then(() => {
                        // Animasi sukses
                        const originalHtml = newCopyBtn.innerHTML;
                        newCopyBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Tersalin!';
                        newCopyBtn.style.backgroundColor = '#059669'; // Warna hijau sukses
                        newCopyBtn.style.color = 'white';
                        newCopyBtn.style.border = 'none';

                        setTimeout(() => {
                            newCopyBtn.innerHTML = originalHtml;
                            newCopyBtn.style = ''; // Kembalikan style semula
                        }, 2000);
                    }).catch(err => {
                        alert('Gagal menyalin. Silakan blok teks dan copy secara manual.');
                    });
                });
            }

        }, 50);
        <?php endif; ?>
        <?php endif; ?>
        // Batasi input username hanya huruf
            document.addEventListener('input', function(e) {
                if (e.target.name === 'username' || e.target.dataset.field === 'username') {
                    e.target.value = e.target.value.replace(/[^a-zA-Z\s]/g, '');  
                }
            });
    </script>
</body>
</html>
