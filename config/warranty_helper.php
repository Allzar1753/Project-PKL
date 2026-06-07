<?php

if (!function_exists('compute_warranty_end_date')) {
    function compute_warranty_end_date(?string $purchaseDate, ?int $months): ?string
    {
        $purchaseDate = trim((string) $purchaseDate);
        $months = (int) $months;

        if ($purchaseDate === '' || $months <= 0) {
            return null;
        }

        $ts = strtotime($purchaseDate);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', strtotime('+' . $months . ' months', $ts));
    }
}

if (!function_exists('normalize_warranty_months')) {
    function normalize_warranty_months($months): int
    {
        $months = (int) $months;
        $allowed = [6, 12, 24, 36, 48, 60];

        if (in_array($months, $allowed, true)) {
            return $months;
        }

        return $months > 0 ? min($months, 120) : 12;
    }
}

if (!function_exists('get_warranty_meta')) {
    /**
     * @return array{status:string,label:string,class:string,icon:string,days_left:?int}
     */
    function get_warranty_meta(?string $endDate): array
    {
        if (!$endDate) {
            return [
                'status' => 'none',
                'label' => 'Belum diatur',
                'class' => 'badge-soft-secondary',
                'icon' => 'bi-shield',
                'days_left' => null,
            ];
        }

        $today = strtotime(date('Y-m-d'));
        $endTs = strtotime($endDate);
        if ($endTs === false) {
            return [
                'status' => 'none',
                'label' => 'Belum diatur',
                'class' => 'badge-soft-secondary',
                'icon' => 'bi-shield',
                'days_left' => null,
            ];
        }

        $daysLeft = (int) floor(($endTs - $today) / 86400);

        if ($daysLeft < 0) {
            return [
                'status' => 'expired',
                'label' => 'Garansi habis',
                'class' => 'badge-soft-danger',
                'icon' => 'bi-shield-x',
                'days_left' => $daysLeft,
            ];
        }

        if ($daysLeft <= 7) {
            return [
                'status' => 'critical',
                'label' => $daysLeft === 0 ? 'Garansi hari ini' : 'Garansi ' . $daysLeft . ' hari lagi',
                'class' => 'badge-soft-danger',
                'icon' => 'bi-shield-exclamation',
                'days_left' => $daysLeft,
            ];
        }

        if ($daysLeft <= 30) {
            return [
                'status' => 'warning',
                'label' => 'Garansi ' . $daysLeft . ' hari lagi',
                'class' => 'badge-soft-warning',
                'icon' => 'bi-shield-fill-exclamation',
                'days_left' => $daysLeft,
            ];
        }

        return [
            'status' => 'active',
            'label' => 'Garansi aktif',
            'class' => 'badge-soft-success',
            'icon' => 'bi-shield-check',
            'days_left' => $daysLeft,
        ];
    }
}

