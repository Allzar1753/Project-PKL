<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'laporan.view');

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function build_url(array $params = []): string
{
    $base = basename($_SERVER['PHP_SELF']);
    $clean = [];

    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $clean[$key] = $value;
    }

    return empty($clean) ? $base : $base . '?' . http_build_query($clean);
}

function status_badge(string $status): string
{
    if ($status === 'Sudah diterima') {
        return '<span class="status-badge status-done">Sudah diterima</span>';
    }

    if ($status === 'Sedang perjalanan') {
        return '<span class="status-badge status-road">Sedang perjalanan</span>';
    }

    if ($status === 'Sedang dikemas') {
        return '<span class="status-badge status-pack">Sedang dikemas</span>';
    }

    return '<span class="status-badge status-wait">' . h($status ?: 'Belum dikirim') . '</span>';
}

function get_iso_week_default(): string
{
    return date('o') . '-W' . date('W');
}

function resolve_period_range(string $periode, string $tahun, string $bulan, string $minggu, string $customAwal, string $customAkhir): array
{
    $now = new DateTime();
    $label = '';

    if (!preg_match('/^\d{4}$/', $tahun)) {
        $tahun = $now->format('Y');
    }

    if (!preg_match('/^(0[1-9]|1[0-2])$/', $bulan)) {
        $bulan = $now->format('m');
    }

    if ($periode === 'tahun') {
        $startDate = new DateTime($tahun . '-01-01');
        $endDate   = new DateTime($tahun . '-12-31');
        $label = 'Tahun ' . $tahun;
    } elseif ($periode === 'minggu') {
        if (!preg_match('/^(\d{4})-W(\d{2})$/', $minggu, $matches)) {
            $minggu = get_iso_week_default();
            preg_match('/^(\d{4})-W(\d{2})$/', $minggu, $matches);
        }

        $isoYear = (int) $matches[1];
        $isoWeek = (int) $matches[2];

        $startDate = new DateTime();
        $startDate->setISODate($isoYear, $isoWeek);
        $endDate = clone $startDate;
        $endDate->modify('+6 days');

        $label = 'Minggu ' . $isoWeek . ' - ' . $isoYear;
    } elseif ($periode === 'custom') {
        if ($customAwal === '' && $customAkhir === '') {
            $startDate = new DateTime($now->format('Y-m-01'));
            $endDate   = new DateTime($now->format('Y-m-t'));
        } else {
            $startDate = new DateTime($customAwal !== '' ? $customAwal : $customAkhir);
            $endDate   = new DateTime($customAkhir !== '' ? $customAkhir : $customAwal);
        }

        if ($startDate > $endDate) {
            $temp = $startDate;
            $startDate = $endDate;
            $endDate = $temp;
        }

        $label = 'Custom: ' . $startDate->format('d M Y') . ' s/d ' . $endDate->format('d M Y');
    } else {
        $startDate = new DateTime($tahun . '-' . $bulan . '-01');
        $endDate   = new DateTime($tahun . '-' . $bulan . '-01');
        $endDate->modify('last day of this month');
        $label = 'Bulan ' . $startDate->format('F Y');
        $periode = 'bulan';
    }

    return [
        'periode' => $periode,
        'start'   => $startDate->format('Y-m-d'),
        'end'     => $endDate->format('Y-m-d'),
        'label'   => $label,
    ];
}

function build_pengiriman_note(array $step): string
{
    $parts = [];

    if (!empty($step['jasa_pengiriman'])) {
        $parts[] = 'Dikirim via <b>' . h($step['jasa_pengiriman']) . '</b>';
    }

    if (!empty($step['nomor_resi_keluar'])) {
        $parts[] = 'Nomor resi <b>' . h($step['nomor_resi_keluar']) . '</b>';
    }

    if (!empty($step['catatan_pengiriman_keluar'])) {
        $parts[] = h($step['catatan_pengiriman_keluar']);
    } else {
        $parts[] = 'Pengiriman dibuat tanpa catatan tambahan';
    }

    return implode('. ', $parts) . '.';
}

