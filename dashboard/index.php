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

$totalInventaris = (int) mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total FROM barang"
))['total'];

$totalMasuk = (int) mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total FROM barang WHERE tanggal_keluar IS NULL"
))['total'];

$totalKeluar = (int) mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total FROM barang WHERE tanggal_keluar IS NOT NULL"
))['total'];

$totalBermasalah = (int) mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total FROM barang WHERE bermasalah = 'Iya'"
))['total'];

$totalSedangDikirim = (int) mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total
     FROM barang
     WHERE tanggal_keluar IS NOT NULL
       AND (
            status_pengiriman IS NULL
            OR status_pengiriman = ''
            OR status_pengiriman != 'Sudah diterima'
       )"
))['total'];

$baseJoin = "
FROM barang
LEFT JOIN tb_barang ON barang.id_barang = tb_barang.id_barang
LEFT JOIN tb_merk ON barang.id_merk = tb_merk.id_merk
LEFT JOIN tb_tipe ON barang.id_tipe = tb_tipe.id_tipe
LEFT JOIN tb_status ON barang.id_status = tb_status.id_status
LEFT JOIN tb_jenis ON barang.id_jenis = tb_jenis.id_jenis
LEFT JOIN tb_branch AS branch_asal ON barang.id_branch = branch_asal.id_branch
LEFT JOIN tb_branch AS branch_tujuan ON barang.tujuan = branch_tujuan.id_branch
";

