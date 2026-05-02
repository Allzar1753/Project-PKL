<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'riwayat.view');

$isAdmin = is_admin();
$myBranchId = (int) (current_user_branch_id() ?? 0);

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function current_page_url(): string
{
    return basename($_SERVER['PHP_SELF']);
}

function build_url(array $params =[]): string
{
    $base = current_page_url();
    if (empty($params)) return $base;
    return $base . '?' . http_build_query($params);
}

function activityBadge($jenis)
{
    if ($jenis === 'Keluar') {
        return '<span class="badge rounded-pill bg-danger"><i class="bi bi-box-arrow-up me-1"></i>Keluar</span>';
    }
    return '<span class="badge rounded-pill bg-success"><i class="bi bi-box-arrow-in-down me-1"></i>Masuk</span>';
}

function shippingBadge($status)
{
    $class = 'bg-secondary';
    $icon  = 'bi-dash-circle';

    if ($status === 'Sedang dikemas') {
        $class = 'bg-warning text-dark';
        $icon  = 'bi-box-seam';
    } elseif ($status === 'Sedang perjalanan') {
        $class = 'bg-primary';
        $icon  = 'bi-truck';
    } elseif ($status === 'Sudah diterima') {
        $class = 'bg-success';
        $icon  = 'bi-check-circle';
    } elseif ($status === 'Belum dikirim') {
        $class = 'bg-secondary';
        $icon  = 'bi-clock-history';
    }

    return '<span class="badge rounded-pill ' . $class . '"><i class="bi ' . $icon . ' me-1"></i>' . h($status) . '</span>';
}

$search_input = isset($_GET['cari']) ? trim((string) $_GET['cari']) : '';
$filter       = isset($_GET['filter']) ? trim((string) $_GET['filter']) : 'semua';
$tanggalAwal  = isset($_GET['tanggal_awal']) ? trim((string) $_GET['tanggal_awal']) : '';
$tanggalAkhir = isset($_GET['tanggal_akhir']) ? trim((string) $_GET['tanggal_akhir']) : '';

if (!in_array($filter, ['semua', 'masuk', 'keluar'], true)) {
    $filter = 'semua';
}

$allowed_limits =[5, 25, 50, 100];
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
if (!in_array($limit, $allowed_limits, true)) $limit = 25;

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

/*
|--------------------------------------------------------------------------
| LOGIKA SQL RIWAYAT (SUDAH DIPERBAIKI UNTUK CABANG)
|--------------------------------------------------------------------------
*/
$activityDateExpr = "
CASE
    WHEN bp.id_pengiriman IS NULL THEN DATE(b.tanggal_kirim)
    ELSE DATE(bp.tanggal_keluar)
END
";

$baseFrom = "
FROM barang b
LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang
LEFT JOIN tb_merk bm ON b.id_merk = bm.id_merk
LEFT JOIN tb_status st ON b.id_status = st.id_status
LEFT JOIN tb_branch ba ON b.id_branch = ba.id_branch
LEFT JOIN (
    SELECT bp1.*
    FROM barang_pengiriman bp1
    INNER JOIN (
        SELECT id_barang, MAX(id_pengiriman) AS id_terakhir
        FROM barang_pengiriman
        GROUP BY id_barang
    ) x ON x.id_terakhir = bp1.id_pengiriman
) bp ON bp.id_barang = b.id
LEFT JOIN tb_branch bt ON bt.id_branch = bp.branch_tujuan
LEFT JOIN tb_branch bas ON bas.id_branch = bp.branch_asal
";

// 1. FILTER CABANG DASAR (Base Where)
$baseWhere =[];
if (!$isAdmin) {
    if ($myBranchId <= 0) {
        http_response_code(403);
        exit('Branch user belum ditentukan.');
    }
    // HANYA riwayat yang melibatkan cabang tersebut (pemilik asli, pengirim, atau penerima)
    $baseWhere[] = "(b.id_branch = {$myBranchId} OR bp.branch_asal = {$myBranchId} OR bp.branch_tujuan = {$myBranchId})";
}

