<?php
/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

// Proteksi Akses
require_permission($koneksi, 'laporan.pdf');

$isAdmin = is_admin();
$myBranchId = (int) (current_user_branch_id() ?? 0);
if (!$isAdmin && $myBranchId <= 0) { exit('Akses Ditolak.'); }

function h($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }

function resolve_period_range($periode, $tahun, $bulan, $minggu, $customAwal, $customAkhir) {
    $now = new DateTime();
    if (!preg_match('/^\d{4}$/', $tahun)) $tahun = $now->format('Y');
    if (!preg_match('/^(0[1-9]|1[0-2])$/', $bulan)) $bulan = $now->format('m');
    
    if ($periode === 'tahun') {
        $start = "$tahun-01-01"; $end = "$tahun-12-31"; $label = "Tahun $tahun";
    } elseif ($periode === 'custom') {
        $start = $customAwal ?: $now->format('Y-m-01'); $end = $customAkhir ?: $now->format('Y-m-t');
        if ($start > $end) { $t = $start; $start = $end; $end = $t; }
        $label = date('d M Y', strtotime($start)) . ' s/d ' . date('d M Y', strtotime($end));
    } else {
        $start = "$tahun-$bulan-01"; $end = date('Y-m-t', strtotime($start)); $label = date('F Y', strtotime($start));
    }
    return ['start' => $start, 'end' => $end, 'label' => $label];
}

$periode     = $_GET['periode'] ?? 'bulan';
$tahun       = $_GET['tahun'] ?? date('Y');
$bulan       = $_GET['bulan'] ?? date('m');
$singleId    = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$range = resolve_period_range($periode, $tahun, $bulan, '', $_GET['custom_awal']??'', $_GET['custom_akhir']??'');

// ==============================================================================
// KONDISI WHERE PER ROLE (ISOLASI DATA PDF)
// ==============================================================================
$idBranchHO = 40;
$activeBranchId = $isAdmin ? $idBranchHO : $myBranchId;

if ($isAdmin) {
    // Admin HO cetak semua data
    $condA = "1=1";
    $condB = "1=1";
} else {
    // User Cabang HANYA cetak data yang melibatkan cabangnya
    $condA = "(b.id_branch = $activeBranchId OR bp.branch_tujuan = $activeBranchId OR bp.branch_asal = $activeBranchId)";
    $condB = "pch.branch_asal = $activeBranchId";
}

$singleIdCondA = $singleId > 0 ? "AND b.id = $singleId" : "";
$singleIdCondB = $singleId > 0 ? "AND b.id = $singleId" : "";

$penerimaHO = 'Pak Deni'; // Penerima mutlak untuk retur ke HO

// ==============================================================================
// QUERY A: Logistik HO → Cabang
// ==============================================================================
$sqlA = "
    SELECT b.id, b.no_asset, b.serial_number, b.tanggal_terima, b.user, b.bermasalah, b.keterangan_masalah,
           tb.nama_barang, bm.nama_merk, st.nama_status, ba.nama_branch AS branch_aktif,
           bp.id_pengiriman, bp.tanggal_keluar, bp.tanggal_diterima, bp.status_pengiriman, 
           bp.nomor_resi_keluar, bp.nama_penerima, bp.jasa_pengiriman,
           bpasal.nama_branch AS branch_asal_pengiriman, bptujuan.nama_branch AS branch_tujuan,
           DATE(bp.tanggal_keluar) AS tgl_aktivitas
    FROM barang_pengiriman bp
    JOIN barang b ON bp.id_barang = b.id
    LEFT JOIN tb_barang tb ON tb.id_barang = b.id_barang
    LEFT JOIN tb_merk bm ON bm.id_merk = b.id_merk
    LEFT JOIN tb_status st ON st.id_status = b.id_status
    LEFT JOIN tb_branch ba ON ba.id_branch = b.id_branch
    LEFT JOIN tb_branch bpasal ON bpasal.id_branch = bp.branch_asal
    LEFT JOIN tb_branch bptujuan ON bptujuan.id_branch = bp.branch_tujuan
    WHERE DATE(bp.tanggal_keluar) BETWEEN ? AND ? 
      AND ($condA) $singleIdCondA
";

