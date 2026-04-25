<?php

date_default_timezone_set('Asia/Jakarta');

$host="localhost";
$username="root";
$password="";
$database="it_hexindo";

$koneksi=mysqli_connect($host,$username,$password,$database);
if (!$koneksi) {
    die("Koneksi Gagal: " . mysqli_connect_error());
}

if (!function_exists('ensure_system_schema')) {
    function ensure_system_schema(mysqli $koneksi): void
    {
        $databaseName = '';
        $dbResult = mysqli_query($koneksi, "SELECT DATABASE() AS db_name");
        if ($dbResult) {
            $dbRow = mysqli_fetch_assoc($dbResult);
            $databaseName = (string) ($dbRow['db_name'] ?? '');
            mysqli_free_result($dbResult);
        }

        if ($databaseName === '') {
            return;
        }

        $safeDb = mysqli_real_escape_string($koneksi, $databaseName);

        $columnExists = function (string $table, string $column) use ($koneksi, $safeDb): bool {
            $safeTable = mysqli_real_escape_string($koneksi, $table);
            $safeColumn = mysqli_real_escape_string($koneksi, $column);
            $sql = "
                SELECT 1
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = '{$safeDb}'
                  AND TABLE_NAME = '{$safeTable}'
                  AND COLUMN_NAME = '{$safeColumn}'
                LIMIT 1
            ";
            $result = mysqli_query($koneksi, $sql);
            if (!$result) {
                return false;
            }
            $exists = (bool) mysqli_fetch_assoc($result);
            mysqli_free_result($result);
            return $exists;
        };

        $indexExists = function (string $table, string $index) use ($koneksi, $safeDb): bool {
            $safeTable = mysqli_real_escape_string($koneksi, $table);
            $safeIndex = mysqli_real_escape_string($koneksi, $index);
            $sql = "
                SELECT 1
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = '{$safeDb}'
                  AND TABLE_NAME = '{$safeTable}'
                  AND INDEX_NAME = '{$safeIndex}'
                LIMIT 1
            ";
            $result = mysqli_query($koneksi, $sql);
            if (!$result) {
                return false;
            }
            $exists = (bool) mysqli_fetch_assoc($result);
            mysqli_free_result($result);
            return $exists;
        };

        if (!$columnExists('users', 'must_change_password')) {
            mysqli_query($koneksi, "ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
        }

        if (!$columnExists('users', 'id_branch')) {
            mysqli_query($koneksi, "ALTER TABLE users ADD COLUMN id_branch INT NULL");
        }

        if (!$indexExists('users', 'idx_users_id_branch')) {
            mysqli_query($koneksi, "ALTER TABLE users ADD INDEX idx_users_id_branch (id_branch)");
        }

        // =========================
        // Activity log + online presence
        // =========================
        mysqli_query($koneksi, "CREATE TABLE IF NOT EXISTS user_presence (
            user_id INT NOT NULL PRIMARY KEY,
            session_id VARCHAR(128) NOT NULL,
            ip_address VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            last_seen_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_presence_last_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($koneksi, "CREATE TABLE IF NOT EXISTS user_activity_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            role VARCHAR(32) NULL,
            branch_id INT NULL,
            action VARCHAR(64) NOT NULL,
            description VARCHAR(255) NULL,
            path VARCHAR(255) NULL,
            ip_address VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_activity_logs_user_id (user_id),
            INDEX idx_user_activity_logs_created_at (created_at),
            INDEX idx_user_activity_logs_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // =========================
        // Notifications (admin inbox)
        // =========================
        mysqli_query($koneksi, "CREATE TABLE IF NOT EXISTS system_notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            target_role VARCHAR(32) NOT NULL,
            target_user_id INT NULL,
            target_branch_id INT NULL,
            title VARCHAR(120) NOT NULL,
            message VARCHAR(255) NOT NULL,
            link VARCHAR(255) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_system_notifications_target_role (target_role),
            INDEX idx_system_notifications_target_user_id (target_user_id),
            INDEX idx_system_notifications_target_branch_id (target_branch_id),
            INDEX idx_system_notifications_is_read (is_read),
            INDEX idx_system_notifications_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!$columnExists('system_notifications', 'target_user_id')) {
            mysqli_query($koneksi, "ALTER TABLE system_notifications ADD COLUMN target_user_id INT NULL AFTER target_role");
        }
        if (!$columnExists('system_notifications', 'target_branch_id')) {
            mysqli_query($koneksi, "ALTER TABLE system_notifications ADD COLUMN target_branch_id INT NULL AFTER target_user_id");
        }
        if (!$indexExists('system_notifications', 'idx_system_notifications_target_user_id')) {
            mysqli_query($koneksi, "ALTER TABLE system_notifications ADD INDEX idx_system_notifications_target_user_id (target_user_id)");
        }
        if (!$indexExists('system_notifications', 'idx_system_notifications_target_branch_id')) {
            mysqli_query($koneksi, "ALTER TABLE system_notifications ADD INDEX idx_system_notifications_target_branch_id (target_branch_id)");
        }

        // =========================
        // Shipping approvals (cabang -> HO Jakarta)
        // =========================
        mysqli_query($koneksi, "CREATE TABLE IF NOT EXISTS pengiriman_cabang_ho (
            id_pengiriman_ho INT AUTO_INCREMENT PRIMARY KEY,
            id_barang INT NOT NULL,
            branch_asal INT NOT NULL,
            branch_tujuan INT NOT NULL,
            tanggal_pengajuan DATE NOT NULL,
            jasa_pengiriman VARCHAR(50) NOT NULL,
            nomor_resi_keluar VARCHAR(100) NOT NULL,
            foto_resi_keluar VARCHAR(255) NULL,
            status_pengiriman VARCHAR(50) NOT NULL DEFAULT 'Menunggu persetujuan admin',
            catatan_user VARCHAR(255) NULL,
            catatan_admin VARCHAR(255) NULL,
            dibuat_oleh INT NULL,
            disetujui_oleh INT NULL,
            disetujui_pada DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_pengiriman_cabang_ho_status (status_pengiriman),
            INDEX idx_pengiriman_cabang_ho_barang (id_barang),
            INDEX idx_pengiriman_cabang_ho_asal (branch_asal),
            INDEX idx_pengiriman_cabang_ho_tujuan (branch_tujuan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($koneksi, "CREATE TABLE IF NOT EXISTS password_resets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            reset_token VARCHAR(128) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_resets_user_id (user_id),
            UNIQUE KEY uq_password_resets_reset_token (reset_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

ensure_system_schema($koneksi);
?>