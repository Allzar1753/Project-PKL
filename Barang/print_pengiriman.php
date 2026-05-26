<?php
include '../config/auth.php';
require_login();

function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$descriptions   = isset($_GET['description']) ? (array) $_GET['description'] : [];
$hostnames      = isset($_GET['hostname'])    ? (array) $_GET['hostname']    : [];
$qty            = trim((string) ($_GET['qty']            ?? ''));
$catatan        = trim((string) ($_GET['catatan']        ?? ''));
$asuransi       = trim((string) ($_GET['asuransi']       ?? ''));
$charge         = trim((string) ($_GET['charge']         ?? ''));
$penerima       = trim((string) ($_GET['penerima']       ?? ''));
$ekspedisi      = trim((string) ($_GET['ekspedisi']      ?? ''));
$pengirim       = trim((string) ($_GET['pengirim']       ?? ''));
$userCabang     = trim((string) ($_GET['user']           ?? ''));
$namaBarangAuto = trim((string) ($_GET['nama_barang_auto'] ?? ''));
$pengirimBranch  = trim((string) ($_GET['pengirim_branch']  ?? ''));
$penerimaBranch  = trim((string) ($_GET['penerima_branch']  ?? ''));
$isAdmin         = trim((string) ($_GET['is_admin']         ?? '0'));     
$today          = date('d F Y');

