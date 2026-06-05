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
    <title>Cetak Laporan Asset</title>
    <!-- Google Fonts untuk konsistensi -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        /* TEMA HEXINDO COLORS */
        :root {
            --hex-orange: #E64312;
            --hex-dark: #231F20;
            --hex-gray: #F4F6F9;
            --hex-border: #E0E4E8;
        }

        /* RESET BROWSER VIEW (Background Gelap saat di luar mode cetak) */
        body { 
            font-family: 'Inter', Arial, sans-serif; 
            font-size: 11px; 
            background: #525659; /* Warna PDF viewer */
            color: var(--hex-dark); 
            line-height: 1.45; 
            margin: 0;
            padding: 40px 0;
        }

        /* WADAH KERTAS A4 (Saat dilihat di browser) */
        .page-wrap {
            background-color: #ffffff;
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 15mm;
            box-sizing: border-box;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
        }

        /* ==================================================
           ATURAN KHUSUS SAAT DITEKAN TOMBOL PRINT (CTRL+P)
           ================================================== */
        @page { 
            size: A4 portrait; 
            margin: 10mm 15mm; 
        }

        @media print {
            body { 
                background: #fff; 
                padding: 0; 
                margin: 0; 
                -webkit-print-color-adjust: exact !important; 
                print-color-adjust: exact !important; 
            }
            .page-wrap {
                box-shadow: none;
                margin: 0;
                padding: 0;
                width: 100%;
                min-height: auto;
            }
            
            /* Cegah elemen terpotong antar halaman */
            .print-intro { 
                page-break-after: avoid !important; 
                break-after: avoid !important; 
                margin-bottom: 20px !important;
            }
            .asset-card {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                display: block;
            }
            .force-new-page {
                page-break-before: always !important;
                break-before: page !important;
                margin-top: 10px !important;
            }
        }
        
        /* ==================================================
           KOP SURAT (HEADER)
           ================================================== */
        .print-intro { 
            margin-bottom: 20px; 
            border: 2px solid var(--hex-dark); 
        }
        .print-intro-top { 
            display: flex; 
            width: 100%; 
            background: #fff; 
        }
        .print-intro-left { 
            flex: 1; 
            padding: 20px; 
        }
        .print-intro-right { 
            width: 220px; 
            text-align: center; 
            border-left: 2px solid var(--hex-dark); 
            background: var(--hex-gray); 
            padding: 20px; 
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .print-doc-heading { 
            font-size: 24px; 
            font-weight: 900; 
            line-height: 1; 
            margin-bottom: 5px; 
            letter-spacing: 1px;
        }
        .print-doc-no { 
            font-size: 10px; 
            color: #666; 
            margin-bottom: 15px; 
        }
        
        /* LOGO SEGITIGA HEXINDO */
        .triangle-logo { 
            width: 0; height: 0; 
            border-left: 12px solid transparent; 
            border-right: 12px solid transparent; 
            border-bottom: 22px solid var(--hex-orange); 
            margin-bottom: 8px;
            position: relative; 
        }
        .triangle-logo::after { 
            content: ''; position: absolute; 
            top: 10px; left: -4px; 
            width: 0; height: 0; 
            border-left: 4px solid transparent; 
            border-right: 4px solid transparent; 
            border-bottom: 8px solid #fff; 
        }
        
        /* BARIS META DATA */
        .print-meta-row { 
            display: flex; 
            border-top: 2px solid var(--hex-dark); 
            background: #fff; 
        }
        .print-meta-card { 
            flex: 1; 
            padding: 8px 15px; 
            border-right: 2px solid var(--hex-dark); 
        }
        .print-meta-card:last-child { border-right: none; }
        .print-meta-label { 
            font-size: 8px; 
            font-weight: 800; 
            text-transform: uppercase; 
            color: var(--hex-orange); 
            margin-bottom: 2px; 
        }
        .print-meta-value { font-size: 11px; font-weight: 700; }

        /* ==================================================
           KARTU ASET (ISI LAPORAN)
           ================================================== */
        .asset-card { 
            border: 2px solid var(--hex-border); 
            background: #fff; 
            margin-bottom: 20px; 
        }
        .asset-top { 
            position: relative; 
            padding: 12px 15px; 
            border-bottom: 2px solid var(--hex-border); 
            background: var(--hex-gray); 
        }
        /* Garis Oranye di samping kiri header aset */
        .asset-top::before { 
            content: ""; 
            position: absolute; 
            top: 0; left: 0; bottom: 0; 
            width: 6px; 
            background-color: var(--hex-orange); 
        }
        
        .asset-title { font-size: 13px; font-weight: 800; margin: 0 0 4px 10px; text-transform: uppercase; color: var(--hex-dark); }
        .asset-meta { font-size: 10px; color: #555; margin-left: 10px; }

        .asset-body { padding: 15px; }
        
        .info-box { 
            background: #fff; 
            border: 1px dashed var(--hex-orange); 
            padding: 12px; 
            margin-bottom: 15px; 
            color: #333; 
            border-radius: 4px;
        }
        
        /* TIMELINE LOGISTIK */
        .timeline-card { 
            border: 1px solid var(--hex-border); 
            background: #fff; 
            margin-bottom: 10px; 
        }
        .timeline-head { 
            padding: 8px 12px; 
            background: var(--hex-gray); 
            border-bottom: 1px solid var(--hex-border); 
            display: flex; justify-content: space-between; align-items: center; 
        }
        .timeline-title { font-size: 10px; font-weight: 800; color: var(--hex-dark); text-transform: uppercase; }
        
        /* Status Badges */
        .status-badge { 
            color: #fff; 
            padding: 3px 8px; 
            border-radius: 4px; 
            font-size: 9px; 
            font-weight: 800; 
            text-transform: uppercase;
        }
        .status-badge.selesai { background: #059669; } /* Hijau Success */
        .status-badge.proses { background: #D32F2F; } /* Merah Danger (Belum Diterima) */

        /* Grid Tabel dalam Timeline */
        .detail-grid { 
            display: flex; flex-wrap: wrap; 
            border: 1px solid var(--hex-border); 
            border-bottom: none; 
        }
        .detail-box { 
            width: 50%; 
            padding: 6px 10px; 
            border-bottom: 1px solid var(--hex-border); 
            border-right: 1px solid var(--hex-border); 
            box-sizing: border-box; 
        }
        .detail-box:nth-child(even) { border-right: none; }
        .detail-label { 
            font-size: 8px; font-weight: 800; 
            color: #555; text-transform: uppercase; margin-bottom: 2px; 
        }
        .detail-value { font-size: 10px; font-weight: 700; color: var(--hex-dark); }

        /* Catatan (Log Notes) */
        .log-note-grid { display: flex; gap: 10px; margin-top: 10px; }
        .log-note-card { 
            flex: 1; padding: 10px; 
            border: 1px solid var(--hex-border); 
            background: #fff; 
        }
        
        /* Teks Penutup / Footer */
        .footer-document {
            text-align: center; 
            margin-top: 30px; 
            font-size: 8px; 
            color: #888; 
            border-top: 1px solid var(--hex-border); 
            padding-top: 10px;
        }
    </style>
</head>
<body>

<!-- WADAH PEMBUNGKUS KERTAS A4 -->
<div class="page-wrap">

    <div class="print-intro">
        <div class="print-intro-top">
            <div class="print-intro-left">
                <div class="print-doc-heading">IT SYSTEM REPORT</div>
                <div class="print-doc-no">Doc Ref: <?= h($docNum) ?></div>
                <div style="font-size: 11px; font-weight: 800; margin-bottom: 3px;">Ringkasan Informasi Dokumen:</div>
                <div style="font-size: 10px; color: #555; line-height: 1.6;">
                    PT HEXINDO ADIPERKASA TBK<br>
                    Divisi: IT Head Office<br>
                    Laporan: Mutasi Asset dan Log Aktivitas Logistik
                </div>
            </div>
            <div class="print-intro-right">
                <div class="triangle-logo"></div>
                <div style="font-size: 13px; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; color: var(--hex-dark);">PT HEXINDO</div>
                <div style="font-size: 9px; font-weight: 700; color: #555;">IT DIVISION</div>
            </div>
        </div>
        <div class="print-meta-row">
            <div class="print-meta-card">
                <div class="print-meta-label">Cakupan Periode</div>
                <div class="print-meta-value"><?= h($range['label']) ?></div>
            </div>
            <div class="print-meta-card">
                <div class="print-meta-label">Tanggal Dicetak</div>
                <div class="print-meta-value"><?= date('d M Y, H:i') ?> WIB</div>
            </div>
            <div class="print-meta-card">
                <div class="print-meta-label">Klasifikasi Dokumen</div>
                <div class="print-meta-value">Valid / Internal Use Only</div>
            </div>
        </div>
    </div>

    <?php if (empty($groupedAssets)): ?>
        <div style="text-align: center; padding: 40px; border: 1px dashed var(--hex-border); color: #666; font-weight: 600;">
            Tidak ada data transaksi laporan pada periode yang dipilih.
        </div>
    <?php else: foreach ($groupedAssets as $entry): $asset = $entry['asset']; ?>
        <div class="asset-card">
            
            <!-- HEADER ASET -->
            <div class="asset-top">
                <div class="asset-title"><?= h($asset['nama_barang']) ?> - <?= h($asset['nama_merk']) ?></div>
                <div class="asset-meta">
                    No Asset: <b><?= h($asset['no_asset'] ?: '-') ?></b> | 
                    Serial Number: <b><?= h($asset['serial_number'] ?: '-') ?></b> | 
                    User/PIC: <b><?= h($asset['user'] ?: 'Belum Ada') ?></b>
                </div>
            </div>

            <!-- ISI ASET & TIMELINE -->
            <div class="asset-body">
                <div class="info-box">
                    <div style="font-size: 11px; font-weight: 800; margin-bottom: 5px; color: var(--hex-dark);">LOKASI & STATUS TERKINI</div>
                    Cabang Aktif: <b><?= h($asset['branch_aktif'] ?: '-') ?></b> | 
                    Tanggal Tiba: <b><?= h($asset['tanggal_terima'] ?: '-') ?></b> | 
                    Status: <b><?= h($asset['nama_status'] ?: '-') ?></b><br>
                    Kondisi Fisik: <b style="color: <?= ($asset['bermasalah'] === 'Iya') ? '#D32F2F' : '#059669' ?>;">
                        <?= ($asset['bermasalah'] === 'Iya' ? 'Bermasalah — ' . h($asset['keterangan_masalah']) : 'Kondisi Normal') ?>
                    </b>
                </div>

                <?php if (empty($entry['timeline'])): ?>
                    <div class="timeline-card" style="padding: 15px; text-align: center; color: #666; font-size: 10px; font-style: italic;">
                        Belum ada riwayat aktivitas logistik atau pemindahan yang tercatat untuk aset ini.
                    </div>
                <?php else: foreach ($entry['timeline'] as $idx => $step): 
                    $isDone = in_array(strtolower(trim($step['status_pengiriman'])), ['sudah diterima', 'sudah diterima ho', 'selesai']);
                    // Memaksa halaman baru jika aktivitas terlalu banyak agar tidak terpotong
                    $breakClass = ($idx === 2) ? 'force-new-page' : '';                
                ?>
                    <div class="timeline-card <?= $breakClass ?>">
                        <div class="timeline-head">
                            <div class="timeline-title">Riwayat Logistik Ke-<?= $idx + 1 ?></div>
                            <div class="status-badge <?= $isDone ? 'selesai' : 'proses' ?>">
                                <?= h($step['status_pengiriman']) ?>
                            </div>
                        </div>
                        <div style="padding: 10px;">
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
                                    <div class="detail-label">Tanggal Dikirim</div>
                                    <div class="detail-value"><?= h($step['tanggal_keluar'] ?? '-') ?></div>
                                </div>
                                <div class="detail-box">
                                    <div class="detail-label">Tanggal Diterima</div>
                                    <div class="detail-value"><?= h($step['tanggal_diterima'] ?? '-') ?></div>
                                </div>
                            </div>

                            <div class="log-note-grid">
                                <div class="log-note-card">
                                    <div class="detail-label">Informasi Ekspedisi</div>
                                    <div style="font-size: 10px; color: #333; margin-top: 3px;">
                                        Jasa Kurir: <b><?= h($step['jasa_pengiriman'] ?: '-') ?></b><br>
                                        No Resi/AWB: <b><?= h($step['nomor_resi_keluar'] ?: '-') ?></b>
                                    </div>
                                </div>
                                <div class="log-note-card">
                                    <div class="detail-label">Informasi Penerima</div>
                                    <div style="font-size: 10px; color: #333; margin-top: 3px;">
                                        Penerima Fisik: <b><?= h($step['nama_penerima'] ?: '-') ?></b><br>
                                        Status Validasi: <b><?= $isDone ? 'Terkonfirmasi.' : 'Menunggu Konfirmasi.' ?></b>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            
        </div>
    <?php endforeach; endif; ?>

    <div class="footer-document">
        PT HEXINDO ADIPERKASA TBK · Laporan Sistem Manajemen Aset & Logistik · Dokumen Ini Valid Dihasilkan Oleh Komputer Tanpa Tanda Tangan Fisik.
    </div>

</div> <!-- Akhir dari pembungkus kertas -->

    <!-- Auto-Print Script -->
    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 300); // Jeda sebentar agar font selesai diload sebelum menu print muncul
        }
    </script>
</body>
</html>