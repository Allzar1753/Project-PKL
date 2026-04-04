<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'riwayat.view');

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$filter = isset($_GET['filter']) ? trim($_GET['filter']) : 'semua';
$tanggal_awal = isset($_GET['tanggal_awal']) ? trim($_GET['tanggal_awal']) : '';
$tanggal_akhir = isset($_GET['tanggal_akhir']) ? trim($_GET['tanggal_akhir']) : '';

if (!in_array($filter, ['semua', 'masuk', 'keluar'], true)) {
    $filter = 'semua';
}

$where = [];

if ($filter === 'masuk') {
    $where[] = "barang.tanggal_masuk IS NOT NULL";
} elseif ($filter === 'keluar') {
    $where[] = "barang.tanggal_keluar IS NOT NULL";
}

if ($tanggal_awal !== '' && $tanggal_akhir !== '') {
    if ($filter === 'keluar') {
        $where[] = "DATE(barang.tanggal_keluar) BETWEEN '" . mysqli_real_escape_string($koneksi, $tanggal_awal) . "' 
                    AND '" . mysqli_real_escape_string($koneksi, $tanggal_akhir) . "'";
    } else {
        $where[] = "DATE(barang.tanggal_masuk) BETWEEN '" . mysqli_real_escape_string($koneksi, $tanggal_awal) . "' 
                    AND '" . mysqli_real_escape_string($koneksi, $tanggal_akhir) . "'";
    }
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

$total_semua = (int) mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT COUNT(*) as total FROM barang"
))['total'];

$total_masuk = (int) mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT COUNT(*) as total FROM barang WHERE tanggal_masuk IS NOT NULL"
))['total'];

$total_keluar = (int) mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT COUNT(*) as total FROM barang WHERE tanggal_keluar IS NOT NULL"
))['total'];

