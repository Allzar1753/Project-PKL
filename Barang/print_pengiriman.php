<?php
include '../config/auth.php';
require_login();

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$descriptions = isset($_GET['description']) ? (array) $_GET['description'] : [];
$hostnames = isset($_GET['hostname']) ? (array) $_GET['hostname'] : [];
$qty = trim((string) ($_GET['qty'] ?? ''));
$catatan = trim((string) ($_GET['catatan'] ?? ''));
$attn = trim((string) ($_GET['attn'] ?? ''));
$asuransi = trim((string) ($_GET['asuransi'] ?? ''));
$charge = trim((string) ($_GET['charge'] ?? ''));
$penerima = trim((string) ($_GET['penerima'] ?? ''));
$ekspedisi = trim((string) ($_GET['ekspedisi'] ?? ''));
$pengirim = trim((string) ($_GET['pengirim'] ?? ''));
$userCabang = trim((string) ($_GET['user'] ?? ''));
$today = date('d F Y');

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Tanda Terima Pengiriman Barang</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 24px; color: #1f2937; }
        .page { max-width: 900px; margin: 0 auto; border: 1px solid #333; padding: 24px; }
        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .logo { display: flex; align-items: center; gap: 12px; }
        .logo h1 { margin: 0; font-size: 28px; letter-spacing: -1px; }
        .title { text-align: center; flex: 1; }
        .title h2 { margin: 0; font-size: 18px; text-transform: uppercase; letter-spacing: .1em; }
        .title p { margin: 4px 0 0; font-size: 12px; }
        .box { border: 1px solid #333; padding: 16px; margin-bottom: 16px; }
        .row { display: flex; flex-wrap: wrap; gap: 16px; }
        .col { flex: 1 1 240px; min-width: 240px; }
        .label { font-weight: 700; font-size: 12px; margin-bottom: 6px; display: block; text-transform: uppercase; color: #374151; }
        .value { font-size: 14px; min-height: 20px; }
        .table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .table th, .table td { border: 1px solid #333; padding: 10px; font-size: 13px; }
        .table th { background: #f3f4f6; text-align: left; }
        .notes { font-size: 13px; color: #334155; }
        .footer { display: flex; justify-content: space-between; margin-top: 24px; gap: 16px; }
        .footer .sign { width: 48%; border: 1px solid #333; padding: 16px; min-height: 120px; }
        .footer .sign strong { display: block; margin-bottom: 8px; }
        .small { font-size: 12px; color: #4b5563; }
        @media print {
            body { margin: 0; }
            .page { border: none; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <div class="logo">
                <div style="width:48px;height:48px;background:#f59e0b;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:24px;">H</div>
                <div>
                    <h1>HEXINDO</h1>
                    <div style="font-size:12px; color:#4b5563;">PT HEXINDO ADIPERKASA TBK</div>
                </div>
            </div>
            <div class="title">
                <h2>TANDA TERIMA PENGIRIMAN BARANG</h2>
                <p>IT DIVISION HEAD OFFICE</p>
            </div>
            <div style="min-width:120px;text-align:right;">
                <div class="label">Tanggal</div>
                <div class="value"><?= h($today) ?></div>
            </div>
        </div>

        <div class="box">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:6%;">NO</th>
                        <th>DESKRIPSI BARANG</th>
                        <th>HOSTNAME</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $count = max(1, count($descriptions));
                    for ($i = 0; $i < $count; $i++):
                        $desc = isset($descriptions[$i]) ? (string) $descriptions[$i] : '';
                        $host = isset($hostnames[$i]) ? (string) $hostnames[$i] : '';
                    ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= h($desc) ?></td>
                            <td><?= h($host) ?></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
            <div class="row" style="margin-top:16px;">
                <div class="col">
                    <span class="label">Qty</span>
                    <div class="value"><?= h($qty ?: '-') ?></div>
                </div>
                <div class="col">
                    <span class="label">ATTN</span>
                    <div class="value"><?= h($attn) ?></div>
                </div>
                <div class="col">
                    <span class="label">Asuransi</span>
                    <div class="value"><?= h($asuransi) ?></div>
                </div>
                <div class="col">
                    <span class="label">Charge</span>
                    <div class="value"><?= h($charge) ?></div>
                </div>
            </div>
            <div class="row" style="margin-top:16px;">
                <div class="col">
                    <span class="label">User / Cabang</span>
                    <div class="value"><?= h($userCabang) ?></div>
                </div>
                <div class="col">
                    <span class="label">Penerima</span>
                    <div class="value"><?= h($penerima) ?></div>
                </div>
                <div class="col">
                    <span class="label">Ekspedisi</span>
                    <div class="value"><?= h($ekspedisi) ?></div>
                </div>
            </div>
            <div class="box" style="margin-top:16px;">
                <span class="label">Catatan</span>
                <div class="value notes"><?= nl2br(h($catatan)) ?></div>
            </div>
            <div class="row" style="margin-top:16px;">
                <div class="col">
                    <span class="label">Pengirim</span>
                    <div class="value"><?= h($pengirim) ?></div>
                </div>
            </div>
        </div>

        <!-- single Catatan box above already renders general notes -->

        <div class="footer">
            <div class="sign">
                <strong>Penerima</strong>
                <div style="height:72px; margin-top:12px; border-bottom:1px solid #333;"></div>
                <div style="text-align:center; margin-top:8px;"><?= h($penerima ?: '-') ?></div>
            </div>
            <div class="sign">
                <strong>Pengirim</strong>
                <div style="height:72px; margin-top:12px; border-bottom:1px solid #333;"></div>
                <div style="text-align:center; margin-top:8px;"><?= h($pengirim ?: '-') ?></div>
            </div>
        </div>

        <div class="small" style="margin-top:24px;">Jika sudah diterima dan ditandatangani, simpan dokumen ini sebagai bukti pengiriman dan lanjutkan proses pengiriman barang ke cabang.</div>
    </div>
    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 250);
        };
    </script>
</body>
</html>
