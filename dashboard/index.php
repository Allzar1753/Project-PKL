<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'dashboard.view');

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function barangBadge($bermasalah)
{
    if ($bermasalah === 'Iya') {
        return '<span class="badge rounded-pill bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Bermasalah</span>';
    }

    return '<span class="badge rounded-pill bg-success"><i class="bi bi-check-circle me-1"></i>Normal</span>';
}

function fetchAllAssoc($query)
{
    $rows = [];
    if ($query) {
        while ($row = mysqli_fetch_assoc($query)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

$previewLimit = 3;

$baseJoin = "
FROM barang
LEFT JOIN tb_barang ON barang.id_barang = tb_barang.id_barang
LEFT JOIN tb_merk ON barang.id_merk = tb_merk.id_merk
LEFT JOIN tb_tipe ON barang.id_tipe = tb_tipe.id_tipe
LEFT JOIN tb_status ON barang.id_status = tb_status.id_status
LEFT JOIN tb_jenis ON barang.id_jenis = tb_jenis.id_jenis
LEFT JOIN tb_branch AS branch_aktif ON barang.id_branch = branch_aktif.id_branch

LEFT JOIN (
    SELECT bp1.*
    FROM barang_pengiriman bp1
    INNER JOIN (
        SELECT id_barang, MAX(id_pengiriman) AS id_pengiriman_terakhir
        FROM barang_pengiriman
        GROUP BY id_barang
    ) last_bp ON bp1.id_pengiriman = last_bp.id_pengiriman_terakhir
) AS pengiriman ON barang.id = pengiriman.id_barang

LEFT JOIN tb_branch AS branch_tujuan ON pengiriman.branch_tujuan = branch_tujuan.id_branch
LEFT JOIN tb_branch AS branch_asal_pengiriman ON pengiriman.branch_asal = branch_asal_pengiriman.id_branch
";

$totalInventaris = (int) mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total FROM barang"
))['total'];

$totalMasuk = (int) mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "
    SELECT COUNT(DISTINCT barang.id) AS total
    $baseJoin
    WHERE pengiriman.id_pengiriman IS NULL
       OR pengiriman.status_pengiriman = 'Sudah diterima'
    "
))['total'];

$totalKeluar = (int) mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "
    SELECT COUNT(DISTINCT barang.id) AS total
    $baseJoin
    WHERE pengiriman.id_pengiriman IS NOT NULL
      AND COALESCE(pengiriman.status_pengiriman, 'Belum dikirim') <> 'Sudah diterima'
    "
))['total'];

$totalBermasalah = (int) mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total FROM barang WHERE bermasalah = 'Iya'"
))['total'];

$totalSedangDikirim = (int) mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "
    SELECT COUNT(DISTINCT barang.id) AS total
    $baseJoin
    WHERE pengiriman.status_pengiriman IN ('Sedang dikemas', 'Sedang perjalanan')
    "
))['total'];

