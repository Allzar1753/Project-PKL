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

        if (!$columnExists('users', 'employment_status')) {
            mysqli_query($koneksi, "ALTER TABLE users ADD COLUMN employment_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER id_branch");
        }

        if (!$indexExists('users', 'idx_users_employment_status')) {
            mysqli_query($koneksi, "ALTER TABLE users ADD INDEX idx_users_employment_status (employment_status)");
        }

        if (!$columnExists('barang', 'tanggal_pembelian')) {
            mysqli_query($koneksi, "ALTER TABLE barang ADD COLUMN tanggal_pembelian DATE NULL AFTER tanggal_terima");
        }
        if (!$columnExists('barang', 'masa_garansi_bulan')) {
            mysqli_query($koneksi, "ALTER TABLE barang ADD COLUMN masa_garansi_bulan INT NULL DEFAULT 12 AFTER tanggal_pembelian");
        }
        if (!$columnExists('barang', 'tanggal_garansi_berakhir')) {
            mysqli_query($koneksi, "ALTER TABLE barang ADD COLUMN tanggal_garansi_berakhir DATE NULL AFTER masa_garansi_bulan");
        }
        if (!$indexExists('barang', 'idx_barang_garansi_berakhir')) {
            mysqli_query($koneksi, "ALTER TABLE barang ADD INDEX idx_barang_garansi_berakhir (tanggal_garansi_berakhir)");
        }

        if (!$columnExists('barang', 'kode_aset')) {
            mysqli_query($koneksi, "ALTER TABLE barang ADD COLUMN kode_aset VARCHAR(25) NULL AFTER no_asset");
        }
        if (!$indexExists('barang', 'uq_barang_kode_aset')) {
            mysqli_query($koneksi, "ALTER TABLE barang ADD UNIQUE KEY uq_barang_kode_aset (kode_aset)");
        }
        if (!$indexExists('barang', 'idx_barang_kode_aset')) {
            mysqli_query($koneksi, "ALTER TABLE barang ADD INDEX idx_barang_kode_aset (kode_aset)");
        }

        require_once __DIR__ . '/asset_code_helper.php';
        backfill_kode_aset($koneksi);

        mysqli_query($koneksi, "
            UPDATE barang
            SET tanggal_pembelian = tanggal_terima,
                masa_garansi_bulan = COALESCE(masa_garansi_bulan, 12),
                tanggal_garansi_berakhir = DATE_ADD(tanggal_terima, INTERVAL COALESCE(masa_garansi_bulan, 12) MONTH)
            WHERE tanggal_pembelian IS NULL
              AND tanggal_terima IS NOT NULL
              AND tanggal_garansi_berakhir IS NULL
        ");

        if (!$columnExists('system_notifications', 'notification_type')) {
            mysqli_query($koneksi, "ALTER TABLE system_notifications ADD COLUMN notification_type VARCHAR(32) NULL DEFAULT 'general' AFTER link");
        }
        if (!$columnExists('system_notifications', 'reference_key')) {
            mysqli_query($koneksi, "ALTER TABLE system_notifications ADD COLUMN reference_key VARCHAR(150) NULL AFTER notification_type");
        }
        if (!$indexExists('system_notifications', 'uq_system_notifications_reference_key')) {
            mysqli_query($koneksi, "ALTER TABLE system_notifications ADD UNIQUE KEY uq_system_notifications_reference_key (reference_key)");
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

        if (!$columnExists('pengiriman_cabang_ho', 'catatan_user')) {
            mysqli_query($koneksi, "ALTER TABLE pengiriman_cabang_ho ADD COLUMN catatan_user VARCHAR(255) NULL AFTER status_pengiriman");
        }
        if (!$columnExists('pengiriman_cabang_ho', 'catatan_admin')) {
            mysqli_query($koneksi, "ALTER TABLE pengiriman_cabang_ho ADD COLUMN catatan_admin VARCHAR(255) NULL AFTER catatan_user");
        }

        mysqli_query($koneksi, "CREATE TABLE IF NOT EXISTS tb_ekspedisi (
            id_ekspedisi INT AUTO_INCREMENT PRIMARY KEY,
            nama_ekspedisi VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tb_ekspedisi_nama (nama_ekspedisi)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (mysqli_num_rows(mysqli_query($koneksi, "SELECT 1 FROM tb_ekspedisi LIMIT 1")) === 0) {
            mysqli_query($koneksi, "INSERT IGNORE INTO tb_ekspedisi (nama_ekspedisi) VALUES
                ('SAP Express'),
                ('PCP Express')");
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

        mysqli_query($koneksi, "CREATE TABLE IF NOT EXISTS password_reset_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            alasan TEXT NULL,
            status ENUM('pending','selesai') NOT NULL DEFAULT 'pending',
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_by INT NULL,
            processed_at DATETIME NULL,
            INDEX idx_password_reset_requests_user_id (user_id),
            INDEX idx_password_reset_requests_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!$columnExists('pengiriman_cabang_ho', 'jenis_pengiriman')) {
            mysqli_query($koneksi, "ALTER TABLE pengiriman_cabang_ho ADD COLUMN jenis_pengiriman VARCHAR(20) NOT NULL DEFAULT 'ke_ho' AFTER branch_tujuan");
        }
        if (!$columnExists('pengiriman_cabang_ho', 'foto_barang_diterima_ho')) {
            mysqli_query($koneksi, "ALTER TABLE pengiriman_cabang_ho ADD COLUMN foto_barang_diterima_ho VARCHAR(255) NULL AFTER foto_resi_keluar");
        }
    }
}

ensure_system_schema($koneksi);
?>