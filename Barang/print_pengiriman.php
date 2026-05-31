<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Tanda Terima & Label Packing</title>
    <style>
        /* BACKGROUND ABU-ABU SEPERTI TAMPILAN BROWSER PDF */
        body { 
            background-color: #525659; 
            font-family: Arial, Helvetica, sans-serif; 
            margin: 0; 
            padding: 40px 0; 
            color: #000; 
        }

        /* FORMAT LEMBARAN KERTAS (A4) */
        .page-container {
            background-color: #ffffff; 
            width: 210mm; 
            min-height: 297mm; 
            margin: 0 auto 40px auto; 
            padding: 20px; 
            box-sizing: border-box;
            box-shadow: 0 4px 8px rgba(0,0,0,0.5); 
            font-weight: 900; 
            font-size: 13px;
        }

        /* 
           =========================================================
           PENGATURAN CETAK (MENGHILANGKAN URL BAWAAN BROWSER)
           ========================================================= 
        */
        @page {
            size: A4 portrait;
            margin: 0; /* INI YANG BIKIN TULISAN URL DI BAWAH HILANG */
        }

        @media print {
            body { 
                background-color: #ffffff; 
                padding: 10mm; /* Memberi jarak agar konten tidak mepet tepi kertas */
                margin: 0; 
            }
            .page-container { 
                margin: 0; 
                padding: 0; 
                box-shadow: none; 
                width: 100%; 
                min-height: auto; 
                page-break-after: always; /* INI KUNCI AGAR JADI 2 HALAMAN PDF */
            }
            .page-container:last-child { page-break-after: auto; }
        }

        /* BORDER LUAR HALAMAN 1 (GARIS TEBAL HITAM) */
        .cetak-border {
            border: 4px solid #000; 
            padding: 15px; 
            height: 100%; 
            box-sizing: border-box;
        }

        /* =========================================================
           STYLING HALAMAN 1 (SURAT TANDA TERIMA)
        ========================================================= */
        .header-box { display: flex; align-items: center; justify-content: flex-start; border-bottom: 4px solid #000; padding-bottom: 10px; margin-bottom: 10px; }
        .header-logo { display: flex; align-items: center; font-size: 30px; color: #000; margin-right: 20px; letter-spacing: 1px; }
        
        .triangle-logo { width: 0; height: 0; border-left: 20px solid transparent; border-right: 20px solid transparent; border-bottom: 35px solid #d46b25; margin-right: 15px; position: relative; }
        .triangle-logo::after { content: ''; position: absolute; top: 15px; left: -8px; width: 0; height: 0; border-left: 8px solid transparent; border-right: 8px solid transparent; border-bottom: 15px solid #fff; }
        
        .header-text { margin-left: 20px; }
        .header-text h2 { margin: 0 0 5px 0; font-size: 18px; letter-spacing: 0.5px; }
        .header-text h3 { margin: 0; font-size: 16px; letter-spacing: 0.5px; }
        
        .title { text-align: center; color: #bd1414; font-size: 16px; border-bottom: 4px solid #000; padding-bottom: 5px; margin-bottom: 5px; text-transform: uppercase; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 2px solid #000; padding: 8px; text-transform: uppercase; font-weight: 900; }
        th { text-align: center; }
        .td-center { text-align: center; }
        
        .info-box { width: 50%; border-collapse: collapse; float: left;}
        .info-box td { border: 2px solid #000; padding: 6px 10px; }
        
        .clear { clear: both; }

        .tanggal { text-align: right; margin: 15px 0 5px 0; font-weight: 900; text-transform: uppercase; font-size: 14px;}

        .sig-table { width: 100%; border-collapse: collapse; margin-top: 5px; }
        .sig-table th, .sig-table td { border: 2px solid #000; text-align: center; width: 33.33%; }
        .sig-table th { padding: 5px; }
        .sig-table td { height: 100px; vertical-align: bottom; padding-bottom: 10px; }

        .footer-note { font-size: 12px; text-align: center; margin-top: 15px; font-weight: bold;}

        /* =========================================================
           STYLING HALAMAN 2 (LABEL PACKING)
        ========================================================= */
        .label-box {
            width: 100%;
            max-width: 750px;
            margin: 60px auto 0 auto;
            border: 2px solid #000;
            text-align: center;
            font-family: Arial, Helvetica, sans-serif;
            color: #000;
        }
        .label-section {
            border-bottom: 2px solid #000;
            padding: 35px 20px;
        }
        .label-section:last-child {
            border-bottom: none;
            padding: 25px 20px;
        }
        
        .text-kop { font-size: 22px; font-weight: normal; margin-bottom: 25px; }
        .text-dest { font-size: 26px; font-weight: 900; margin-bottom: 20px; }
        .text-up { font-size: 24px; font-weight: 900; }
        .text-from { font-size: 22px; font-weight: normal; }
        .text-warning { font-size: 20px; font-weight: normal; margin-bottom: 10px; }
        .text-warning-bold { font-size: 22px; font-weight: 900; line-height: 1.4; }
    </style>
</head>
<body>

<?php
    // LOGIKA CERDAS UNTUK MEMBEDAKAN PRINT DARI ADMIN (HO) ATAU USER CABANG
    $isAdmin = (isset($_GET['is_admin']) && $_GET['is_admin'] == '1') ? true : false;
?>

<!-- =========================================================================
     HALAMAN 1 : SURAT TANDA TERIMA
     ========================================================================= -->
<div class="page-container">
    <div class="cetak-border">
        
        <div class="header-box">
            <div class="header-logo">
                <div class="triangle-logo"></div>
                HEXINDO
            </div>
            <div class="header-text">
                <h2>PT HEXINDO ADIPERKASA TBK</h2>
                <h3>IT DIVISION HEAD OFFICE</h3>
            </div>
        </div>

        <div class="title">TANDA TERIMA PENGIRIMAN BARANG</div>

        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">NO</th>
                    <th style="width: 40%;">DESKRIPSI BARANG</th>
                    <th style="width: 25%;">HOSTNAME</th>
                    <th style="width: 10%;">QTY</th>
                    <th style="width: 20%;">CATATAN</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="td-center">1</td>
                    <td><?php echo htmlspecialchars($_GET['description'][0] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($_GET['hostname'][0] ?? ''); ?></td>
                    <td class="td-center"><?php echo htmlspecialchars($_GET['qty'] ?? '1'); ?></td>
                    <td class="td-center"><?php echo htmlspecialchars($_GET['catatan'] ?? ''); ?></td>
                </tr>
                <tr>
                    <td class="td-center">2</td>
                    <td></td><td></td><td></td><td></td>
                </tr>
                <tr>
                    <td class="td-center">3</td>
                    <td></td><td></td><td></td><td></td>
                </tr>
                <tr>
                    <td class="td-center">4</td>
                    <td></td><td></td><td></td><td></td>
                </tr>
            </tbody>
        </table>

        <!-- INFO BOX -->
        <table class="info-box">
            <tr>
                <td>ASURANSI : <?php echo htmlspecialchars($_GET['asuransi'] ?? ''); ?></td>
            </tr>
            <tr>
                <td>CHARGE : <?php echo htmlspecialchars($_GET['charge'] ?? ''); ?></td>
            </tr>
            <tr>
                <td>USER : <?php echo htmlspecialchars($_GET['user'] ?? ''); ?></td>
            </tr>
        </table>
        <div class="clear"></div>

        <?php $tanggal_cetak = strtoupper(date('d F Y')); ?>
        <div class="tanggal">JAKARTA, <?php echo $tanggal_cetak; ?></div>

        <!-- TABEL TANDA TANGAN DINAMIS -->
        <table class="sig-table">
            <tr>
                <th>PENERIMA</th>
                <th>EKSPEDISI</th>
                <th>PENGIRIM</th>
            </tr>
            <tr>
                <?php if ($isAdmin): ?>
                    <!-- JIKA ADMIN YANG CETAK (HO -> CABANG) -->
                    <td>
                        ( <?php echo htmlspecialchars($_GET['penerima'] ?? ''); ?> )<br>
                        <?php echo htmlspecialchars($_GET['penerima_branch'] ?? ''); ?>
                    </td>
                    <!-- KOLOM EKSPEDISI DIRAPIKAN -->
                    <td>
                        <div style="white-space: nowrap;">( ........................................ )</div>
                    </td>
                    <td>
                        ( DENI PRATAMA )<br>
                        IT HEAD OFFICE
                    </td>
                <?php else: ?>
                    <!-- JIKA CABANG YANG CETAK (CABANG -> HO) -->
                    <td>
                        ( DENI PRATAMA )<br>
                        IT HEAD OFFICE
                    </td>
                    <!-- KOLOM EKSPEDISI DIRAPIKAN -->
                    <td>
                        <div style="white-space: nowrap;">( ........................................ )</div>
                    </td>
                    <td>
                        ( <?php echo htmlspecialchars($_GET['pengirim'] ?? ''); ?> )<br>
                        <?php echo htmlspecialchars($_GET['pengirim_branch'] ?? ''); ?>
                    </td>
                <?php endif; ?>
            </tr>
        </table>

        <div class="footer-note">
            NOTE : Apabila sudah diterima dan ditandatangan harap dikonfirmasi<br>
            ke denipratama@hexindo-tbk.co.id
        </div>
        
    </div>
</div>

<!-- =========================================================================
     HALAMAN 2 : LABEL PACKING KARDUS (BERUBAH SESUAI YANG PRINT)
     ========================================================================= -->
<div class="page-container">
    
    <div class="label-box">
        
        <div class="label-section">
            <div class="text-kop">PT HEXINDO ADIPERKASA TBK</div>
            <?php if ($isAdmin): ?>
                <!-- JIKA DARI ADMIN/HO KE CABANG -->
                <div class="text-dest">CABANG : <?php echo htmlspecialchars($_GET['penerima_branch'] ?? ''); ?></div>
                <div class="text-up">UP : <?php echo htmlspecialchars($_GET['penerima'] ?? ''); ?></div>
            <?php else: ?>
                <!-- JIKA DARI CABANG KE ADMIN/HO -->
                <div class="text-dest">HEAD OFFICE : JAKARTA</div>
                <div class="text-up">UP : DENI PRATAMA</div>
            <?php endif; ?>
        </div>
        
        <div class="label-section">
            <?php if ($isAdmin): ?>
                <div class="text-from">FROM : IT HEAD OFFICE</div>
            <?php else: ?>
                <div class="text-from">FROM : CABANG <?php echo htmlspecialchars($_GET['pengirim_branch'] ?? ''); ?></div>
            <?php endif; ?>
        </div>
        
        <div class="label-section">
            <div class="text-warning">HATI - HATI KOMPUTER</div>
            <?php if ($isAdmin): ?>
                <div class="text-warning-bold">JANGAN DI BANTING / PACKING KAYU TO<br>CABANG <?php echo htmlspecialchars($_GET['penerima_branch'] ?? ''); ?></div>
            <?php else: ?>
                <div class="text-warning-bold">JANGAN DI BANTING / PACKING KAYU TO<br>HEAD OFFICE JAKARTA</div>
            <?php endif; ?>
        </div>

    </div>

</div>

<!-- SCRIPT OTOMATIS MUNCULKAN WINDOW PRINT -->
<script>
    window.onload = function() {
        window.print();
    };
</script>

</body>
</html>