$qBarangMasukTerbaru = mysqli_query($koneksi, "
    SELECT 
        barang.id,
        barang.no_asset,
        barang.serial_number,
        barang.tanggal_masuk,
        barang.`user`,
        tb_barang.nama_barang,
        tb_merk.nama_merk,
        branch_asal.nama_branch AS branch_asal
    $baseJoin
    WHERE barang.tanggal_keluar IS NULL
    ORDER BY barang.id DESC
    LIMIT 5
");
$barangMasukTerbaru = fetchAllAssoc($qBarangMasukTerbaru);

$qBarangKeluarTerbaru = mysqli_query($koneksi, "
    SELECT 
        barang.id,
        barang.no_asset,
        barang.serial_number,
        barang.tanggal_keluar,
        barang.status_pengiriman,
        barang.nomor_resi,
        tb_barang.nama_barang,
        tb_merk.nama_merk,
        branch_tujuan.nama_branch AS branch_tujuan
    $baseJoin
    WHERE barang.tanggal_keluar IS NOT NULL
    ORDER BY barang.id DESC
    LIMIT 5
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
        branch_asal.nama_branch AS branch_asal
    $baseJoin
    WHERE barang.bermasalah = 'Iya'
    ORDER BY barang.id DESC
    LIMIT 5
");
$barangBermasalah = fetchAllAssoc($qBarangBermasalah);

$qPengirimanBelumDiterima = mysqli_query($koneksi, "
    SELECT 
        barang.id,
        barang.no_asset,
        barang.serial_number,
        barang.tanggal_keluar,
        barang.status_pengiriman,
        barang.nomor_resi,
        barang.estimasi_pengiriman,
        tb_barang.nama_barang,
        tb_merk.nama_merk,
        branch_tujuan.nama_branch AS branch_tujuan
    $baseJoin
    WHERE barang.tanggal_keluar IS NOT NULL
      AND (
            barang.status_pengiriman IS NULL
            OR barang.status_pengiriman = ''
            OR barang.status_pengiriman != 'Sudah diterima'
      )
    ORDER BY barang.id DESC
    LIMIT 5
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
    <style>
        :root {
            --primary: #ffc107;
            --dark: #212529;
            --muted: #6c757d;
            --surface: #ffffff;
            --surface-soft: #f8f9fa;
            --border-soft: #eceff3;
            --shadow-soft: 0 8px 24px rgba(0, 0, 0, 0.06);
        }

        body {
            background: #f5f7fb;
            font-family: 'Inter', sans-serif;
            color: #212529;
        }

        .text-warning-custom {
            color: var(--primary);
        }

        .bg-warning-custom {
            background: var(--primary);
        }

        .hero-card {
            background: linear-gradient(135deg, #212529 0%, #343a40 100%);
            color: #fff;
            border: none;
            border-radius: 20px;
            box-shadow: var(--shadow-soft);
            overflow: hidden;
            position: relative;
        }

        .hero-card::after {
            content: "";
            position: absolute;
            right: -40px;
            top: -40px;
            width: 180px;
            height: 180px;
            background: rgba(255, 193, 7, 0.15);
            border-radius: 50%;
        }

        .hero-card .badge-role {
            background: rgba(255, 193, 7, 0.18);
            color: #fff3cd;
            border: 1px solid rgba(255, 193, 7, 0.35);
            border-radius: 999px;
            padding: .5rem .9rem;
            font-weight: 700;
        }

        .hero-title {
            font-size: 1.75rem;
            font-weight: 800;
            line-height: 1.2;
        }

        .hero-subtitle {
            color: rgba(255, 255, 255, 0.75);
            max-width: 700px;
        }

        .summary-card {
            border: none;
            border-radius: 18px;
            background: var(--surface);
            box-shadow: var(--shadow-soft);
            height: 100%;
            overflow: hidden;
            position: relative;
        }

        .summary-card .summary-topbar {
            height: 5px;
            width: 100%;
        }

        .summary-card .summary-body {
            padding: 1.15rem 1.15rem 1.25rem;
        }

        .summary-label {
            font-size: 0.88rem;
            color: var(--muted);
            margin-bottom: .35rem;
        }

        .summary-value {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: .35rem;
        }

        .summary-note {
            font-size: 0.88rem;
            color: var(--muted);
        }

        .summary-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }

        .panel-card {
            border: none;
            border-radius: 18px;
            background: var(--surface);
            box-shadow: var(--shadow-soft);
            overflow: hidden;
            height: 100%;
        }

        .panel-header {
            padding: 1rem 1.2rem;
            border-bottom: 1px solid var(--border-soft);
            background: #fff;
        }

        .panel-title {
            font-size: 1rem;
            font-weight: 800;
            margin-bottom: .15rem;
        }

        .panel-subtitle {
            font-size: .88rem;
            color: var(--muted);
        }

        .panel-section-title {
            font-size: .92rem;
            font-weight: 700;
            color: #212529;
            margin-bottom: .9rem;
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        .panel-body {
            padding: 1.1rem 1.2rem;
        }

        .panel-divider {
            border-top: 1px solid var(--border-soft);
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: .85rem;
        }

        .activity-item {
            border: 1px solid var(--border-soft);
            border-radius: 14px;
            padding: .9rem 1rem;
            background: #fff;
        }

        .activity-title {
            font-weight: 700;
            margin-bottom: .25rem;
        }

        .meta-line {
            display: block;
            font-size: 0.92rem;
            line-height: 1.45;
            color: #212529;
        }

        .meta-muted {
            display: block;
            font-size: 0.88rem;
            line-height: 1.45;
            color: var(--muted);
        }

        .badge-soft {
            background: #f8f9fa;
            color: #212529;
            border: 1px solid #dee2e6;
            border-radius: 999px;
            padding: .35rem .65rem;
            font-size: .78rem;
            font-weight: 600;
        }

        .empty-state {
            text-align: center;
            color: var(--muted);
            padding: 1.8rem 1rem;
            border: 1px dashed #d9dee5;
            border-radius: 14px;
            background: #fcfcfd;
        }

        .empty-state i {
            font-size: 1.8rem;
            display: block;
            margin-bottom: .5rem;
        }

        .action-center {
            border: none;
            border-radius: 18px;
            background: var(--surface);
            box-shadow: var(--shadow-soft);
            overflow: hidden;
        }

        .shortcut-card {
            display: block;
            text-decoration: none;
            color: inherit;
            background: #fff;
            border: 1px solid var(--border-soft);
            border-radius: 16px;
            padding: 1rem;
            height: 100%;
            transition: all .18s ease;
        }

        .shortcut-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(0, 0, 0, 0.07);
            border-color: #e2c04f;
        }

        .shortcut-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-bottom: .8rem;
        }

        .shortcut-title {
            font-weight: 800;
            margin-bottom: .25rem;
        }

        .shortcut-desc {
            color: var(--muted);
            font-size: .9rem;
            line-height: 1.4;
        }

        .text-truncate-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        @media (max-width: 991.98px) {
            .hero-title {
                font-size: 1.45rem;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <?php require_once '../layout/sidebar.php'; ?>

            <div class="col-md-10 p-4">
                <div class="hero-card p-4 mb-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 position-relative" style="z-index:1;">
                        <div>
                            <div class="hero-title mb-2">Dashboard IT Asset Management</div>
                            <div class="hero-subtitle">
                                Pusat kontrol untuk memantau inventaris, aktivitas barang, kondisi perangkat, dan proses pengiriman dalam satu tampilan.
                            </div>
                        </div>

                        <div class="text-end">
                            <div class="badge-role">
                                <i class="bi bi-person-badge me-1"></i><?= h(current_role() ?? '-') ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6 col-xl">
                        <div class="summary-card">
                            <div class="summary-topbar bg-dark"></div>
                            <div class="summary-body">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Total Inventaris</div>
                                        <div class="summary-value"><?= $totalInventaris ?></div>
                                        <div class="summary-note">Seluruh aset yang tercatat di sistem</div>
                                    </div>
                                    <div class="summary-icon bg-light text-dark">
                                        <i class="bi bi-box-seam"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl">
                        <div class="summary-card">
                            <div class="summary-topbar bg-success"></div>
                            <div class="summary-body">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Barang Masuk</div>
                                        <div class="summary-value text-success"><?= $totalMasuk ?></div>
                                        <div class="summary-note">Aset yang masih berada di inventaris aktif</div>
                                    </div>
                                    <div class="summary-icon bg-success-subtle text-success">
                                        <i class="bi bi-box-arrow-in-down"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl">
                        <div class="summary-card">
                            <div class="summary-topbar bg-danger"></div>
                            <div class="summary-body">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Barang Keluar</div>
                                        <div class="summary-value text-danger"><?= $totalKeluar ?></div>
                                        <div class="summary-note">Aset yang sudah dikirim / keluar</div>
                                    </div>
                                    <div class="summary-icon bg-danger-subtle text-danger">
                                        <i class="bi bi-box-arrow-up"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl">
                        <div class="summary-card">
                            <div class="summary-topbar bg-warning"></div>
                            <div class="summary-body">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Barang Bermasalah</div>
                                        <div class="summary-value text-danger"><?= $totalBermasalah ?></div>
                                        <div class="summary-note">Aset yang perlu perhatian khusus</div>
                                    </div>
                                    <div class="summary-icon bg-warning-subtle text-warning-emphasis">
                                        <i class="bi bi-exclamation-triangle"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl">
                        <div class="summary-card">
                            <div class="summary-topbar bg-primary"></div>
                            <div class="summary-body">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Dalam Pengiriman</div>
                                        <div class="summary-value text-primary"><?= $totalSedangDikirim ?></div>
                                        <div class="summary-note">Barang keluar yang belum diterima</div>
                                    </div>
                                    <div class="summary-icon bg-primary-subtle text-primary">
                                        <i class="bi bi-truck"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-xl-7">
                        <div class="panel-card">
                            <div class="panel-header">
                                <div class="panel-title"><i class="bi bi-activity me-2 text-warning-custom"></i>Aktivitas Barang Terbaru</div>
                                <div class="panel-subtitle">Pantau pergerakan inventaris masuk dan keluar terbaru</div>
                            </div>

                            <div class="panel-body">
                                <div class="panel-section-title">
                                    <i class="bi bi-box-arrow-in-down text-success"></i>
                                    Barang Masuk Terbaru
                                </div>

                                <?php if (!empty($barangMasukTerbaru)): ?>
                                    <div class="activity-list">
                                        <?php foreach ($barangMasukTerbaru as $item): ?>
                                            <div class="activity-item">
                                                <div class="d-flex justify-content-between align-items-start gap-3">
                                                    <div>
                                                        <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                        <span class="badge-soft"><?= h($item['nama_merk'] ?? '-') ?></span>
                                                        <span class="meta-line mt-2"><b>Asset:</b> <?= h($item['no_asset'] ?? '-') ?></span>
                                                        <span class="meta-line"><b>Serial:</b> <?= h($item['serial_number'] ?? '-') ?></span>
                                                        <span class="meta-muted"><i class="bi bi-geo-alt me-1"></i><?= h($item['branch_asal'] ?? '-') ?></span>
                                                        <span class="meta-muted"><i class="bi bi-person me-1"></i><?= h($item['user'] ?? '-') ?></span>
                                                    </div>
                                                    <div class="text-end">
                                                        <span class="badge rounded-pill bg-success">Masuk</span>
                                                        <div class="meta-muted mt-2"><?= !empty($item['tanggal_masuk']) ? h($item['tanggal_masuk']) : '-' ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="bi bi-inbox"></i>
                                        Belum ada data barang masuk terbaru.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="panel-divider"></div>

                            <div class="panel-body">
                                <div class="panel-section-title">
                                    <i class="bi bi-box-arrow-up text-danger"></i>
                                    Barang Keluar Terbaru
                                </div>

                                <?php if (!empty($barangKeluarTerbaru)): ?>
                                    <div class="activity-list">
                                        <?php foreach ($barangKeluarTerbaru as $item): ?>
                                            <div class="activity-item">
                                                <div class="d-flex justify-content-between align-items-start gap-3">
                                                    <div>
                                                        <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                        <span class="badge-soft"><?= h($item['nama_merk'] ?? '-') ?></span>
                                                        <span class="meta-line mt-2"><b>Asset:</b> <?= h($item['no_asset'] ?? '-') ?></span>
                                                        <span class="meta-line"><b>Serial:</b> <?= h($item['serial_number'] ?? '-') ?></span>
                                                        <span class="meta-muted"><i class="bi bi-geo-alt me-1"></i><?= h($item['branch_tujuan'] ?? '-') ?></span>
                                                        <span class="meta-muted"><i class="bi bi-receipt me-1"></i>Resi: <?= h($item['nomor_resi'] ?? '-') ?></span>
                                                    </div>
                                                    <div class="text-end">
                                                        <div><?= shippingBadge($item['status_pengiriman'] ?? 'Belum dikirim') ?></div>
                                                        <div class="meta-muted mt-2"><?= !empty($item['tanggal_keluar']) ? h($item['tanggal_keluar']) : '-' ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="bi bi-inbox"></i>
                                        Belum ada data barang keluar terbaru.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-5">
                        <div class="panel-card mb-4">
                            <div class="panel-header">
                                <div class="panel-title"><i class="bi bi-exclamation-diamond me-2 text-warning-custom"></i>Perlu Perhatian</div>
                                <div class="panel-subtitle">Fokus pada aset bermasalah dan pengiriman tertunda</div>
                            </div>

                            <div class="panel-body">
                                <div class="panel-section-title">
                                    <i class="bi bi-exclamation-triangle text-danger"></i>
                                    Barang Bermasalah
                                </div>

                                <?php if (!empty($barangBermasalah)): ?>
                                    <div class="activity-list">
                                        <?php foreach ($barangBermasalah as $item): ?>
                                            <div class="activity-item">
                                                <div class="d-flex justify-content-between align-items-start gap-3">
                                                    <div>
                                                        <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                        <span class="badge-soft"><?= h($item['nama_merk'] ?? '-') ?></span>
                                                        <span class="meta-line mt-2"><b>Asset:</b> <?= h($item['no_asset'] ?? '-') ?></span>
                                                        <span class="meta-muted"><i class="bi bi-geo-alt me-1"></i><?= h($item['branch_asal'] ?? '-') ?></span>
                                                        <span class="meta-muted"><i class="bi bi-person me-1"></i><?= h($item['user'] ?? '-') ?></span>
                                                        <div class="meta-line text-danger mt-2 text-truncate-2"><?= h($item['keterangan_masalah'] ?? '-') ?></div>
                                                    </div>
                                                    <div>
                                                        <?= barangBadge('Iya') ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="bi bi-check-circle"></i>
                                        Tidak ada barang bermasalah saat ini.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="panel-divider"></div>

                            <div class="panel-body">
                                <div class="panel-section-title">
                                    <i class="bi bi-truck text-primary"></i>
                                    Pengiriman Belum Diterima
                                </div>

                                <?php if (!empty($pengirimanBelumDiterima)): ?>
                                    <div class="activity-list">
                                        <?php foreach ($pengirimanBelumDiterima as $item): ?>
                                            <div class="activity-item">
                                                <div class="d-flex justify-content-between align-items-start gap-3">
                                                    <div>
                                                        <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                        <span class="badge-soft"><?= h($item['nama_merk'] ?? '-') ?></span>
                                                        <span class="meta-line mt-2"><b>Asset:</b> <?= h($item['no_asset'] ?? '-') ?></span>
                                                        <span class="meta-muted"><i class="bi bi-geo-alt me-1"></i><?= h($item['branch_tujuan'] ?? '-') ?></span>
                                                        <span class="meta-muted"><i class="bi bi-receipt me-1"></i>Resi: <?= h($item['nomor_resi'] ?? '-') ?></span>
                                                        <span class="meta-muted"><i class="bi bi-hourglass-split me-1"></i>Estimasi: <?= h($item['estimasi_pengiriman'] ?? '-') ?></span>
                                                    </div>
                                                    <div class="text-end">
                                                        <div><?= shippingBadge($item['status_pengiriman'] ?? 'Belum dikirim') ?></div>
                                                        <div class="meta-muted mt-2"><?= !empty($item['tanggal_keluar']) ? h($item['tanggal_keluar']) : '-' ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
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

                <div class="action-center">
                    <div class="panel-header">
                        <div class="panel-title"><i class="bi bi-lightning-charge me-2 text-warning-custom"></i>Akses Cepat</div>
                        <div class="panel-subtitle">Masuk ke modul utama sesuai hak akses pengguna</div>
                    </div>

                    <div class="panel-body">
                        <div class="row g-3">
                            <?php if (can('barang.view')): ?>
                                <div class="col-md-6 col-xl-3">
                                    <a href="../Barang/index.php" class="shortcut-card">
                                        <div class="shortcut-icon bg-warning-subtle text-warning-emphasis">
                                            <i class="bi bi-box-seam"></i>
                                        </div>
                                        <div class="shortcut-title">Kelola Barang</div>
                                        <div class="shortcut-desc">Masuk ke modul utama inventaris untuk melihat dan mengelola data barang.</div>
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php if (can('riwayat.view')): ?>
                                <div class="col-md-6 col-xl-3">
                                    <a href="../Riwayat/index.php" class="shortcut-card">
                                        <div class="shortcut-icon bg-dark-subtle text-dark">
                                            <i class="bi bi-clock-history"></i>
                                        </div>
                                        <div class="shortcut-title">Riwayat</div>
                                        <div class="shortcut-desc">Lihat histori aktivitas dan pergerakan data inventaris yang sudah tercatat.</div>
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php if (can('laporan.view')): ?>
                                <div class="col-md-6 col-xl-3">
                                    <a href="../laporan/index.php" class="shortcut-card">
                                        <div class="shortcut-icon bg-light text-dark border">
                                            <i class="bi bi-file-earmark-text"></i>
                                        </div>
                                        <div class="shortcut-title">Laporan</div>
                                        <div class="shortcut-desc">Buka halaman laporan untuk melihat rekap dan kebutuhan pelaporan inventaris.</div>
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php if (is_super_admin()): ?>
                                <div class="col-md-6 col-xl-3">
                                    <a href="../users/index.php" class="shortcut-card">
                                        <div class="shortcut-icon bg-primary-subtle text-primary">
                                            <i class="bi bi-people"></i>
                                        </div>
                                        <div class="shortcut-title">Kelola User</div>
                                        <div class="shortcut-desc">Atur akun, role, dan hak akses pengguna sistem dari pusat kontrol admin.</div>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>