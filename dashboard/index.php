<?php

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

// Cek Permission
require_permission($koneksi, 'dashboard.view');

$isAdmin    = is_admin();
$myBranchId = (int) current_user_branch_id();

// Proteksi Awal
if ($myBranchId <= 0) {
    http_response_code(403);
    exit('Error: ID Branch user tidak ditemukan dalam sistem.');
}

// ==============================================================================
// FUNGSI HELPER
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
    $class      = 'bg-secondary';
    $icon       = 'bi-clock';
    $statusLower = strtolower(trim($status));

    if (in_array($statusLower, ['menunggu persetujuan admin', 'sedang dikemas'])) {
        $class = 'bg-warning text-dark';
        $icon  = 'bi-hourglass-split';
    } elseif ($statusLower === 'sedang perjalanan') {
        $class = 'bg-primary';
        $icon  = 'bi-truck';
    } elseif (in_array($statusLower, ['sudah diterima', 'sudah diterima ho', 'selesai'])) {
        $class = 'bg-success';
        $icon  = 'bi-check-circle';
    }
    return '<span class="badge rounded-pill ' . $class . '" style="line-height:1.4;"><i class="bi ' . $icon . ' me-1"></i>' . h($status) . '</span>';
}

function barangBadge(string $bermasalah): string
{
    if ($bermasalah === 'Iya') {
        return '<span class="badge rounded-pill bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Bermasalah</span>';
    }
    return '<span class="badge rounded-pill bg-success"><i class="bi bi-check-circle me-1"></i>Normal</span>';
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
// KONSTANTA — ID Branch HO (selaras dengan Barang/index.php)
// ==============================================================================
$idBranchHO = 40;

// ==============================================================================
// LOGIKA FILTER & EXCLUDE TRANSIT
// Selaras 100% dengan Barang/index.php
// ==============================================================================
if ($isAdmin) {
    /**
     * ADMIN HO: hanya lihat barang yang fisiknya ada di HO (id_branch = 40)
     * Barang disembunyikan jika sedang transit keluar ke cabang
     */
    $whereLokasi      = "barang.id_branch = $idBranchHO";
    $excludeTransitSql = " AND barang.id NOT IN (SELECT id_barang FROM barang_pengiriman WHERE status_pengiriman = 'Sedang perjalanan') ";

} else {
    /**
     * USER CABANG: hanya lihat barang di cabangnya sendiri
     * Barang disembunyikan jika sedang transit ke/dari HO
     */
    $whereLokasi      = "barang.id_branch = $myBranchId";
    $excludeTransitSql  = " AND barang.id NOT IN (SELECT id_barang FROM barang_pengiriman WHERE status_pengiriman = 'Sedang perjalanan') ";
    $excludeTransitSql .= " AND barang.serial_number NOT IN (SELECT serial_number FROM pengiriman_cabang_ho WHERE branch_asal = $myBranchId AND status_pengiriman NOT IN ('Ditolak', 'Selesai')) ";
}

$stokAktifSql = $isAdmin
    ? " AND (barang.status IN ('Tersedia','Diterima') OR barang.bermasalah = 'Iya') "
    : " AND barang.status IN ('Tersedia','Diterima') ";

// ==============================================================================
// WIDGET SUMMARY — 5 ANGKA ATAS
// Selaras dengan Barang/index.php
// ==============================================================================

// 1. Total Inventaris
$totalInventaris = fetchSingleValue($koneksi,
    "SELECT COUNT(barang.id) AS total FROM barang WHERE $whereLokasi $excludeTransitSql $stokAktifSql"
);

if ($isAdmin) {
    // Admin HO:
    // Masuk   = barang dari Cabang yang sudah diterima HO (pengiriman_cabang_ho)
    // Keluar  = barang dari HO ke Cabang (barang_pengiriman)
    // Transit = barang HO ke Cabang yang belum diterima (kebalikan masuk)
    $totalMasuk         = fetchSingleValue($koneksi,
        "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE status_pengiriman IN ('Sudah diterima HO', 'Selesai')"
    );
    $totalKeluar        = fetchSingleValue($koneksi,
        "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman"
    );
    $totalSedangDikirim = fetchSingleValue($koneksi,
        "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman WHERE status_pengiriman = 'Sedang perjalanan'"
    );

} else {
    // User Cabang:
    // Masuk   = barang dari HO ke Cabang yang sudah diterima (barang_pengiriman)
    // Keluar  = barang dari Cabang ke HO (pengiriman_cabang_ho)
    // Transit = barang Cabang ke HO yang belum selesai (kebalikan masuk)
    $totalMasuk         = fetchSingleValue($koneksi,
        "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman WHERE branch_tujuan = $myBranchId AND status_pengiriman = 'Sudah diterima'"
    );
    $totalKeluar        = fetchSingleValue($koneksi,
        "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE branch_asal = $myBranchId"
    );
    $totalSedangDikirim = fetchSingleValue($koneksi,
        "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE branch_asal = $myBranchId AND status_pengiriman NOT IN ('Ditolak', 'Selesai')"
    );
}

// Bermasalah — mengikuti whereLokasi masing-masing
$totalBermasalah = fetchSingleValue($koneksi,
    "SELECT COUNT(barang.id) AS total FROM barang WHERE $whereLokasi AND bermasalah = 'Iya'"
);

// ==============================================================================
// PANEL DETAIL — LIST AKTIVITAS
// ==============================================================================
$previewLimit = 3;

// --------------------------------------------------------------------------
// PANEL 1: BARANG MASUK
// Admin  = dari Cabang ke HO (pengiriman_cabang_ho) + pemilik_barang
// User   = dari HO ke Cabang (barang_pengiriman) + nama_penerima
// --------------------------------------------------------------------------
if ($isAdmin) {
    $qMasuk = "SELECT
                    p.id_pengiriman_ho AS id,
                    p.tanggal_pengajuan AS tanggal_kirim,
                    p.status_pengiriman,
                    tb.nama_barang,
                    br.nama_branch AS nama_branch_aktif,
                    CASE 
                        WHEN b.user IS NOT NULL AND b.user != '' AND b.user != '0' THEN b.user 
                        ELSE p.pemilik_barang
                    END AS nama_pemilik
               FROM pengiriman_cabang_ho p
               LEFT JOIN barang b ON p.serial_number = b.serial_number
               LEFT JOIN tb_barang tb ON p.id_barang = tb.id_barang
               LEFT JOIN tb_branch br ON p.branch_asal = br.id_branch
               ORDER BY p.id_pengiriman_ho DESC LIMIT 10";
} else {
    $qMasuk = "SELECT
                    p.id_pengiriman AS id,
                    p.tanggal_keluar AS tanggal_kirim,
                    p.status_pengiriman,
                    tb.nama_barang,
                    'PUSAT HO' AS nama_branch_aktif,
                    CASE
                        WHEN b.user IS NOT NULL AND b.user != '' AND b.user != '0' THEN b.user
                        ELSE 'Belum Ada Pemilik'
                    END AS nama_pemilik
               FROM barang_pengiriman p
               LEFT JOIN barang b ON p.id_barang = b.id
               LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang
               WHERE p.branch_tujuan = $myBranchId
               ORDER BY p.id_pengiriman DESC LIMIT 10";
}
$barangMasukTerbaru = fetchAllAssoc(mysqli_query($koneksi, $qMasuk));

// --------------------------------------------------------------------------
// PANEL 2: BARANG KELUAR
// Admin  = dari HO ke Cabang (barang_pengiriman)
// User   = dari Cabang ke HO (pengiriman_cabang_ho)
// --------------------------------------------------------------------------
if ($isAdmin) {
    $qKeluar = "SELECT
                    p.id_pengiriman AS id,
                    p.tanggal_keluar,
                    p.status_pengiriman,
                    p.nomor_resi_keluar,
                    tb.nama_barang,
                    br.nama_branch AS nama_branch_tujuan
                FROM barang_pengiriman p
                LEFT JOIN barang b ON p.id_barang = b.id
                LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang
                LEFT JOIN tb_branch br ON p.branch_tujuan = br.id_branch
                ORDER BY p.id_pengiriman DESC LIMIT 10";
} else {
    $qKeluar = "SELECT
                    p.id_pengiriman_ho AS id,
                    p.tanggal_pengajuan AS tanggal_keluar,
                    p.status_pengiriman,
                    p.nomor_resi_keluar,
                    tb.nama_barang,
                    'Kantor Pusat' AS nama_branch_tujuan
                FROM pengiriman_cabang_ho p
                LEFT JOIN tb_barang tb ON p.id_barang = tb.id_barang
                WHERE p.branch_asal = $myBranchId
                ORDER BY p.id_pengiriman_ho DESC LIMIT 10";
}
$barangKeluarTerbaru = fetchAllAssoc(mysqli_query($koneksi, $qKeluar));

// --------------------------------------------------------------------------
// PANEL 3: BARANG BERMASALAH
// Mengikuti whereLokasi masing-masing (selaras Barang/index.php)
// --------------------------------------------------------------------------
$qBermasalah = "SELECT
                    barang.id,
                    barang.serial_number,
                    barang.keterangan_masalah,
                    tb.nama_barang,
                    br.nama_branch AS nama_branch_aktif
                FROM barang
                LEFT JOIN tb_barang tb ON barang.id_barang = tb.id_barang
                LEFT JOIN tb_branch br ON barang.id_branch = br.id_branch
                WHERE $whereLokasi AND barang.bermasalah = 'Iya'
                ORDER BY barang.id DESC LIMIT 10";
$barangBermasalah = fetchAllAssoc(mysqli_query($koneksi, $qBermasalah));

// --------------------------------------------------------------------------
// PANEL 4: BELUM DITERIMA (kebalikan dari Masuk)
// Admin  = HO kirim ke Cabang yang belum diterima (barang_pengiriman)
// User   = Cabang kirim ke HO yang belum selesai (pengiriman_cabang_ho)
// --------------------------------------------------------------------------
if ($isAdmin) {
    $qTransit = "SELECT
                     p.id_pengiriman AS id,
                     p.tanggal_keluar,
                     p.status_pengiriman,
                     p.nomor_resi_keluar,
                     tb.nama_barang,
                     'Pusat HO' AS nama_branch_asal,
                     br.nama_branch AS nama_branch_tujuan
                 FROM barang_pengiriman p
                 LEFT JOIN barang b ON p.id_barang = b.id
                 LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang
                 LEFT JOIN tb_branch br ON p.branch_tujuan = br.id_branch
                 WHERE p.status_pengiriman = 'Sedang perjalanan'
                 ORDER BY p.id_pengiriman DESC LIMIT 10";
} else {
    $qTransit = "SELECT
                     p.id_pengiriman_ho AS id,
                     p.tanggal_pengajuan AS tanggal_keluar,
                     p.status_pengiriman,
                     p.nomor_resi_keluar,
                     tb.nama_barang,
                     br.nama_branch AS nama_branch_asal,
                     'Kantor Pusat' AS nama_branch_tujuan
                 FROM pengiriman_cabang_ho p
                 LEFT JOIN tb_barang tb ON p.id_barang = tb.id_barang
                 LEFT JOIN tb_branch br ON p.branch_asal = br.id_branch
                 WHERE p.branch_asal = $myBranchId
                   AND p.status_pengiriman NOT IN ('Ditolak', 'Selesai')
                 ORDER BY p.id_pengiriman_ho DESC LIMIT 10";
}
$pengirimanBelumDiterima = fetchAllAssoc(mysqli_query($koneksi, $qTransit));

// ==============================================================================
// VIEW DATA
// ==============================================================================
$roleLabel    = $isAdmin ? 'Administrator HO' : 'Staff Cabang';
$branchLabel  = fetchBranchName($koneksi, $myBranchId);
$usernameLabel = (string) (current_user()['username'] ?? 'User');
$heroTitle    = "Selamat Datang, " . $usernameLabel;

// Notifikasi
$role        = current_role();
$branchFilter = '';
if ($role === 'user') {
    $branchFilter = " AND (target_branch_id IS NULL OR target_branch_id = {$myBranchId})";
}
$sqlNotifs      = "SELECT * FROM system_notifications WHERE target_role = '" . $role . "' AND is_read = 0 " . $branchFilter . " ORDER BY created_at DESC LIMIT 3";
$qNotifications = mysqli_query($koneksi, $sqlNotifs);
$notifications  = fetchAllAssoc($qNotifications);
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
            --orange-1: #ff7a00;
            --orange-2: #ff9800;
            --orange-3: #ffb000;
            --orange-4: #ffd166;
            --orange-5: #fff3e0;
            --dark-1: #111111;
            --dark-2: #1f1f1f;
            --dark-3: #2a2a2a;
            --text-main: #1e1e1e;
            --text-soft: #6b7280;
            --white: #ffffff;
            --border-soft: rgba(255, 152, 0, 0.14);
            --shadow-soft: 0 14px 40px rgba(17, 17, 17, 0.08);
            --shadow-hover: 0 18px 46px rgba(255, 122, 0, 0.18);
            --radius-xl: 28px;
            --radius-lg: 22px;
            --radius-md: 16px;
        }

        * { box-sizing: border-box; }

        body {
            background:
                radial-gradient(circle at top left, rgba(255, 176, 0, 0.18), transparent 28%),
                radial-gradient(circle at bottom right, rgba(255, 122, 0, 0.10), transparent 22%),
                linear-gradient(180deg, #fff8f1 0%, #fffaf5 35%, #ffffff 100%);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
            min-height: 100vh;
        }

        .page-content { padding: 30px; }
        .text-warning-custom { color: var(--orange-2) !important; }

        /* Hero */
        .dashboard-hero {
            position: relative;
            overflow: hidden;
            border: 0;
            border-radius: var(--radius-xl);
            background: linear-gradient(135deg, rgba(17,17,17,.94) 0%, rgba(42,42,42,.90) 28%, rgba(255,122,0,.96) 100%);
            box-shadow: 0 18px 45px rgba(255,122,0,.22);
            padding: 1.8rem;
            margin-bottom: 1.5rem;
        }
        .dashboard-hero::before {
            content: "";
            position: absolute;
            width: 280px; height: 280px;
            border-radius: 50%;
            background: rgba(255,255,255,.08);
            top: -100px; right: -60px;
        }
        .dashboard-hero::after {
            content: "";
            position: absolute;
            width: 180px; height: 180px;
            border-radius: 50%;
            background: rgba(255,208,102,.18);
            bottom: -70px; left: -50px;
        }
        .dashboard-hero h1 {
            position: relative; z-index: 2;
            font-size: 1.85rem; font-weight: 800;
            color: #fff; margin-bottom: .45rem; letter-spacing: -0.02em;
        }
        .dashboard-hero p {
            position: relative; z-index: 2;
            color: rgba(255,255,255,.86); margin-bottom: 0;
            max-width: 760px; line-height: 1.7; font-size: .95rem;
        }
        .role-badge {
            position: relative; z-index: 2;
            background: rgba(255,255,255,.14);
            color: #fff; border: 1px solid rgba(255,255,255,.18);
            border-radius: 999px; padding: .65rem 1rem;
            font-weight: 700; font-size: .86rem;
            white-space: nowrap; backdrop-filter: blur(10px);
        }

        /* Summary Cards */
        .summary-card {
            position: relative; overflow: hidden;
            background: linear-gradient(180deg, #ffffff 0%, #fffaf3 100%);
            border: 1px solid rgba(255,176,0,.15);
            border-radius: 22px;
            box-shadow: var(--shadow-soft);
            height: 100%; padding: 1.15rem 1.1rem;
            transition: all .25s ease;
        }
        .summary-card::before {
            content: ""; position: absolute;
            inset: 0 0 auto 0; height: 6px;
            background: linear-gradient(90deg, var(--orange-1), var(--orange-3));
        }
        .summary-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
        .summary-label { font-size: .84rem; color: var(--text-soft); margin-bottom: .45rem; font-weight: 600; }
        .summary-value { font-size: 2rem; line-height: 1; font-weight: 800; margin-bottom: .45rem; color: var(--dark-1); letter-spacing: -0.03em; }
        .summary-note { font-size: .83rem; color: #7b7b7b; line-height: 1.5; }
        .summary-icon {
            width: 54px; height: 54px; border-radius: 16px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.28rem; flex-shrink: 0;
            color: #fff !important;
            background: linear-gradient(135deg, var(--orange-1), var(--orange-3)) !important;
        }

        /* Panel Cards */
        .panel-card {
            overflow: hidden; background: #ffffff;
            border: 1px solid rgba(255,176,0,.13);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-soft);
            height: 100%; display: flex; flex-direction: column;
        }
        .panel-header {
            padding: 1.15rem 1.25rem;
            border-bottom: 1px solid rgba(255,176,0,.16);
            background: linear-gradient(135deg, #111111 0%, #2c2c2c 40%, #ff8f00 100%);
            position: relative; overflow: hidden;
        }
        .panel-title {
            position: relative; z-index: 1;
            font-size: 1.02rem; font-weight: 800;
            margin-bottom: .22rem; color: #fff; letter-spacing: -0.02em;
        }
        .panel-subtitle {
            position: relative; z-index: 1;
            font-size: .84rem; color: rgba(255,255,255,.80); line-height: 1.5;
        }
        .panel-body {
            padding: 1.15rem 1.2rem;
            background: linear-gradient(180deg, #fffdf9 0%, #fff8ef 100%);
            flex: 1; display: flex; flex-direction: column;
        }

        /* Activity Items */
        .activity-list { display: flex; flex-direction: column; gap: .9rem; }
        .activity-item {
            position: relative;
            border: 1px solid rgba(255,176,0,.14);
            border-left: 5px solid var(--orange-2);
            border-radius: 18px; padding: 1rem;
            background: #ffffff;
            box-shadow: 0 8px 20px rgba(17,17,17,.04);
            transition: all .22s ease;
        }
        .activity-item:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(255,122,0,.14); border-color: rgba(255,152,0,.28); }
        .activity-title { font-weight: 800; margin-bottom: .38rem; color: var(--dark-1); letter-spacing: -0.01em; }
        .meta-grid { display: grid; gap: .28rem; margin-top: .6rem; }
        .meta-line { font-size: .88rem; color: #4b5563; line-height: 1.5; }
        .meta-line strong { color: #111827; font-weight: 700; }
        .meta-muted { font-size: .84rem; color: #6b7280; line-height: 1.5; }
        .meta-muted i { width: 16px; color: var(--orange-2); }

        .empty-state {
            text-align: center; color: var(--text-soft);
            padding: 1.8rem 1rem;
            border: 1px dashed rgba(255,152,0,.28);
            border-radius: 18px;
            background: linear-gradient(180deg, #fffaf2 0%, #fff5e8 100%);
        }
        .empty-state i { display: block; font-size: 1.9rem; margin-bottom: .5rem; color: var(--orange-2); }

        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2; line-clamp: 2;
            -webkit-box-orient: vertical; overflow: hidden;
        }

        .section-action { margin-top: 1rem; text-align: center; }
        .btn-toggle-list {
            border: none; border-radius: 999px;
            padding: .7rem 1.1rem; font-size: .85rem; font-weight: 700;
            background: linear-gradient(135deg, #111111 0%, #ff8f00 100%);
            color: #fff; box-shadow: 0 10px 24px rgba(255,143,0,.18);
            transition: all .25s ease;
        }
        .btn-toggle-list:hover { transform: translateY(-2px); box-shadow: 0 14px 28px rgba(255,143,0,.25); }

        .extra-item.d-none { display: none !important; }

        .badge.rounded-pill {
            padding: .5rem .8rem; font-size: .75rem; font-weight: 700;
            letter-spacing: .1px; white-space: normal;
            text-align: left; line-height: 1.4;
            display: inline-block; max-width: 100%;
        }

        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #ff9800, #ffb000); border-radius: 999px; }
        ::-webkit-scrollbar-track { background: #fff4e3; }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="d-flex flex-nowrap w-100 overflow-hidden">

            <?php require_once '../layout/sidebar.php'; ?>

            <div id="mainContent" class="flex-grow-1" style="transition: all 0.28s ease; min-width: 0;">
                <div class="page-content">

                    <!-- Header Hero -->
                    <div class="dashboard-hero">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div>
                                <h1><?= h($heroTitle) ?></h1>
                                <p>Ringkasan inventaris, kondisi perangkat, status pengiriman, dan aktivitas aset dalam tampilan modern sesuai peranan.</p>
                            </div>
                            <div class="role-badge">
                                <i class="bi bi-person-badge me-1"></i><?= h($roleLabel) ?> · <?= h($branchLabel) ?>
                            </div>
                        </div>
                    </div>

                    <!-- Notifikasi -->
                    <?php if (!empty($notifications)): ?>
                        <div class="alert alert-warning border-0 shadow-sm mb-4">
                            <div class="fw-bold mb-2"><i class="bi bi-bell me-2"></i>Notifikasi Terbaru</div>
                            <?php foreach ($notifications as $notif): ?>
                                <?php
                                $notifId      = (int) ($notif['id'] ?? 0);
                                $notifLink    = trim((string) ($notif['link'] ?? ''));
                                $notifReadUrl = '../dashboard/notification_read.php?id=' . $notifId . '&redirect=' . urlencode($notifLink !== '' ? $notifLink : '../dashboard/index.php');
                                ?>
                                <div class="mb-2">
                                    <div class="fw-semibold"><?= dv($notif['title'] ?? null) ?></div>
                                    <div class="small"><?= dv($notif['message'] ?? null) ?></div>
                                    <a href="<?= h($notifReadUrl) ?>" class="small">Buka detail</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Widget Summary (5 Kotak) -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-sm-6 col-xl">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Total Inventaris</div>
                                        <div class="summary-value"><?= $totalInventaris ?></div>
                                        <div class="summary-note">Aset aktif di lokasi saya</div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-box-seam"></i></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Barang Masuk</div>
                                        <div class="summary-value"><?= $totalMasuk ?></div>
                                        <div class="summary-note">
                                            <?= $isAdmin ? 'Diterima dari Cabang' : 'Diterima dari HO' ?>
                                        </div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-box-arrow-in-down"></i></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Barang Keluar</div>
                                        <div class="summary-value"><?= $totalKeluar ?></div>
                                        <div class="summary-note">
                                            <?= $isAdmin ? 'Dikirim ke Cabang' : 'Dikirim ke HO' ?>
                                        </div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-box-arrow-up"></i></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Bermasalah</div>
                                        <div class="summary-value"><?= $totalBermasalah ?></div>
                                        <div class="summary-note">Perlu perhatian khusus</div>
                                    </div>
                                    <div class="summary-icon" style="background: linear-gradient(135deg,#dc3545,#ff6b6b) !important;">
                                        <i class="bi bi-exclamation-triangle"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Belum Diterima</div>
                                        <div class="summary-value"><?= $totalSedangDikirim ?></div>
                                        <div class="summary-note">
                                            <?= $isAdmin ? 'Kiriman HO ke Cabang' : 'Kiriman Cabang ke HO' ?>
                                        </div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-truck"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Panel Detail (4 Kolom) -->
                    <div class="row g-4">

                        <!-- PANEL 1: BARANG MASUK -->
                        <div class="col-xl-3 col-md-6">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title">
                                        <i class="bi bi-box-arrow-in-down me-2 text-warning-custom"></i>Barang Masuk
                                    </div>
                                    <div class="panel-subtitle">
                                        <?= $isAdmin ? 'Dari Cabang ke HO' : 'Dari HO ke Cabang' ?>
                                    </div>
                                </div>
                                <div class="panel-body">
                                    <?php if (!empty($barangMasukTerbaru)): ?>
                                        <div class="activity-list" id="barangMasukList">
                                            <?php foreach ($barangMasukTerbaru as $index => $item): ?>
                                                <div class="activity-item <?= $index >= $previewLimit ? 'extra-item d-none' : '' ?> d-flex flex-column">
                                                    <div class="flex-grow-1">
                                                        <div class="activity-title mb-2"><?= dv($item['nama_barang'] ?? null) ?></div>
                                                        <div class="meta-grid">
                                                            <div class="meta-muted">
                                                                <i class="bi bi-person me-1"></i>
                                                                <?= dv($item['nama_pemilik'] ?? null, 'Belum Ada Pemilik') ?>
                                                            </div>
                                                            <div class="meta-muted">
                                                                <i class="bi bi-geo-alt me-1"></i>Asal: <?= dv($item['nama_branch_aktif'] ?? null) ?>
                                                            </div>
                                                            <div class="meta-muted">
                                                                <i class="bi bi-calendar3 me-1"></i><?= dv($item['tanggal_kirim'] ?? null) ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="pt-2 mt-3 text-start" style="border-top:1px dashed rgba(255,152,0,.2);">
                                                        <?= shippingBadge($item['status_pengiriman'] ?? 'Masuk') ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($barangMasukTerbaru) > $previewLimit): ?>
                                            <div class="section-action">
                                                <button type="button" class="btn-toggle-list" onclick="toggleList('barangMasukList', this)">
                                                    <i class="bi bi-chevron-down"></i> Lihat selengkapnya
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="empty-state"><i class="bi bi-inbox"></i>Belum ada data barang masuk.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- PANEL 2: BARANG KELUAR -->
                        <div class="col-xl-3 col-md-6">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title">
                                        <i class="bi bi-box-arrow-up me-2 text-warning-custom"></i>Barang Keluar
                                    </div>
                                    <div class="panel-subtitle">
                                        <?= $isAdmin ? 'Dari HO ke Cabang' : 'Dari Cabang ke HO' ?>
                                    </div>
                                </div>
                                <div class="panel-body">
                                    <?php if (!empty($barangKeluarTerbaru)): ?>
                                        <div class="activity-list" id="barangKeluarList">
                                            <?php foreach ($barangKeluarTerbaru as $index => $item): ?>
                                                <div class="activity-item <?= $index >= $previewLimit ? 'extra-item d-none' : '' ?> d-flex flex-column">
                                                    <div class="flex-grow-1">
                                                        <div class="activity-title mb-2"><?= dv($item['nama_barang'] ?? null) ?></div>
                                                        <div class="meta-grid">
                                                            <div class="meta-muted">
                                                                <i class="bi bi-geo-alt me-1"></i>Tujuan: <?= dv($item['nama_branch_tujuan'] ?? 'Pusat HO') ?>
                                                            </div>
                                                            <div class="meta-muted">
                                                                <i class="bi bi-receipt me-1"></i>Resi: <?= dv($item['nomor_resi_keluar'] ?? null) ?>
                                                            </div>
                                                            <div class="meta-muted">
                                                                <i class="bi bi-calendar3 me-1"></i><?= dv($item['tanggal_keluar'] ?? null) ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="pt-2 mt-3 text-start" style="border-top:1px dashed rgba(255,152,0,.2);">
                                                        <?= shippingBadge($item['status_pengiriman'] ?? '-') ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($barangKeluarTerbaru) > $previewLimit): ?>
                                            <div class="section-action">
                                                <button type="button" class="btn-toggle-list" onclick="toggleList('barangKeluarList', this)">
                                                    <i class="bi bi-chevron-down"></i> Lihat selengkapnya
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="empty-state"><i class="bi bi-inbox"></i>Belum ada data barang keluar.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- PANEL 3: BARANG BERMASALAH -->
                        <div class="col-xl-3 col-md-6">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title">
                                        <i class="bi bi-exclamation-triangle me-2 text-warning-custom"></i>Barang Bermasalah
                                    </div>
                                    <div class="panel-subtitle">Butuh perbaikan / rusak</div>
                                </div>
                                <div class="panel-body">
                                    <?php if (!empty($barangBermasalah)): ?>
                                        <div class="activity-list" id="barangBermasalahList">
                                            <?php foreach ($barangBermasalah as $index => $item): ?>
                                                <div class="activity-item <?= $index >= $previewLimit ? 'extra-item d-none' : '' ?> d-flex flex-column">
                                                    <div class="flex-grow-1">
                                                        <div class="activity-title mb-2"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                        <div class="meta-grid">
                                                            <div class="meta-line"><strong>Serial:</strong> <?= h($item['serial_number'] ?? '-') ?></div>
                                                            <div class="meta-muted"><i class="bi bi-geo-alt me-1"></i><?= h($item['nama_branch_aktif'] ?? '-') ?></div>
                                                            <div class="meta-line text-danger line-clamp-2">
                                                                <strong>Kendala:</strong> <?= h($item['keterangan_masalah'] ?? '-') ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="pt-2 mt-3 text-start" style="border-top:1px dashed rgba(255,152,0,.2);">
                                                        <?= barangBadge('Iya') ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($barangBermasalah) > $previewLimit): ?>
                                            <div class="section-action">
                                                <button type="button" class="btn-toggle-list" onclick="toggleList('barangBermasalahList', this)">
                                                    <i class="bi bi-chevron-down"></i> Lihat selengkapnya
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="empty-state"><i class="bi bi-check-circle"></i>Tidak ada barang bermasalah saat ini.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- PANEL 4: BELUM DITERIMA -->
                        <div class="col-xl-3 col-md-6">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title">
                                        <i class="bi bi-truck me-2 text-warning-custom"></i>Belum Diterima
                                    </div>
                                    <div class="panel-subtitle">
                                        <?= $isAdmin ? 'Kiriman HO → Cabang' : 'Kiriman Cabang → HO' ?>
                                    </div>
                                </div>
                                <div class="panel-body">
                                    <?php if (!empty($pengirimanBelumDiterima)): ?>
                                        <div class="activity-list" id="pengirimanBelumDiterimaList">
                                            <?php foreach ($pengirimanBelumDiterima as $index => $item): ?>
                                                <div class="activity-item <?= $index >= $previewLimit ? 'extra-item d-none' : '' ?> d-flex flex-column">
                                                    <div class="flex-grow-1">
                                                        <div class="activity-title mb-2"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                        <div class="meta-grid">
                                                            <div class="meta-muted"><i class="bi bi-geo-alt me-1"></i>Asal: <?= h($item['nama_branch_asal'] ?? '-') ?></div>
                                                            <div class="meta-muted"><i class="bi bi-geo-alt me-1"></i>Tujuan: <?= h($item['nama_branch_tujuan'] ?? '-') ?></div>
                                                            <div class="meta-muted"><i class="bi bi-receipt me-1"></i>Resi: <?= h($item['nomor_resi_keluar'] ?? '-') ?></div>
                                                            <div class="meta-muted"><i class="bi bi-calendar3 me-1"></i><?= h($item['tanggal_keluar'] ?? '-') ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="pt-2 mt-3 text-start" style="border-top:1px dashed rgba(255,152,0,.2);">
                                                        <?= shippingBadge($item['status_pengiriman'] ?? '-') ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($pengirimanBelumDiterima) > $previewLimit): ?>
                                            <div class="section-action">
                                                <button type="button" class="btn-toggle-list" onclick="toggleList('pengirimanBelumDiterimaList', this)">
                                                    <i class="bi bi-chevron-down"></i> Lihat selengkapnya
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="empty-state"><i class="bi bi-check2-all"></i>Tidak ada pengiriman aktif saat ini.</div>
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
                button.innerHTML = '<i class="bi bi-chevron-down"></i> Lihat selengkapnya';
            } else {
                button.setAttribute('data-expanded', 'true');
                button.innerHTML = '<i class="bi bi-chevron-up"></i> Tampilkan lebih sedikit';
            }
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>