<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ganti sesuai nama folder project di localhost.A
if (!defined('BASE_URL')) {
    define('BASE_URL', '/PROJECT-PKL');
}

function base_url(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function set_flash(string $key, string $message): void
{
    $_SESSION['flash'][$key] = $message;
}

function get_flash(string $key): ?string
{
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $message;
}

function normalize_role(?string $role): string
{
    $role = strtolower(trim((string) $role));
    $allowed = ['super_admin', 'admin', 'user'];

    return in_array($role, $allowed, true) ? $role : 'user';
}

function is_logged_in(): bool
{
    return !empty($_SESSION['auth']['id']);
}

function current_user(): ?array
{
    return $_SESSION['auth'] ?? null;
}

function current_user_id(): ?int
{
    return isset($_SESSION['auth']['id']) ? (int) $_SESSION['auth']['id'] : null;
}

function current_role(): string
{
    return $_SESSION['auth']['role'] ?? 'guest';
}

function is_super_admin(): bool
{
    return current_role() === 'super_admin';
}

function redirect_to(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

function get_role_permissions(mysqli $koneksi, string $role): array
{
    $role = normalize_role($role);

    if ($role === 'super_admin') {
        return ['*'];
    }

    $sql = "SELECT p.permission_key
            FROM rbac_permissions p
            INNER JOIN rbac_role_permissions rp ON rp.permission_id = p.id
            WHERE rp.role = ?";

    $stmt = mysqli_prepare($koneksi, $sql);
    mysqli_stmt_bind_param($stmt, 's', $role);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $permissions = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $permissions[] = $row['permission_key'];
    }

    return array_values(array_unique($permissions));
}

function refresh_permissions(mysqli $koneksi): void
{
    if (!is_logged_in()) {
        return;
    }

    $_SESSION['auth']['permissions'] = get_role_permissions($koneksi, current_role());
}

function can(string $permission): bool
{
    if (!is_logged_in()) {
        return false;
    }

    if (is_super_admin()) {
        return true;
    }

    $permissions = $_SESSION['auth']['permissions'] ?? [];
    return in_array($permission, $permissions, true);
}

function require_login(): void
{
    if (!is_logged_in()) {
        set_flash('error', 'Silakan login terlebih dahulu.');
        redirect_to(base_url('auth/login.php'));
    }
}

function require_super_admin(): void
{
    require_login();

    if (!is_super_admin()) {
        http_response_code(403);
        exit('403 Forbidden - Hanya Super Admin yang bisa mengakses halaman ini.');
    }
}

function require_permission(mysqli $koneksi, string $permission): void
{
    require_login();
    refresh_permissions($koneksi);

    if (!can($permission)) {
        http_response_code(403);
        exit('403 Forbidden - Anda tidak memiliki permission untuk mengakses fitur ini.');
    }
}

function find_user_by_login(mysqli $koneksi, string $login): ?array
{
    $sql = "SELECT id, username, email, password, role
            FROM users
            WHERE username = ? OR email = ?
            LIMIT 1";

    $stmt = mysqli_prepare($koneksi, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $login, $login);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    return mysqli_fetch_assoc($result) ?: null;
}

function verify_password_and_upgrade(mysqli $koneksi, array $user, string $password): bool
{
    $storedPassword = (string) $user['password'];
    $passwordInfo = password_get_info($storedPassword);

    // Password sudah hash modern.
    if (!empty($passwordInfo['algo'])) {
        return password_verify($password, $storedPassword);
    }

    // Kompatibilitas jika password lama masih plain text.
    if (hash_equals($storedPassword, $password)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $id = (int) $user['id'];
        $stmt = mysqli_prepare($koneksi, "UPDATE users SET password = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $newHash, $id);
        mysqli_stmt_execute($stmt);
        return true;
    }

    return false;
}

function login_user(mysqli $koneksi, array $user): void
{
    $_SESSION['auth'] = [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => normalize_role($user['role']),
        'permissions' => []
    ];

    refresh_permissions($koneksi);
}
