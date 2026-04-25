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
        return h($value);
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

if (!function_exists('current_user_id')) {
    function current_user_id(): ?int
    {
        return isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;
    }
}

if (!function_exists('current_user_branch_id')) {
    function current_user_branch_id(): ?int
    {
        return isset($_SESSION['user']['id_branch']) ? (int) $_SESSION['user']['id_branch'] : null;
    }
}

if (!function_exists('current_role')) {
    function current_role(): ?string
    {
        return $_SESSION['user']['role'] ?? null;
    }
}

if (!function_exists('current_permissions')) {
    function current_permissions(): array
    {
        return $_SESSION['user']['permissions'] ?? [];
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return current_role() === 'admin';
    }
}

if (!function_exists('is_user_role')) {
    function is_user_role(): bool
    {
        return current_role() === 'user';
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

if (!function_exists('forbidden_response')) {
    function forbidden_response(string $message = 'Anda tidak memiliki izin untuk mengakses halaman ini.'): void
    {
        http_response_code(403);
        echo "<h1>403 Forbidden</h1>";
        echo "<p>" . h($message) . "</p>";
        exit;
    }
}

if (!function_exists('require_admin')) {
    function require_admin(): void
    {
        require_login();

        if (!is_admin()) {
            forbidden_response('Halaman ini hanya untuk Administrator.');
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
                (bool) $params['secure'],
                (bool) $params['httponly']
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
        if (!$stmt) {
            return null;
        }

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

        if ($storedPassword === '') {
            return false;
        }

        $info = password_get_info($storedPassword);

        if (!empty($info['algo'])) {
            return password_verify($password, $storedPassword);
        }

        if (hash_equals($storedPassword, $password)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $userId = (int) ($user['id'] ?? 0);

            if ($userId > 0) {
                $stmt = mysqli_prepare($koneksi, "UPDATE users SET password = ? WHERE id = ?");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'si', $newHash, $userId);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
            }

            return true;
        }

        return false;
    }
}

if (!function_exists('login_user')) {
    function login_user(mysqli $koneksi, array $user): void
    {
        $_SESSION['user'] = [
            'id'          => (int) ($user['id'] ?? 0),
            'username'    => (string) ($user['username'] ?? ''),
            'email'       => (string) ($user['email'] ?? ''),
            'role'        => (string) ($user['role'] ?? 'user'),
            'id_branch'   => isset($user['id_branch']) ? (int) $user['id_branch'] : null,
            'permissions' => [],
            'must_change_password' => (int) ($user['must_change_password'] ?? 0),
        ];

        refresh_permissions($koneksi);
    }
}

if (!function_exists('check_must_change_password')) {
    function check_must_change_password(): void 
    {
        if (is_logged_in()) return;

        $currentPage = basename($_SERVER['PHP_SELF']);
        if ($currentPage === 'force_change_password.php') return;

        if (!empty($_SESSION['user']['must_change_password'])) {
            redirect_to(base_url('dashboard/index.php'));
        }
    }
}



if (!function_exists('needs_password_change')) {
    function needs_password_change(array $user): bool
    {
        return !empty($user['must_change_password']);
    }
}

if (!function_exists('get_all_permissions')) {
    function get_all_permissions(mysqli $koneksi): array
    {
        $permissions = [];
        $result = mysqli_query($koneksi, "SELECT permission_key FROM rbac_permissions");

        if (!$result) {
            return [];
        }

        while ($row = mysqli_fetch_assoc($result)) {
            $permissions[] = (string) $row['permission_key'];
        }

        return array_values(array_unique($permissions));
    }
}

if (!function_exists('get_user_permissions')) {
    function get_user_permissions(mysqli $koneksi, int $userId, string $role): array
    {
        if ($role === 'admin') {
            return ['*'];
        }

        $permissions = [];

        $sql = "
            SELECT p.id, p.permission_key
            FROM rbac_role_permissions rp
            INNER JOIN rbac_permissions p ON rp.permission_id = p.id
            WHERE rp.role = ?
        ";

        $stmt = mysqli_prepare($koneksi, $sql);
        if (!$stmt) {
            return [];
        }

        mysqli_stmt_bind_param($stmt, 's', $role);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $permissions[(int) $row['id']] = (string) $row['permission_key'];
        }

        mysqli_stmt_close($stmt);

        return array_values(array_unique($permissions));
    }
}

if (!function_exists('refresh_permissions')) {
    function refresh_permissions(mysqli $koneksi): void
    {
        if (!is_logged_in()) {
            return;
        }

        $userId = current_user_id();
        $role   = current_role() ?? 'user';

        if (!$userId) {
            $_SESSION['user']['permissions'] = [];
            return;
        }

        $_SESSION['user']['permissions'] = get_user_permissions($koneksi, $userId, $role);
    }
}

if (!function_exists('can')) {
    function can(string $permission): bool
    {
        if (!is_logged_in()) {
            return false;
        }

        $permissions = current_permissions();

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
        check_must_change_password();

        // Update online presence (best-effort, non-blocking)
        if (function_exists('update_user_presence')) {
            update_user_presence($koneksi);
        }

        if (empty($_SESSION['user']['permissions'])) {
            refresh_permissions($koneksi);
        }

        if (!can($permission)) {
            forbidden_response('Anda tidak memiliki izin untuk mengakses halaman ini.');
        }
    }
}

if (!function_exists('get_client_ip')) {
    function get_client_ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $raw = (string) $_SERVER[$key];
                if ($key === 'HTTP_X_FORWARDED_FOR') {
                    $parts = array_map('trim', explode(',', $raw));
                    return $parts[0] ?? '';
                }
                return $raw;
            }
        }
        return '';
    }
}