// Tentukan TTD kiri (Penerima) dan kanan (Pengirim)
if ($isAdmin === '1') {
    // Admin HO kirim ke Cabang
    $ttdKiriNama    = $penerima;       // nama penerima di cabang
    $ttdKiriJabatan = $penerimaBranch;     // nama branch cabang tujuan
    $ttdKananNama   = 'Pak Deni';       // nama admin HO
    $ttdKananJabatan = 'Head Office';
} else {
    // Cabang kirim ke HO
    $ttdKiriNama    = 'Pak Deni';       // nama penerima di HO
    $ttdKiriJabatan = 'Head Office';
    $ttdKananNama   = $pengirim;       // nama user cabang
    $ttdKananJabatan = $pengirimBranch; // nama branch cabang pengirim
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Tanda Terima Pengiriman Barang</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 0; padding: 24px; color: #1f2937; font-size: 13px; }
        .page { max-width: 900px; margin: 0 auto; border: 1px solid #333; padding: 28px; }

        /* HEADER */
        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; border-bottom: 2px solid #333; padding-bottom: 16px; }
        .logo { display: flex; align-items: center; gap: 12px; }
        .logo-box { width: 48px; height: 48px; background: #f59e0b; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 24px; }
        .logo h1 { margin: 0; font-size: 26px; letter-spacing: -1px; }
        .logo-sub { font-size: 11px; color: #4b5563; }
        .title-center { text-align: center; flex: 1; }
        .title-center h2 { margin: 0; font-size: 16px; text-transform: uppercase; letter-spacing: .08em; }
        .title-center p { margin: 4px 0 0; font-size: 11px; color: #6b7280; }
        .date-box { text-align: right; min-width: 110px; }
        .date-label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #374151; }
        .date-value { font-size: 13px; margin-top: 4px; }

        /* TABLE */
        .table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        .table th { background: #f3f4f6; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .table th, .table td { border: 1px solid #aaa; padding: 10px 12px; vertical-align: top; }
        .table td:first-child { text-align: center; width: 5%; }
        .table td:last-child { width: 25%; }

        /* INFO GRID */
        .info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0; border: 1px solid #aaa; border-top: none; }
        .info-cell { padding: 10px 14px; border-right: 1px solid #aaa; border-bottom: 1px solid #aaa; }
        .info-cell:nth-child(3n) { border-right: none; }
        .info-cell:nth-last-child(-n+3) { border-bottom: none; }
        .info-label { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #6b7280; margin-bottom: 4px; }
        .info-value { font-size: 13px; color: #111; min-height: 18px; }

        /* CATATAN */
        .catatan-box { border: 1px solid #aaa; border-top: none; padding: 10px 14px; }
        .catatan-label { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #6b7280; margin-bottom: 4px; }
        .catatan-value { font-size: 13px; min-height: 40px; }

        /* TTD */
        .footer { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 28px; }
        .sign-box { border: 1px solid #aaa; padding: 16px 20px; }
        .sign-title { font-size: 12px; font-weight: 700; text-transform: uppercase; color: #374151; margin-bottom: 4px; }
        .sign-space { height: 72px; border-bottom: 1px solid #333; margin: 12px 0 8px; }
        .sign-name { text-align: center; font-weight: 700; font-size: 13px; }
        .sign-jabatan { text-align: center; font-size: 11px; color: #6b7280; margin-top: 2px; }

        .small-note { font-size: 11px; color: #6b7280; margin-top: 20px; text-align: center; }

        @media print {
            body { margin: 0; padding: 0; }
            .page { border: none; padding: 16px; }
        }
    </style>
</head>
<body>
<div class="page">

    <!-- HEADER -->
    <div class="header">
        <div class="logo">
            <div class="logo-box">H</div>
            <div>
                <h1>HEXINDO</h1>
                <div class="logo-sub">PT HEXINDO ADIPERKASA TBK</div>
            </div>
        </div>
        <div class="title-center">
            <h2>Tanda Terima Pengiriman Barang</h2>
            <p>IT DIVISION HEAD OFFICE</p>
        </div>
        <div class="date-box">
            <div class="date-label">Tanggal</div>
            <div class="date-value"><?= h($today) ?></div>
        </div>
    </div>

    <!-- TABEL DESKRIPSI BARANG -->
    <table class="table">
        <thead>
            <tr>
                <th style="width:5%;">NO</th>
                <th>DESKRIPSI BARANG</th>
                <th style="width:25%;">HOSTNAME</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // Gunakan nama_barang_auto sebagai deskripsi otomatis
            $count = max(1, count($descriptions));
            for ($i = 0; $i < $count; $i++):
                // Jika ada nama_barang_auto, tampilkan itu — tidak bisa diedit (sudah di print view)
                $desc = $namaBarangAuto !== '' ? $namaBarangAuto : (isset($descriptions[$i]) ? (string)$descriptions[$i] : '');
                $host = isset($hostnames[$i]) ? (string)$hostnames[$i] : '';
            ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= h($desc) ?></td>
                <td><?= h($host) ?></td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <!-- INFO GRID -->
    <div class="info-grid">
        <div class="info-cell">
            <div class="info-label">QTY</div>
            <div class="info-value"><?= h($qty ?: '1') ?></div>
        </div>
        <div class="info-cell">
            <div class="info-label">Asuransi</div>
            <div class="info-value"><?= h($asuransi ?: '-') ?></div>
        </div>
        <div class="info-cell">
            <div class="info-label">Charge</div>
            <div class="info-value"><?= h($charge ?: '-') ?></div>
        </div>
        <div class="info-cell">
            <div class="info-label">User / Cabang</div>
            <div class="info-value"><?= h($userCabang ?: '-') ?></div>
        </div>
        <div class="info-cell">
            <div class="info-label">Penerima</div>
            <div class="info-value"><?= h($penerima ?: '-') ?></div>
        </div>
        <div class="info-cell">
            <div class="info-label">Ekspedisi</div>
            <div class="info-value"><?= h($ekspedisi ?: '-') ?></div>
        </div>
    </div>

    <!-- CATATAN -->
    <div class="catatan-box">
        <div class="catatan-label">Catatan</div>
        <div class="catatan-value"><?= nl2br(h($catatan)) ?></div>
    </div>

    <!-- TTD -->
    <div class="footer">
        <div class="sign-box">
            <div class="sign-title">Penerima</div>
            <div class="sign-space"></div>
            <div class="sign-name"><?= h($ttdKiriNama ?: '-') ?></div>
            <div class="sign-jabatan"><?= h($ttdKiriJabatan) ?></div>
        </div>
        <div class="sign-box">
            <div class="sign-title">Pengirim</div>
            <div class="sign-space"></div>
            <div class="sign-name"><?= h($ttdKananNama ?: '-') ?></div>
            <div class="sign-jabatan"><?= h($ttdKananJabatan) ?></div>
        </div>
    </div>

    <div class="small-note">Jika sudah diterima dan ditandatangani, simpan dokumen ini sebagai bukti pengiriman dan lanjutkan proses pengiriman barang ke cabang.</div>
</div>
<script>
    window.onload = function() {
        setTimeout(function() { window.print(); }, 300);
    };
</script>
</body>
</html>