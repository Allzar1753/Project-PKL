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

$documentNumber = 'ITS/' . date('Ymd') . '/' . strtoupper(substr(md5($range['start'] . $range['end'] . $periodeLabel), 0, 4));
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
        --accent-1: #f59e0b;
        --accent-2: #f97316;
        --accent-3: #ffb020;
        --accent-soft: #fff3e3;
        --accent-soft-2: #fff8ef;

        --dark-1: #171717;
        --dark-2: #262626;
        --dark-3: #3f3f46;

        --text-main: #202020;
        --text-soft: #6b7280;
        --text-faint: #9ca3af;

        --line-1: #eadfce;
        --line-2: #e4d4bf;
        --line-3: #f2e7d9;

        --bg-page-top: #fff8f0;
        --bg-page-mid: #fffaf6;
        --bg-page-bottom: #ffffff;
        --bg-card: #ffffff;
        --bg-soft: #fffaf4;
        --bg-soft-2: #fffbf7;

        --success-bg: #ecfdf3;
        --success-text: #166534;
        --success-line: #bbf7d0;

        --warning-bg: #fff7ed;
        --warning-text: #c2410c;
        --warning-line: #fed7aa;

        --pending-bg: #fff1dc;
        --pending-text: #9a5410;
        --pending-line: #f5c27a;

        --muted-bg: #f8fafc;
        --muted-text: #475569;
        --muted-line: #cbd5e1;

        --shadow-soft: 0 16px 40px rgba(0, 0, 0, 0.07);
        --shadow-hover: 0 18px 46px rgba(245, 158, 11, 0.16);

        --radius-xl: 28px;
        --radius-lg: 22px;
        --radius-md: 18px;
        --radius-sm: 14px;
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        background:
            radial-gradient(circle at top left, rgba(245, 158, 11, 0.14), transparent 24%),
            radial-gradient(circle at bottom right, rgba(249, 115, 22, 0.10), transparent 18%),
            linear-gradient(180deg, var(--bg-page-top) 0%, var(--bg-page-mid) 42%, var(--bg-page-bottom) 100%);
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
        background: linear-gradient(135deg, #1a1a1a 0%, #2c2c2c 34%, #8d5a20 62%, #f08a14 100%);
        box-shadow: 0 18px 45px rgba(240, 138, 20, 0.18);
        padding: 1.55rem 1.65rem;
        margin-bottom: 1.35rem;
    }

    .hero-card::before {
        content: "";
        position: absolute;
        width: 240px;
        height: 240px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.08);
        top: -100px;
        right: -80px;
    }

    .hero-card::after {
        content: "";
        position: absolute;
        width: 180px;
        height: 180px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.06);
        left: -50px;
        bottom: -60px;
    }

    .hero-content {
        position: relative;
        z-index: 2;
    }

    .page-title {
        color: #fff;
        font-size: 1.85rem;
        font-weight: 800;
        margin-bottom: .35rem;
        letter-spacing: -0.02em;
    }

    .page-subtitle {
        color: rgba(255, 255, 255, 0.84);
        margin-bottom: 0;
        line-height: 1.7;
        max-width: 780px;
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
        background: #fff7ef;
        color: #111;
    }

    .panel-card,
    .report-card,
    .asset-card,
    .summary-card {
        background: var(--bg-card);
        border: 1px solid rgba(245, 158, 11, 0.12);
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
        background: linear-gradient(90deg, var(--accent-1), var(--accent-2));
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
        background: linear-gradient(135deg, var(--accent-1), var(--accent-2));
        box-shadow: 0 10px 24px rgba(245, 158, 11, 0.20);
    }

    .card-head {
        padding: 1.05rem 1.2rem;
        background: linear-gradient(135deg, #171717 0%, #2d2d2d 42%, #f08a14 100%);
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
        border-color: #f5b55a;
        box-shadow: 0 0 0 .2rem rgba(245, 158, 11, 0.12);
    }

    .btn-main {
        border: none;
        border-radius: 14px;
        font-weight: 800;
        padding: .82rem 1rem;
        background: linear-gradient(135deg, var(--accent-1), var(--accent-2));
        color: #fff;
        box-shadow: 0 12px 28px rgba(245, 158, 11, 0.22);
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
        background: var(--accent-soft);
        color: #9a5410;
        border: 1px solid rgba(245, 158, 11, 0.18);
        padding: .38rem .75rem;
        font-size: .8rem;
        font-weight: 800;
    }

    .asset-card + .asset-card {
        margin-top: 1rem;
    }

    .asset-top {
        padding: 1rem;
        background: linear-gradient(180deg, #fffbf6 0%, #fff4e8 100%);
        border-bottom: 1px solid #efe1cf;
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
        background: linear-gradient(180deg, #fffaf4 0%, #fff3e7 100%);
        border: 1px solid rgba(245, 158, 11, 0.14);
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
        background: linear-gradient(180deg, rgba(245, 158, 11, 0.30), rgba(249, 115, 22, 0.12));
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
        background: linear-gradient(135deg, var(--accent-1), var(--accent-2));
        box-shadow: 0 0 0 4px #fff1df;
    }

    .timeline-card {
        background: #fff;
        border: 1px solid #f2e4d4;
        border-radius: 18px;
        overflow: hidden;
    }

    .timeline-head {
        padding: .95rem 1rem;
        background: #fff8f0;
        border-bottom: 1px solid #f0e3d6;
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
        background: #fffdfb;
        border: 1px solid #efe4d8;
        border-radius: 14px;
        padding: .8rem .9rem;
    }

    .detail-label {
        font-size: .74rem;
        font-weight: 800;
        color: #b45309;
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
        background: linear-gradient(180deg, #fffaf4 0%, #fffdfb 100%);
        border: 1px solid rgba(245, 158, 11, 0.14);
        border-radius: 14px;
        padding: .85rem .9rem;
    }

    .log-note-title {
        font-size: .82rem;
        font-weight: 800;
        color: #9a5410;
        margin-bottom: .35rem;
    }

    .log-note-text {
        font-size: .88rem;
        line-height: 1.65;
        color: #475569;
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
        background: linear-gradient(135deg, #171717, #2f2f2f);
        color: #fff;
    }

    .status-road {
        background: linear-gradient(135deg, var(--accent-1), var(--accent-2));
        color: #fff;
    }

    .status-pack {
        background: linear-gradient(135deg, #ffe2b8, #ffd089);
        color: #8a4a00;
    }

    .status-wait {
        background: #fff7ed;
        color: #7c4a13;
        border: 1px solid rgba(245, 158, 11, 0.24);
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
        color: var(--accent-2);
    }

    .print-repeat-header,
    .print-intro,
    .print-footer,
    .print-only {
        display: none;
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

    @page {
        size: A4 portrait;
        margin: 34mm 12mm 16mm 12mm;
    }

    @media print {
        html,
        body {
            width: 100%;
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
            color: #171717 !important;
            font-family: 'Plus Jakarta Sans', sans-serif !important;
            font-size: 11px !important;
            line-height: 1.45 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .laporan-layout > :not(.laporan-main) {
            display: none !important;
        }

        .no-print,
        .hero-card,
        .panel-card,
        .summary-card,
        .hero-action,
        .btn-main {
            display: none !important;
        }

        .laporan-main,
        .laporan-main .page-wrap,
        .laporan-main .page-container,
        .report-card,
        .card-body-custom {
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
            border: none !important;
            box-shadow: none !important;
        }

        .print-repeat-header {
            display: block !important;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 26mm;
            background: #fff !important;
            z-index: 9999;
            padding: 0 12mm;
        }

        .print-repeat-header::before {
            content: "";
            position: absolute;
            top: 0;
            left: 12mm;
            right: 12mm;
            height: 8px;
            background: linear-gradient(90deg, #222222 0%, #3a2c21 34%, #b96a18 70%, #f08a14 100%);
        }

        .print-repeat-header::after {
            content: "";
            position: absolute;
            top: 0;
            left: 62px;
            width: 34px;
            height: 8px;
            background: #fff;
            transform: skewX(-32deg);
            transform-origin: left top;
        }

        .print-repeat-header-inner {
            position: relative;
            display: table;
            width: 100%;
            table-layout: fixed;
            padding-top: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--line-1);
        }

        .print-repeat-header-left,
        .print-repeat-header-right {
            display: table-cell;
            vertical-align: middle;
        }

        .print-repeat-header-right {
            text-align: right;
        }

        .print-brand {
            font-size: 9px !important;
            font-weight: 800 !important;
            color: #c96d12 !important;
            text-transform: uppercase !important;
            letter-spacing: .08em !important;
            margin-bottom: 2px !important;
        }

        .print-company {
            font-size: 15px !important;
            font-weight: 800 !important;
            color: #171717 !important;
            text-transform: uppercase !important;
            line-height: 1.15 !important;
            margin-bottom: 2px !important;
        }

        .print-sub {
            font-size: 9px !important;
            color: var(--text-soft) !important;
            line-height: 1.4 !important;
        }

        .print-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            background: var(--accent-soft) !important;
            border: 1px solid #f6c27f !important;
            color: #9a5410 !important;
            font-size: 8px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .print-title {
            font-size: 18px !important;
            font-weight: 800 !important;
            color: #171717 !important;
            line-height: 1.1 !important;
            margin: 0 !important;
            text-transform: uppercase !important;
            letter-spacing: .03em !important;
        }

        .print-period {
            font-size: 9px !important;
            color: var(--text-soft) !important;
            margin-top: 2px !important;
            line-height: 1.35 !important;
        }

        .print-intro {
            display: block !important;
            margin-bottom: 14px !important;
            border: 1px solid var(--line-1) !important;
            border-radius: 0 !important;
            overflow: hidden !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        .print-intro-top {
            display: table;
            width: 100%;
            table-layout: fixed;
            background: #fff;
        }

        .print-intro-left,
        .print-intro-right {
            display: table-cell;
            vertical-align: top;
            padding: 16px 16px 12px 16px;
        }

        .print-intro-right {
            width: 210px;
            text-align: center;
            border-left: 1px solid var(--line-1);
            background: #fff7ef !important;
        }

        .print-doc-heading {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 28px !important;
            font-weight: 800 !important;
            color: #171717 !important;
            line-height: 1 !important;
            margin-bottom: 6px !important;
            text-transform: uppercase !important;
            letter-spacing: .02em !important;
        }

        .print-doc-no {
            font-size: 10px !important;
            color: #6b7280 !important;
            line-height: 1.5 !important;
            margin-bottom: 16px !important;
        }

        .print-bill-label {
            font-size: 11px !important;
            font-weight: 800 !important;
            color: #171717 !important;
            margin-bottom: 4px !important;
        }

        .print-bill-text {
            font-size: 10px !important;
            color: #334155 !important;
            line-height: 1.7 !important;
        }

        .print-logo-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 84px;
            height: 84px;
            border-radius: 18px;
            background: linear-gradient(135deg, var(--accent-1), var(--accent-2)) !important;
            color: #fff;
            font-size: 28px;
            font-weight: 800;
            margin: 0 auto 8px auto;
            box-shadow: 0 10px 24px rgba(245, 158, 11, 0.18) !important;
        }

        .print-logo-title {
            font-size: 12px !important;
            font-weight: 800 !important;
            color: #171717 !important;
            line-height: 1.35 !important;
            text-transform: uppercase !important;
            margin-bottom: 2px !important;
        }

        .print-logo-subtitle {
            font-size: 9px !important;
            font-weight: 700 !important;
            color: #475569 !important;
            line-height: 1.4 !important;
            text-transform: uppercase !important;
        }

        .print-intro-bottom {
            display: table;
            width: 100%;
            table-layout: fixed;
            border-top: 1px solid var(--line-1);
        }

        .print-meta-card {
            display: table-cell;
            width: 33.3333%;
            padding: 10px 12px;
            border-right: 1px solid var(--line-1);
            background: #fff;
        }

        .print-meta-card:last-child {
            border-right: none;
        }

        .print-meta-label {
            font-size: 8px !important;
            font-weight: 800 !important;
            text-transform: uppercase !important;
            letter-spacing: .08em !important;
            color: #b45309 !important;
            margin-bottom: 4px !important;
        }

        .print-meta-value {
            font-size: 10px !important;
            font-weight: 700 !important;
            color: #171717 !important;
            line-height: 1.5 !important;
        }

        .report-card {
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
        }

        .card-body-custom {
            background: #fff !important;
        }

        .asset-card {
            display: block !important;
            margin: 0 0 14px 0 !important;
            padding: 0 !important;
            border: 1px solid var(--line-1) !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            background: #fff !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
            overflow: hidden !important;
        }

        .asset-top {
            position: relative;
            padding: 14px 16px !important;
            margin: 0 !important;
            border: none !important;
            border-bottom: 1px solid var(--line-1) !important;
            border-radius: 0 !important;
            background: linear-gradient(180deg, #fff9f2 0%, #ffffff 100%) !important;
        }

        .asset-top::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 10px;
            bottom: 0;
            background: linear-gradient(180deg, #1f1f1f, #f08a14) !important;
        }

        .asset-title {
            font-size: 13px !important;
            font-weight: 800 !important;
            color: #171717 !important;
            margin: 0 0 4px 0 !important;
            line-height: 1.35 !important;
            text-transform: uppercase !important;
            letter-spacing: .02em !important;
            padding-left: 8px !important;
        }

        .asset-meta {
            font-size: 10px !important;
            color: var(--text-soft) !important;
            line-height: 1.65 !important;
            padding-left: 8px !important;
        }

        .asset-body {
            padding: 14px 16px !important;
        }

        .info-box {
            margin: 0 0 12px 0 !important;
            padding: 12px 14px !important;
            border: 1px solid var(--line-1) !important;
            border-radius: 0 !important;
            background: #fffbf7 !important;
            color: #334155 !important;
            box-shadow: none !important;
            font-size: 10px !important;
            line-height: 1.7 !important;
        }

        .timeline {
            padding-left: 0 !important;
        }

        .timeline::before,
        .timeline-dot {
            display: none !important;
        }

        .timeline-item {
            margin: 0 0 12px 0 !important;
            padding-left: 0 !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        .timeline-item:last-child {
            margin-bottom: 0 !important;
        }

        .timeline-card {
            border: 1px solid var(--line-1) !important;
            border-radius: 0 !important;
            overflow: hidden !important;
            background: #fff !important;
            box-shadow: none !important;
        }

        .timeline-head {
            padding: 10px 12px !important;
            background: #fff8f0 !important;
            border-bottom: 1px solid var(--line-1) !important;
        }

        .timeline-title {
            font-size: 11px !important;
            font-weight: 800 !important;
            color: #171717 !important;
            margin-bottom: 2px !important;
            text-transform: uppercase !important;
            letter-spacing: .03em !important;
        }

        .timeline-subtitle {
            font-size: 10px !important;
            color: var(--text-soft) !important;
            line-height: 1.5 !important;
        }

        .timeline-body {
            padding: 12px !important;
            background: #fff !important;
        }

        .detail-grid {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 0 !important;
            margin-bottom: 10px !important;
            border: 1px solid var(--line-1) !important;
        }

        .detail-box {
            padding: 9px 10px !important;
            border: none !important;
            border-right: 1px solid var(--line-1) !important;
            border-bottom: 1px solid var(--line-1) !important;
            border-radius: 0 !important;
            background: #fff !important;
            box-shadow: none !important;
        }

        .detail-box:nth-child(2n) {
            border-right: none !important;
        }

        .detail-box:nth-last-child(-n+2) {
            border-bottom: none !important;
        }

        .detail-label {
            margin-bottom: 3px !important;
            font-size: 8px !important;
            font-weight: 800 !important;
            color: #b45309 !important;
            letter-spacing: .08em !important;
            text-transform: uppercase !important;
        }

        .detail-value {
            font-size: 10px !important;
            font-weight: 700 !important;
            color: #171717 !important;
            line-height: 1.45 !important;
            word-break: break-word !important;
        }

        .log-note-grid {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 10px !important;
        }

        .log-note-card {
            padding: 10px 11px !important;
            border: 1px solid var(--line-1) !important;
            border-radius: 0 !important;
            background: #fffaf5 !important;
            box-shadow: none !important;
        }

        .log-note-title {
            margin-bottom: 4px !important;
            font-size: 8px !important;
            font-weight: 800 !important;
            color: #b45309 !important;
            letter-spacing: .08em !important;
            text-transform: uppercase !important;
        }

        .log-note-text {
            font-size: 10px !important;
            color: #171717 !important;
            line-height: 1.55 !important;
        }

        .status-badge {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 5px 10px !important;
            border-radius: 999px !important;
            font-size: 9px !important;
            font-weight: 800 !important;
            line-height: 1 !important;
            border: 1px solid transparent !important;
            box-shadow: none !important;
        }

        .status-done {
            background: var(--success-bg) !important;
            color: var(--success-text) !important;
            border-color: var(--success-line) !important;
        }

        .status-road {
            background: var(--warning-bg) !important;
            color: var(--warning-text) !important;
            border-color: var(--warning-line) !important;
        }

        .status-pack {
            background: var(--pending-bg) !important;
            color: var(--pending-text) !important;
            border-color: var(--pending-line) !important;
        }

        .status-wait {
            background: #fff7ed !important;
            color: #7c4a13 !important;
            border-color: #f3cf9c !important;
        }

        .empty-state {
            padding: 24px 0 !important;
            color: #334155 !important;
            font-size: 11px !important;
        }

        .print-only {
            display: block !important;
            margin-top: 10px !important;
            padding-top: 10px !important;
            border-top: 1px solid var(--line-1) !important;
            font-size: 9px !important;
            color: var(--text-soft) !important;
            text-align: right !important;
        }

        .print-footer {
            display: block !important;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 10mm;
            background: #fff;
            padding: 2mm 12mm 0 12mm;
            border-top: 1px solid var(--line-1);
            font-size: 8px !important;
            color: var(--text-soft) !important;
            line-height: 1.4 !important;
            text-align: center !important;
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
    <div class="print-repeat-header">
        <div class="print-repeat-header-inner">
            <div class="print-repeat-header-left">
                <div class="print-company">PT HEXINDO ADIPEKARSA TBK</div>
                <div class="print-sub">IT System Asset Reporting</div>
            </div>
            <div class="print-repeat-header-right">
                <div class="print-badge">IT SYSTEM</div>
                <div class="print-title">Laporan Asset</div>
                <div class="print-period"><?= h($periodeLabel) ?></div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <div class="row laporan-layout">
            <?php require_once '../layout/sidebar.php'; ?>

            <div class="col-md-10 laporan-main">
                <div class="page-wrap">
                    <div class="page-container">

                        <div class="hero-card no-print">
                            <div class="hero-content d-flex justify-content-between align-items-start flex-wrap gap-3">
                                <div>
                                    <h1 class="page-title">Laporan Asset</h1>
                                    <p class="page-subtitle">
                                        Tampilan cetak dibuat lebih premium ala invoice modern, tapi isinya tetap cocok untuk kebutuhan IT System dan laporan asset internal.
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

                        <div class="print-intro">
                            <div class="print-intro-top">
                                <div class="print-intro-left">
                                    <div class="print-doc-heading">IT SYSTEM</div>
                                    <div class="print-doc-no">
                                        No: <?= h($documentNumber) ?>
                                    </div>

                                    <div class="print-bill-label">Internal Report:</div>
                                    <div class="print-bill-text">
                                        PT HEXINDO ADIPEKARSA TBK<br>
                                        Unit: IT System<br>
                                        Dokumen: Laporan Asset dan Log Aktivitas Pengiriman
                                    </div>
                                </div>

                                <div class="print-intro-right">
                                    <div class="print-logo-box">HX</div>
                                    <div class="print-logo-title">PT HEXINDO</div>
                                    <div class="print-logo-subtitle">IT System</div>
                                </div>
                            </div>

                            <div class="print-intro-bottom">
                                <div class="print-meta-card">
                                    <div class="print-meta-label">Periode</div>
                                    <div class="print-meta-value"><?= h($periodeLabel) ?></div>
                                </div>
                                <div class="print-meta-card">
                                    <div class="print-meta-label">Tanggal Cetak</div>
                                    <div class="print-meta-value"><?= h(date('d F Y H:i')) ?></div>
                                </div>
                                <div class="print-meta-card">
                                    <div class="print-meta-label">Jenis Dokumen</div>
                                    <div class="print-meta-value">Laporan Asset IT System</div>
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

                                                <div class="print-only">
                                                    Dicetak dari sistem laporan asset · IT System
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="print-footer">
                            PT HEXINDO ADIPEKARSA TBK · IT System Asset Reporting · <?= h($documentNumber) ?>
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