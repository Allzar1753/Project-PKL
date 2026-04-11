<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('BASE_URL')) {
    define('BASE_URL', '/PROJECT-PKL');
}

if (!function_exists('base_url')) {
    function base_url(string $path = ''): string
    {
        return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('h')) {
    function h($value): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('e')) {
    function e($value): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('redirect_to')) {
    function redirect_to(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }
}

if (!function_exists('set_flash')) {
    function set_flash(string $key, string $message): void
    {
        $_SESSION['flash'][$key] = $message;
    }
}

if (!function_exists('get_flash')) {
    function get_flash(string $key): ?string
    {
        if (!isset($_SESSION['flash'][$key])) {
            return null;
        }

        $message = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);

        if (empty($_SESSION['flash'])) {
            unset($_SESSION['flash']);
        }

        return $message;
    }
}

if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool
    {
        return !empty($_SESSION['user']);
    }
}

if (!function_exists('current_user')) {
    function current_user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }
}

if (!function_exists('current_user_branch_id')) {
    function current_user_branch_id(): ?int
    {
        return isset($_SESSION['user']['id_branch']) ? (int) $_SESSION['user']['id_branch'] : null;
    }
}

if (!function_exists('current_user_id')) {
    function current_user_id(): ?int
    {
        return isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;
    }
}

if (!function_exists('current_role')) {
    function current_role(): ?string
    {
        return $_SESSION['user']['role'] ?? null;
    }
}

if (!function_exists('is_super_admin')) {
    function is_super_admin(): bool
    {
        return current_role() === 'super_admin';
    }
}

if (!function_exists('require_login')) {
    function require_login(): void
    {
        if (!is_logged_in()) {
            set_flash('error', 'Silakan login terlebih dahulu.');
            redirect_to(base_url('auth/login.php'));
        }
    }
}

if (!function_exists('logout_user')) {
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
}

if (!function_exists('find_user_by_login')) {
    function find_user_by_login(mysqli $koneksi, string $login): ?array
    {
        $sql = "
            SELECT id, username, email, password, role, id_branch, must_change_password
            FROM users
            WHERE username = ? OR email = ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare($koneksi, $sql);
        mysqli_stmt_bind_param($stmt, 'ss', $login, $login);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result) ?: null;

        mysqli_stmt_close($stmt);

        return $user;
    }
}

if (!function_exists('verify_password_and_upgrade')) {
    function verify_password_and_upgrade(mysqli $koneksi, array $user, string $password): bool
    {
        $storedPassword = (string) ($user['password'] ?? '');

        $info = password_get_info($storedPassword);
        if (!empty($info['algo'])) {
            return password_verify($password, $storedPassword);
        }

        if (hash_equals($storedPassword, $password)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $id = (int) $user['id'];

            $stmt = mysqli_prepare($koneksi, "UPDATE users SET password = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'si', $newHash, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            return true;
        }

        return false;
    }
}

if (!function_exists('get_all_permissions')) {
    function get_all_permissions(mysqli $koneksi): array
    {
        $permissions = [];
        $result = mysqli_query($koneksi, "SELECT permission_key FROM rbac_permissions");

        while ($row = mysqli_fetch_assoc($result)) {
            $permissions[] = $row['permission_key'];
        }

        return array_values(array_unique($permissions));
    }
}

if (!function_exists('get_user_permissions')) {
    function get_user_permissions(mysqli $koneksi, int $userId, string $role): array
    {
        if ($role === 'super_admin') {
            return ['*'];
        }

        $finalPermissions = [];

        // 1. Permission bawaan dari role
        $sqlRole = "
            SELECT p.id, p.permission_key
            FROM rbac_role_permissions rp
            INNER JOIN rbac_permissions p ON rp.permission_id = p.id
            WHERE rp.role = ?
        ";
        $stmtRole = mysqli_prepare($koneksi, $sqlRole);
        mysqli_stmt_bind_param($stmtRole, 's', $role);
        mysqli_stmt_execute($stmtRole);

        $resultRole = mysqli_stmt_get_result($stmtRole);
        while ($row = mysqli_fetch_assoc($resultRole)) {
            $finalPermissions[(int)$row['id']] = $row['permission_key'];
        }
        mysqli_stmt_close($stmtRole);

        // 2. Tambahan permission khusus user
        $sqlAllow = "
            SELECT p.id, p.permission_key
            FROM rbac_user_permissions up
            INNER JOIN rbac_permissions p ON up.permission_id = p.id
            WHERE up.user_id = ?
        ";
        $stmtAllow = mysqli_prepare($koneksi, $sqlAllow);
        mysqli_stmt_bind_param($stmtAllow, 'i', $userId);
        mysqli_stmt_execute($stmtAllow);

        $resultAllow = mysqli_stmt_get_result($stmtAllow);
        while ($row = mysqli_fetch_assoc($resultAllow)) {
            $finalPermissions[(int)$row['id']] = $row['permission_key'];
        }
        mysqli_stmt_close($stmtAllow);

        // 3. Permission yang dicabut khusus user
        $sqlDeny = "
            SELECT permission_id
            FROM rbac_user_denied_permissions
            WHERE user_id = ?
        ";
        $stmtDeny = mysqli_prepare($koneksi, $sqlDeny);
        mysqli_stmt_bind_param($stmtDeny, 'i', $userId);
        mysqli_stmt_execute($stmtDeny);

        $resultDeny = mysqli_stmt_get_result($stmtDeny);
        while ($row = mysqli_fetch_assoc($resultDeny)) {
            $permissionId = (int)$row['permission_id'];
            unset($finalPermissions[$permissionId]);
        }
        mysqli_stmt_close($stmtDeny);

        return array_values(array_unique($finalPermissions));
    }
}

if (!function_exists('refresh_permissions')) {
    function refresh_permissions(mysqli $koneksi): void
    {
        if (!is_logged_in()) {
            return;
        }

        $userId = current_user_id();
        $role = current_role() ?? '';

        if (!$userId) {
            $_SESSION['user']['permissions'] = [];
            return;
        }

        $_SESSION['user']['permissions'] = get_user_permissions($koneksi, $userId, $role);
    }
}

if (!function_exists('login_user')) {
    function login_user(mysqli $koneksi, array $user): void
    {
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'id_branch' => isset($user['id_branch']) ? (int) $user['id_branch'] : null,
            'permissions' => []
        ];

        refresh_permissions($koneksi);
    }
}