$query = mysqli_query($koneksi, "
    SELECT 
        barang.*, 
        tb_barang.nama_barang,
        tb_merk.nama_merk,
        tb_tipe.nama_tipe,
        tb_status.nama_status,
        tb_jenis.nama_jenis,
        branch_asal.nama_branch AS branch_asal,
        branch_tujuan.nama_branch AS branch_tujuan
    FROM barang
    LEFT JOIN tb_barang ON barang.id_barang = tb_barang.id_barang
    LEFT JOIN tb_merk ON barang.id_merk = tb_merk.id_merk
    LEFT JOIN tb_tipe ON barang.id_tipe = tb_tipe.id_tipe
    LEFT JOIN tb_status ON barang.id_status = tb_status.id_status
    LEFT JOIN tb_jenis ON barang.id_jenis = tb_jenis.id_jenis
    LEFT JOIN tb_branch AS branch_asal ON barang.id_branch = branch_asal.id_branch
    LEFT JOIN tb_branch AS branch_tujuan ON barang.tujuan = branch_tujuan.id_branch
    $where_sql
    ORDER BY barang.id DESC
");

if (!$query) {
    die(mysqli_error($koneksi));
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Asset</title>

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
            --text-main: #1e1e1e;
            --text-soft: #6b7280;

            --surface: #ffffff;
            --surface-soft: #fffaf3;
            --border-soft: rgba(255, 152, 0, 0.14);
            --shadow-soft: 0 14px 40px rgba(17, 17, 17, 0.08);
            --shadow-hover: 0 18px 46px rgba(255, 122, 0, 0.14);

            --radius-xl: 28px;
            --radius-lg: 22px;
            --radius-md: 16px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background:
                radial-gradient(circle at top left, rgba(255, 176, 0, 0.16), transparent 26%),
                radial-gradient(circle at bottom right, rgba(255, 122, 0, 0.09), transparent 18%),
                linear-gradient(180deg, #fff8f1 0%, #fffaf5 35%, #ffffff 100%);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
        }

        .page-wrap {
            padding: 28px;
        }

        .hero-card {
            position: relative;
            overflow: hidden;
            border-radius: var(--radius-xl);
            background: linear-gradient(135deg, rgba(17, 17, 17, 0.95) 0%, rgba(42, 42, 42, 0.90) 30%, rgba(255, 122, 0, 0.96) 100%);
            box-shadow: 0 18px 45px rgba(255, 122, 0, 0.20);
            padding: 1.55rem 1.6rem;
            margin-bottom: 1.4rem;
        }

        .hero-card::before {
            content: "";
            position: absolute;
            width: 240px;
            height: 240px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            top: -90px;
            right: -65px;
        }

        .hero-card::after {
            content: "";
            position: absolute;
            width: 170px;
            height: 170px;
            border-radius: 50%;
            background: rgba(255, 209, 102, 0.16);
            left: -55px;
            bottom: -65px;
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .page-title {
            color: #fff;
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: .35rem;
            letter-spacing: -0.02em;
        }

        .page-subtitle {
            color: rgba(255, 255, 255, 0.84);
            margin-bottom: 0;
            line-height: 1.7;
            max-width: 720px;
            font-size: .94rem;
        }

        .glass-badge {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 999px;
            padding: .62rem .95rem;
            font-weight: 700;
            font-size: .84rem;
            backdrop-filter: blur(10px);
        }

        .summary-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(180deg, #ffffff 0%, #fffaf3 100%);
            border: 1px solid rgba(255, 176, 0, 0.15);
            border-radius: 22px;
            box-shadow: var(--shadow-soft);
            height: 100%;
            padding: 1.15rem;
            transition: all .25s ease;
        }

        .summary-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 5px;
            background: linear-gradient(90deg, var(--orange-1), var(--orange-3));
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
        }

        .summary-label {
            font-size: .84rem;
            color: var(--text-soft);
            font-weight: 700;
            margin-bottom: .38rem;
        }

        .summary-value {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
            color: var(--dark-1);
            margin-bottom: .35rem;
        }

        .summary-note {
            font-size: .82rem;
            color: var(--text-soft);
            line-height: 1.5;
        }

        .summary-icon {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
            color: #fff;
            background: linear-gradient(135deg, var(--orange-1), var(--orange-3));
            box-shadow: 0 10px 24px rgba(255, 152, 0, 0.20);
        }

        .filter-card,
        .table-card {
            background: #fff;
            border: 1px solid var(--border-soft);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-soft);
            overflow: hidden;
        }

        .filter-card {
            padding: 1.2rem;
            margin-bottom: 1.35rem;
        }

        .section-title {
            font-size: 1rem;
            font-weight: 800;
            color: var(--dark-1);
            margin-bottom: .25rem;
        }

        .section-subtitle {
            font-size: .86rem;
            color: var(--text-soft);
            margin-bottom: 1rem;
        }

        .form-label {
            font-weight: 700;
            color: var(--dark-1);
            font-size: .88rem;
            margin-bottom: .45rem;
        }

        .form-control,
        .form-select {
            border-radius: 14px;
            border: 1px solid #e8dcc7;
            padding: .82rem .95rem;
            box-shadow: none;
            font-size: .92rem;
            background: #fff;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #f0c63d;
            box-shadow: 0 0 0 .2rem rgba(255, 193, 7, 0.14);
        }

        .btn-filter {
            border: none;
            border-radius: 14px;
            padding: .86rem 1rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--orange-1), var(--orange-3));
            color: #fff;
            box-shadow: 0 12px 28px rgba(255, 152, 0, 0.18);
            transition: all .22s ease;
        }

        .btn-filter:hover {
            color: #fff;
            transform: translateY(-1px);
            filter: brightness(.98);
        }

        .table-card-header {
            padding: 1.1rem 1.2rem;
            background: linear-gradient(135deg, #111111 0%, #2c2c2c 40%, #ff8f00 100%);
            color: #fff;
        }

        .table-card-title {
            font-size: 1rem;
            font-weight: 800;
            margin-bottom: .2rem;
        }

        .table-card-subtitle {
            font-size: .84rem;
            color: rgba(255, 255, 255, 0.82);
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: #fffaf2;
            color: #2c2c2c;
            font-weight: 800;
            white-space: nowrap;
            border-bottom: 1px solid #f0dfc3;
            padding-top: 1rem;
            padding-bottom: 1rem;
        }

        .table tbody td {
            vertical-align: top;
            padding-top: 1rem;
            padding-bottom: 1rem;
            border-color: #f3ece2;
        }

        .table tbody tr:hover {
            background: #fffaf4;
        }

        .asset-code {
            font-weight: 800;
            color: var(--dark-1);
        }

        .meta-line {
            display: block;
            font-size: .89rem;
            line-height: 1.5;
            color: #222;
        }

        .meta-muted {
            display: block;
            font-size: .84rem;
            color: var(--text-soft);
            line-height: 1.5;
        }

        .thumb-img {
            width: 58px;
            height: 58px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid #eadcc7;
            box-shadow: 0 8px 18px rgba(17, 17, 17, 0.05);
        }

        .thumb-placeholder {
            width: 58px;
            height: 58px;
            border-radius: 12px;
            border: 1px dashed #d8c6ab;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #c59a57;
            background: #fff8ee;
        }

        .badge {
            border-radius: 999px;
            padding: .48rem .75rem;
            font-size: .74rem;
            font-weight: 700;
            letter-spacing: .1px;
        }

        .badge.bg-success {
            background: linear-gradient(135deg, #111111, #2e2e2e) !important;
            color: #fff !important;
        }

        .badge.bg-danger {
            background: linear-gradient(135deg, #ff7a00, #ff9f1a) !important;
            color: #fff !important;
        }

        .badge.bg-light {
            background: #fff4de !important;
            color: #8b4f00 !important;
            border: 1px solid rgba(255, 152, 0, 0.18) !important;
        }

        .empty-state {
            text-align: center;
            color: var(--text-soft);
            padding: 2.1rem 1rem !important;
        }

        .empty-state i {
            display: block;
            font-size: 1.7rem;
            margin-bottom: .5rem;
            color: var(--orange-2);
        }

        @media (max-width: 991.98px) {
            .page-wrap {
                padding: 18px;
            }

            .hero-card {
                padding: 1.3rem 1.15rem;
            }

            .page-title {
                font-size: 1.4rem;
            }

            .summary-value {
                font-size: 1.7rem;
            }

            .filter-card,
            .table-card-header {
                padding-left: 1rem;
                padding-right: 1rem;
            }
        }

        @media (max-width: 575.98px) {
            .page-title {
                font-size: 1.2rem;
            }

            .page-subtitle {
                font-size: .9rem;
            }

            .glass-badge {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <?php require_once '../layout/sidebar.php'; ?>

            <div class="col-md-10">
                <div class="page-wrap">

                    <div class="hero-card">
                        <div class="hero-content d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div>
                                <h1 class="page-title">
                                    <i class="bi bi-clock-history me-2"></i>Riwayat Aktivitas Asset
                                </h1>
                                <p class="page-subtitle">
                                    Pantau histori pergerakan inventaris barang masuk dan barang keluar dalam tampilan yang lebih rapi, jelas, dan nyaman dipantau.
                                </p>
                            </div>

                            <div class="glass-badge">
                                <i class="bi bi-funnel"></i>
                                <span><?= ucfirst(h($filter)) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <a href="index.php" class="text-decoration-none">
                                <div class="summary-card">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <div class="summary-label">Semua Aktivitas</div>
                                            <div class="summary-value"><?= $total_semua ?></div>
                                            <div class="summary-note">Total seluruh riwayat asset</div>
                                        </div>
                                        <div class="summary-icon">
                                            <i class="bi bi-grid-1x2-fill"></i>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>

                        <div class="col-md-4">
                            <a href="index.php?filter=masuk" class="text-decoration-none">
                                <div class="summary-card">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <div class="summary-label">Barang Masuk</div>
                                            <div class="summary-value"><?= $total_masuk ?></div>
                                            <div class="summary-note">Riwayat asset yang tercatat masuk</div>
                                        </div>
                                        <div class="summary-icon">
                                            <i class="bi bi-box-arrow-in-down"></i>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>

                        <div class="col-md-4">
                            <a href="index.php?filter=keluar" class="text-decoration-none">
                                <div class="summary-card">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <div class="summary-label">Barang Keluar</div>
                                            <div class="summary-value"><?= $total_keluar ?></div>
                                            <div class="summary-note">Riwayat asset yang sudah keluar</div>
                                        </div>
                                        <div class="summary-icon">
                                            <i class="bi bi-box-arrow-up"></i>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>

                    <div class="filter-card">
                        <div class="section-title">Filter Riwayat</div>
                        <div class="section-subtitle">Pilih rentang tanggal dan tipe aktivitas untuk menampilkan riwayat yang lebih spesifik.</div>

                        <form method="GET">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label">Tanggal Awal</label>
                                    <input type="date" name="tanggal_awal" class="form-control" value="<?= h($tanggal_awal) ?>">
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label">Tanggal Akhir</label>
                                    <input type="date" name="tanggal_akhir" class="form-control" value="<?= h($tanggal_akhir) ?>">
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label">Filter Aktivitas</label>
                                    <select name="filter" class="form-select">
                                        <option value="semua" <?= $filter === 'semua' ? 'selected' : '' ?>>Semua Aktivitas</option>
                                        <option value="masuk" <?= $filter === 'masuk' ? 'selected' : '' ?>>Barang Masuk</option>
                                        <option value="keluar" <?= $filter === 'keluar' ? 'selected' : '' ?>>Barang Keluar</option>
                                    </select>
                                </div>

                                <div class="col-md-3">
                                    <button class="btn btn-filter w-100">
                                        <i class="bi bi-search me-2"></i>Filter Data
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="table-card">
                        <div class="table-card-header">
                            <div class="table-card-title">Daftar Riwayat Asset</div>
                            <div class="table-card-subtitle">Menampilkan histori aktivitas inventaris berdasarkan filter yang dipilih</div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Asset & SN</th>
                                        <th>Nama Barang</th>
                                        <th>Detail</th>
                                        <th>Tanggal</th>
                                        <th>Logistik</th>
                                        <th>Status</th>
                                        <th>Masalah</th>
                                        <th>Foto</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php $no = 1; ?>
                                    <?php if (mysqli_num_rows($query) > 0): ?>
                                        <?php while ($data = mysqli_fetch_assoc($query)): ?>
                                            <tr>
                                                <td><?= $no++ ?></td>

                                                <td>
                                                    <div class="asset-code"><?= h($data['no_asset'] ?? '-') ?></div>
                                                    <small class="meta-muted"><?= h($data['serial_number'] ?? '-') ?></small>
                                                </td>

                                                <td>
                                                    <div class="fw-bold mb-1"><?= h($data['nama_barang'] ?? '-') ?></div>
                                                    <span class="badge bg-light"><?= h($data['nama_merk'] ?? '-') ?></span>
                                                </td>

                                                <td>
                                                    <span class="meta-line"><b>Tipe:</b> <?= h($data['nama_tipe'] ?? '-') ?></span>
                                                    <span class="meta-line"><b>Jenis:</b> <?= h($data['nama_jenis'] ?? '-') ?></span>
                                                </td>

                                                <td>
                                                    <span class="meta-line"><b>Masuk:</b> <?= h($data['tanggal_masuk'] ?? '-') ?></span>
                                                    <span class="meta-line"><b>Keluar:</b> <?= !empty($data['tanggal_keluar']) ? h($data['tanggal_keluar']) : '-' ?></span>
                                                </td>

                                                <td>
                                                    <span class="meta-line">
                                                        <i class="bi bi-geo-alt me-1"></i>
                                                        <?= !empty($data['tanggal_keluar']) ? h($data['branch_tujuan'] ?? '-') : h($data['branch_asal'] ?? '-') ?>
                                                    </span>
                                                    <span class="meta-muted">
                                                        <i class="bi bi-person me-1"></i><?= h($data['user'] ?? '-') ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <div class="mb-2">
                                                        <?php if (($data['bermasalah'] ?? '') === 'Iya'): ?>
                                                            <span class="badge bg-danger">Bermasalah</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-success">Normal</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="meta-muted"><?= h($data['nama_status'] ?? '-') ?></span>
                                                </td>

                                                <td>
                                                    <?php if (($data['bermasalah'] ?? '') === 'Iya'): ?>
                                                        <span class="text-danger"><?= h($data['keterangan_masalah'] ?? '-') ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php if (!empty($data['foto'])): ?>
                                                        <img src="../assets/images/<?= h($data['foto']) ?>" class="thumb-img" alt="Foto Barang">
                                                    <?php else: ?>
                                                        <span class="thumb-placeholder"><i class="bi bi-image"></i></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="empty-state">
                                                <i class="bi bi-inbox"></i>
                                                Data riwayat tidak ditemukan.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</body>
</html>