$where = $baseWhere; // Kondisi query utama

// 2. Filter Teks
if ($search_input !== '') {
    $search = mysqli_real_escape_string($koneksi, $search_input);
    $where[] = "(
        b.no_asset LIKE '%$search%' OR b.serial_number LIKE '%$search%' OR b.user LIKE '%$search%'
        OR tb.nama_barang LIKE '%$search%' OR bm.nama_merk LIKE '%$search%' OR st.nama_status LIKE '%$search%'
        OR ba.nama_branch LIKE '%$search%' OR bas.nama_branch LIKE '%$search%' OR bt.nama_branch LIKE '%$search%'
        OR bp.status_pengiriman LIKE '%$search%' OR bp.nomor_resi_keluar LIKE '%$search%'
        OR b.keterangan_masalah LIKE '%$search%' OR bp.nama_penerima LIKE '%$search%' OR bp.jasa_pengiriman LIKE '%$search%'
    )";
}

// 3. Filter Status (Masuk/Keluar)
if ($filter === 'masuk') {
    if (!$isAdmin) {
        // Cabang: Dihitung Masuk jika barang inputan murni ATAU cabang adalah tujuan pengiriman
        $where[] = "(bp.id_pengiriman IS NULL OR bp.branch_tujuan = {$myBranchId})";
    } else {
        $where[] = "(bp.id_pengiriman IS NULL)";
    }
} elseif ($filter === 'keluar') {
    if (!$isAdmin) {
        // Cabang: Dihitung Keluar jika cabang yang mengirim barang
        $where[] = "(bp.branch_asal = {$myBranchId})";
    } else {
        $where[] = "(bp.id_pengiriman IS NOT NULL)";
    }
}

// 4. Filter Tanggal
if ($tanggalAwal !== '' && $tanggalAkhir !== '') {
    $safeAwal  = mysqli_real_escape_string($koneksi, $tanggalAwal);
    $safeAkhir = mysqli_real_escape_string($koneksi, $tanggalAkhir);
    $where[] = "$activityDateExpr BETWEEN '$safeAwal' AND '$safeAkhir'";
} elseif ($tanggalAwal !== '') {
    $safeAwal = mysqli_real_escape_string($koneksi, $tanggalAwal);
    $where[] = "$activityDateExpr >= '$safeAwal'";
} elseif ($tanggalAkhir !== '') {
    $safeAkhir = mysqli_real_escape_string($koneksi, $tanggalAkhir);
    $where[] = "$activityDateExpr <= '$safeAkhir'";
}

$whereSql = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// 5. Query Pagination
$countQuery = mysqli_query($koneksi, "SELECT COUNT(DISTINCT b.id) AS total $baseFrom $whereSql");
if (!$countQuery) die(mysqli_error($koneksi));
$total_rows  = (int) (mysqli_fetch_assoc($countQuery)['total'] ?? 0);
$total_pages = max(1, (int) ceil($total_rows / $limit));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;

/*
|--------------------------------------------------------------------------
| LOGIKA ANGKA KARTU (HANYA MILIK CABANG YBS & TIDAK TERPENGARUH PENCARIAN)
|--------------------------------------------------------------------------
*/
$baseWhereStr = count($baseWhere) ? 'WHERE ' . implode(' AND ', $baseWhere) : '';

// Total Semua
$totalSemuaQuery = mysqli_query($koneksi, "SELECT COUNT(DISTINCT b.id) AS total $baseFrom $baseWhereStr");
$totalSemua = (int) (mysqli_fetch_assoc($totalSemuaQuery)['total'] ?? 0);

// Total Masuk
$masukArr = $baseWhere;
if (!$isAdmin) {
    $masukArr[] = "(bp.id_pengiriman IS NULL OR bp.branch_tujuan = {$myBranchId})";
} else {
    $masukArr[] = "bp.id_pengiriman IS NULL";
}
$masukStr = count($masukArr) ? 'WHERE ' . implode(' AND ', $masukArr) : '';
$totalMasukQuery = mysqli_query($koneksi, "SELECT COUNT(DISTINCT b.id) AS total $baseFrom $masukStr");
$totalMasuk = (int) (mysqli_fetch_assoc($totalMasukQuery)['total'] ?? 0);