if (!function_exists('needs_password_change')) {
    function needs_password_change(array $user): bool
    {
        return !empty($user['must_change_password']);
    }
}

if (!function_exists('create_password_reset_token')) {
    function create_password_reset_token(mysqli $koneksi, int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        $stmtDelete = mysqli_prepare($koneksi, "DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL");
        mysqli_stmt_bind_param($stmtDelete, 'i', $userId);
        mysqli_stmt_execute($stmtDelete);
        mysqli_stmt_close($stmtDelete);

        $stmt = mysqli_prepare($koneksi, "INSERT INTO password_resets (user_id, reset_token, expires_at) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'iss', $userId, $token, $expiresAt);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return $token;
    }
}

if (!function_exists('find_valid_password_reset')) {
    function find_valid_password_reset(mysqli $koneksi, string $token): ?array
    {
        $stmt = mysqli_prepare(
            $koneksi,
            "SELECT pr.id, pr.user_id, pr.expires_at, u.username, u.email
             FROM password_resets pr
             INNER JOIN users u ON u.id = pr.user_id
             WHERE pr.reset_token = ? AND pr.used_at IS NULL
             LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, 's', $token);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result) ?: null;
        mysqli_stmt_close($stmt);

        if (!$row) {
            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        return $row;
    }
}

if (!function_exists('can')) {
    function can(string $permission): bool
    {
        if (!is_logged_in()) {
            return false;
        }

        $permissions = $_SESSION['user']['permissions'] ?? [];

        if (in_array('*', $permissions, true)) {
            return true;
        }

        return in_array($permission, $permissions, true);
    }
}

if (!function_exists('require_permission')) {
    function require_permission(mysqli $koneksi, string $permission): void
    {
        require_login();

        if (empty($_SESSION['user']['permissions'])) {
            refresh_permissions($koneksi);
        }

        if (!can($permission)) {
            http_response_code(403);
            echo "<h1>403 Forbidden</h1>";
            echo "<p>Anda tidak memiliki izin untuk mengakses halaman ini.</p>";
            exit;
        }
    }
}

if (!function_exists('require_super_admin')) {
    function require_super_admin(): void
    {
        require_login();

        if (!is_super_admin()) {
            http_response_code(403);
            echo "<h1>403 Forbidden</h1>";
            echo "<p>Halaman ini hanya untuk Super Admin.</p>";
            exit;
        }
    }
}