if (!function_exists('update_user_presence')) {
    function update_user_presence(mysqli $koneksi): void
    {
        if (!is_logged_in()) {
            return;
        }

        $userId = current_user_id();
        if (!$userId) {
            return;
        }

        $sessionId = (string) session_id();
        $ip = get_client_ip();
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
        $now = date('Y-m-d H:i:s');

        $stmt = mysqli_prepare(
            $koneksi,
            "INSERT INTO user_presence (user_id, session_id, ip_address, user_agent, last_seen_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                session_id = VALUES(session_id),
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent),
                last_seen_at = VALUES(last_seen_at)"
        );
        if (!$stmt) {
            return;
        }

        mysqli_stmt_bind_param($stmt, 'issss', $userId, $sessionId, $ip, $ua, $now);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('log_user_activity')) {
    function log_user_activity(mysqli $koneksi, string $action, ?string $description): void
    {
        if (!is_logged_in()) {
            return;
        }

        $userId = current_user_id();
        $role = current_role();
        $branchId = current_user_branch_id();
        $path = substr((string) ($_SERVER['PHP_SELF'] ?? ''), 0, 250);
        $ip = get_client_ip();
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);

        $stmt = mysqli_prepare(
            $koneksi,
            "INSERT INTO user_activity_logs (user_id, role, branch_id, action, description, path, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return;
        }

        $desc = $description !== null ? substr($description, 0, 250) : null;
        mysqli_stmt_bind_param($stmt, 'isisssss', $userId, $role, $branchId, $action, $desc, $path, $ip, $ua);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

if (!function_exists('user_can_access_branch')) {
    function user_can_access_branch(?int $targetBranchId): bool
    {
        if (!is_logged_in()) {
            return false;
        }

        if (is_admin()) {
            return true;
        }
        $myBranchId = current_user_branch_id();

        if ($myBranchId === null || $targetBranchId === null) {
            return false;
        }

        return $myBranchId === $targetBranchId;
    }
}

if (!function_exists('enforce_branch_access')) {
    function enforce_branch_access(?int $targetBranchId): void
    {
        require_login();

        if (!user_can_access_branch($targetBranchId)) {
            forbidden_response('Anda tidak boleh mengakses data cabang lain.');
        }
    }
}

if (!function_exists('resolve_input_branch_id')) {
    function resolve_input_branch_id(?int $postedBranchId = null): ?int
    {
        if (is_user_role()) {
            return current_user_branch_id();
        }

        if ($postedBranchId === null || $postedBranchId <= 0) {
            return null;
        }

        return $postedBranchId;
    }
}

if (!function_exists('create_password_reset_token')) {
    function create_password_reset_token(mysqli $koneksi, int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        $stmtDelete = mysqli_prepare(
            $koneksi,
            "DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL"
        );

        if ($stmtDelete) {
            mysqli_stmt_bind_param($stmtDelete, 'i', $userId);
            mysqli_stmt_execute($stmtDelete);
            mysqli_stmt_close($stmtDelete);
        }

        $stmt = mysqli_prepare(
            $koneksi,
            "INSERT INTO password_resets (user_id, reset_token, expires_at) VALUES (?, ?, ?)"
        );

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'iss', $userId, $token, $expiresAt);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

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

        if (!$stmt) {
            return null;
        }

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