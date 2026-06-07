<?php

/**
 * Activity Logger Helper
 * Fungsi untuk mencatat semua aktivitas user termasuk CRUD operations
 */

if (!function_exists('log_activity')) {
    /**
     * Log user activity ke database
     *
     * @param mysqli $koneksi
     * @param string $action Jenis aksi (create_barang, update_barang, delete_barang, send_barang, receive_barang, etc)
     * @param string $description Deskripsi detail aktivitas
     * @param array $details Array tambahan berisi detail (id_barang, id_pengiriman, etc)
     * @return bool
     */
    function log_activity(mysqli $koneksi, string $action, string $description, array $details = []): bool
    {
        // Dapatkan info user saat ini
        $user_id = $_SESSION['user']['id'] ?? null;
        $role = $_SESSION['user']['role'] ?? 'unknown';
        $branch_id = $_SESSION['user']['id_branch'] ?? null;

        // Jika user_id tidak ada, skip logging
        if (!$user_id) {
            return false;
        }

        $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $path = $_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'] ?? '';

        // Tambahkan detail ke description jika ada
        if (!empty($details)) {
            $details_json = json_encode($details, JSON_UNESCAPED_UNICODE);
            $description = $description . ' | Details: ' . $details_json;
        }

        $query = "
            INSERT INTO user_activity_logs 
            (user_id, role, branch_id, action, description, path, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";

        $stmt = mysqli_prepare($koneksi, $query);

        if (!$stmt) {
            error_log('Prepare failed: ' . mysqli_error($koneksi));
            return false;
        }

        $result = mysqli_stmt_bind_param(
            $stmt,
            'isisssss',
            $user_id,
            $role,
            $branch_id,
            $action,
            $description,
            $path,
            $ip_address,
            $user_agent
        );

        if (!$result) {
            error_log('Bind param failed: ' . mysqli_error($koneksi));
            mysqli_stmt_close($stmt);
            return false;
        }

        $execute_result = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return $execute_result;
    }
}

if (!function_exists('get_user_name')) {
    /**
     * Dapatkan nama user dari user_id
     */
    function get_user_name(mysqli $koneksi, int $user_id): string
    {
        $query = "SELECT username FROM users WHERE id = ? LIMIT 1";
        $stmt = mysqli_prepare($koneksi, $query);

        if (!$stmt) {
            return 'Unknown User';
        }

        mysqli_stmt_bind_param($stmt, 'i', $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        return $row['username'] ?? 'Unknown User';
    }
}

if (!function_exists('get_branch_name')) {
    /**
     * Dapatkan nama branch dari branch_id
     */
    function get_branch_name(mysqli $koneksi, ?int $branch_id): string
    {
        if (!$branch_id || $branch_id === 0) {
            return 'Admin HO';
        }

        $query = "SELECT nama_branch FROM tb_branch WHERE id_branch = ? LIMIT 1";
        $stmt = mysqli_prepare($koneksi, $query);

        if (!$stmt) {
            return 'Unknown Branch';
        }

        mysqli_stmt_bind_param($stmt, 'i', $branch_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        return $row['nama_branch'] ?? 'Unknown Branch';
    }
}

if (!function_exists('get_activity_categories')) {
    function get_activity_categories(): array
    {
        return [
            'all' => 'Semua Aktivitas',
            'barang' => 'CRUD Barang',
            'pengiriman' => 'Pengiriman',
            'penerimaan' => 'Penerimaan',
            'user' => 'Kelola User',
        ];
    }
}

if (!function_exists('get_activity_actions_by_category')) {
    function get_activity_actions_by_category(string $category): ?array
    {
        $map = [
            'barang' => ['create_barang', 'update_barang', 'delete_barang'],
            'pengiriman' => ['send_barang', 'send_pengiriman_cabang'],
            'penerimaan' => ['receive_barang', 'receive_barang_ho', 'approve_pengiriman_ho'],
            'user' => ['create_user', 'update_user', 'delete_user'],
            'create_barang' => ['create_barang'],
            'update_barang' => ['update_barang'],
            'delete_barang' => ['delete_barang'],
            'send_barang' => ['send_barang'],
            'send_pengiriman_cabang' => ['send_pengiriman_cabang'],
            'receive_barang' => ['receive_barang'],
            'approve_pengiriman_ho' => ['approve_pengiriman_ho'],
        ];

        return $map[$category] ?? null;
    }
}

if (!function_exists('get_activity_label')) {
    function get_activity_label(string $action): string
    {
        $labels = [
            'create_barang' => 'Tambah Barang',
            'update_barang' => 'Edit Barang',
            'delete_barang' => 'Hapus Barang',
            'send_barang' => 'Kirim Barang (HO → Cabang)',
            'send_pengiriman_cabang' => 'Ajukan Pengiriman (Cabang → HO)',
            'receive_barang' => 'Terima Barang (Cabang)',
            'receive_barang_ho' => 'Terima Barang (HO)',
            'approve_pengiriman_ho' => 'Setujui Pengiriman Cabang',
            'create_user' => 'Tambah User',
            'update_user' => 'Edit User',
            'delete_user' => 'Hapus User',
        ];

        return $labels[$action] ?? ucwords(str_replace('_', ' ', $action));
    }
}

if (!function_exists('get_activity_badge_type')) {
    function get_activity_badge_type(string $action): string
    {
        if (str_contains($action, 'create')) {
            return 'create';
        }
        if (str_contains($action, 'delete')) {
            return 'delete';
        }
        if (str_contains($action, 'update')) {
            return 'update';
        }
        if (str_contains($action, 'send') || str_contains($action, 'pengiriman')) {
            return 'send';
        }
        if (str_contains($action, 'receive') || str_contains($action, 'approve')) {
            return 'receive';
        }

        return 'update';
    }
}

if (!function_exists('parse_activity_description')) {
    function parse_activity_description(string $description): array
    {
        $main = $description;
        $details = [];

        if (str_contains($description, ' | Details: ')) {
            [$main, $jsonPart] = explode(' | Details: ', $description, 2);
            $decoded = json_decode($jsonPart, true);
            if (is_array($decoded)) {
                $details = $decoded;
            }
        }

        return ['text' => trim($main), 'details' => $details];
    }
}

if (!function_exists('format_presence_datetime')) {
    function format_presence_datetime(?string $datetime): string
    {
        if (!$datetime) {
            return 'Belum pernah login';
        }

        return date('d M Y H:i', strtotime($datetime)) . ' WIB';
    }
}

if (!function_exists('get_user_presence_snapshot')) {
    /**
     * Snapshot status online/offline user cabang untuk monitoring admin.
     */
    function get_user_presence_snapshot(mysqli $koneksi, int $onlineThresholdMinutes = 5): array
    {
        $query = "
            SELECT u.id, u.username, u.email, u.id_branch,
                   tb.nama_branch, p.last_seen_at, p.ip_address,
                   COALESCE(u.employment_status, 'active') AS employment_status
            FROM users u
            LEFT JOIN tb_branch tb ON tb.id_branch = u.id_branch
            LEFT JOIN user_presence p ON p.user_id = u.id
            WHERE LOWER(u.role) = 'user'
            ORDER BY FIELD(COALESCE(u.employment_status, 'active'), 'active', 'inactive'), tb.nama_branch ASC, u.username ASC
        ";

        $result = mysqli_query($koneksi, $query);
        $usersData = [];
        $onlineUsers = 0;
        $inactiveUsers = 0;
        $threshold = time() - ($onlineThresholdMinutes * 60);

        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $lastSeen = $row['last_seen_at'] ?? null;
                $employmentStatus = normalize_employment_status($row['employment_status'] ?? 'active');
                $isEmployed = is_employment_active($employmentStatus);
                $isOnline = $isEmployed && $lastSeen && strtotime($lastSeen) >= $threshold;

                if (!$isEmployed) {
                    $inactiveUsers++;
                } elseif ($isOnline) {
                    $onlineUsers++;
                }

                $usersData[] = [
                    'id' => (int)$row['id'],
                    'username' => $row['username'],
                    'email' => $row['email'] ?? '',
                    'nama_branch' => $row['nama_branch'] ?? '-',
                    'employment_status' => $employmentStatus,
                    'employment_label' => employment_status_label($employmentStatus),
                    'is_employed' => $isEmployed,
                    'is_online' => $isOnline,
                    'last_seen_at' => $lastSeen,
                    'last_seen_formatted' => format_presence_datetime($lastSeen),
                    'ip_address' => $row['ip_address'] ?? '-',
                ];
            }
        }

        $totalUsers = count($usersData);

        return [
            'onlineThresholdMinutes' => $onlineThresholdMinutes,
            'totalUsers' => $totalUsers,
            'onlineUsers' => $onlineUsers,
            'offlineUsers' => max(0, $totalUsers - $onlineUsers - $inactiveUsers),
            'inactiveUsers' => $inactiveUsers,
            'usersData' => $usersData,
        ];
    }
}

if (!function_exists('get_barang_info')) {
    /**
     * Dapatkan info barang dari id_barang
     */
    function get_barang_info(mysqli $koneksi, int $barang_id): ?array
    {
        $query = "
            SELECT b.id, b.no_asset, b.serial_number, tb.nama_barang, b.status
            FROM barang b
            LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang
            WHERE b.id = ? LIMIT 1
        ";
        $stmt = mysqli_prepare($koneksi, $query);

        if (!$stmt) {
            return null;
        }

        mysqli_stmt_bind_param($stmt, 'i', $barang_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        return $row;
    }
}