// ==============================================================================
// QUERY B: Logistik Cabang → HO (Retur)
// ==============================================================================
$sqlB = "
    SELECT b.id, b.no_asset, pch.serial_number, b.tanggal_terima, b.user, b.bermasalah, b.keterangan_masalah,
           tb.nama_barang, bm.nama_merk, st.nama_status, ba.nama_branch AS branch_aktif,
           pch.id_pengiriman_ho AS id_pengiriman, pch.tanggal_pengajuan AS tanggal_keluar, 
           DATE(pch.disetujui_pada) AS tanggal_diterima,
           pch.status_pengiriman, pch.nomor_resi_keluar, '$penerimaHO' AS nama_penerima, 
           pch.jasa_pengiriman AS jasa_pengiriman,
           bpasal.nama_branch AS branch_asal_pengiriman, 'Kantor Pusat HO' AS branch_tujuan,
           DATE(pch.tanggal_pengajuan) AS tgl_aktivitas
    FROM pengiriman_cabang_ho pch
    LEFT JOIN barang b ON b.serial_number = pch.serial_number
    LEFT JOIN tb_barang tb ON tb.id_barang = pch.id_barang
    LEFT JOIN tb_merk bm ON bm.id_merk = b.id_merk
    LEFT JOIN tb_status st ON st.id_status = b.id_status
    LEFT JOIN tb_branch ba ON ba.id_branch = b.id_branch
    LEFT JOIN tb_branch bpasal ON bpasal.id_branch = pch.branch_asal
    WHERE DATE(pch.tanggal_pengajuan) BETWEEN ? AND ? 
      AND ($condB) $singleIdCondB AND b.id IS NOT NULL
";

// Eksekusi Query A
$stmtA = mysqli_prepare($koneksi, $sqlA);
mysqli_stmt_bind_param($stmtA, 'ss', $range['start'], $range['end']);
mysqli_stmt_execute($stmtA);
$resA = mysqli_stmt_get_result($stmtA);

// Eksekusi Query B
$stmtB = mysqli_prepare($koneksi, $sqlB);
mysqli_stmt_bind_param($stmtB, 'ss', $range['start'], $range['end']);
mysqli_stmt_execute($stmtB);
$resB = mysqli_stmt_get_result($stmtB);

// Menggabungkan Data
$groupedAssets = [];
while ($row = mysqli_fetch_assoc($resA)) {
    $id = (string)$row['id'];
    if (!isset($groupedAssets[$id])) { $groupedAssets[$id] = ['asset' => $row, 'timeline' => []]; }
    if (!empty($row['id_pengiriman'])) { $groupedAssets[$id]['timeline'][] = $row; }
}
while ($row = mysqli_fetch_assoc($resB)) {
    $id = (string)$row['id'];
    if (!isset($groupedAssets[$id])) { $groupedAssets[$id] = ['asset' => $row, 'timeline' => []]; }
    if (!empty($row['id_pengiriman'])) { $groupedAssets[$id]['timeline'][] = $row; }
}

// Sorting Timeline Berdasarkan Tanggal Secara Otomatis
foreach ($groupedAssets as &$entry) {
    usort($entry['timeline'], function($a, $b) {
        return strcmp($a['tgl_aktivitas'], $b['tgl_aktivitas']);
    });
}
unset($entry);