if (!function_exists('warranty_badge_html')) {
    function warranty_badge_html(?string $endDate): string
    {
        $meta = get_warranty_meta($endDate);
        $dateLabel = $endDate ? date('d M Y', strtotime($endDate)) : '—';

        return '<span class="badge rounded-pill ' . $meta['class'] . '" title="Berakhir: ' . htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') . '">'
            . '<i class="bi ' . $meta['icon'] . ' me-1"></i>' . htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

if (!function_exists('notification_exists_by_reference')) {
    function notification_exists_by_reference(mysqli $koneksi, string $referenceKey): bool
    {
        $stmt = mysqli_prepare($koneksi, "SELECT id FROM system_notifications WHERE reference_key = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, 's', $referenceKey);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        return (bool) $row;
    }
}

if (!function_exists('create_system_notification')) {
    function create_system_notification(
        mysqli $koneksi,
        string $targetRole,
        string $title,
        string $message,
        ?string $link,
        ?int $targetBranchId = null,
        ?int $targetUserId = null,
        string $notificationType = 'general',
        ?string $referenceKey = null
    ): bool {
        if ($referenceKey !== null && notification_exists_by_reference($koneksi, $referenceKey)) {
            return false;
        }

        $stmt = mysqli_prepare(
            $koneksi,
            "INSERT INTO system_notifications
             (target_role, target_user_id, target_branch_id, title, message, link, notification_type, reference_key, is_read)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)"
        );

        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param(
            $stmt,
            'siisssss',
            $targetRole,
            $targetUserId,
            $targetBranchId,
            $title,
            $message,
            $link,
            $notificationType,
            $referenceKey
        );

        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return $ok;
    }
}

if (!function_exists('sync_warranty_notifications')) {
    /**
     * Generate warranty alerts for admin + branch users.
     * Thresholds: 30 hari (warning), 7 hari (critical), 0/habis (expired).
     */
    function sync_warranty_notifications(mysqli $koneksi): void
    {
        $query = mysqli_query($koneksi, "
            SELECT b.id, b.serial_number, b.no_asset, b.id_branch, b.tanggal_garansi_berakhir,
                   tb.nama_barang, br.nama_branch,
                   DATEDIFF(b.tanggal_garansi_berakhir, CURDATE()) AS days_left
            FROM barang b
            INNER JOIN tb_barang tb ON b.id_barang = tb.id_barang
            LEFT JOIN tb_branch br ON b.id_branch = br.id_branch
            WHERE b.tanggal_garansi_berakhir IS NOT NULL
              AND b.status IN ('Tersedia', 'Diterima')
              AND DATEDIFF(b.tanggal_garansi_berakhir, CURDATE()) <= 30
            ORDER BY b.tanggal_garansi_berakhir ASC
        ");

        if (!$query) {
            return;
        }

        while ($row = mysqli_fetch_assoc($query)) {
            $daysLeft = (int) ($row['days_left'] ?? 999);
            $barangId = (int) $row['id'];
            $branchId = (int) ($row['id_branch'] ?? 0);
            $endDate = (string) $row['tanggal_garansi_berakhir'];
            $namaBarang = (string) ($row['nama_barang'] ?? 'Barang');
            $serial = (string) ($row['serial_number'] ?? '-');
            $branchName = (string) ($row['nama_branch'] ?? 'Cabang');
            $endFormatted = date('d M Y', strtotime($endDate));
            $link = '../Barang/update.php?id=' . $barangId;

            if ($daysLeft < 0) {
                $level = 'expired';
                $title = 'Garansi Sudah Habis';
                $message = "{$namaBarang} (SN: {$serial}) — garansi berakhir {$endFormatted}";
                $type = 'warranty_expired';
            } elseif ($daysLeft <= 7) {
                $level = 'critical';
                $title = 'Garansi Segera Berakhir';
                $message = "{$namaBarang} (SN: {$serial}) — sisa {$daysLeft} hari ({$endFormatted})";
                $type = 'warranty_critical';
            } else {
                $level = 'warning';
                $title = 'Pengingat Garansi Produk';
                $message = "{$namaBarang} (SN: {$serial}) — garansi berakhir {$endFormatted} ({$daysLeft} hari lagi)";
                $type = 'warranty_warning';
            }

            $referenceKey = "warranty:{$barangId}:{$level}:{$endDate}";

            create_system_notification(
                $koneksi,
                'admin',
                $title,
                $message . ' · ' . $branchName,
                $link,
                null,
                null,
                $type,
                $referenceKey
            );

            if ($branchId > 0) {
                create_system_notification(
                    $koneksi,
                    'user',
                    $title,
                    $message,
                    $link,
                    $branchId,
                    null,
                    $type,
                    $referenceKey . ':branch:' . $branchId
                );
            }
        }
    }
}

if (!function_exists('count_unread_notifications')) {
    function count_unread_notifications(mysqli $koneksi, string $role, ?int $branchId = null): int
    {
        if ($role === 'user' && $branchId) {
            $stmt = mysqli_prepare(
                $koneksi,
                "SELECT COUNT(id) AS total FROM system_notifications
                 WHERE target_role = ? AND is_read = 0
                   AND (target_branch_id IS NULL OR target_branch_id = ?)"
            );
            if (!$stmt) {
                return 0;
            }
            mysqli_stmt_bind_param($stmt, 'si', $role, $branchId);
        } else {
            $stmt = mysqli_prepare(
                $koneksi,
                "SELECT COUNT(id) AS total FROM system_notifications
                 WHERE target_role = ? AND is_read = 0"
            );
            if (!$stmt) {
                return 0;
            }
            mysqli_stmt_bind_param($stmt, 's', $role);
        }

        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        return (int) ($row['total'] ?? 0);
    }
}

if (!function_exists('notification_type_meta')) {
    function notification_type_meta(?string $type): array
    {
        $map = [
            'warranty_warning' => ['icon' => 'bi-shield-fill-exclamation', 'color' => '#d97706', 'bg' => 'rgba(245,158,11,.12)'],
            'warranty_critical' => ['icon' => 'bi-shield-exclamation', 'color' => '#dc2626', 'bg' => 'rgba(239,68,68,.12)'],
            'warranty_expired' => ['icon' => 'bi-shield-x', 'color' => '#991b1b', 'bg' => 'rgba(239,68,68,.18)'],
            'general' => ['icon' => 'bi-bell-fill', 'color' => '#2563eb', 'bg' => 'rgba(59,130,246,.12)'],
        ];

        return $map[$type ?? 'general'] ?? $map['general'];
    }
}

if (!function_exists('time_ago_id')) {
    function time_ago_id(?string $datetime): string
    {
        if (!$datetime) {
            return '-';
        }

        $ts = strtotime($datetime);
        if ($ts === false) {
            return '-';
        }

        $diff = time() - $ts;
        if ($diff < 60) {
            return 'Baru saja';
        }
        if ($diff < 3600) {
            return (int) floor($diff / 60) . ' menit lalu';
        }
        if ($diff < 86400) {
            return (int) floor($diff / 3600) . ' jam lalu';
        }
        if ($diff < 604800) {
            return (int) floor($diff / 86400) . ' hari lalu';
        }

        return date('d M Y H:i', $ts);
    }
}