$qBarangMasukTerbaru = mysqli_query($koneksi, "
    SELECT
        barang.id,
        barang.no_asset,
        barang.serial_number,
        barang.tanggal_masuk,
        barang.`user`,
        tb_barang.nama_barang,
        tb_merk.nama_merk,
        branch_aktif.nama_branch AS nama_branch_aktif
    $baseJoin
    WHERE pengiriman.id_pengiriman IS NULL
       OR pengiriman.status_pengiriman = 'Sudah diterima'
    ORDER BY barang.id DESC
");
$barangMasukTerbaru = fetchAllAssoc($qBarangMasukTerbaru);

$qBarangKeluarTerbaru = mysqli_query($koneksi, "
    SELECT
        barang.id,
        barang.no_asset,
        barang.serial_number,
        pengiriman.tanggal_keluar,
        pengiriman.status_pengiriman,
        pengiriman.nomor_resi_keluar,
        tb_barang.nama_barang,
        tb_merk.nama_merk,
        branch_tujuan.nama_branch AS nama_branch_tujuan
    $baseJoin
    WHERE pengiriman.id_pengiriman IS NOT NULL
      AND COALESCE(pengiriman.status_pengiriman, 'Belum dikirim') <> 'Sudah diterima'
    ORDER BY barang.id DESC
");
$barangKeluarTerbaru = fetchAllAssoc($qBarangKeluarTerbaru);

$qBarangBermasalah = mysqli_query($koneksi, "
    SELECT
        barang.id,
        barang.no_asset,
        barang.serial_number,
        barang.keterangan_masalah,
        barang.`user`,
        tb_barang.nama_barang,
        tb_merk.nama_merk,
        branch_aktif.nama_branch AS nama_branch_aktif
    $baseJoin
    WHERE barang.bermasalah = 'Iya'
    ORDER BY barang.id DESC
");
$barangBermasalah = fetchAllAssoc($qBarangBermasalah);

$qPengirimanBelumDiterima = mysqli_query($koneksi, "
    SELECT
        barang.id,
        barang.no_asset,
        barang.serial_number,
        pengiriman.tanggal_keluar,
        pengiriman.status_pengiriman,
        pengiriman.nomor_resi_keluar,
        pengiriman.estimasi_pengiriman,
        tb_barang.nama_barang,
        tb_merk.nama_merk,
        branch_tujuan.nama_branch AS nama_branch_tujuan
    $baseJoin
    WHERE pengiriman.id_pengiriman IS NOT NULL
      AND COALESCE(pengiriman.status_pengiriman, 'Belum dikirim') <> 'Sudah diterima'
    ORDER BY barang.id DESC
");
$pengirimanBelumDiterima = fetchAllAssoc($qPengirimanBelumDiterima);
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

        * {
            box-sizing: border-box;
        }

        body {
            background:
                radial-gradient(circle at top left, rgba(255, 176, 0, 0.18), transparent 28%),
                radial-gradient(circle at bottom right, rgba(255, 122, 0, 0.10), transparent 22%),
                linear-gradient(180deg, #fff8f1 0%, #fffaf5 35%, #ffffff 100%);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
            min-height: 100vh;
        }

        .page-content {
            padding: 30px;
        }

        .text-warning-custom {
            color: var(--orange-2) !important;
        }

        .dashboard-hero {
            position: relative;
            overflow: hidden;
            border: 0;
            border-radius: var(--radius-xl);
            background:
                linear-gradient(135deg, rgba(17, 17, 17, 0.94) 0%, rgba(42, 42, 42, 0.90) 28%, rgba(255, 122, 0, 0.96) 100%);
            box-shadow: 0 18px 45px rgba(255, 122, 0, 0.22);
            padding: 1.8rem;
            margin-bottom: 1.5rem;
        }

        .dashboard-hero::before {
            content: "";
            position: absolute;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            top: -100px;
            right: -60px;
        }

        .dashboard-hero::after {
            content: "";
            position: absolute;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: rgba(255, 208, 102, 0.18);
            bottom: -70px;
            left: -50px;
        }

        .dashboard-hero h1 {
            position: relative;
            z-index: 2;
            font-size: 1.85rem;
            font-weight: 800;
            color: #fff;
            margin-bottom: .45rem;
            letter-spacing: -0.02em;
        }

        .dashboard-hero p {
            position: relative;
            z-index: 2;
            color: rgba(255, 255, 255, 0.86);
            margin-bottom: 0;
            max-width: 760px;
            line-height: 1.7;
            font-size: .95rem;
        }

        .role-badge {
            position: relative;
            z-index: 2;
            background: rgba(255, 255, 255, 0.14);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 999px;
            padding: .65rem 1rem;
            font-weight: 700;
            font-size: .86rem;
            white-space: nowrap;
            backdrop-filter: blur(10px);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .08);
        }

        .summary-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(180deg, #ffffff 0%, #fffaf3 100%);
            border: 1px solid rgba(255, 176, 0, 0.15);
            border-radius: 22px;
            box-shadow: var(--shadow-soft);
            height: 100%;
            padding: 1.15rem 1.1rem;
            transition: all .25s ease;
        }

        .summary-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 6px;
            background: linear-gradient(90deg, var(--orange-1), var(--orange-3));
        }

        .summary-card::after {
            content: "";
            position: absolute;
            right: -12px;
            bottom: -18px;
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 176, 0, 0.18) 0%, rgba(255, 176, 0, 0) 70%);
        }

        .summary-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-hover);
        }

        .summary-label {
            font-size: .84rem;
            color: var(--text-soft);
            margin-bottom: .45rem;
            font-weight: 600;
        }

        .summary-value {
            font-size: 2rem;
            line-height: 1;
            font-weight: 800;
            margin-bottom: .45rem;
            color: var(--dark-1);
            letter-spacing: -0.03em;
        }

        .summary-note {
            font-size: .83rem;
            color: #7b7b7b;
            line-height: 1.5;
        }

        .summary-icon {
            width: 54px;
            height: 54px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.28rem;
            flex-shrink: 0;
            color: #fff !important;
            background: linear-gradient(135deg, var(--orange-1), var(--orange-3)) !important;
            box-shadow: 0 10px 24px rgba(255, 152, 0, 0.22);
        }

        .panel-card {
            overflow: hidden;
            background: #ffffff;
            border: 1px solid rgba(255, 176, 0, 0.13);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-soft);
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            padding: 1.15rem 1.25rem;
            border-bottom: 1px solid rgba(255, 176, 0, 0.16);
            background: linear-gradient(135deg, #111111 0%, #2c2c2c 40%, #ff8f00 100%);
            position: relative;
            overflow: hidden;
        }

        .panel-header::after {
            content: "";
            position: absolute;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            top: -90px;
            right: -70px;
            background: rgba(255, 255, 255, 0.09);
        }

        .panel-title {
            position: relative;
            z-index: 1;
            font-size: 1.02rem;
            font-weight: 800;
            margin-bottom: .22rem;
            color: #fff;
            letter-spacing: -0.02em;
        }

        .panel-subtitle {
            position: relative;
            z-index: 1;
            font-size: .84rem;
            color: rgba(255, 255, 255, 0.80);
            line-height: 1.5;
        }

        .panel-body {
            padding: 1.15rem 1.2rem;
            background: linear-gradient(180deg, #fffdf9 0%, #fff8ef 100%);
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: .9rem;
        }

        .activity-item {
            position: relative;
            border: 1px solid rgba(255, 176, 0, 0.14);
            border-left: 5px solid var(--orange-2);
            border-radius: 18px;
            padding: 1rem;
            background: #ffffff;
            box-shadow: 0 8px 20px rgba(17, 17, 17, 0.04);
            transition: all .22s ease;
        }

        .activity-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 30px rgba(255, 122, 0, 0.14);
            border-color: rgba(255, 152, 0, 0.28);
        }

        .activity-title {
            font-weight: 800;
            margin-bottom: .38rem;
            color: var(--dark-1);
            letter-spacing: -0.01em;
        }

        .badge-soft {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            background: linear-gradient(180deg, #fff3df 0%, #ffe7be 100%);
            color: #8b4f00;
            border: 1px solid rgba(255, 152, 0, 0.20);
            border-radius: 999px;
            padding: .38rem .72rem;
            font-size: .74rem;
            font-weight: 700;
            margin-bottom: .6rem;
        }

        .meta-grid {
            display: grid;
            gap: .28rem;
        }

        .meta-line {
            font-size: .88rem;
            color: #4b5563;
            line-height: 1.5;
        }

        .meta-line strong {
            color: #111827;
            font-weight: 700;
        }

        .meta-muted {
            font-size: .84rem;
            color: #6b7280;
            line-height: 1.5;
        }

        .meta-muted i {
            width: 16px;
            color: var(--orange-2);
        }

        .empty-state {
            text-align: center;
            color: var(--text-soft);
            padding: 1.8rem 1rem;
            border: 1px dashed rgba(255, 152, 0, 0.28);
            border-radius: 18px;
            background: linear-gradient(180deg, #fffaf2 0%, #fff5e8 100%);
        }

        .empty-state i {
            display: block;
            font-size: 1.9rem;
            margin-bottom: .5rem;
            color: var(--orange-2);
        }

        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .section-action {
            margin-top: 1rem;
            text-align: center;
        }

        .btn-toggle-list {
            border: none;
            border-radius: 999px;
            padding: .7rem 1.1rem;
            font-size: .85rem;
            font-weight: 700;
            background: linear-gradient(135deg, #111111 0%, #ff8f00 100%);
            color: #fff;
            box-shadow: 0 10px 24px rgba(255, 143, 0, 0.18);
            transition: all .25s ease;
        }

        .btn-toggle-list:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 28px rgba(255, 143, 0, 0.25);
        }

        .btn-toggle-list i {
            margin-right: .45rem;
        }

        .extra-item.d-none {
            display: none !important;
        }

        .badge.rounded-pill {
            padding: .5rem .8rem;
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .1px;
        }

        .badge.rounded-pill.bg-success {
            background: linear-gradient(135deg, #111111, #2e2e2e) !important;
            color: #fff !important;
            border: none !important;
        }

        .badge.rounded-pill.bg-danger {
            background: linear-gradient(135deg, #ff7a00, #ff9f1a) !important;
            color: #fff !important;
            border: none !important;
        }

        .badge.rounded-pill.bg-primary {
            background: linear-gradient(135deg, #ff8c00, #ffb000) !important;
            color: #fff !important;
            border: none !important;
        }

        .badge.rounded-pill.bg-secondary {
            background: #fff7ea !important;
            color: #6b4a00 !important;
            border: 1px solid rgba(255, 176, 0, 0.25) !important;
        }

        .badge.rounded-pill.bg-warning.text-dark {
            background: linear-gradient(135deg, #ffd166, #ffbf47) !important;
            color: #5b3a00 !important;
            border: none !important;
        }

        .bg-success-subtle,
        .bg-danger-subtle,
        .bg-primary-subtle,
        .bg-warning-subtle,
        .bg-light {
            background: rgba(255, 152, 0, 0.10) !important;
        }

        .summary-card .text-success,
        .summary-card .text-danger,
        .summary-card .text-primary,
        .summary-card .text-warning,
        .summary-card .text-warning-emphasis,
        .summary-card .text-dark {
            color: var(--dark-1) !important;
        }

        .row.g-3.mb-4>.col-12:nth-child(1) .summary-card::before {
            background: linear-gradient(90deg, #ff7a00, #ffb000);
        }

        .row.g-3.mb-4>.col-12:nth-child(2) .summary-card::before {
            background: linear-gradient(90deg, #ff8a00, #ffd166);
        }

        .row.g-3.mb-4>.col-12:nth-child(3) .summary-card::before {
            background: linear-gradient(90deg, #111111, #ff8f00);
        }

        .row.g-3.mb-4>.col-12:nth-child(4) .summary-card::before {
            background: linear-gradient(90deg, #ff9d00, #ffcf66);
        }

        .row.g-3.mb-4>.col-12:nth-child(5) .summary-card::before {
            background: linear-gradient(90deg, #2a2a2a, #ffb000);
        }

        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #ff9800, #ffb000);
            border-radius: 999px;
        }

        ::-webkit-scrollbar-track {
            background: #fff4e3;
        }

        @media (max-width: 1399.98px) {
            .panel-title {
                font-size: .95rem;
            }
        }

        @media (max-width: 991.98px) {
            .page-content {
                padding: 18px;
            }

            .dashboard-hero {
                padding: 1.35rem 1.2rem;
            }

            .dashboard-hero h1 {
                font-size: 1.4rem;
            }

            .summary-value {
                font-size: 1.7rem;
            }

            .panel-header,
            .panel-body {
                padding-left: 1rem;
                padding-right: 1rem;
            }
        }

        @media (max-width: 575.98px) {
            .activity-item {
                padding: .9rem;
            }

            .dashboard-hero h1 {
                font-size: 1.2rem;
            }

            .dashboard-hero p {
                font-size: .92rem;
            }

            .role-badge {
                width: 100%;
                text-align: center;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <?php require_once '../layout/sidebar.php'; ?>

            <div class="col-md-10 ms-auto">
                <div class="page-content">

                    <div class="dashboard-hero">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div>
                                <h1>Dashboard IT Asset Management</h1>
                                <p>
                                    Ringkasan inventaris, kondisi perangkat, status pengiriman, dan aktivitas aset
                                    dalam tampilan yang lebih modern, lebih mudah dibaca, dan nyaman dipantau.
                                </p>
                            </div>

                            <div class="role-badge">
                                <i class="bi bi-person-badge me-1"></i><?= h(current_role() ?? '-') ?>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-12 col-sm-6 col-xl">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Total Inventaris</div>
                                        <div class="summary-value"><?= $totalInventaris ?></div>
                                        <div class="summary-note">Seluruh aset yang tercatat</div>
                                    </div>
                                    <div class="summary-icon">
                                        <i class="bi bi-box-seam"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Barang Masuk</div>
                                        <div class="summary-value"><?= $totalMasuk ?></div>
                                        <div class="summary-note">Masih aktif di inventaris</div>
                                    </div>
                                    <div class="summary-icon">
                                        <i class="bi bi-box-arrow-in-down"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Barang Keluar</div>
                                        <div class="summary-value"><?= $totalKeluar ?></div>
                                        <div class="summary-note">Sudah dikirim atau keluar</div>
                                    </div>
                                    <div class="summary-icon">
                                        <i class="bi bi-box-arrow-up"></i>
                                    </div>
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
                                    <div class="summary-icon">
                                        <i class="bi bi-exclamation-triangle"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-xl">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Pengiriman</div>
                                        <div class="summary-value"><?= $totalSedangDikirim ?></div>
                                        <div class="summary-note">Belum diterima tujuan</div>
                                    </div>
                                    <div class="summary-icon">
                                        <i class="bi bi-truck"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-xl-3 col-md-6">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title">
                                        <i class="bi bi-box-arrow-in-down me-2 text-warning-custom"></i>Barang Masuk
                                    </div>
                                    <div class="panel-subtitle">
                                        Tampil ringkas dulu, buka jika ingin lihat semua data
                                    </div>
                                </div>

                                <div class="panel-body">
                                    <?php if (!empty($barangMasukTerbaru)): ?>
                                        <div class="activity-list" id="barangMasukList">
                                            <?php foreach ($barangMasukTerbaru as $index => $item): ?>
                                                <div class="activity-item <?= $index >= $previewLimit ? 'extra-item d-none' : '' ?>">
                                                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                                        <div class="flex-grow-1">
                                                            <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                            <div class="badge-soft"><?= h($item['nama_merk'] ?? '-') ?></div>

                                                            <div class="meta-grid">
                                                                <div class="meta-line"><strong>Asset:</strong> <?= h($item['no_asset'] ?? '-') ?></div>
                                                                <div class="meta-line"><strong>Serial:</strong> <?= h($item['serial_number'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-geo-alt me-1"></i><?= h($item['nama_branch_aktif'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-person me-1"></i><?= h($item['user'] ?? '-') ?></div>
                                                            </div>
                                                        </div>

                                                        <div class="text-end">
                                                            <span class="badge rounded-pill bg-success">Masuk</span>
                                                            <div class="meta-muted mt-2">
                                                                <?= !empty($item['tanggal_masuk']) ? h($item['tanggal_masuk']) : '-' ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <?php if (count($barangMasukTerbaru) > $previewLimit): ?>
                                            <div class="section-action">
                                                <button type="button" class="btn-toggle-list" onclick="toggleList('barangMasukList', this)">
                                                    <i class="bi bi-chevron-down"></i>Lihat selengkapnya
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <i class="bi bi-inbox"></i>
                                            Belum ada data barang masuk terbaru.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title">
                                        <i class="bi bi-box-arrow-up me-2 text-warning-custom"></i>Barang Keluar
                                    </div>
                                    <div class="panel-subtitle">
                                        Tampil sebagian dulu supaya tetap rapi dan mudah dibaca
                                    </div>
                                </div>

                                <div class="panel-body">
                                    <?php if (!empty($barangKeluarTerbaru)): ?>
                                        <div class="activity-list" id="barangKeluarList">
                                            <?php foreach ($barangKeluarTerbaru as $index => $item): ?>
                                                <div class="activity-item <?= $index >= $previewLimit ? 'extra-item d-none' : '' ?>">
                                                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                                        <div class="flex-grow-1">
                                                            <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                            <div class="badge-soft"><?= h($item['nama_merk'] ?? '-') ?></div>

                                                            <div class="meta-grid">
                                                                <div class="meta-line"><strong>Asset:</strong> <?= h($item['no_asset'] ?? '-') ?></div>
                                                                <div class="meta-line"><strong>Serial:</strong> <?= h($item['serial_number'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-geo-alt me-1"></i><?= h($item['nama_branch_tujuan'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-receipt me-1"></i>Resi: <?= h($item['nomor_resi_keluar'] ?? '-') ?></div>
                                                            </div>
                                                        </div>

                                                        <div class="text-end">
                                                            <div><?= shippingBadge($item['status_pengiriman'] ?? 'Belum dikirim') ?></div>
                                                            <div class="meta-muted mt-2">
                                                                <?= !empty($item['tanggal_keluar']) ? h($item['tanggal_keluar']) : '-' ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <?php if (count($barangKeluarTerbaru) > $previewLimit): ?>
                                            <div class="section-action">
                                                <button type="button" class="btn-toggle-list" onclick="toggleList('barangKeluarList', this)">
                                                    <i class="bi bi-chevron-down"></i>Lihat selengkapnya
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <i class="bi bi-inbox"></i>
                                            Belum ada data barang keluar terbaru.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title">
                                        <i class="bi bi-exclamation-triangle me-2 text-warning-custom"></i>Barang Bermasalah
                                    </div>
                                    <div class="panel-subtitle">
                                        Fokus pada perangkat yang perlu perhatian khusus
                                    </div>
                                </div>

                                <div class="panel-body">
                                    <?php if (!empty($barangBermasalah)): ?>
                                        <div class="activity-list" id="barangBermasalahList">
                                            <?php foreach ($barangBermasalah as $index => $item): ?>
                                                <div class="activity-item <?= $index >= $previewLimit ? 'extra-item d-none' : '' ?>">
                                                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                                        <div class="flex-grow-1">
                                                            <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                            <div class="badge-soft"><?= h($item['nama_merk'] ?? '-') ?></div>

                                                            <div class="meta-grid">
                                                                <div class="meta-line"><strong>Asset:</strong> <?= h($item['no_asset'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-geo-alt me-1"></i><?= h($item['nama_branch_aktif'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-person me-1"></i><?= h($item['user'] ?? '-') ?></div>
                                                                <div class="meta-line text-danger line-clamp-2">
                                                                    <strong>Masalah:</strong> <?= h($item['keterangan_masalah'] ?? '-') ?>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <div>
                                                            <?= barangBadge('Iya') ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <?php if (count($barangBermasalah) > $previewLimit): ?>
                                            <div class="section-action">
                                                <button type="button" class="btn-toggle-list" onclick="toggleList('barangBermasalahList', this)">
                                                    <i class="bi bi-chevron-down"></i>Lihat selengkapnya
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <i class="bi bi-check-circle"></i>
                                            Tidak ada barang bermasalah saat ini.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3 col-md-6">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title">
                                        <i class="bi bi-truck me-2 text-warning-custom"></i>Pengiriman Belum Diterima
                                    </div>
                                    <div class="panel-subtitle">
                                        Pantau pengiriman yang masih berjalan atau belum diterima tujuan
                                    </div>
                                </div>

                                <div class="panel-body">
                                    <?php if (!empty($pengirimanBelumDiterima)): ?>
                                        <div class="activity-list" id="pengirimanBelumDiterimaList">
                                            <?php foreach ($pengirimanBelumDiterima as $index => $item): ?>
                                                <div class="activity-item <?= $index >= $previewLimit ? 'extra-item d-none' : '' ?>">
                                                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                                        <div class="flex-grow-1">
                                                            <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                            <div class="badge-soft"><?= h($item['nama_merk'] ?? '-') ?></div>

                                                            <div class="meta-grid">
                                                                <div class="meta-line"><strong>Asset:</strong> <?= h($item['no_asset'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-geo-alt me-1"></i><?= h($item['nama_branch_tujuan'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-receipt me-1"></i>Resi: <?= h($item['nomor_resi_keluar'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-hourglass-split me-1"></i>Estimasi: <?= h($item['estimasi_pengiriman'] ?? '-') ?></div>
                                                            </div>
                                                        </div>

                                                        <div class="text-end">
                                                            <div><?= shippingBadge($item['status_pengiriman'] ?? 'Belum dikirim') ?></div>
                                                            <div class="meta-muted mt-2">
                                                                <?= !empty($item['tanggal_keluar']) ? h($item['tanggal_keluar']) : '-' ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <?php if (count($pengirimanBelumDiterima) > $previewLimit): ?>
                                            <div class="section-action">
                                                <button type="button" class="btn-toggle-list" onclick="toggleList('pengirimanBelumDiterimaList', this)">
                                                    <i class="bi bi-chevron-down"></i>Lihat selengkapnya
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <i class="bi bi-check2-all"></i>
                                            Tidak ada pengiriman tertunda saat ini.
                                        </div>
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