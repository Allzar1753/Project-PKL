<?php

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';
require_once '../config/warranty_helper.php';

// Cek Permission
require_permission($koneksi, 'dashboard.view');

sync_warranty_notifications($koneksi);

$isAdmin    = is_admin();
$myBranchId = (int) current_user_branch_id();

// Proteksi Awal
if ($myBranchId <= 0) {
    http_response_code(403);
    exit('Error: ID Branch user tidak ditemukan dalam sistem.');
}

// ==============================================================================
// FUNGSI HELPER (DIPERBARUI DENGAN SOFT BADGES MODERN)
// ==============================================================================
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dv($value, $fallback = '—'): string
{
    if (!isset($value) || $value === null || $value === '') return $fallback;
    return h($value);
}

function shippingBadge(string $status): string
{
    $class      = 'badge-soft-secondary';
    $icon       = 'bi-clock';
    $statusLower = strtolower(trim($status));

    if (in_array($statusLower, ['menunggu persetujuan admin', 'sedang dikemas'])) {
        $class = 'badge-soft-warning';
        $icon  = 'bi-hourglass-split';
    } elseif ($statusLower === 'sedang perjalanan') {
        $class = 'badge-soft-primary';
        $icon  = 'bi-truck';
    } elseif (in_array($statusLower, ['sudah diterima', 'sudah diterima ho', 'selesai'])) {
        $class = 'badge-soft-success';
        $icon  = 'bi-check-circle';
    }
    return '<span class="badge rounded-pill ' . $class . '"><i class="bi ' . $icon . ' me-1"></i>' . h($status) . '</span>';
}

function barangBadge(string $bermasalah): string
{
    if ($bermasalah === 'Iya') {
        return '<span class="badge rounded-pill badge-soft-danger"><i class="bi bi-exclamation-triangle me-1"></i>Bermasalah</span>';
    }
    return '<span class="badge rounded-pill badge-soft-success"><i class="bi bi-check-circle me-1"></i>Normal</span>';
}

function fetchSingleValue(mysqli $koneksi, string $sql): int
{
    $query = mysqli_query($koneksi, $sql);
    if (!$query) return 0;
    $row = mysqli_fetch_assoc($query);
    return (int) ($row['total'] ?? 0);
}