$docNum = 'ITS/' . date('Ymd') . '/' . strtoupper(substr(md5($range['start'].$range['end']), 0, 4));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Print Document</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Memaksa browser mencetak warna background dan gradien */
        @media print {
            body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            @page { size: A4 portrait; margin: 10mm 15mm; } /* Margin disesuaikan agar lebih proporsional */
            
            /* MENCEGAH KOP DOKUMEN PISAH HALAMAN DENGAN KONTEN */
            .print-intro { 
                page-break-after: avoid !important; 
                break-after: avoid !important; 
                margin-bottom: 20px !important;
            }
            
            /* MEMASTIKAN KARTU ASET BISA NAIK KE HALAMAN 1 JIKA MUAT */
            .asset-card {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                page-break-before: auto !important;
                break-before: auto !important;
                display: block;
            }

            .force-new-page {
                page-break-before: always !important;
                break-before: page !important;
                margin-top: 10px !important;
            }
        }
        
        body { font-family: 'Plus Jakarta Sans', Arial, sans-serif; font-size: 11px; background: #fff; color: #171717; line-height: 1.45; }
        
        /* Header & Intro */
        .print-intro { margin-bottom: 20px; border: 1px solid #eadfce; }
        .print-intro-top { display: flex; width: 100%; background: #fff; }
        .print-intro-left { flex: 1; padding: 20px; }
        .print-intro-right { width: 220px; text-align: center; border-left: 1px solid #eadfce; background: #fff7ef; padding: 20px; }
        
        .print-doc-heading { font-family: Georgia, serif; font-size: 28px; font-weight: 800; line-height: 1; margin-bottom: 5px; }
        .print-doc-no { font-size: 10px; color: #6b7280; margin-bottom: 15px; }
        
        .print-logo-box { display: inline-flex; align-items: center; justify-content: center; width: 70px; height: 70px; border-radius: 15px; background: linear-gradient(135deg, #f59e0b, #f97316); color: #fff; font-size: 24px; font-weight: 800; margin-bottom: 10px; }
        
        /* Baris Meta (Bawah Kop) */
        .print-meta-row { display: flex; border-top: 1px solid #eadfce; background: #fff; }
        .print-meta-card { flex: 1; padding: 10px 15px; border-right: 1px solid #eadfce; }
        .print-meta-card:last-child { border-right: none; }
        .print-meta-label { font-size: 8px; font-weight: 800; text-transform: uppercase; color: #b45309; margin-bottom: 2px; }
        .print-meta-value { font-size: 11px; font-weight: 700; }

        /* Asset Card Premium */
        .asset-card { border: 1px solid #eadfce; background: #fff; margin-bottom: 20px; }
        .asset-top { position: relative; padding: 15px; border-bottom: 1px solid #eadfce; background: linear-gradient(180deg, #fff9f2, #fff); }
        .asset-top::before { content: ""; position: absolute; top: 0; left: 0; bottom: 0; width: 8px; background: linear-gradient(180deg, #1f1f1f, #f08a14); }
        
        .asset-title { font-size: 14px; font-weight: 800; margin: 0 0 5px 10px; text-transform: uppercase; }
        .asset-meta { font-size: 10px; color: #666; margin-left: 10px; }

        .asset-body { padding: 15px; }
        
        .info-box { background: #fffbf7; border: 1px solid #eadfce; padding: 12px; margin-bottom: 15px; color: #333; }
        
        /* Tabel Timeline Logistik */
        .timeline-card { border: 1px solid #eadfce; background: #fff; margin-bottom: 10px; }
        .timeline-head { padding: 8px 12px; background: #fff8f0; border-bottom: 1px solid #eadfce; display: flex; justify-content: space-between; align-items: center; }
        .timeline-title { font-size: 11px; font-weight: 800; color: #171717; text-transform: uppercase; }
        
        .status-badge { background: #171717; color: #fff; padding: 4px 10px; border-radius: 20px; font-size: 9px; font-weight: 800; }
        .status-badge.selesai { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
        .status-badge.proses { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }

        /* Grid Detail */
        .detail-grid { display: flex; flex-wrap: wrap; border: 1px solid #eadfce; border-bottom: none; }
        .detail-box { width: 50%; padding: 8px 10px; border-bottom: 1px solid #eadfce; border-right: 1px solid #eadfce; box-sizing: border-box; }
        .detail-box:nth-child(even) { border-right: none; }
        .detail-label { font-size: 8px; font-weight: 800; color: #b45309; text-transform: uppercase; margin-bottom: 2px; }
        .detail-value { font-size: 10px; font-weight: 700; }

        /* Log Notes */
        .log-note-grid { display: flex; gap: 10px; margin-top: 10px; }
        .log-note-card { flex: 1; padding: 10px; border: 1px dashed #eadfce; background: #fffaf5; }
    </style>
</head>
<body>

    <div class="print-intro">
        <div class="print-intro-top">
            <div class="print-intro-left">
                <div class="print-doc-heading">IT SYSTEM</div>
                <div class="print-doc-no">No File: <?= h($docNum) ?></div>
                <div style="font-size: 11px; font-weight: 800; margin-bottom: 3px;">Internal Report:</div>
                <div style="font-size: 10px; color: #334155; line-height: 1.6;">
                    PT HEXINDO ADIPEKARSA TBK<br>
                    Unit: IT System<br>
                    Dokumen: Laporan Asset dan Log Aktivitas Pengiriman
                </div>
            </div>
            <div class="print-intro-right">
                <div class="print-logo-box">HX</div>
                <div style="font-size: 12px; font-weight: 800; text-transform: uppercase;">PT HEXINDO</div>
                <div style="font-size: 9px; font-weight: 700; color: #475569;">IT System</div>
            </div>
        </div>
        <div class="print-meta-row">
            <div class="print-meta-card">
                <div class="print-meta-label">Periode Laporan</div>
                <div class="print-meta-value"><?= h($range['label']) ?></div>
            </div>
            <div class="print-meta-card">
                <div class="print-meta-label">Tanggal Cetak</div>
                <div class="print-meta-value"><?= date('d F Y H:i') ?></div>
            </div>
            <div class="print-meta-card">
                <div class="print-meta-label">Status Dokumen</div>
                <div class="print-meta-value">Valid / Rahasia Internal</div>
            </div>
        </div>
    </div>

    <?php if (empty($groupedAssets)): ?>
        <div style="text-align: center; padding: 40px; border: 1px solid #eadfce; color: #666;">
            Tidak ada data laporan pada periode ini.
        </div>
    <?php else: foreach ($groupedAssets as $entry): $asset = $entry['asset']; ?>
        <div class="asset-card">
            
            <div class="asset-top">
                <div class="asset-title"><?= h($asset['nama_barang']) ?> - <?= h($asset['nama_merk']) ?></div>
                <div class="asset-meta">
                    Asset: <b><?= h($asset['no_asset'] ?: '-') ?></b> | 
                    SN: <b><?= h($asset['serial_number'] ?: '-') ?></b> | 
                    PIC: <b><?= h($asset['user'] ?: 'Belum ada PIC') ?></b>
                </div>
            </div>

            <div class="asset-body">
                <div class="info-box">
                    <div style="font-weight: 800; margin-bottom: 5px;">Ringkasan Asset & Kondisi</div>
                    Cabang aktif: <b><?= h($asset['branch_aktif'] ?: '-') ?></b> | 
                    Tanggal masuk: <b><?= h($asset['tanggal_terima'] ?: '-') ?></b> | 
                    Status: <b><?= h($asset['nama_status'] ?: '-') ?></b><br>
                    Kondisi Fisik: <b><?= ($asset['bermasalah'] === 'Iya' ? 'Bermasalah — ' . h($asset['keterangan_masalah']) : 'Kondisi Normal') ?></b>
                </div>

                <?php if (empty($entry['timeline'])): ?>
                    <div class="timeline-card" style="padding: 15px; text-align: center; color: #666; font-size: 10px;">
                        Asset masih berada di cabang aktif. Belum ada aktivitas pengiriman tercatat.
                    </div>
                <?php else: foreach ($entry['timeline'] as $idx => $step): 
                    $isDone = in_array(strtolower(trim($step['status_pengiriman'])), ['sudah diterima', 'sudah diterima ho', 'selesai']);
$breakClass = ($idx === 2) ? 'force-new-page' : '';                ?>
                    <div class="timeline-card <?= $breakClass ?>">
                        <div class="timeline-head">
                            <div class="timeline-title">Aktivitas Transaksi Mutasi Ke-<?= $idx + 1 ?></div>
                            <div class="status-badge <?= $isDone ? 'selesai' : 'proses' ?>">
                                <?= h($step['status_pengiriman']) ?>
                            </div>
                        </div>
                        <div style="padding: 12px;">
                            <div class="detail-grid">
                                <div class="detail-box">
                                    <div class="detail-label">Dari Cabang Asal</div>
                                    <div class="detail-value"><?= h($step['branch_asal_pengiriman'] ?: $asset['branch_aktif']) ?></div>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Ke Cabang Tujuan</div>
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
                            </div>

                            <div class="log-note-grid">
                                <div class="log-note-card">
                                    <div class="detail-label">Logistik Ekspedisi</div>
                                    <div style="font-size: 10px; color: #333; margin-top: 3px;">
                                        Jasa Kurir: <b><?= h($step['jasa_pengiriman'] ?: '-') ?></b><br>
                                        No Resi: <b><?= h($step['nomor_resi_keluar'] ?: '-') ?></b>
                                    </div>
                                </div>
                                <div class="log-note-card">
                                    <div class="detail-label">Verifikasi Penerimaan</div>
                                    <div style="font-size: 10px; color: #333; margin-top: 3px;">
                                        Penerima Fisik: <b><?= h($step['nama_penerima'] ?: '-') ?></b><br>
                                        Status: <?= $isDone ? 'Terkonfirmasi.' : 'Menunggu Konfirmasi.' ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            
        </div>
    <?php endforeach; endif; ?>

    <div style="text-align: center; margin-top: 30px; font-size: 8px; color: #888; border-top: 1px solid #eadfce; padding-top: 10px;">
        PT HEXINDO ADIPEKARSA TBK · IT System Asset Reporting Automation · Dokumen Valid Tanpa Tanda Tangan Fisik.
    </div>

    <script>
        window.onload = function() {
            // Ketika file ini selesai diload oleh Iframe gaib, ia langsung memanggil perintah Print
            window.print();
        }
    </script>
</body>
</html>