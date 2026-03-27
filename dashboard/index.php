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
            --border-soft: #e9ecef;
            --shadow-soft: 0 8px 24px rgba(0, 0, 0, 0.05);
            --radius-lg: 18px;
            --radius-md: 14px;
        }

        body {
            background: #f6f8fb;
            font-family: 'Inter', sans-serif;
            color: #212529;
        }

        .page-content {
            padding: 28px;
        }

        .text-warning-custom {
            color: var(--primary);
        }

        .dashboard-hero {
            background: #fff;
            border: 1px solid var(--border-soft);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-soft);
            padding: 1.5rem 1.5rem;
            margin-bottom: 1.25rem;
        }

        .dashboard-hero h1 {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: .35rem;
        }

        .dashboard-hero p {
            color: var(--muted);
            margin-bottom: 0;
            max-width: 760px;
        }

        .role-badge {
            background: #fff8db;
            color: #8a6d00;
            border: 1px solid #ffe082;
            border-radius: 999px;
            padding: .45rem .85rem;
            font-weight: 700;
            font-size: .85rem;
            white-space: nowrap;
        }

        .summary-card {
            background: var(--surface);
            border: 1px solid var(--border-soft);
            border-radius: 16px;
            box-shadow: var(--shadow-soft);
            height: 100%;
            padding: 1rem 1rem;
        }

        .summary-label {
            font-size: .86rem;
            color: var(--muted);
            margin-bottom: .35rem;
        }

        .summary-value {
            font-size: 1.8rem;
            line-height: 1;
            font-weight: 800;
            margin-bottom: .35rem;
        }

        .summary-note {
            font-size: .85rem;
            color: var(--muted);
        }

        .summary-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .panel-card {
            background: var(--surface);
            border: 1px solid var(--border-soft);
            border-radius: var(--radius-lg);
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
            margin-bottom: .2rem;
        }

        .panel-subtitle {
            font-size: .87rem;
            color: var(--muted);
        }

        .panel-body {
            padding: 1rem 1.2rem;
        }

        .panel-section-title {
            font-size: .92rem;
            font-weight: 700;
            margin-bottom: .9rem;
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        .panel-divider {
            border-top: 1px solid var(--border-soft);
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: .8rem;
        }

        .activity-item {
            border: 1px solid var(--border-soft);
            border-radius: var(--radius-md);
            padding: .95rem 1rem;
            background: #fff;
        }

        .activity-title {
            font-weight: 700;
            margin-bottom: .3rem;
        }

        .badge-soft {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            background: #f8f9fa;
            color: #495057;
            border: 1px solid #e9ecef;
            border-radius: 999px;
            padding: .3rem .65rem;
            font-size: .76rem;
            font-weight: 600;
            margin-bottom: .5rem;
        }

        .meta-grid {
            display: grid;
            gap: .2rem;
        }

        .meta-line {
            font-size: .88rem;
            color: #495057;
            line-height: 1.45;
        }

        .meta-line strong {
            color: #212529;
        }

        .meta-muted {
            font-size: .84rem;
            color: var(--muted);
            line-height: 1.45;
        }

        .meta-muted i {
            width: 16px;
        }

        .empty-state {
            text-align: center;
            color: var(--muted);
            padding: 1.6rem 1rem;
            border: 1px dashed #d9dee5;
            border-radius: 14px;
            background: #fcfcfd;
        }

        .empty-state i {
            display: block;
            font-size: 1.6rem;
            margin-bottom: .45rem;
        }

        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        @media (max-width: 991.98px) {
            .page-content {
                padding: 18px;
            }

            .dashboard-hero h1 {
                font-size: 1.25rem;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <?php require_once '../layout/sidebar.php'; ?>

            <div class="col-md-10">
                <div class="page-content">

                    <div class="dashboard-hero">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div>
                                <h1>Dashboard IT Asset Management</h1>
                                <p>
                                    Ringkasan inventaris, aktivitas barang, kondisi perangkat, dan status pengiriman
                                    dalam tampilan yang lebih sederhana dan mudah dipantau.
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
                                    <div class="summary-icon bg-light text-dark">
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
                                        <div class="summary-value text-success"><?= $totalMasuk ?></div>
                                        <div class="summary-note">Masih aktif di inventaris</div>
                                    </div>
                                    <div class="summary-icon bg-success-subtle text-success">
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
                                        <div class="summary-value text-danger"><?= $totalKeluar ?></div>
                                        <div class="summary-note">Sudah dikirim atau keluar</div>
                                    </div>
                                    <div class="summary-icon bg-danger-subtle text-danger">
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
                                        <div class="summary-value text-warning"><?= $totalBermasalah ?></div>
                                        <div class="summary-note">Perlu perhatian khusus</div>
                                    </div>
                                    <div class="summary-icon bg-warning-subtle text-warning-emphasis">
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
                                        <div class="summary-value text-primary"><?= $totalSedangDikirim ?></div>
                                        <div class="summary-note">Belum diterima tujuan</div>
                                    </div>
                                    <div class="summary-icon bg-primary-subtle text-primary">
                                        <i class="bi bi-truck"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-xl-7">
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title">
                                        <i class="bi bi-clock-history me-2 text-warning-custom"></i>Aktivitas Terbaru
                                    </div>
                                    <div class="panel-subtitle">
                                        Ringkasan barang masuk dan barang keluar terbaru
                                    </div>
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
                                                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                                        <div class="flex-grow-1">
                                                            <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                            <div class="badge-soft"><?= h($item['nama_merk'] ?? '-') ?></div>

                                                            <div class="meta-grid">
                                                                <div class="meta-line"><strong>Asset:</strong> <?= h($item['no_asset'] ?? '-') ?></div>
                                                                <div class="meta-line"><strong>Serial:</strong> <?= h($item['serial_number'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-geo-alt me-1"></i><?= h($item['branch_asal'] ?? '-') ?></div>
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
                                                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                                        <div class="flex-grow-1">
                                                            <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                            <div class="badge-soft"><?= h($item['nama_merk'] ?? '-') ?></div>

                                                            <div class="meta-grid">
                                                                <div class="meta-line"><strong>Asset:</strong> <?= h($item['no_asset'] ?? '-') ?></div>
                                                                <div class="meta-line"><strong>Serial:</strong> <?= h($item['serial_number'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-geo-alt me-1"></i><?= h($item['branch_tujuan'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-receipt me-1"></i>Resi: <?= h($item['nomor_resi'] ?? '-') ?></div>
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
                            <div class="panel-card">
                                <div class="panel-header">
                                    <div class="panel-title">
                                        <i class="bi bi-exclamation-diamond me-2 text-warning-custom"></i>Perlu Perhatian
                                    </div>
                                    <div class="panel-subtitle">
                                        Fokus pada barang bermasalah dan pengiriman tertunda
                                    </div>
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
                                                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                                        <div class="flex-grow-1">
                                                            <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                            <div class="badge-soft"><?= h($item['nama_merk'] ?? '-') ?></div>

                                                            <div class="meta-grid">
                                                                <div class="meta-line"><strong>Asset:</strong> <?= h($item['no_asset'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-geo-alt me-1"></i><?= h($item['branch_asal'] ?? '-') ?></div>
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
                                                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                                        <div class="flex-grow-1">
                                                            <div class="activity-title"><?= h($item['nama_barang'] ?? '-') ?></div>
                                                            <div class="badge-soft"><?= h($item['nama_merk'] ?? '-') ?></div>

                                                            <div class="meta-grid">
                                                                <div class="meta-line"><strong>Asset:</strong> <?= h($item['no_asset'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-geo-alt me-1"></i><?= h($item['branch_tujuan'] ?? '-') ?></div>
                                                                <div class="meta-muted"><i class="bi bi-receipt me-1"></i>Resi: <?= h($item['nomor_resi'] ?? '-') ?></div>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>