// Total Keluar
$keluarArr = $baseWhere;
if (!$isAdmin) {
    $keluarArr[] = "(bp.branch_asal = {$myBranchId})";
} else {
    $keluarArr[] = "bp.id_pengiriman IS NOT NULL";
}
$keluarStr = count($keluarArr) ? 'WHERE ' . implode(' AND ', $keluarArr) : '';
$totalKeluarQuery = mysqli_query($koneksi, "SELECT COUNT(DISTINCT b.id) AS total $baseFrom $keluarStr");
$totalKeluar = (int) (mysqli_fetch_assoc($totalKeluarQuery)['total'] ?? 0);

// Eksekusi Data Utama
$query = mysqli_query($koneksi, "
    SELECT DISTINCT
        b.id, b.no_asset, b.serial_number, b.tanggal_kirim, b.bermasalah, b.keterangan_masalah, b.user, b.foto,
        tb.nama_barang, bm.nama_merk, st.nama_status, ba.nama_branch AS branch_aktif,
        bas.id_branch AS id_branch_asal, bas.nama_branch AS branch_asal_pengiriman,
        bt.id_branch AS id_branch_tujuan, bt.nama_branch AS branch_tujuan,
        bp.id_pengiriman, bp.tanggal_keluar, bp.tanggal_diterima, bp.status_pengiriman, bp.nomor_resi_keluar, bp.nama_penerima, bp.jasa_pengiriman,
        CASE
            WHEN bp.id_pengiriman IS NULL THEN DATE(b.tanggal_kirim)
            ELSE DATE(bp.tanggal_keluar)
        END AS tanggal_aktivitas
    $baseFrom
    $whereSql
    ORDER BY tanggal_aktivitas DESC, b.id DESC
    LIMIT $offset, $limit
");

if (!$query) die(mysqli_error($koneksi));

$from_row = $total_rows > 0 ? $offset + 1 : 0;
$to_row   = min($offset + $limit, $total_rows);

$btnSemua  = $filter === 'semua' ? 'btn-mode is-active' : 'btn-mode';
$btnMasuk  = $filter === 'masuk' ? 'btn-mode is-active' : 'btn-mode';
$btnKeluar = $filter === 'keluar' ? 'btn-mode is-active' : 'btn-mode';

$tableTitle = 'Riwayat Semua Asset';
$tableIcon  = 'bi bi-clock-history';
if ($filter === 'masuk') {
    $tableTitle = 'Riwayat Asset Masuk';
    $tableIcon  = 'bi bi-box-arrow-in-down';
} elseif ($filter === 'keluar') {
    $tableTitle = 'Riwayat Asset Keluar';
    $tableIcon  = 'bi bi-box-arrow-up';
}

$hasFilter = ($search_input !== '' || $tanggalAwal !== '' || $tanggalAkhir !== '' || $filter !== 'semua');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Asset - IT Asset Management</title>
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
            --dark-1: #111111;
            --dark-2: #1f1f1f;
            --text-main: #1e1e1e;
            --text-soft: #6b7280;
            --surface: #ffffff;
            --border-soft: rgba(255, 152, 0, 0.14);
            --shadow-soft: 0 12px 36px rgba(17, 17, 17, 0.07);
            --shadow-hover: 0 18px 42px rgba(255, 122, 0, 0.14);
            --radius-xl: 28px;
        }

        * { box-sizing: border-box; }
        body { background: radial-gradient(circle at top left, rgba(255, 176, 0, 0.16), transparent 28%), radial-gradient(circle at bottom right, rgba(255, 122, 0, 0.10), transparent 20%), linear-gradient(180deg, #fff8f1 0%, #fffaf5 36%, #ffffff 100%); font-family: 'Plus Jakarta Sans', sans-serif; color: var(--text-main); }
        .page-shell { padding: 28px; }
        .page-hero { position: relative; overflow: hidden; border-radius: var(--radius-xl); background: linear-gradient(135deg, rgba(17, 17, 17, 0.94) 0%, rgba(42, 42, 42, 0.90) 30%, rgba(255, 122, 0, 0.96) 100%); box-shadow: 0 18px 45px rgba(255, 122, 0, 0.20); padding: 1.6rem; margin-bottom: 1.4rem; }
        .page-hero::before { content: ""; position: absolute; width: 240px; height: 240px; border-radius: 50%; background: rgba(255, 255, 255, 0.08); top: -90px; right: -60px; }
        .page-hero::after { content: ""; position: absolute; width: 180px; height: 180px; border-radius: 50%; background: rgba(255, 209, 102, 0.18); left: -60px; bottom: -70px; }
        .page-hero .hero-content { position: relative; z-index: 2; }
        .page-title { color: #fff; font-size: 1.8rem; font-weight: 800; margin-bottom: .35rem; letter-spacing: -0.02em; }
        .page-desc { color: rgba(255, 255, 255, 0.84); margin-bottom: 0; line-height: 1.7; max-width: 780px; font-size: .94rem; }

        .ui-card { background: var(--surface); border: 1px solid var(--border-soft); border-radius: 22px; box-shadow: var(--shadow-soft); }
        .toolbar-card { padding: 1.15rem; margin-bottom: 1.25rem; }
        .toolbar-label { font-size: .84rem; font-weight: 700; color: var(--dark-2); margin-bottom: .55rem; }
        .form-control, .form-select { border-radius: 16px; border: 1px solid #e6dfd2; box-shadow: none; padding: .9rem 1rem; font-size: .94rem; }
        .form-control:focus, .form-select:focus { border-color: #f0c63d; box-shadow: 0 0 0 .2rem rgba(255, 193, 7, 0.14); }
        .search-inline { display: flex; gap: .75rem; align-items: stretch; }
        .search-inline .search-input-wrap { flex: 1; }
        .filter-actions { display: flex; gap: .65rem; align-items: stretch; }
        .search-btn { border: none; background: linear-gradient(135deg, var(--orange-1), var(--orange-3)); color: #fff; font-weight: 700; padding: 0 1.15rem; border-radius: 16px; min-height: 50px; }
        .search-btn:hover { color: #fff; filter: brightness(.98); }
        .reset-btn { border: none; background: #1f1f1f; color: #fff; font-weight: 700; padding: 0 1rem; border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; min-height: 50px; }
        .reset-btn:hover { color: #fff; background: #111; }
        .mode-switch { display: flex; flex-wrap: wrap; gap: .6rem; }
        .btn-mode { display: inline-flex; align-items: center; gap: .45rem; padding: .72rem 1rem; border-radius: 999px; border: 1px solid rgba(255, 152, 0, 0.18); background: #fff; color: #3d3d3d; font-weight: 700; text-decoration: none; transition: all .2s ease; box-shadow: 0 8px 20px rgba(17, 17, 17, 0.04); }
        .btn-mode:hover { transform: translateY(-1px); color: #111; background: #fff7ea; }
        .btn-mode.is-active { background: linear-gradient(135deg, #111111, #ff8f00); color: #fff; border-color: transparent; }

        .active-filter-bar { margin-top: 1rem; padding: .95rem 1rem; border-radius: 18px; background: linear-gradient(180deg, #fffaf3 0%, #fff6ea 100%); border: 1px solid rgba(255, 152, 0, 0.12); }
        .active-filter-title { font-size: .82rem; font-weight: 800; color: #8b4f00; margin-bottom: .65rem; }
        .filter-chip-wrap { display: flex; flex-wrap: wrap; gap: .55rem; }
        .filter-chip { display: inline-flex; align-items: center; gap: .4rem; padding: .45rem .8rem; border-radius: 999px; background: #fff; border: 1px solid rgba(255, 152, 0, 0.16); color: #6b4a00; font-size: .82rem; font-weight: 700; }

        .summary-card { position: relative; overflow: hidden; border-radius: 22px; background: linear-gradient(180deg, #ffffff 0%, #fffaf3 100%); border: 1px solid rgba(255, 176, 0, 0.15); box-shadow: var(--shadow-soft); height: 100%; padding: 1.15rem; transition: all .25s ease; }
        .summary-card::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 5px; background: linear-gradient(90deg, var(--orange-1), var(--orange-3)); }
        .summary-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-hover); }
        .summary-label { color: var(--text-soft); font-size: .84rem; font-weight: 700; margin-bottom: .4rem; }
        .summary-value { font-size: 2rem; line-height: 1; font-weight: 800; margin-bottom: .35rem; color: var(--dark-1); }
        .summary-note { color: var(--text-soft); font-size: .82rem; }
        .summary-icon { width: 52px; height: 52px; border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; color: #fff; background: linear-gradient(135deg, var(--orange-1), var(--orange-3)); box-shadow: 0 10px 24px rgba(255, 152, 0, 0.2); }

        .table-card { overflow: hidden; }
        .table-card .card-header { border: none; padding: 1.1rem 1.2rem; background: linear-gradient(135deg, #111111 0%, #2c2c2c 40%, #ff8f00 100%); color: #fff; }
        .table-title { font-size: 1rem; font-weight: 800; margin-bottom: .2rem; }
        .table-subinfo { font-size: .84rem; color: rgba(255, 255, 255, 0.82); }
        .limit-box { display: flex; align-items: center; gap: .6rem; background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.16); border-radius: 999px; padding: .45rem .55rem .45rem .85rem; }
        .limit-box .form-select { min-width: 86px; border-radius: 999px; border: none; box-shadow: none; font-weight: 700; }
        .limit-box .section-label { color: rgba(255, 255, 255, 0.88); font-size: .82rem; font-weight: 700; margin: 0; }
        .table-responsive { background: #fff; }
        .table { margin-bottom: 0; }
        .table thead th { background: #fffaf2; color: #2c2c2c; font-weight: 800; border-bottom: 1px solid #f0dfc3; white-space: nowrap; padding-top: 1rem; padding-bottom: 1rem; }
        .table tbody tr { transition: all .18s ease; }
        .table tbody tr:hover { background: #fffaf4; }
        .table tbody td { vertical-align: top; padding-top: 1rem; padding-bottom: 1rem; border-color: #f2ede5; }
        .asset-code { font-weight: 800; color: var(--dark-1); }
        .meta-line { display: block; font-size: .9rem; line-height: 1.5; color: #222; }
        .meta-muted { display: block; font-size: .88rem; color: var(--text-soft); line-height: 1.5; }
        .meta-muted i, .meta-line i { color: #d98710; }
        .badge.rounded-pill { padding: .5rem .78rem; font-size: .74rem; font-weight: 700; letter-spacing: .1px; }
        .badge.rounded-pill.bg-success { background: linear-gradient(135deg, #111111, #2e2e2e) !important; color: #fff !important; border: none !important; }
        .badge.rounded-pill.bg-danger { background: linear-gradient(135deg, #ff7a00, #ff9f1a) !important; color: #fff !important; border: none !important; }
        .badge.rounded-pill.bg-primary { background: linear-gradient(135deg, #ff8c00, #ffb000) !important; color: #fff !important; border: none !important; }
        .badge.rounded-pill.bg-secondary { background: #fff7ea !important; color: #6b4a00 !important; border: 1px solid rgba(255, 176, 0, 0.25) !important; }
        .badge.rounded-pill.bg-warning.text-dark { background: linear-gradient(135deg, #ffd166, #ffbf47) !important; color: #5b3a00 !important; border: none !important; }
        .pagination { gap: .35rem; }
        .pagination .page-link { border-radius: 12px; color: #2a2a2a; border: 1px solid #ead9bf; padding: .6rem .85rem; font-weight: 700; box-shadow: none; margin: 0 2px;}
        .pagination .page-link:hover { background: #fff4de; color: #111; }
        .pagination .page-item.active .page-link { background: linear-gradient(135deg, #111111, #ff8f00); border-color: transparent; color: #fff; }
        .empty-state { text-align: center; color: var(--text-soft); padding: 2.2rem 1rem !important; }
        .empty-state i { display: block; font-size: 1.8rem; margin-bottom: .55rem; color: var(--orange-2); }

        @media (max-width: 991.98px) { .page-shell { padding: 18px; } .page-hero { padding: 1.3rem 1.2rem; } .page-title { font-size: 1.4rem; } .toolbar-card { padding: 1rem; } }
        @media (max-width: 767.98px) { .search-inline, .filter-actions { flex-direction: column; } }
        @media (max-width: 575.98px) { .page-title { font-size: 1.2rem; } .page-desc { font-size: .9rem; } .limit-box { width: 100%; justify-content: space-between; } .btn-mode { width: 100%; justify-content: center; } }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <?php include '../layout/sidebar.php'; ?>

            <div class="col-md-10">
                <div class="page-shell">

                    <div class="page-hero">
                        <div class="hero-content">
                            <h1 class="page-title">Riwayat Aktivitas Asset</h1>
                            <p class="page-desc">
                                Pantau riwayat perpindahan, status masuk, status keluar, dan aktivitas asset
                                secara akurat dan khusus untuk cabang Anda.
                            </p>
                        </div>
                    </div>

                    <div class="ui-card toolbar-card">
                        <div class="mb-4">
                            <label class="toolbar-label">Mode Tampilan</label>
                            <div class="mode-switch">
                                <a href="<?= build_url(['filter' => 'semua', 'cari' => $search_input, 'tanggal_awal' => $tanggalAwal, 'tanggal_akhir' => $tanggalAkhir, 'limit' => $limit]) ?>" class="<?= $btnSemua ?>">
                                    <i class="bi bi-clock-history"></i>Semua Riwayat
                                </a>
                                <a href="<?= build_url(['filter' => 'masuk', 'cari' => $search_input, 'tanggal_awal' => $tanggalAwal, 'tanggal_akhir' => $tanggalAkhir, 'limit' => $limit]) ?>" class="<?= $btnMasuk ?>">
                                    <i class="bi bi-box-arrow-in-down"></i>Riwayat Masuk
                                </a>
                                <a href="<?= build_url(['filter' => 'keluar', 'cari' => $search_input, 'tanggal_awal' => $tanggalAwal, 'tanggal_akhir' => $tanggalAkhir, 'limit' => $limit]) ?>" class="<?= $btnKeluar ?>">
                                    <i class="bi bi-box-arrow-up"></i>Riwayat Keluar
                                </a>
                            </div>
                        </div>

                        <form method="GET">
                            <input type="hidden" name="filter" value="<?= h($filter) ?>">
                            <input type="hidden" name="limit" value="<?= h((string) $limit) ?>">

                            <div class="row g-3 align-items-end">
                                <div class="col-lg-5">
                                    <label class="toolbar-label">Pencarian Riwayat</label>
                                    <div class="search-inline">
                                        <div class="search-input-wrap">
                                            <input type="text" name="cari" class="form-control" placeholder="Cari asset, serial number, resi..." value="<?= h($search_input) ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-2">
                                    <label class="toolbar-label">Tanggal Awal</label>
                                    <input type="date" name="tanggal_awal" class="form-control" value="<?= h($tanggalAwal) ?>">
                                </div>
                                <div class="col-lg-2">
                                    <label class="toolbar-label">Tanggal Akhir</label>
                                    <input type="date" name="tanggal_akhir" class="form-control" value="<?= h($tanggalAkhir) ?>">
                                </div>
                                <div class="col-lg-3">
                                    <div class="filter-actions">
                                        <button type="submit" class="btn search-btn w-100"><i class="bi bi-funnel me-2"></i>Terapkan</button>
                                        <a href="<?= h(build_url()) ?>" class="reset-btn w-100"><i class="bi bi-arrow-counterclockwise me-2"></i>Reset</a>
                                    </div>
                                </div>
                            </div>
                        </form>

                        <?php if ($hasFilter): ?>
                            <div class="active-filter-bar">
                                <div class="active-filter-title">Filter Aktif</div>
                                <div class="filter-chip-wrap">
                                    <span class="filter-chip">
                                        <i class="bi bi-ui-checks-grid"></i>
                                        Mode: <?= $filter === 'masuk' ? 'Riwayat Masuk' : ($filter === 'keluar' ? 'Riwayat Keluar' : 'Semua Riwayat') ?>
                                    </span>
                                    <?php if ($search_input !== ''): ?><span class="filter-chip"><i class="bi bi-search"></i>Cari: <?= h($search_input) ?></span><?php endif; ?>
                                    <?php if ($tanggalAwal !== ''): ?><span class="filter-chip"><i class="bi bi-calendar-event"></i>Dari: <?= h($tanggalAwal) ?></span><?php endif; ?>
                                    <?php if ($tanggalAkhir !== ''): ?><span class="filter-chip"><i class="bi bi-calendar-check"></i>Sampai: <?= h($tanggalAkhir) ?></span><?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Total Riwayat Asset</div>
                                        <div class="summary-value"><?= $totalSemua ?></div>
                                        <div class="summary-note">Aset yang melibatkan cabang ini</div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-box-seam"></i></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Asset Masuk</div>
                                        <div class="summary-value"><?= $totalMasuk ?></div>
                                        <div class="summary-note">Diterima / ditambahkan ke cabang</div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-box-arrow-in-down"></i></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Asset Keluar</div>
                                        <div class="summary-value"><?= $totalKeluar ?></div>
                                        <div class="summary-note">Dikirim keluar dari cabang</div>
                                    </div>
                                    <div class="summary-icon"><i class="bi bi-box-arrow-up"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card table-card ui-card">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                                <div>
                                    <div class="table-title"><i class="<?= h($tableIcon) ?> me-2"></i><?= h($tableTitle) ?></div>
                                    <div class="table-subinfo">Menampilkan <?= $from_row ?> - <?= $to_row ?> dari <?= $total_rows ?> data</div>
                                </div>

                                <form method="GET" class="mb-0">
                                    <input type="hidden" name="cari" value="<?= h($search_input) ?>">
                                    <input type="hidden" name="filter" value="<?= h($filter) ?>">
                                    <input type="hidden" name="tanggal_awal" value="<?= h($tanggalAwal) ?>">
                                    <input type="hidden" name="tanggal_akhir" value="<?= h($tanggalAkhir) ?>">

                                    <div class="limit-box">
                                        <span class="section-label">Tampilkan</span>
                                        <select name="limit" onchange="this.form.submit()" class="form-select form-select-sm">
                                            <option value="5" <?= $limit == 5 ? 'selected' : '' ?>>5</option>
                                            <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                                            <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                                            <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                                        </select>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Asset</th>
                                        <th>Barang</th>
                                        <th>Aktivitas</th>
                                        <th>Asal</th>
                                        <th>Tujuan</th>
                                        <th>Tanggal</th>
                                        <th>Status Pengiriman</th>
                                        <th>User / Catatan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($query && mysqli_num_rows($query) > 0): ?>
                                        <?php $no = $offset + 1; ?>
                                        <?php while ($d = mysqli_fetch_assoc($query)): ?>
                                            <?php
                                            // LOGIKA CANGGIH PENENTUAN BADGE MASUK/KELUAR (TERGANTUNG SIAPA YANG MELIHAT)
                                            $jenisRiwayat = 'Masuk';
                                            if (!empty($d['id_pengiriman'])) {
                                                if (!$isAdmin && $d['id_branch_asal'] == $myBranchId) {
                                                    $jenisRiwayat = 'Keluar'; // Jika cabang ini yg ngirim
                                                } elseif (!$isAdmin && $d['id_branch_tujuan'] == $myBranchId) {
                                                    $jenisRiwayat = 'Masuk'; // Jika cabang ini yg nerima
                                                } else {
                                                    $jenisRiwayat = 'Keluar'; // Admin behavior default
                                                }
                                            }

                                            $statusKirim  = $d['id_pengiriman'] ? ($d['status_pengiriman'] ?: 'Belum dikirim') : 'Belum dikirim';
                                            $asal = $d['id_pengiriman'] ? ($d['branch_asal_pengiriman'] ?: '-') : ($d['branch_aktif'] ?: '-');
                                            $tujuan = $d['id_pengiriman'] ? ($d['branch_tujuan'] ?: '-') : ($d['branch_aktif'] ?: '-');
                                            $catatan = '-';

                                            if ($jenisRiwayat === 'Keluar') {
                                                if (!empty($d['nomor_resi_keluar'])) {
                                                    $catatan = 'Resi: ' . $d['nomor_resi_keluar'];
                                                } elseif (!empty($d['jasa_pengiriman'])) {
                                                    $catatan = 'Pengiriman: ' . $d['jasa_pengiriman'];
                                                }
                                            } else {
                                                if (($d['bermasalah'] ?? '') === 'Iya' && !empty($d['keterangan_masalah'])) {
                                                    $catatan = 'Masalah: ' . $d['keterangan_masalah'];
                                                }
                                            }
                                            ?>
                                            <tr>
                                                <td><?= $no++ ?></td>
                                                <td>
                                                    <div class="asset-code"><?= h($d['no_asset'] ?? '-') ?></div>
                                                    <span class="meta-muted"><?= h($d['serial_number'] ?? '-') ?></span>
                                                </td>
                                                <td>
                                                    <span class="meta-line"><b><?= h($d['nama_barang'] ?? '-') ?></b></span>
                                                    <span class="meta-muted"><?= h($d['nama_merk'] ?? '-') ?></span>
                                                </td>
                                                <td>
                                                    <div class="mb-2"><?= activityBadge($jenisRiwayat) ?></div>
                                                    <span class="meta-muted"><?= h($d['nama_status'] ?? '-') ?></span>
                                                </td>
                                                <td><span class="meta-line"><i class="bi bi-geo-alt"></i> <?= h($asal) ?></span></td>
                                                <td><span class="meta-line"><i class="bi bi-send"></i> <?= h($tujuan) ?></span></td>
                                                <td>
                                                    <span class="meta-line"><i class="bi bi-calendar-event"></i> <?= h($d['tanggal_aktivitas'] ?? '-') ?></span>
                                                    <?php if (!empty($d['tanggal_kirim']) && $jenisRiwayat === 'Masuk'): ?>
                                                        <span class="meta-muted">Masuk awal: <?= h($d['tanggal_kirim']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($d['tanggal_keluar']) && $jenisRiwayat === 'Keluar'): ?>
                                                        <span class="meta-muted">Keluar: <?= h($d['tanggal_keluar']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($d['tanggal_diterima']) && $jenisRiwayat === 'Keluar'): ?>
                                                        <span class="meta-muted">Diterima: <?= h($d['tanggal_diterima']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="mb-2"><?= shippingBadge($statusKirim) ?></div>
                                                    <?php if (!empty($d['jasa_pengiriman'])): ?><span class="meta-muted"><?= h($d['jasa_pengiriman']) ?></span><?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="meta-line"><i class="bi bi-person"></i> <?= h($d['user'] ?? '-') ?></span>
                                                    <?php if (!empty($d['nama_penerima']) && $jenisRiwayat === 'Keluar'): ?>
                                                        <span class="meta-muted">Penerima: <?= h($d['nama_penerima']) ?></span>
                                                    <?php endif; ?>
                                                    <span class="meta-muted"><?= h($catatan) ?></span>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="9" class="empty-state"><i class="bi bi-inbox"></i>Data riwayat tidak ditemukan.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="p-3 p-md-4">
                            <nav>
                                <ul class="pagination mb-0 flex-wrap">
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= build_url(['page' => $i, 'limit' => $limit, 'cari' => $search_input, 'filter' => $filter, 'tanggal_awal' => $tanggalAwal, 'tanggal_akhir' => $tanggalAkhir]) ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</body>
</html>