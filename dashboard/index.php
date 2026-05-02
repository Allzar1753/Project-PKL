<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'dashboard.view');

$isAdmin = is_admin();
$myBranchId = current_user_branch_id();

if (!$isAdmin && (!$myBranchId || $myBranchId <= 0)) {
    http_response_code(403);
    exit('Branch user belum ditentukan.');
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function shippingBadge(string $status): string
{
    $class = 'bg-secondary';
    $icon  = 'bi-clock';

    $statusLower = strtolower(trim($status));

    if ($statusLower === 'menunggu persetujuan admin' || $statusLower === 'sedang dikemas') {
        $class = 'bg-warning text-dark';
        $icon  = 'bi-hourglass-split';
    } elseif ($statusLower === 'sedang perjalanan') {
        $class = 'bg-primary';
        $icon  = 'bi-truck';
    } elseif ($statusLower === 'sudah diterima' || $statusLower === 'sudah diterima ho') {
        $class = 'bg-success';
        $icon  = 'bi-check-circle';
    }

    return '<span class="badge rounded-pill ' . $class . '"><i class="bi ' . $icon . ' me-1"></i>' . h($status) . '</span>';
}

function barangBadge(string $bermasalah): string
{
    if ($bermasalah === 'Iya') {
        return '<span class="badge rounded-pill bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Bermasalah</span>';
    }
    return '<span class="badge rounded-pill bg-success"><i class="bi bi-check-circle me-1"></i>Normal</span>';
}

function fetchAllAssoc($query): array
{
    $rows =[];
    if ($query) {
        while ($row = mysqli_fetch_assoc($query)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function fetchSingleValue(mysqli $koneksi, string $sql, string $field = 'total'): int
{
    $query = mysqli_query($koneksi, $sql);
    if (!$query) return 0;
    $row = mysqli_fetch_assoc($query) ?: [];
    return (int) ($row[$field] ?? 0);
}

function fetchBranchName(mysqli $koneksi, ?int $branchId): string
{
    $branchId = (int) $branchId;
    if ($branchId <= 0) return '-';
    $stmt = mysqli_prepare($koneksi, "SELECT nama_branch FROM tb_branch WHERE id_branch = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $branchId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result) ?: null;
    mysqli_stmt_close($stmt);
    return $row['nama_branch'] ?? '-';
}

$previewLimit = 3;

// ==============================================================================
// 1. LOGIKA UNTUK ANGKA WIDGET (KOTAK ATAS)
// ==============================================================================
if ($isAdmin) {
    // ADMIN (HO) VIEW
    $totalInventaris = fetchSingleValue($koneksi, "SELECT COUNT(id) AS total FROM barang");
    $totalMasuk = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE status_pengiriman = 'Sudah diterima HO'");
    $totalKeluar = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman");
    $totalBermasalah = fetchSingleValue($koneksi, "SELECT COUNT(id) AS total FROM barang WHERE bermasalah = 'Iya'");
    $totalSedangDikirim = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE status_pengiriman = 'Menunggu persetujuan admin'");
} else {
    // USER (CABANG) VIEW
    $totalInventaris = fetchSingleValue($koneksi, "SELECT COUNT(id) AS total FROM barang WHERE id_branch = " . (int)$myBranchId);
    $totalMasuk = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman WHERE branch_tujuan = " . (int)$myBranchId . " AND status_pengiriman = 'Sudah diterima'");
    $totalKeluar = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman_ho) AS total FROM pengiriman_cabang_ho WHERE branch_asal = " . (int)$myBranchId);
    $totalBermasalah = fetchSingleValue($koneksi, "SELECT COUNT(id) AS total FROM barang WHERE id_branch = " . (int)$myBranchId . " AND bermasalah = 'Iya'");
    $totalSedangDikirim = fetchSingleValue($koneksi, "SELECT COUNT(id_pengiriman) AS total FROM barang_pengiriman WHERE branch_tujuan = " . (int)$myBranchId . " AND status_pengiriman = 'Sedang perjalanan'");
}

// ==============================================================================
// 2. LOGIKA UNTUK DAFTAR AKTIVITAS (CARD DI BAWAH)
// ==============================================================================

// --- KOTAK BARANG MASUK ---
if ($isAdmin) {
    $qBarangMasukTerbaru = mysqli_query($koneksi, "
        SELECT p.id_pengiriman_ho AS id, p.serial_number, p.tanggal_pengajuan AS tanggal_kirim, p.pemilik_barang AS user, 
               tb.nama_barang, asal.nama_branch AS nama_branch_aktif
        FROM pengiriman_cabang_ho p
        LEFT JOIN tb_barang tb ON p.id_barang = tb.id_barang
        LEFT JOIN tb_branch asal ON p.branch_asal = asal.id_branch
        WHERE p.status_pengiriman = 'Sudah diterima HO'
        ORDER BY p.id_pengiriman_ho DESC LIMIT 15
    ");
} else {
    $qBarangMasukTerbaru = mysqli_query($koneksi, "
        SELECT p.id_pengiriman AS id, p.tanggal_keluar AS tanggal_kirim, 
               tb.nama_barang, asal.nama_branch AS nama_branch_aktif, p.nomor_resi_keluar
        FROM barang_pengiriman p
        LEFT JOIN tb_barang tb ON p.id_barang = tb.id_barang
        LEFT JOIN tb_branch asal ON p.branch_asal = asal.id_branch
        WHERE p.branch_tujuan = '{$myBranchId}' AND p.status_pengiriman = 'Sudah diterima'
        ORDER BY p.id_pengiriman DESC LIMIT 15
    ");
}
$barangMasukTerbaru = fetchAllAssoc($qBarangMasukTerbaru);


// --- KOTAK BARANG KELUAR ---
if ($isAdmin) {
    $qBarangKeluarTerbaru = mysqli_query($koneksi, "
        SELECT p.id_pengiriman AS id, p.tanggal_keluar, p.status_pengiriman, p.nomor_resi_keluar, 
               tb.nama_barang, tujuan.nama_branch AS nama_branch_tujuan
        FROM barang_pengiriman p
        LEFT JOIN tb_barang tb ON p.id_barang = tb.id_barang
        LEFT JOIN tb_branch tujuan ON p.branch_tujuan = tujuan.id_branch
        ORDER BY p.id_pengiriman DESC LIMIT 15
    ");
} else {
    $qBarangKeluarTerbaru = mysqli_query($koneksi, "
        SELECT p.id_pengiriman_ho AS id, p.serial_number, p.tanggal_pengajuan AS tanggal_keluar, p.status_pengiriman, p.nomor_resi_keluar, 
               tb.nama_barang, tujuan.nama_branch AS nama_branch_tujuan
        FROM pengiriman_cabang_ho p
        LEFT JOIN tb_barang tb ON p.id_barang = tb.id_barang
        LEFT JOIN tb_branch tujuan ON p.branch_tujuan = tujuan.id_branch
        WHERE p.branch_asal = '{$myBranchId}'
        ORDER BY p.id_pengiriman_ho DESC LIMIT 15
    ");
}
$barangKeluarTerbaru = fetchAllAssoc($qBarangKeluarTerbaru);


// --- KOTAK BARANG BERMASALAH (Logika Sama, Beda WHERE) ---
$whereBermasalah = $isAdmin ? "1=1" : "b.id_branch = '{$myBranchId}'";
$qBarangBermasalah = mysqli_query($koneksi, "
    SELECT b.id, b.no_asset, b.serial_number, b.keterangan_masalah, b.user, 
           tb.nama_barang, m.nama_merk, br.nama_branch AS nama_branch_aktif
    FROM barang b
    LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang
    LEFT JOIN tb_merk m ON b.id_merk = m.id_merk
    LEFT JOIN tb_branch br ON b.id_branch = br.id_branch
    WHERE {$whereBermasalah} AND b.bermasalah = 'Iya'
    ORDER BY b.id DESC LIMIT 15
");
$barangBermasalah = fetchAllAssoc($qBarangBermasalah);


// --- KOTAK BELUM DITERIMA ---
if ($isAdmin) {
    $qPengirimanBelumDiterima = mysqli_query($koneksi, "
        SELECT p.id_pengiriman_ho AS id, p.serial_number, p.tanggal_pengajuan AS tanggal_keluar, p.status_pengiriman, p.nomor_resi_keluar, 
               tb.nama_barang, asal.nama_branch AS nama_branch_asal, tujuan.nama_branch AS nama_branch_tujuan
        FROM pengiriman_cabang_ho p
        LEFT JOIN tb_barang tb ON p.id_barang = tb.id_barang
        LEFT JOIN tb_branch asal ON p.branch_asal = asal.id_branch
        LEFT JOIN tb_branch tujuan ON p.branch_tujuan = tujuan.id_branch
        WHERE p.status_pengiriman = 'Menunggu persetujuan admin'
        ORDER BY p.id_pengiriman_ho DESC LIMIT 15
    ");
} else {
    $qPengirimanBelumDiterima = mysqli_query($koneksi, "
        SELECT p.id_pengiriman AS id, p.tanggal_keluar, p.status_pengiriman, p.nomor_resi_keluar, 
               tb.nama_barang, asal.nama_branch AS nama_branch_asal, tujuan.nama_branch AS nama_branch_tujuan
        FROM barang_pengiriman p
        LEFT JOIN tb_barang tb ON p.id_barang = tb.id_barang
        LEFT JOIN tb_branch asal ON p.branch_asal = asal.id_branch
        LEFT JOIN tb_branch tujuan ON p.branch_tujuan = tujuan.id_branch
        WHERE p.branch_tujuan = '{$myBranchId}' AND p.status_pengiriman = 'Sedang perjalanan'
        ORDER BY p.id_pengiriman DESC LIMIT 15
    ");
}
$pengirimanBelumDiterima = fetchAllAssoc($qPengirimanBelumDiterima);

// Set View Properties
$roleLabel = is_admin() ? 'Administrator' : 'User';
$branchLabel = $isAdmin ? 'Semua Cabang' : fetchBranchName($koneksi, $myBranchId);
$usernameLabel = (string) (current_user()['username'] ?? 'User');
$heroTitle = $isAdmin
    ? 'Dashboard IT Asset Management'
    : ('Selamat datang ' . $usernameLabel . ' dari cabang ' . $branchLabel);

$notifWhere = "target_role = '" . mysqli_real_escape_string($koneksi, (string) current_role()) . "'";
if (!$isAdmin) {
    $notifWhere .= " AND (target_branch_id IS NULL OR target_branch_id = " . (int) $myBranchId . ")";
}
$qNotifications = mysqli_query($koneksi, "
    SELECT id, title, message, link, created_at
    FROM system_notifications
    WHERE {$notifWhere}
      AND is_read = 0
    ORDER BY id DESC
    LIMIT 3
");
$notifications = fetchAllAssoc($qNotifications);
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

        .dashboard-hero {
            position: relative;
            overflow: hidden;
            border: 0;
            border-radius: var(--radius-xl);
            background: linear-gradient(135deg, rgba(17, 17, 17, 0.94) 0%, rgba(42, 42, 42, 0.90) 28%, rgba(255, 122, 0, 0.96) 100%);
            box-shadow: 0 18px 45px rgba(255, 122, 0, 0.22);
            padding: 1.8rem;
            margin-bottom: 1.5rem;
        }

        .dashboard-hero::before {
            content: ""; position: absolute; width: 280px; height: 280px; border-radius: 50%;
            background: rgba(255, 255, 255, 0.08); top: -100px; right: -60px;
        }

        .dashboard-hero::after {
            content: ""; position: absolute; width: 180px; height: 180px; border-radius: 50%;
            background: rgba(255, 208, 102, 0.18); bottom: -70px; left: -50px;
        }

        .dashboard-hero h1 { position: relative; z-index: 2; font-size: 1.85rem; font-weight: 800; color: #fff; margin-bottom: .45rem; letter-spacing: -0.02em; }
        .dashboard-hero p { position: relative; z-index: 2; color: rgba(255, 255, 255, 0.86); margin-bottom: 0; max-width: 760px; line-height: 1.7; font-size: .95rem; }

        .role-badge {
            position: relative; z-index: 2; background: rgba(255, 255, 255, 0.14); color: #fff; border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 999px; padding: .65rem 1rem; font-weight: 700; font-size: .86rem; white-space: nowrap; backdrop-filter: blur(10px);
        }

        .summary-card {
            position: relative; overflow: hidden; background: linear-gradient(180deg, #ffffff 0%, #fffaf3 100%); border: 1px solid rgba(255, 176, 0, 0.15);
            border-radius: 22px; box-shadow: var(--shadow-soft); height: 100%; padding: 1.15rem 1.1rem; transition: all .25s ease;
        }

        .summary-card::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 6px; background: linear-gradient(90deg, var(--orange-1), var(--orange-3)); }
        .summary-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }

        .summary-label { font-size: .84rem; color: var(--text-soft); margin-bottom: .45rem; font-weight: 600; }
        .summary-value { font-size: 2rem; line-height: 1; font-weight: 800; margin-bottom: .45rem; color: var(--dark-1); letter-spacing: -0.03em; }
        .summary-note { font-size: .83rem; color: #7b7b7b; line-height: 1.5; }
        .summary-icon { width: 54px; height: 54px; border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.28rem; flex-shrink: 0; color: #fff !important; background: linear-gradient(135deg, var(--orange-1), var(--orange-3)) !important; }

        .panel-card { overflow: hidden; background: #ffffff; border: 1px solid rgba(255, 176, 0, 0.13); border-radius: var(--radius-xl); box-shadow: var(--shadow-soft); height: 100%; display: flex; flex-direction: column; }
        .panel-header { padding: 1.15rem 1.25rem; border-bottom: 1px solid rgba(255, 176, 0, 0.16); background: linear-gradient(135deg, #111111 0%, #2c2c2c 40%, #ff8f00 100%); position: relative; overflow: hidden; }
        .panel-title { position: relative; z-index: 1; font-size: 1.02rem; font-weight: 800; margin-bottom: .22rem; color: #fff; letter-spacing: -0.02em; }
        .panel-subtitle { position: relative; z-index: 1; font-size: .84rem; color: rgba(255, 255, 255, 0.80); line-height: 1.5; }
        .panel-body { padding: 1.15rem 1.2rem; background: linear-gradient(180deg, #fffdf9 0%, #fff8ef 100%); flex: 1; display: flex; flex-direction: column; }

        .activity-list { display: flex; flex-direction: column; gap: .9rem; }
        .activity-item { position: relative; border: 1px solid rgba(255, 176, 0, 0.14); border-left: 5px solid var(--orange-2); border-radius: 18px; padding: 1rem; background: #ffffff; box-shadow: 0 8px 20px rgba(17, 17, 17, 0.04); transition: all .22s ease; }
        .activity-item:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(255, 122, 0, 0.14); border-color: rgba(255, 152, 0, 0.28); }
        .activity-title { font-weight: 800; margin-bottom: .38rem; color: var(--dark-1); letter-spacing: -0.01em; }
        
        .meta-grid { display: grid; gap: .28rem; margin-top: .6rem; }
        .meta-line { font-size: .88rem; color: #4b5563; line-height: 1.5; }
        .meta-line strong { color: #111827; font-weight: 700; }
        .meta-muted { font-size: .84rem; color: #6b7280; line-height: 1.5; }
        .meta-muted i { width: 16px; color: var(--orange-2); }

        .empty-state { text-align: center; color: var(--text-soft); padding: 1.8rem 1rem; border: 1px dashed rgba(255, 152, 0, 0.28); border-radius: 18px; background: linear-gradient(180deg, #fffaf2 0%, #fff5e8 100%); }
        .empty-state i { display: block; font-size: 1.9rem; margin-bottom: .5rem; color: var(--orange-2); }
        .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

        .section-action { margin-top: 1rem; text-align: center; }
        .btn-toggle-list { border: none; border-radius: 999px; padding: .7rem 1.1rem; font-size: .85rem; font-weight: 700; background: linear-gradient(135deg, #111111 0%, #ff8f00 100%); color: #fff; box-shadow: 0 10px 24px rgba(255, 143, 0, 0.18); transition: all .25s ease; }
        .btn-toggle-list:hover { transform: translateY(-2px); box-shadow: 0 14px 28px rgba(255, 143, 0, 0.25); }

        .extra-item.d-none { display: none !important; }
        .badge.rounded-pill { padding: .5rem .8rem; font-size: .75rem; font-weight: 700; letter-spacing: .1px; }

        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #ff9800, #ffb000); border-radius: 999px; }
        ::-webkit-scrollbar-track { background: #fff4e3; }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <?php require_once '../layout/sidebar.php'; ?>

            <div class="col-md-10 ms-auto">
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
                                    $notifId = (int) ($notif['id'] ?? 0);
                                    $notifLink = trim((string) ($notif['link'] ?? ''));
                                    $notifReadUrl = '../dashboard/notification_read.php?id=' . $notifId . '&redirect=' . urlencode($notifLink !== '' ? $notifLink : '../dashboard/index.php');
                                ?>
                                <div class="mb-2">
                                    <div class="fw-semibold"><?= h($notif['title'] ?? '-') ?></div>
                                    <div class="small"><?= h($notif['message'] ?? '-') ?></div>
                                    <a href="<?= h($notifReadUrl) ?>" class="small">Buka detail</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Widget Top Boxes -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-sm-6 col-xl">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Total Inventaris</div>
                                        <div class="summary-value"><?= $totalInventaris ?></div>
                                        <div class="summary-note">Aset aktif yang tercatat</div>
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
                                        <div class="summary-note">Barang masuk terbaru</div>
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
                                        <div class="summary-note">Pengiriman barang aktif</div>
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
                                    <div class="summary-icon bg-danger"><i class="bi bi-exclamation-triangle"></i></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Belum Diterima</div>
                                        <div class="summary-value"><?= $totalSedangDikirim ?></div>
                                        <div class="summary-note">Masih proses pengiriman</div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-truck"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Panel Detail -->
                    <div class="row g-4">
                        <!-- BARANG MASUK -->
                        <div class="col-xl-3 col-md-6">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title"><i class="bi bi-box-arrow-in-down me-2 text-warning-custom"></i>Barang Masuk</div>
                                    <div class="panel-subtitle">Histori barang diterima</div>
                                </div>
                                <div class="panel-body">
                                    <?php if (!empty($barangMasukTerbaru)): ?>
                                        <div class="activity-list" id="barangMasukList">
                                            <?php foreach ($barangMasukTerbaru as $index => $item): ?>
                                                <div class="activity-item <?= $index >= $previewLimit ? 'extra-item d-none' : '' ?>">
                                                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                                        <div class="flex-grow-1">
                                                            <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                            <div class="meta-grid">
                                                                <?php if(isset($item['serial_number'])): ?>
                                                                    <div class="meta-line"><strong>Serial:</strong> <?= h($item['serial_number']) ?></div>
                                                                <?php endif; ?>
                                                                <div class="meta-muted"><i class="bi bi-geo-alt me-1"></i>Asal: <?= h($item['nama_branch_aktif'] ?? '-') ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="text-end">
                                                            <span class="badge rounded-pill bg-success">Masuk</span>
                                                            <div class="meta-muted mt-2"><?= h($item['tanggal_kirim'] ?? '-') ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($barangMasukTerbaru) > $previewLimit): ?>
                                            <div class="section-action">
                                                <button type="button" class="btn-toggle-list" onclick="toggleList('barangMasukList', this)"><i class="bi bi-chevron-down"></i>Lihat selengkapnya</button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="empty-state"><i class="bi bi-inbox"></i>Belum ada data barang masuk.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- BARANG KELUAR -->
                        <div class="col-xl-3 col-md-6">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title"><i class="bi bi-box-arrow-up me-2 text-warning-custom"></i>Barang Keluar</div>
                                    <div class="panel-subtitle">Histori barang dikirim</div>
                                </div>
                                <div class="panel-body">
                                    <?php if (!empty($barangKeluarTerbaru)): ?>
                                        <div class="activity-list" id="barangKeluarList">
                                            <?php foreach ($barangKeluarTerbaru as $index => $item): ?>
                                                <div class="activity-item <?= $index >= $previewLimit ? 'extra-item d-none' : '' ?>">
                                                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                                        <div class="flex-grow-1">
                                                            <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                            <div class="meta-grid">
                                                                <div class="meta-muted"><i class="bi bi-geo-alt me-1"></i>Tujuan: <?= h($item['nama_branch_tujuan'] ?? 'Pusat HO') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-receipt me-1"></i>Resi: <?= h($item['nomor_resi_keluar'] ?? '-') ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="text-end">
                                                            <div><?= shippingBadge($item['status_pengiriman'] ?? '-') ?></div>
                                                            <div class="meta-muted mt-2"><?= h($item['tanggal_keluar'] ?? '-') ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($barangKeluarTerbaru) > $previewLimit): ?>
                                            <div class="section-action">
                                                <button type="button" class="btn-toggle-list" onclick="toggleList('barangKeluarList', this)"><i class="bi bi-chevron-down"></i>Lihat selengkapnya</button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="empty-state"><i class="bi bi-inbox"></i>Belum ada data barang keluar.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- BARANG BERMASALAH -->
                        <div class="col-xl-3 col-md-6">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title"><i class="bi bi-exclamation-triangle me-2 text-warning-custom"></i>Barang Bermasalah</div>
                                    <div class="panel-subtitle">Butuh perbaikan / rusak</div>
                                </div>
                                <div class="panel-body">
                                    <?php if (!empty($barangBermasalah)): ?>
                                        <div class="activity-list" id="barangBermasalahList">
                                            <?php foreach ($barangBermasalah as $index => $item): ?>
                                                <div class="activity-item <?= $index >= $previewLimit ? 'extra-item d-none' : '' ?>">
                                                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                                        <div class="flex-grow-1">
                                                            <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                            <div class="meta-grid">
                                                                <div class="meta-line"><strong>Asset:</strong> <?= h($item['no_asset'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-geo-alt me-1"></i><?= h($item['nama_branch_aktif'] ?? '-') ?></div>
                                                                <div class="meta-line text-danger line-clamp-2"><strong>Kendala:</strong> <?= h($item['keterangan_masalah'] ?? '-') ?></div>
                                                            </div>
                                                        </div>
                                                        <div><?= barangBadge('Iya') ?></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($barangBermasalah) > $previewLimit): ?>
                                            <div class="section-action">
                                                <button type="button" class="btn-toggle-list" onclick="toggleList('barangBermasalahList', this)"><i class="bi bi-chevron-down"></i>Lihat selengkapnya</button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="empty-state"><i class="bi bi-check-circle"></i>Tidak ada barang bermasalah saat ini.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- BELUM DITERIMA -->
                        <div class="col-xl-3 col-md-6">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title"><i class="bi bi-truck me-2 text-warning-custom"></i>Belum Diterima</div>
                                    <div class="panel-subtitle">Dalam proses perjalanan</div>
                                </div>
                                <div class="panel-body">
                                    <?php if (!empty($pengirimanBelumDiterima)): ?>
                                        <div class="activity-list" id="pengirimanBelumDiterimaList">
                                            <?php foreach ($pengirimanBelumDiterima as $index => $item): ?>
                                                <div class="activity-item <?= $index >= $previewLimit ? 'extra-item d-none' : '' ?>">
                                                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                                        <div class="flex-grow-1">
                                                            <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                            <div class="meta-grid">
                                                                <div class="meta-muted"><i class="bi bi-geo-alt me-1"></i>Asal: <?= h($item['nama_branch_asal'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-geo-alt me-1"></i>Tujuan: <?= h($item['nama_branch_tujuan'] ?? 'Pusat HO') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-receipt me-1"></i>Resi: <?= h($item['nomor_resi_keluar'] ?? '-') ?></div>
                                                            </div>
                                                        </div>
                                                        <div class="text-end">
                                                            <div><?= shippingBadge($item['status_pengiriman'] ?? '-') ?></div>
                                                            <div class="meta-muted mt-2"><?= h($item['tanggal_keluar'] ?? '-') ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($pengirimanBelumDiterima) > $previewLimit): ?>
                                            <div class="section-action">
                                                <button type="button" class="btn-toggle-list" onclick="toggleList('pengirimanBelumDiterimaList', this)"><i class="bi bi-chevron-down"></i>Lihat selengkapnya</button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="empty-state"><i class="bi bi-check2-all"></i>Tidak ada pengiriman aktif saat ini.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleList(listId, button) {
            const list = document.getElementById(listId);
            const hiddenItems = list.querySelectorAll('.extra-item');
            const isExpanded = button.getAttribute('data-expanded') === 'true';

            hiddenItems.forEach(item => {
                if (isExpanded) {
                    item.classList.add('d-none');
                } else {
                    item.classList.remove('d-none');
                }
            });

            if (isExpanded) {
                button.setAttribute('data-expanded', 'false');
                button.innerHTML = '<i class="bi bi-chevron-down"></i>Lihat selengkapnya';
            } else {
                button.setAttribute('data-expanded', 'true');
                button.innerHTML = '<i class="bi bi-chevron-up"></i>Tampilkan lebih sedikit';
            }
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>