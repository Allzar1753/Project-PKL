<?php

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