function fetchAllAssoc($query): array
{
    $rows = [];
    if ($query) {
        while ($row = mysqli_fetch_assoc($query)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function fetchBranchName(mysqli $koneksi, int $id): string
{
    $stmt = mysqli_prepare($koneksi, "SELECT nama_branch FROM tb_branch WHERE id_branch = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    return $row['nama_branch'] ?? 'Unknown';
}

// ==============================================================================
// KONSTANTA & LOGIKA (TIDAK DIUBAH)
// ==============================================================================
$idBranchHO = 40;

if ($isAdmin) {
    $whereLokasi      = "barang.id_branch = $idBranchHO";
    $excludeTransitSql = " AND barang.id NOT IN (SELECT id_barang FROM barang_pengiriman WHERE status_pengiriman = 'Sedang perjalanan') ";
} else {
    $whereLokasi      = "barang.id_branch = $myBranchId";
    $excludeTransitSql  = " AND barang.id NOT IN (SELECT id_barang FROM barang_pengiriman WHERE status_pengiriman = 'Sedang perjalanan') ";
    $excludeTransitSql .= " AND barang.serial_number NOT IN (SELECT serial_number FROM pengiriman_cabang_ho WHERE branch_asal = $myBranchId AND status_pengiriman NOT IN ('Ditolak', 'Selesai')) ";
}

$stokAktifSql = $isAdmin
    ? " AND (barang.status IN ('Tersedia','Diterima') OR barang.bermasalah = 'Iya') "
    : " AND barang.status IN ('Tersedia','Diterima') ";

$totalInventaris = fetchSingleValue($koneksi, "SELECT COUNT(barang.id) AS total FROM barang WHERE $whereLokasi $excludeTransitSql $stokAktifSql");

if ($isAdmin) {
    $totalMasuk         = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE status_pengiriman IN ('Sudah diterima HO', 'Selesai')");
    $totalKeluar        = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman");
    $totalSedangDikirim = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman WHERE status_pengiriman = 'Sedang perjalanan'");
} else {
    $totalMasuk         = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman WHERE branch_tujuan = $myBranchId AND status_pengiriman = 'Sudah diterima'");
    $totalKeluar        = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE branch_asal = $myBranchId");
    $totalSedangDikirim = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE branch_asal = $myBranchId AND status_pengiriman NOT IN ('Ditolak', 'Selesai')");
}

$totalBermasalah = fetchSingleValue($koneksi, "SELECT COUNT(barang.id) AS total FROM barang WHERE $whereLokasi AND bermasalah = 'Iya'");

$warrantyBranchFilter = $isAdmin ? '' : " AND barang.id_branch = $myBranchId ";
$warrantyCritical = fetchSingleValue($koneksi, "SELECT COUNT(barang.id) AS total FROM barang WHERE tanggal_garansi_berakhir IS NOT NULL AND DATEDIFF(tanggal_garansi_berakhir, CURDATE()) BETWEEN 0 AND 7 $warrantyBranchFilter");
$warrantyWarning = fetchSingleValue($koneksi, "SELECT COUNT(barang.id) AS total FROM barang WHERE tanggal_garansi_berakhir IS NOT NULL AND DATEDIFF(tanggal_garansi_berakhir, CURDATE()) BETWEEN 8 AND 30 $warrantyBranchFilter");
$warrantyExpired = fetchSingleValue($koneksi, "SELECT COUNT(barang.id) AS total FROM barang WHERE tanggal_garansi_berakhir IS NOT NULL AND DATEDIFF(tanggal_garansi_berakhir, CURDATE()) < 0 $warrantyBranchFilter");

// PANEL DETAIL QUERIES
$previewLimit = 3;

if ($isAdmin) {
    $qMasuk = "SELECT p.id_pengiriman_ho AS id, p.tanggal_pengajuan AS tanggal_kirim, p.status_pengiriman, tb.nama_barang, br.nama_branch AS nama_branch_aktif, CASE WHEN b.user IS NOT NULL AND b.user != '' AND b.user != '0' THEN b.user ELSE p.pemilik_barang END AS nama_pemilik FROM pengiriman_cabang_ho p LEFT JOIN barang b ON p.serial_number = b.serial_number LEFT JOIN tb_barang tb ON p.id_barang = tb.id_barang LEFT JOIN tb_branch br ON p.branch_asal = br.id_branch ORDER BY p.id_pengiriman_ho DESC LIMIT 10";
} else {
    $qMasuk = "SELECT p.id_pengiriman AS id, p.tanggal_keluar AS tanggal_kirim, p.status_pengiriman, tb.nama_barang, 'PUSAT HO' AS nama_branch_aktif, CASE WHEN b.user IS NOT NULL AND b.user != '' AND b.user != '0' THEN b.user ELSE 'Belum Ada Pemilik' END AS nama_pemilik FROM barang_pengiriman p LEFT JOIN barang b ON p.id_barang = b.id LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang WHERE p.branch_tujuan = $myBranchId ORDER BY p.id_pengiriman DESC LIMIT 10";
}
$barangMasukTerbaru = fetchAllAssoc(mysqli_query($koneksi, $qMasuk));

if ($isAdmin) {
    $qKeluar = "SELECT p.id_pengiriman AS id, p.tanggal_keluar, p.status_pengiriman, p.nomor_resi_keluar, tb.nama_barang, br.nama_branch AS nama_branch_tujuan FROM barang_pengiriman p LEFT JOIN barang b ON p.id_barang = b.id LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang LEFT JOIN tb_branch br ON p.branch_tujuan = br.id_branch ORDER BY p.id_pengiriman DESC LIMIT 10";
} else {
    $qKeluar = "SELECT p.id_pengiriman_ho AS id, p.tanggal_pengajuan AS tanggal_keluar, p.status_pengiriman, p.nomor_resi_keluar, tb.nama_barang, 'Kantor Pusat' AS nama_branch_tujuan FROM pengiriman_cabang_ho p LEFT JOIN tb_barang tb ON p.id_barang = tb.id_barang WHERE p.branch_asal = $myBranchId ORDER BY p.id_pengiriman_ho DESC LIMIT 10";
}
$barangKeluarTerbaru = fetchAllAssoc(mysqli_query($koneksi, $qKeluar));

$qBermasalah = "SELECT barang.id, barang.serial_number, barang.keterangan_masalah, tb.nama_barang, br.nama_branch AS nama_branch_aktif FROM barang LEFT JOIN tb_barang tb ON barang.id_barang = tb.id_barang LEFT JOIN tb_branch br ON barang.id_branch = br.id_branch WHERE $whereLokasi AND barang.bermasalah = 'Iya' ORDER BY barang.id DESC LIMIT 10";
$barangBermasalah = fetchAllAssoc(mysqli_query($koneksi, $qBermasalah));

if ($isAdmin) {
    $qTransit = "SELECT p.id_pengiriman AS id, p.tanggal_keluar, p.status_pengiriman, p.nomor_resi_keluar, tb.nama_barang, 'Pusat HO' AS nama_branch_asal, br.nama_branch AS nama_branch_tujuan FROM barang_pengiriman p LEFT JOIN barang b ON p.id_barang = b.id LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang LEFT JOIN tb_branch br ON p.branch_tujuan = br.id_branch WHERE p.status_pengiriman = 'Sedang perjalanan' ORDER BY p.id_pengiriman DESC LIMIT 10";
} else {
    $qTransit = "SELECT p.id_pengiriman_ho AS id, p.tanggal_pengajuan AS tanggal_keluar, p.status_pengiriman, p.nomor_resi_keluar, tb.nama_barang, br.nama_branch AS nama_branch_asal, 'Kantor Pusat' AS nama_branch_tujuan FROM pengiriman_cabang_ho p LEFT JOIN tb_barang tb ON p.id_barang = tb.id_barang LEFT JOIN tb_branch br ON p.branch_asal = br.id_branch WHERE p.branch_asal = $myBranchId AND p.status_pengiriman NOT IN ('Ditolak', 'Selesai') ORDER BY p.id_pengiriman_ho DESC LIMIT 10";
}
$pengirimanBelumDiterima = fetchAllAssoc(mysqli_query($koneksi, $qTransit));

$roleLabel    = $isAdmin ? 'Administrator HO' : 'Staff Cabang';
$branchLabel  = fetchBranchName($koneksi, $myBranchId);
$usernameLabel = (string) (current_user()['username'] ?? 'User');
$heroTitle    = "Selamat Datang, " . $usernameLabel;

$role        = current_role();
$branchFilter = '';
if ($role === 'user') {
    $branchFilter = " AND (target_branch_id IS NULL OR target_branch_id = {$myBranchId})";
}
$sqlNotifs      = "SELECT * FROM system_notifications WHERE target_role = '" . $role . "' AND is_read = 0 " . $branchFilter . " ORDER BY created_at DESC LIMIT 5";
$qNotifications = mysqli_query($koneksi, $sqlNotifs);
$notifications  = fetchAllAssoc($qNotifications);
$unreadNotifCount = count_unread_notifications($koneksi, $role, $role === 'user' ? $myBranchId : null);
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - IT Asset Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --orange-1: #E64312;
            --orange-2: #F25C05;
            --dark-1: #231F20;
            --text-main: #2d3136;
            --text-soft: #6b7280;
            --surface-bg: #F4F6F9;
            --border-soft: #e5e7eb;
            --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.04);
            --shadow-hover: 0 10px 25px rgba(0, 0, 0, 0.08);
            --radius-xl: 8px;
        }

        body {
            background-color: var(--surface-bg);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
            min-height: 100vh;
        }

        .page-content { padding: 24px 32px; }

        /* PERBAIKAN: Hero Section Lebih Elegan */
        .dashboard-hero {
            background: var(--dark-1);
            border-top: 4px solid var(--orange-1);
            border-radius: 16px;
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: var(--shadow-soft);
        }

        .dashboard-hero h1 {
            color: #fff;
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .dashboard-hero p {
            color: #9ca3af;
            margin-bottom: 0;
            font-size: 0.95rem;
        }

        .role-badge {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 999px;
            padding: 0.6rem 1.2rem;
            font-weight: 600;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* PERBAIKAN: Summary Cards Bersih (White) */
        .summary-card {
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-left: 4px solid var(--orange-1);
            border-radius: 16px;
            box-shadow: var(--shadow-soft);
            height: 100%; 
            padding: 1.25rem 1.5rem;
            transition: all .2s ease;
        }
        
        .summary-card.bermasalah { border-left-color: #ef4444; }

        .summary-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-hover); }
        .summary-label { font-size: .85rem; color: var(--text-soft); font-weight: 600; }
        .summary-value { font-size: 1.8rem; font-weight: 800; color: var(--dark-1); margin: 0.2rem 0; }
        .summary-note { font-size: .8rem; color: #9ca3af; }
        
        .summary-icon {
            width: 45px; height: 45px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem;
            background: rgba(255, 122, 0, 0.1);
            color: var(--orange-1);
        }
        .summary-icon.bermasalah { background: rgba(239, 68, 68, 0.1); color: #ef4444; }

        /* PERBAIKAN: Panel Cards Layout & Clean Header */
        .panel-card {
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-radius: 16px;
            box-shadow: var(--shadow-soft);
            height: 100%; display: flex; flex-direction: column;
        }
        .panel-header {
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid var(--border-soft);
            background: #fff;
            border-radius: 16px 16px 0 0;
            display: flex; flex-direction: column; justify-content: center;
        }
        .panel-title {
            font-size: 1.05rem; font-weight: 700; color: var(--dark-1);
            display: flex; align-items: center; gap: 8px;
        }
        .panel-subtitle { font-size: .85rem; color: var(--text-soft); margin-top: 2px; }
        
        .panel-body { padding: 1.2rem 1.5rem; flex: 1; display: flex; flex-direction: column; }

        /* PERBAIKAN: Activity Items Dibuat Flex Horizontal */
        .activity-list { display: flex; flex-direction: column; gap: 1rem; }
        .activity-item {
            background: #fff;
            border: 1px solid var(--border-soft);
            border-radius: 12px; 
            padding: 1rem 1.25rem;
            transition: all .2s ease;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .activity-item:hover { border-color: var(--orange-1); box-shadow: var(--shadow-soft); }
        
        /* Flex Header (Title + Badge sebaris) */
        .activity-item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }

        .activity-title { font-weight: 700; color: var(--dark-1); font-size: 0.95rem; line-height: 1.3;}
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.4rem; }
        
        /* Agar di layar kecil meta grid jadi 1 kolom */
        @media (max-width: 576px) { .meta-grid { grid-template-columns: 1fr; } }

        .meta-line { font-size: .85rem; color: var(--text-main); }
        .meta-muted { font-size: .85rem; color: var(--text-soft); display: flex; align-items: center; gap: 6px; }

        .empty-state {
            text-align: center; color: var(--text-soft);
            padding: 2.5rem 1rem;
            border: 1px dashed var(--border-soft);
            border-radius: 12px;
            background: #f9fafb;
            height: 100%;
            display: flex; flex-direction: column; justify-content: center; align-items: center;
        }
        .empty-state i { font-size: 2rem; margin-bottom: .5rem; color: #d1d5db; }

        .section-action { margin-top: 1.2rem; text-align: center; }
        .btn-toggle-list {
            border: 1px solid var(--border-soft); border-radius: 8px;
            padding: .6rem 1.2rem; font-size: .85rem; font-weight: 600;
            background: #fff; color: var(--text-main);
            transition: all .2s ease;
        }
        .btn-toggle-list:hover { background: var(--surface-bg); border-color: #d1d5db; }

        .extra-item.d-none { display: none !important; }

        /* PERBAIKAN: Soft Badges */
        .badge.rounded-pill { padding: 0.4em 0.8em; font-weight: 600; font-size: 0.75rem; letter-spacing: 0.3px; }
        .badge-soft-success { background-color: rgba(16, 185, 129, 0.15); color: #059669; }
        .badge-soft-warning { background-color: rgba(245, 158, 11, 0.15); color: #d97706; }
        .badge-soft-danger { background-color: rgba(239, 68, 68, 0.15); color: #b91c1c; }
        .badge-soft-primary { background-color: rgba(59, 130, 246, 0.15); color: #1d4ed8; }
        .badge-soft-secondary { background-color: rgba(107, 114, 128, 0.15); color: #4b5563; }

        .notif-panel { background:#fff; border:1px solid var(--border-soft); border-radius:14px; box-shadow:var(--shadow-soft); margin-bottom:1.25rem; overflow:hidden; }
        .notif-panel-head { padding:.9rem 1.1rem; border-bottom:1px solid var(--border-soft); display:flex; justify-content:space-between; align-items:center; background:linear-gradient(90deg,#fffdfb,#fff); }
        .notif-panel-item { display:flex; gap:.85rem; padding:.9rem 1.1rem; border-bottom:1px solid #f3f4f6; text-decoration:none; color:inherit; transition:.15s; }
        .notif-panel-item:hover { background:#fff8f5; }
        .notif-panel-item:last-child { border-bottom:none; }
        .notif-panel-icon { width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .warranty-alert-card { border-left:4px solid #f59e0b; }
        .warranty-alert-card.critical { border-left-color:#ef4444; }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="d-flex flex-nowrap w-100 overflow-hidden">

            <?php require_once '../layout/sidebar.php'; ?>

            <div id="mainContent" class="flex-grow-1" style="transition: all 0.28s ease; min-width: 0;">
                <?php include '../layout/notification_bell.php'; ?>
                <div class="page-content">

                    <!-- Header Hero -->
                    <div class="dashboard-hero">
                        <div>
                            <h1><?= h($heroTitle) ?></h1>
                            <p>Ringkasan inventaris, kondisi perangkat, dan aktivitas logistik aset Anda.</p>
                        </div>
                        <div class="role-badge">
                            <i class="bi bi-person-badge"></i>
                            <?= h($roleLabel) ?> &bull; <?= h($branchLabel) ?>
                        </div>
                    </div>

                    <!-- Notifikasi Profesional -->
                    <?php if (!empty($notifications) || $warrantyCritical > 0 || $warrantyWarning > 0): ?>
                        <div class="notif-panel">
                            <div class="notif-panel-head">
                                <div>
                                    <div class="fw-bold"><i class="bi bi-bell-fill me-2 text-warning"></i>Pusat Pemberitahuan</div>
                                    <div class="small text-muted"><?= $unreadNotifCount ?> notifikasi belum dibaca</div>
                                </div>
                                <a href="<?= h(base_url('dashboard/notifications.php')) ?>" class="btn btn-sm btn-outline-dark fw-semibold">Lihat Semua</a>
                            </div>

                            <?php if ($warrantyCritical > 0 || $warrantyWarning > 0 || $warrantyExpired > 0): ?>
                                <div class="px-3 py-2 bg-light border-bottom small d-flex flex-wrap gap-3">
                                    <?php if ($warrantyCritical > 0): ?><span class="text-danger fw-semibold"><i class="bi bi-shield-exclamation me-1"></i><?= $warrantyCritical ?> garansi ≤ 7 hari</span><?php endif; ?>
                                    <?php if ($warrantyWarning > 0): ?><span class="text-warning fw-semibold"><i class="bi bi-shield-fill-exclamation me-1"></i><?= $warrantyWarning ?> garansi ≤ 30 hari</span><?php endif; ?>
                                    <?php if ($warrantyExpired > 0): ?><span class="text-muted fw-semibold"><i class="bi bi-shield-x me-1"></i><?= $warrantyExpired ?> garansi habis</span><?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php foreach ($notifications as $notif): ?>
                                <?php
                                $notifId = (int) ($notif['id'] ?? 0);
                                $notifLink = trim((string) ($notif['link'] ?? ''));
                                $typeMeta = notification_type_meta($notif['notification_type'] ?? 'general');
                                $notifReadUrl = '../dashboard/notification_read.php?id=' . $notifId . '&redirect=' . urlencode($notifLink !== '' ? $notifLink : '../dashboard/index.php');
                                ?>
                                <a href="<?= h($notifReadUrl) ?>" class="notif-panel-item">
                                    <div class="notif-panel-icon" style="background:<?= h($typeMeta['bg']) ?>;color:<?= h($typeMeta['color']) ?>;">
                                        <i class="bi <?= h($typeMeta['icon']) ?>"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold mb-1"><?= dv($notif['title'] ?? null) ?></div>
                                        <div class="small text-muted mb-1"><?= dv($notif['message'] ?? null) ?></div>
                                        <div class="small text-secondary"><i class="bi bi-clock me-1"></i><?= h(time_ago_id($notif['created_at'] ?? null)) ?></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Widget Summary (5 Kotak) -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-sm-6 col-xl">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="summary-label">Total Inventaris</div>
                                        <div class="summary-value"><?= $totalInventaris ?></div>
                                        <div class="summary-note">Aset aktif lokasi saya</div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-box-seam"></i></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="summary-label">Barang Masuk</div>
                                        <div class="summary-value"><?= $totalMasuk ?></div>
                                        <div class="summary-note"><?= $isAdmin ? 'Diterima dr Cabang' : 'Diterima dr HO' ?></div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-box-arrow-in-down"></i></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="summary-label">Barang Keluar</div>
                                        <div class="summary-value"><?= $totalKeluar ?></div>
                                        <div class="summary-note"><?= $isAdmin ? 'Dikirim ke Cabang' : 'Dikirim ke HO' ?></div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-box-arrow-up"></i></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl">
                            <div class="summary-card bermasalah">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="summary-label text-danger">Bermasalah</div>
                                        <div class="summary-value"><?= $totalBermasalah ?></div>
                                        <div class="summary-note">Butuh perbaikan</div>
                                    </div>
                                    <div class="summary-icon bermasalah"><i class="bi bi-exclamation-triangle"></i></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="summary-label">Belum Diterima</div>
                                        <div class="summary-value"><?= $totalSedangDikirim ?></div>
                                        <div class="summary-note"><?= $isAdmin ? 'Kiriman HO → Cabang' : 'Cabang → HO' ?></div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-truck"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Panel Detail (Ubah ke Grid 2x2: col-xl-6) -->
                    <div class="row g-4">

                        <!-- PANEL 1: BARANG MASUK -->
                        <div class="col-xl-6 col-lg-6">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title">
                                        <i class="bi bi-box-arrow-in-down" style="color: var(--orange-1);"></i> Barang Masuk
                                    </div>
                                    <div class="panel-subtitle">
                                        <?= $isAdmin ? 'Logistik dari Cabang masuk ke HO' : 'Logistik dari HO masuk ke Cabang' ?>
                                    </div>
                                </div>
                                <div class="panel-body">
                                    <?php if (!empty($barangMasukTerbaru)): ?>
                                        <div class="activity-list" id="barangMasukList">
                                            <?php foreach ($barangMasukTerbaru as $index => $item): ?>
                                                <div class="activity-item <?= $index >= $previewLimit ? 'extra-item d-none' : '' ?>">
                                                    <div class="activity-item-header">
                                                        <div class="activity-title"><?= dv($item['nama_barang'] ?? null) ?></div>
                                                        <div><?= shippingBadge($item['status_pengiriman'] ?? 'Masuk') ?></div>
                                                    </div>
                                                    <div class="meta-grid">
                                                        <div class="meta-muted"><i class="bi bi-person"></i> <?= dv($item['nama_pemilik'] ?? null, 'Belum Ada Pemilik') ?></div>
                                                        <div class="meta-muted"><i class="bi bi-calendar3"></i> <?= dv($item['tanggal_kirim'] ?? null) ?></div>
                                                        <div class="meta-muted"><i class="bi bi-geo-alt"></i> Asal: <?= dv($item['nama_branch_aktif'] ?? null) ?></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($barangMasukTerbaru) > $previewLimit): ?>
                                            <div class="section-action">
                                                <button type="button" class="btn-toggle-list" onclick="toggleList('barangMasukList', this)">
                                                    Lihat Semua (<?= count($barangMasukTerbaru) ?>)
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <i class="bi bi-inbox"></i>
                                            <span class="fw-semibold">Belum ada barang masuk</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- PANEL 2: BARANG KELUAR -->
                        <div class="col-xl-6 col-lg-6">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title">
                                        <i class="bi bi-box-arrow-up" style="color: var(--orange-1);"></i> Barang Keluar
                                    </div>
                                    <div class="panel-subtitle">
                                        <?= $isAdmin ? 'Logistik dikirim dari HO ke Cabang' : 'Logistik dikirim dari Cabang ke HO' ?>
                                    </div>
                                </div>
                                <div class="panel-body">
                                    <?php if (!empty($barangKeluarTerbaru)): ?>
                                        <div class="activity-list" id="barangKeluarList">
                                            <?php foreach ($barangKeluarTerbaru as $index => $item): ?>
                                                <div class="activity-item <?= $index >= $previewLimit ? 'extra-item d-none' : '' ?>">
                                                    <div class="activity-item-header">
                                                        <div class="activity-title"><?= dv($item['nama_barang'] ?? null) ?></div>
                                                        <div><?= shippingBadge($item['status_pengiriman'] ?? '-') ?></div>
                                                    </div>
                                                    <div class="meta-grid">
                                                        <div class="meta-muted"><i class="bi bi-geo-alt"></i> Tujuan: <?= dv($item['nama_branch_tujuan'] ?? 'Pusat HO') ?></div>
                                                        <div class="meta-muted"><i class="bi bi-calendar3"></i> <?= dv($item['tanggal_keluar'] ?? null) ?></div>
                                                        <div class="meta-muted"><i class="bi bi-receipt"></i> Resi: <?= dv($item['nomor_resi_keluar'] ?? 'Belum ada') ?></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($barangKeluarTerbaru) > $previewLimit): ?>
                                            <div class="section-action">
                                                <button type="button" class="btn-toggle-list" onclick="toggleList('barangKeluarList', this)">
                                                    Lihat Semua (<?= count($barangKeluarTerbaru) ?>)
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <i class="bi bi-send-x"></i>
                                            <span class="fw-semibold">Belum ada barang keluar</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- PANEL 3: BARANG BERMASALAH -->
                        <div class="col-xl-6 col-lg-6">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title">
                                        <i class="bi bi-exclamation-triangle text-danger"></i> Barang Bermasalah
                                    </div>
                                    <div class="panel-subtitle">Aset yang dilaporkan rusak atau butuh perbaikan</div>
                                </div>
                                <div class="panel-body">
                                    <?php if (!empty($barangBermasalah)): ?>
                                        <div class="activity-list" id="barangBermasalahList">
                                            <?php foreach ($barangBermasalah as $index => $item): ?>
                                                <div class="activity-item <?= $index >= $previewLimit ? 'extra-item d-none' : '' ?>" style="border-left: 4px solid #ef4444;">
                                                    <div class="activity-item-header">
                                                        <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                        <div><?= barangBadge('Iya') ?></div>
                                                    </div>
                                                    <div class="meta-grid">
                                                        <div class="meta-muted"><i class="bi bi-upc-scan"></i> SN: <?= h($item['serial_number'] ?? '-') ?></div>
                                                        <div class="meta-muted"><i class="bi bi-geo-alt"></i> <?= h($item['nama_branch_aktif'] ?? '-') ?></div>
                                                    </div>
                                                    <div class="mt-2 pt-2 border-top border-danger border-opacity-10 small text-danger">
                                                        <strong>Kendala:</strong> <?= h($item['keterangan_masalah'] ?? 'Tidak ada keterangan') ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($barangBermasalah) > $previewLimit): ?>
                                            <div class="section-action">
                                                <button type="button" class="btn-toggle-list" onclick="toggleList('barangBermasalahList', this)">
                                                    Lihat Semua (<?= count($barangBermasalah) ?>)
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <i class="bi bi-check-circle text-success"></i>
                                            <span class="fw-semibold">Semua aset dalam kondisi normal</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- PANEL 4: BELUM DITERIMA -->
                        <div class="col-xl-6 col-lg-6">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title">
                                        <i class="bi bi-truck text-primary"></i> Sedang Dalam Perjalanan
                                    </div>
                                    <div class="panel-subtitle">
                                        <?= $isAdmin ? 'Menunggu konfirmasi penerimaan dari Cabang' : 'Menunggu konfirmasi penerimaan dari HO' ?>
                                    </div>
                                </div>
                                <div class="panel-body">
                                    <?php if (!empty($pengirimanBelumDiterima)): ?>
                                        <div class="activity-list" id="pengirimanBelumDiterimaList">
                                            <?php foreach ($pengirimanBelumDiterima as $index => $item): ?>
                                                <div class="activity-item <?= $index >= $previewLimit ? 'extra-item d-none' : '' ?>">
                                                    <div class="activity-item-header">
                                                        <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                        <div><?= shippingBadge($item['status_pengiriman'] ?? '-') ?></div>
                                                    </div>
                                                    <div class="meta-grid">
                                                        <div class="meta-muted"><i class="bi bi-arrow-up-right"></i> Asal: <?= h($item['nama_branch_asal'] ?? '-') ?></div>
                                                        <div class="meta-muted"><i class="bi bi-arrow-down-right"></i> Tujuan: <?= h($item['nama_branch_tujuan'] ?? '-') ?></div>
                                                        <div class="meta-muted"><i class="bi bi-calendar3"></i> Dikirim: <?= h($item['tanggal_keluar'] ?? '-') ?></div>
                                                        <div class="meta-muted"><i class="bi bi-receipt"></i> Resi: <?= h($item['nomor_resi_keluar'] ?? '-') ?></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($pengirimanBelumDiterima) > $previewLimit): ?>
                                            <div class="section-action">
                                                <button type="button" class="btn-toggle-list" onclick="toggleList('pengirimanBelumDiterimaList', this)">
                                                    Lihat Semua (<?= count($pengirimanBelumDiterima) ?>)
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <i class="bi bi-check2-all"></i>
                                            <span class="fw-semibold">Tidak ada pengiriman aktif saat ini</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                    </div><!-- end row panel -->
                </div><!-- end page-content -->
            </div><!-- end mainContent -->
        </div>
    </div>

    <script>
        function toggleList(listId, button) {
            const list        = document.getElementById(listId);
            const hiddenItems = list.querySelectorAll('.extra-item');
            const isExpanded  = button.getAttribute('data-expanded') === 'true';

            hiddenItems.forEach(item => {
                isExpanded ? item.classList.add('d-none') : item.classList.remove('d-none');
            });

            if (isExpanded) {
                button.setAttribute('data-expanded', 'false');
                button.innerText = 'Lihat Semua (' + (hiddenItems.length + 3) + ')'; // Asumsi previewLimit = 3
            } else {
                button.setAttribute('data-expanded', 'true');
                button.innerText = 'Tutup Sebagian';
            }
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>