function build_penerimaan_note(array $step): string
{
    if (($step['status_pengiriman'] ?? '') !== 'Sudah diterima') {
        return 'Belum ada konfirmasi penerimaan barang.';
    }

    $parts = [];

    if (!empty($step['nama_penerima'])) {
        $parts[] = 'Diterima oleh <b>' . h($step['nama_penerima']) . '</b>';
    } else {
        $parts[] = 'Penerimaan sudah dikonfirmasi';
    }

    if (!empty($step['tanggal_diterima'])) {
        $parts[] = 'pada <b>' . h($step['tanggal_diterima']) . '</b>';
    }

    if (!empty($step['catatan_penerimaan'])) {
        $parts[] = h($step['catatan_penerimaan']);
    } else {
        $parts[] = 'Tanpa catatan tambahan saat penerimaan';
    }

    return implode('. ', $parts) . '.';
}

$periode     = trim((string) ($_GET['periode'] ?? 'bulan'));
$tahun       = trim((string) ($_GET['tahun'] ?? date('Y')));
$bulan       = trim((string) ($_GET['bulan'] ?? date('m')));
$minggu      = trim((string) ($_GET['minggu'] ?? get_iso_week_default()));
$customAwal  = trim((string) ($_GET['custom_awal'] ?? ''));
$customAkhir = trim((string) ($_GET['custom_akhir'] ?? ''));

if (!in_array($periode, ['minggu', 'bulan', 'tahun', 'custom'], true)) {
    $periode = 'bulan';
}

$range = resolve_period_range($periode, $tahun, $bulan, $minggu, $customAwal, $customAkhir);

$sql = "
    SELECT
        b.id,
        b.no_asset,
        b.serial_number,
        b.tanggal_masuk,
        b.user,
        b.bermasalah,
        b.keterangan_masalah,
        tb.nama_barang,
        bm.nama_merk,
        st.nama_status,
        ba.nama_branch AS branch_aktif,

        bp.id_pengiriman,
        bp.tanggal_keluar,
        bp.tanggal_diterima,
        bp.status_pengiriman,
        bp.nomor_resi_keluar,
        bp.nama_penerima,
        bp.jasa_pengiriman,
        bp.catatan_pengiriman_keluar,
        bp.catatan_penerimaan,

        bpasal.nama_branch AS branch_asal_pengiriman,
        bptujuan.nama_branch AS branch_tujuan,

        DATE(COALESCE(bp.tanggal_keluar, b.tanggal_masuk)) AS tanggal_aktivitas
    FROM barang b
    LEFT JOIN tb_barang tb ON tb.id_barang = b.id_barang
    LEFT JOIN tb_merk bm ON bm.id_merk = b.id_merk
    LEFT JOIN tb_status st ON st.id_status = b.id_status
    LEFT JOIN tb_branch ba ON ba.id_branch = b.id_branch
    LEFT JOIN barang_pengiriman bp ON bp.id_barang = b.id
    LEFT JOIN tb_branch bpasal ON bpasal.id_branch = bp.branch_asal
    LEFT JOIN tb_branch bptujuan ON bptujuan.id_branch = bp.branch_tujuan
    WHERE DATE(COALESCE(bp.tanggal_keluar, b.tanggal_masuk)) BETWEEN ? AND ?
    ORDER BY b.id DESC, bp.id_pengiriman ASC
";

$stmt = mysqli_prepare($koneksi, $sql);
if (!$stmt) {
    die(mysqli_error($koneksi));
}

mysqli_stmt_bind_param($stmt, 'ss', $range['start'], $range['end']);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$groupedAssets = [];
$totalPengiriman = 0;
$totalDiterima = 0;
$totalProses = 0;

while ($row = mysqli_fetch_assoc($result)) {
    $id = (int) $row['id'];

    if (!isset($groupedAssets[$id])) {
        $groupedAssets[$id] = [
            'asset' => [
                'id' => $row['id'],
                'no_asset' => $row['no_asset'],
                'serial_number' => $row['serial_number'],
                'tanggal_masuk' => $row['tanggal_masuk'],
                'user' => $row['user'],
                'bermasalah' => $row['bermasalah'],
                'keterangan_masalah' => $row['keterangan_masalah'],
                'nama_barang' => $row['nama_barang'],
                'nama_merk' => $row['nama_merk'],
                'nama_status' => $row['nama_status'],
                'branch_aktif' => $row['branch_aktif'],
            ],
            'timeline' => []
        ];
    }

    if (!empty($row['id_pengiriman'])) {
        $groupedAssets[$id]['timeline'][] = [
            'id_pengiriman' => $row['id_pengiriman'],
            'tanggal_keluar' => $row['tanggal_keluar'],
            'tanggal_diterima' => $row['tanggal_diterima'],
            'status_pengiriman' => $row['status_pengiriman'] ?: 'Belum dikirim',
            'nomor_resi_keluar' => $row['nomor_resi_keluar'],
            'nama_penerima' => $row['nama_penerima'],
            'jasa_pengiriman' => $row['jasa_pengiriman'],
            'catatan_pengiriman_keluar' => $row['catatan_pengiriman_keluar'],
            'catatan_penerimaan' => $row['catatan_penerimaan'],
            'branch_asal_pengiriman' => $row['branch_asal_pengiriman'],
            'branch_tujuan' => $row['branch_tujuan'],
        ];

        $totalPengiriman++;

        if (($row['status_pengiriman'] ?? '') === 'Sudah diterima') {
            $totalDiterima++;
        } else {
            $totalProses++;
        }
    }
}

mysqli_stmt_close($stmt);

$totalAsset = count($groupedAssets);

$periodeLabel = $range['label'];

$bulanOptions = [
    '01' => 'Januari',
    '02' => 'Februari',
    '03' => 'Maret',
    '04' => 'April',
    '05' => 'Mei',
    '06' => 'Juni',
    '07' => 'Juli',
    '08' => 'Agustus',
    '09' => 'September',
    '10' => 'Oktober',
    '11' => 'November',
    '12' => 'Desember'
];
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Asset</title>

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
            --shadow-soft: 0 14px 40px rgba(17, 17, 17, 0.08);
            --shadow-hover: 0 18px 46px rgba(255, 122, 0, 0.14);
            --radius-xl: 28px;
            --radius-lg: 22px;
            --radius-md: 16px;
            --radius-sm: 12px;
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
            padding: 24px 26px;
        }

        .page-container {
            max-width: 1180px;
            margin: 0 auto;
        }

        .hero-card {
            position: relative;
            overflow: hidden;
            border-radius: var(--radius-xl);
            background: linear-gradient(135deg, rgba(17, 17, 17, 0.95) 0%, rgba(42, 42, 42, 0.90) 30%, rgba(255, 122, 0, 0.96) 100%);
            box-shadow: 0 18px 45px rgba(255, 122, 0, 0.20);
            padding: 1.45rem 1.55rem;
            margin-bottom: 1.35rem;
        }

        .hero-card::before {
            content: "";
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            top: -90px;
            right: -65px;
        }

        .hero-card::after {
            content: "";
            position: absolute;
            width: 150px;
            height: 150px;
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
            max-width: 760px;
            font-size: .92rem;
        }

        .hero-action {
            border: none;
            background: #fff;
            color: #111;
            font-weight: 800;
            border-radius: 999px;
            padding: .82rem 1.2rem;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.12);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            cursor: pointer;
        }

        .hero-action:hover {
            background: #fff7ea;
            color: #111;
        }

        .panel-card,
        .report-card,
        .asset-card,
        .summary-card {
            background: #fff;
            border: 1px solid var(--border-soft);
            border-radius: 22px;
            box-shadow: var(--shadow-soft);
        }

        .summary-card {
            position: relative;
            overflow: hidden;
            height: 100%;
            padding: 1.1rem;
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
            margin-bottom: .35rem;
        }

        .summary-value {
            font-size: 1.9rem;
            font-weight: 800;
            line-height: 1;
            color: var(--dark-1);
            margin-bottom: .3rem;
        }

        .summary-note {
            font-size: .82rem;
            color: var(--text-soft);
            line-height: 1.5;
        }

        .summary-icon {
            width: 50px;
            height: 50px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
            color: #fff;
            background: linear-gradient(135deg, var(--orange-1), var(--orange-3));
            box-shadow: 0 10px 24px rgba(255, 152, 0, 0.20);
        }

        .card-head {
            padding: 1.05rem 1.2rem;
            background: linear-gradient(135deg, #111111 0%, #2c2c2c 40%, #ff8f00 100%);
            color: #fff;
            border-radius: 22px 22px 0 0;
        }

        .card-head-title {
            font-size: 1rem;
            font-weight: 800;
            margin-bottom: .2rem;
        }

        .card-head-subtitle {
            font-size: .84rem;
            color: rgba(255, 255, 255, 0.82);
        }

        .card-body-custom {
            padding: 1.15rem;
            background: linear-gradient(180deg, #fffdf9 0%, #fff8ef 100%);
            border-radius: 0 0 22px 22px;
        }

        .form-label {
            font-weight: 700;
            color: var(--dark-1);
            margin-bottom: .45rem;
            font-size: .88rem;
        }

        .form-control,
        .form-select {
            border-radius: 14px;
            min-height: 46px;
            border: 1px solid #e9dcc8;
            box-shadow: none;
            padding: .8rem .95rem;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #f0c63d;
            box-shadow: 0 0 0 .2rem rgba(255, 193, 7, 0.14);
        }

        .btn-main {
            border: none;
            border-radius: 14px;
            font-weight: 800;
            padding: .82rem 1rem;
            background: linear-gradient(135deg, var(--orange-1), var(--orange-3));
            color: #fff;
            box-shadow: 0 12px 28px rgba(255, 152, 0, 0.18);
            transition: all .22s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-main:hover {
            color: #fff;
            transform: translateY(-1px);
            filter: brightness(.98);
        }

        .period-chip {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            border-radius: 999px;
            background: #fff3de;
            color: #8b4f00;
            border: 1px solid rgba(255, 152, 0, 0.16);
            padding: .38rem .75rem;
            font-size: .8rem;
            font-weight: 800;
        }

        .asset-card+.asset-card {
            margin-top: 1rem;
        }

        .asset-top {
            padding: 1rem 1rem;
            background: linear-gradient(180deg, #fffdf9 0%, #fff7ee 100%);
            border-bottom: 1px solid #f0ece4;
            border-radius: 22px 22px 0 0;
        }

        .asset-title {
            font-size: 1rem;
            font-weight: 800;
            color: var(--dark-1);
            margin-bottom: .2rem;
        }

        .asset-meta {
            color: var(--text-soft);
            font-size: .88rem;
            line-height: 1.6;
        }

        .asset-body {
            padding: 1rem;
        }

        .info-box {
            background: linear-gradient(180deg, #fffaf3 0%, #fff6ea 100%);
            border: 1px solid rgba(255, 152, 0, 0.12);
            border-radius: 16px;
            padding: .95rem 1rem;
            color: var(--text-soft);
            font-size: .88rem;
            line-height: 1.7;
            margin-bottom: 1rem;
        }

        .timeline {
            position: relative;
            padding-left: 1.2rem;
        }

        .timeline::before {
            content: "";
            position: absolute;
            top: 0;
            bottom: 0;
            left: .32rem;
            width: 2px;
            background: linear-gradient(180deg, rgba(255, 152, 0, 0.25), rgba(255, 122, 0, 0.10));
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1rem;
            padding-left: .9rem;
        }

        .timeline-item:last-child {
            margin-bottom: 0;
        }

        .timeline-dot {
            position: absolute;
            left: -1.2rem;
            top: .4rem;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--orange-1), var(--orange-3));
            box-shadow: 0 0 0 4px #fff3e0;
        }

        .timeline-card {
            background: #fff;
            border: 1px solid #efe4d2;
            border-radius: 18px;
            overflow: hidden;
        }

        .timeline-head {
            padding: .95rem 1rem;
            background: #fffaf2;
            border-bottom: 1px solid #f1e6d8;
        }

        .timeline-title {
            font-size: .96rem;
            font-weight: 800;
            color: var(--dark-1);
            margin-bottom: .15rem;
        }

        .timeline-subtitle {
            font-size: .82rem;
            color: var(--text-soft);
        }

        .timeline-body {
            padding: 1rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .8rem;
            margin-bottom: 1rem;
        }

        .detail-box {
            background: #fffdf9;
            border: 1px solid #f0e4d4;
            border-radius: 14px;
            padding: .8rem .9rem;
        }

        .detail-label {
            font-size: .74rem;
            font-weight: 800;
            color: #9a6d1d;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: .25rem;
        }

        .detail-value {
            font-size: .92rem;
            font-weight: 700;
            color: var(--dark-1);
            line-height: 1.55;
            word-break: break-word;
        }

        .log-note-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .8rem;
        }

        .log-note-card {
            background: linear-gradient(180deg, #fffaf3 0%, #fff6ea 100%);
            border: 1px solid rgba(255, 152, 0, 0.12);
            border-radius: 14px;
            padding: .85rem .9rem;
        }

        .log-note-title {
            font-size: .82rem;
            font-weight: 800;
            color: #8b4f00;
            margin-bottom: .35rem;
        }

        .log-note-text {
            font-size: .88rem;
            line-height: 1.65;
            color: #5e6673;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: .48rem .82rem;
            font-size: .76rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .status-done {
            background: linear-gradient(135deg, #111111, #2d2d2d);
            color: #fff;
        }

        .status-road {
            background: linear-gradient(135deg, #ff8c00, #ffb000);
            color: #fff;
        }

        .status-pack {
            background: linear-gradient(135deg, #ffd166, #ffbf47);
            color: #5b3a00;
        }

        .status-wait {
            background: #fff7ea;
            color: #6b4a00;
            border: 1px solid rgba(255, 176, 0, 0.25);
        }

        .empty-state {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--text-soft);
        }

        .empty-state i {
            display: block;
            font-size: 1.7rem;
            margin-bottom: .45rem;
            color: var(--orange-2);
        }

        .print-header,
        .print-only {
            display: none;
        }

        @page {
            size: A4 portrait;
            margin: 10mm;
        }

        @media (max-width: 991.98px) {
            .page-wrap {
                padding: 18px;
            }

            .page-container {
                max-width: 100%;
            }

            .page-title {
                font-size: 1.4rem;
            }

            .hero-card {
                padding: 1.2rem 1.05rem;
            }

            .detail-grid,
            .log-note-grid {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            html, 
            body {
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
                font-size: 12px;
                color: #000 !important;
            }

            .laporan-layout > :not(.laporan-main) {
                display: none !important;
            }

            .laporan-main,
            .laporan-main .page-wrap,
            .laporan-main .page-container {
                width: 100% !important;
                max-width: 100 !important;
                margin: 0 !important;
                padding: 0 !important;
                flex: 0 0 100% !important;
            }

            .no-print,
            .no-print * {
                display: none !important;
            }

            .print-header {
                display: block !important;
                margin-bottom: 12px !important;
            }

            .print-only {
                display: block !important;
            }

            .report-card,
            .asset-card,
            .timeline-card,
            .info-box,
            .detail-box,
            .log-note-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                background: #fff in !important;
            }

            .asset-card, 
            .timeline-item {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .asset-top,
            .asset-body,
            .timeline-head,
            .timeline-body,
            .card-body-custom {
                background: #fff !important;
            }

            .timeline::before {
                background: #ccc !important;
            }

            .timeline-dot {
                background: #666 !important;
                box-shadow: none Im !important;
            }

            .status-badge {
                border: 1px solid #999 !important;
                background: #fff !important;
                color: #000 !important;
            }

            .detail-grid,
            .log-note-grid {
                grid-template-columns: 1fr 1fr;
            }

            @page {
                size: A4 portrait;
                margin: 12mm;
            }

            body::after {
                content: "PT HEXINDO ADIPEKARSA TBK";
                position: fixed;
                bottom: 5mm;
                left: 0;
                right: 0;
                text-align: center;
                font-size: 11px;
                color: #444;
            }

            body.print-single-asset .asset-card {
                display: none !important;
            }

            body.print-single-asset .asset-card.print-target {
                display: block !important;
            }
 
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row laporan-layout">
            <?php require_once '../layout/sidebar.php'; ?>

            <div class="col-md-10 laporan-main">
                <div class="page-wrap">
                    <div class="page-container">

                        <div class="print-header">
                            <h2 style="margin:0; font-size:22px; font-weight:800; letter-spacing:1px;">LAPORAN</h2>
                            <div style="margin-top:4px; font-size:13px;">Log Aktivitas Asset</div>
                            <div style="margin-top:6px; font-size:12px;">
                                Periode: <?= h($periodeLabel) ?>
                            </div>
                            <div style="margin-top:4px; font-size:12px; color:#444;">
                                Total Asset: <?= (int) $totalAsset ?> |
                                Total Pengiriman: <?= (int) $totalPengiriman ?> |
                                Diterima: <?= (int) $totalDiterima ?> |
                                Proses: <?= (int) $totalProses ?>
                            </div>
                        </div>

                        <div class="hero-card no-print">
                            <div class="hero-content d-flex justify-content-between align-items-start flex-wrap gap-3">
                                <div>
                                    <h1 class="page-title">Laporan Asset</h1>
                                    <p class="page-subtitle">
                                        Tampilan laporan dibuat lebih jelas, lebih maju, lebih rapi, dan proses cetak sekarang tetap di halaman yang sama.
                                    </p>
                                </div>

                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="hero-action" onclick="printAllReport()">
                                        <i class="bi bi-printer me-2"></i>Cetak Semua
                                    </button>
                                    <a href="<?= h(build_url()) ?>" class="hero-action">
                                        <i class="bi bi-arrow-counterclockwise me-2"></i>Reset
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="panel-card mb-4 no-print">
                            <div class="card-head">
                                <div class="card-head-title">Filter Laporan</div>
                                <div class="card-head-subtitle">Pilih periode laporan yang mau dilihat.</div>
                            </div>

                            <div class="card-body-custom">
                                <form method="GET" class="row g-3 align-items-end">
                                    <div class="col-lg-3">
                                        <label class="form-label">Jenis Filter</label>
                                        <select name="periode" id="periodeFilter" class="form-select">
                                            <option value="minggu" <?= $range['periode'] === 'minggu' ? 'selected' : '' ?>>Per Minggu</option>
                                            <option value="bulan" <?= $range['periode'] === 'bulan' ? 'selected' : '' ?>>Per Bulan</option>
                                            <option value="tahun" <?= $range['periode'] === 'tahun' ? 'selected' : '' ?>>Per Tahun</option>
                                            <option value="custom" <?= $range['periode'] === 'custom' ? 'selected' : '' ?>>Custom Tanggal</option>
                                        </select>
                                    </div>

                                    <div class="col-lg-2 filter-bulan-tahun">
                                        <label class="form-label">Tahun</label>
                                        <input type="number" name="tahun" class="form-control" value="<?= h($tahun) ?>" min="2000" max="2100">
                                    </div>

                                    <div class="col-lg-2 filter-bulan">
                                        <label class="form-label">Bulan</label>
                                        <select name="bulan" class="form-select">
                                            <?php foreach ($bulanOptions as $num => $label): ?>
                                                <option value="<?= h($num) ?>" <?= $bulan === $num ? 'selected' : '' ?>>
                                                    <?= h($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-lg-3 filter-minggu" style="display:none;">
                                        <label class="form-label">Minggu</label>
                                        <input type="week" name="minggu" class="form-control" value="<?= h($minggu) ?>">
                                    </div>

                                    <div class="col-lg-3 filter-custom" style="display:none;">
                                        <label class="form-label">Tanggal Awal</label>
                                        <input type="date" name="custom_awal" class="form-control" value="<?= h($customAwal) ?>">
                                    </div>

                                    <div class="col-lg-3 filter-custom" style="display:none;">
                                        <label class="form-label">Tanggal Akhir</label>
                                        <input type="date" name="custom_akhir" class="form-control" value="<?= h($customAkhir) ?>">
                                    </div>

                                    <div class="col-lg-2">
                                        <div class="d-grid">
                                            <button type="submit" class="btn-main">
                                                <i class="bi bi-funnel me-2"></i>Tampilkan
                                            </button>
                                        </div>
                                    </div>
                                </form>

                                <div class="mt-4">
                                    <span class="period-chip">
                                        <i class="bi bi-calendar3"></i><?= h($periodeLabel) ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-4 no-print">
                            <div class="col-md-3">
                                <div class="summary-card">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <div class="summary-label">Total Asset</div>
                                            <div class="summary-value"><?= (int) $totalAsset ?></div>
                                            <div class="summary-note">Asset pada periode ini</div>
                                        </div>
                                        <div class="summary-icon">
                                            <i class="bi bi-box-seam"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="summary-card">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <div class="summary-label">Total Pengiriman</div>
                                            <div class="summary-value"><?= (int) $totalPengiriman ?></div>
                                            <div class="summary-note">Seluruh aktivitas kirim</div>
                                        </div>
                                        <div class="summary-icon">
                                            <i class="bi bi-truck"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="summary-card">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <div class="summary-label">Sudah Diterima</div>
                                            <div class="summary-value"><?= (int) $totalDiterima ?></div>
                                            <div class="summary-note">Pengiriman selesai</div>
                                        </div>
                                        <div class="summary-icon">
                                            <i class="bi bi-check2-circle"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="summary-card">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <div class="summary-label">Masih Proses</div>
                                            <div class="summary-value"><?= (int) $totalProses ?></div>
                                            <div class="summary-note">Belum selesai diterima</div>
                                        </div>
                                        <div class="summary-icon">
                                            <i class="bi bi-hourglass-split"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="report-card">

                            <div class="card-body-custom">
                                <?php if (empty($groupedAssets)): ?>
                                    <div class="empty-state">
                                        <i class="bi bi-inbox"></i>
                                        Tidak ada data laporan pada periode ini.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($groupedAssets as $entry): ?>
                                        <?php $asset = $entry['asset']; ?>
                                        <div class="asset-card" id="asset-card-<?= (int) $asset['id'] ?>">
                                            <div class="asset-top">
                                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                                    <div>
                                                        <div class="asset-title"><?= h($asset['nama_barang'] ?? '-') ?> - <?= h($asset['nama_merk'] ?? '-') ?></div>
                                                        <div class="asset-meta">
                                                            Asset: <b><?= h($asset['no_asset'] ?? '-') ?></b> |
                                                            SN: <b><?= h($asset['serial_number'] ?? '-') ?></b> |
                                                            PIC: <b><?= h($asset['user'] ?? '-') ?></b>
                                                        </div>
                                                    </div>

                                                    <button type="button" class="btn-main no-print" onclick="printSingleAsset(<?= (int) $asset['id'] ?>)">
                                                        <i class="bi bi-printer me-2"></i>Cetak Asset Ini
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="asset-body">
                                                <div class="info-box">
                                                    <div><b>Ringkasan Asset</b></div>
                                                    <div>Cabang aktif: <b><?= h($asset['branch_aktif'] ?? '-') ?></b></div>
                                                    <div>Tanggal masuk: <b><?= h($asset['tanggal_masuk'] ?? '-') ?></b></div>
                                                    <div>Status barang: <b><?= h($asset['nama_status'] ?? '-') ?></b></div>
                                                    <?php if (($asset['bermasalah'] ?? '') === 'Iya'): ?>
                                                        <div>Kondisi: <b>Bermasalah</b> — <?= h($asset['keterangan_masalah'] ?? '-') ?></div>
                                                    <?php else: ?>
                                                        <div>Kondisi: <b>Normal</b></div>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if (empty($entry['timeline'])): ?>
                                                    <div class="timeline">
                                                        <div class="timeline-item">
                                                            <div class="timeline-dot"></div>
                                                            <div class="timeline-card">
                                                                <div class="timeline-head">
                                                                    <div class="timeline-title">Belum ada aktivitas pengiriman</div>
                                                                    <div class="timeline-subtitle">Asset masih berada di cabang aktif saat ini.</div>
                                                                </div>
                                                                <div class="timeline-body">
                                                                    <div class="detail-grid">
                                                                        <div class="detail-box">
                                                                            <div class="detail-label">Cabang Aktif</div>
                                                                            <div class="detail-value"><?= h($asset['branch_aktif'] ?? '-') ?></div>
                                                                        </div>
                                                                        <div class="detail-box">
                                                                            <div class="detail-label">Tanggal Masuk</div>
                                                                            <div class="detail-value"><?= h($asset['tanggal_masuk'] ?? '-') ?></div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="timeline">
                                                        <?php foreach ($entry['timeline'] as $idx => $step): ?>
                                                            <div class="timeline-item">
                                                                <div class="timeline-dot"></div>

                                                                <div class="timeline-card">
                                                                    <div class="timeline-head d-flex justify-content-between align-items-start flex-wrap gap-2">
                                                                        <div>
                                                                            <div class="timeline-title">Aktivitas Pengiriman <?= $idx + 1 ?></div>
                                                                            <div class="timeline-subtitle">
                                                                                Dari <?= h($step['branch_asal_pengiriman'] ?? $asset['branch_aktif'] ?? '-') ?>
                                                                                ke <?= h($step['branch_tujuan'] ?? '-') ?>
                                                                            </div>
                                                                        </div>
                                                                        <div>
                                                                            <?= status_badge((string) ($step['status_pengiriman'] ?? 'Belum dikirim')) ?>
                                                                        </div>
                                                                    </div>

                                                                    <div class="timeline-body">
                                                                        <div class="detail-grid">
                                                                            <div class="detail-box">
                                                                                <div class="detail-label">Dari</div>
                                                                                <div class="detail-value"><?= h($step['branch_asal_pengiriman'] ?? $asset['branch_aktif'] ?? '-') ?></div>
                                                                            </div>

                                                                            <div class="detail-box">
                                                                                <div class="detail-label">Ke</div>
                                                                                <div class="detail-value"><?= h($step['branch_tujuan'] ?? '-') ?></div>
                                                                            </div>

                                                                            <div class="detail-box">
                                                                                <div class="detail-label">Tanggal Keluar</div>
                                                                                <div class="detail-value"><?= h($step['tanggal_keluar'] ?? '-') ?></div>
                                                                            </div>

                                                                            <div class="detail-box">
                                                                                <div class="detail-label">Tanggal Diterima</div>
                                                                                <div class="detail-value"><?= h($step['tanggal_diterima'] ?? '-') ?></div>
                                                                            </div>

                                                                            <div class="detail-box">
                                                                                <div class="detail-label">Jasa Pengiriman</div>
                                                                                <div class="detail-value"><?= h($step['jasa_pengiriman'] ?? '-') ?></div>
                                                                            </div>

                                                                            <div class="detail-box">
                                                                                <div class="detail-label">Nomor Resi</div>
                                                                                <div class="detail-value"><?= h($step['nomor_resi_keluar'] ?? '-') ?></div>
                                                                            </div>
                                                                        </div>

                                                                        <div class="log-note-grid">
                                                                            <div class="log-note-card">
                                                                                <div class="log-note-title">Catatan Pengiriman</div>
                                                                                <div class="log-note-text"><?= build_pengiriman_note($step) ?></div>
                                                                            </div>

                                                                            <div class="log-note-card">
                                                                                <div class="log-note-title">Catatan Penerimaan</div>
                                                                                <div class="log-note-text"><?= build_penerimaan_note($step) ?></div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="print-only" style="margin-top:10px; font-size:11px; color:#555;">
                                                    Dicetak dari sistem laporan asset.
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function clearPrintTarget() {
            document.body.classList.remove('print-single-asset');
            document.querySelectorAll('.asset-card').forEach(function(card) {
                card.classList.remove('print-target');
            });
        }

        function printAllReport() {
            clearPrintTarget();
            window.print();
        }

        function printSingleAsset(assetId) {
            clearPrintTarget();

            const target = document.getElementById('asset-card-' + assetId);
            if (!target) {
                window.print();
                return;
            }

            document.body.classList.add('print-single-asset');
            target.classList.add('print-target');

            window.print();
        }

        window.addEventListener('afterprint', function() {
            clearPrintTarget();
        });

        document.addEventListener('DOMContentLoaded', function() {
            const periodeSelect = document.getElementById('periodeFilter');
            const bulanFields = document.querySelectorAll('.filter-bulan');
            const bulanTahunFields = document.querySelectorAll('.filter-bulan-tahun');
            const mingguFields = document.querySelectorAll('.filter-minggu');
            const customFields = document.querySelectorAll('.filter-custom');

            function toggleFilterFields() {
                const value = periodeSelect.value;

                bulanFields.forEach(el => el.style.display = 'none');
                bulanTahunFields.forEach(el => el.style.display = 'none');
                mingguFields.forEach(el => el.style.display = 'none');
                customFields.forEach(el => el.style.display = 'none');

                if (value === 'bulan') {
                    bulanTahunFields.forEach(el => el.style.display = '');
                    bulanFields.forEach(el => el.style.display = '');
                } else if (value === 'tahun') {
                    bulanTahunFields.forEach(el => el.style.display = '');
                } else if (value === 'minggu') {
                    mingguFields.forEach(el => el.style.display = '');
                } else if (value === 'custom') {
                    customFields.forEach(el => el.style.display = '');
                }
            }

            toggleFilterFields();
            periodeSelect.addEventListener('change', toggleFilterFields);
        });
    </script>
</body>

</html>