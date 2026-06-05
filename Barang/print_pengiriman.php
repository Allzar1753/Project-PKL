<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Tanda Terima & Label Packing (Landscape)</title>
    <style>
        /* =========================================================
           1. RESET & SETUP BACKGROUND BROWSER
           ========================================================= */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap');

        body { 
            background-color: #525659; 
            font-family: 'Arial', sans-serif; 
            margin: 0; 
            padding: 40px 0; 
            color: #000; 
            -webkit-print-color-adjust: exact !important; 
            print-color-adjust: exact !important;
        }

        /* FORMAT KERTAS A4 LANDSCAPE DI BROWSER */
        .page-container {
            background-color: #ffffff; 
            width: 297mm;  /* LEBAR A4 */
            min-height: 210mm; /* TINGGI A4 */
            margin: 0 auto 40px auto; 
            padding: 10mm; 
            box-sizing: border-box;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5); 
            font-size: 13px;
        }

        /* =========================================================
           2. PENGATURAN CETAK (PRINT MEDIA QUERY) LANDSCAPE
           ========================================================= */
        @page {
            size: A4 landscape; /* PAKSA PRINTER JADI LANDSCAPE */
            margin: 0mm; 
        }

        @media print {
            body { 
                background-color: #ffffff; 
                padding: 0; 
                margin: 0; 
            }
            .page-container { 
                margin: 0; 
                padding: 10mm; 
                box-shadow: none; 
                width: 297mm; 
                height: 210mm; /* PAKSA TINGGI KERTAS PRESISI */
                page-break-after: always; 
                page-break-inside: avoid;
            }
            .page-container:last-child { 
                page-break-after: auto; 
            }
        }

        /* =========================================================
           3. STYLING HALAMAN 1 (SURAT TANDA TERIMA)
           ========================================================= */
        .cetak-border {
            border: 3px solid #000; 
            padding: 15px 20px; 
            height: 100%; 
            box-sizing: border-box;
            position: relative; 
            display: flex;
            flex-direction: column; /* PENTING UNTUK PRESISI TTD */
            justify-content: space-between; /* PENTING UNTUK PRESISI TTD */
        }

        /* BUNGKUS KONTEN ATAS (HEADER + TABEL + INFO) */
        .top-content {
            width: 100%;
        }

        /* HEADER KOP SURAT */
        .header-box { 
            display: flex; 
            align-items: flex-end; 
            justify-content: space-between; 
            border-bottom: 3px solid #000; 
            padding-bottom: 10px; 
            margin-bottom: 10px; 
        }
        
        .header-logo { 
            display: flex; 
            align-items: center; 
            font-size: 30px; 
            font-weight: 900;
            color: #000; 
            letter-spacing: 1px; 
            font-family: 'Inter', sans-serif;
            margin-bottom: -4px;
        }
        .triangle-logo { 
            width: 0; height: 0; 
            border-left: 16px solid transparent; 
            border-right: 16px solid transparent; 
            border-bottom: 28px solid #E64312; 
            margin-right: 12px; 
            position: relative; 
        }
        .triangle-logo::after { 
            content: ''; position: absolute; 
            top: 12px; left: -6px; 
            width: 0; height: 0; 
            border-left: 6px solid transparent; 
            border-right: 6px solid transparent; 
            border-bottom: 11px solid #fff; 
        }
        
        .header-text { text-align: right; }
        .header-text h2 { margin: 0 0 2px 0; font-size: 18px; font-weight: 900; letter-spacing: 0.5px; }
        .header-text h3 { margin: 0; font-size: 13px; font-weight: 600; letter-spacing: 1px; color: #333; }
        
        .title { 
            text-align: center; 
            color: #D32F2F; 
            font-size: 16px; 
            font-weight: 900;
            border-bottom: 3px solid #000; 
            padding-bottom: 5px; 
            margin-bottom: 10px; 
            text-transform: uppercase; 
            letter-spacing: 2px;
        }
        
        /* TABEL BARANG */
        table.tbl-barang { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .tbl-barang th, .tbl-barang td { border: 2px solid #000; padding: 6px 10px; font-size: 13px;}
        .tbl-barang thead th { text-align: center; text-transform: uppercase; font-weight: 900; background-color: #f2f2f2; }
        .tbl-barang tbody td { font-weight: 700; text-transform: uppercase; }
        .td-center { text-align: center; }
        
        /* INFO & TANGGAL SEJAJAR */
        .bottom-wrap {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 10px;
        }

        .info-box { 
            width: 50%; 
            border-collapse: collapse; 
        }
        .info-box td { 
            border: 2px solid #000; 
            padding: 5px 10px; 
            font-weight: 900;
            font-size: 12px;
        }
        .info-box td:first-child { width: 25%; border-right: none;}
        .info-box td:last-child { width: 75%; border-left: none;}

        .tanggal { 
            font-weight: 900; 
            text-transform: uppercase; 
            font-size: 13px;
        }

        /* BUNGKUS KONTEN BAWAH (TTD + NOTE) AGAR SELALU PRESISI DI BAWAH */
        .bottom-content {
            width: 100%;
        }

        /* TABEL TANDA TANGAN */
        .sig-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 10px; /* Jarak ke NOTE */
        }
        .sig-table th, .sig-table td { border: 2px solid #000; text-align: center; width: 33.33%; }
        .sig-table th { padding: 6px; background-color: #f2f2f2; font-weight: 900; font-size: 12px;}
        .sig-table td { 
            height: 90px; 
            vertical-align: bottom; 
            padding-bottom: 5px; 
            font-weight: 900;
            font-size: 12px;
        }
        .sig-line {
            display: block; 
            border-bottom: 1px dotted #000;
            width: 60%;
            margin: 0 auto 5px auto; 
        }

        .footer-note { 
            font-size: 11px; 
            text-align: center; 
            font-weight: bold;
            font-style: italic;
            width: 100%;
        }

        /* =========================================================
           4. STYLING HALAMAN 2 (LABEL PACKING KEMBALI KE DESAIN ASLI)
           ========================================================= */
        .label-page {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Desain kotak ditengah seperti aslinya, tapi dilebarkan sedikit untuk landscape */
        .label-box {
            width: 100%;
            max-width: 900px; /* Lebar dimaksimalkan */
            border: 4px solid #000;
            text-align: center;
            font-family: 'Inter', Arial, sans-serif;
            color: #000;
            background-color: #fff;
        }

        .label-inner {
            border: 2px solid #000; /* Garis inner */
        }

        .label-section {
            border-bottom: 3px solid #000;
            padding: 35px 20px; /* Padding proporsional */
        }
        .label-section:last-child {
            border-bottom: none;
        }
        
        /* Font dikembalikan seperti hierarki desain Anda sebelumnya */
        .text-kop { font-size: 26px; font-weight: 700; margin-bottom: 25px; letter-spacing: 1px;}
        .text-dest { font-size: 34px; font-weight: 900; margin-bottom: 20px; text-decoration: underline;}
        .text-up { font-size: 28px; font-weight: 900; }
        
        .text-from { font-size: 26px; font-weight: 700; }
        
        .text-warning { font-size: 24px; font-weight: 700; margin-bottom: 15px; color: #D32F2F;}
        .text-warning-bold { font-size: 28px; font-weight: 900; line-height: 1.5; color: #000;}

    </style>
</head>
<body>

<?php
    // LOGIKA CERDAS UNTUK MEMBEDAKAN PRINT DARI ADMIN (HO) ATAU USER CABANG
    $isAdmin = (isset($_GET['is_admin']) && $_GET['is_admin'] == '1') ? true : false;
?>

<!-- =========================================================================
     HALAMAN 1 : SURAT TANDA TERIMA (LANDSCAPE - PRESISI)
     ========================================================================= -->
<div class="page-container">
    <div class="cetak-border">
        
        <!-- BUNGKUS ATAS (HEADER + TABEL) -->
        <div class="top-content">
            <!-- HEADER KOP SURAT PRESISI -->
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

            <div class="title">Tanda Terima Pengiriman Barang</div>

            <!-- TABEL BARANG LEBIH LEGA -->
            <table class="tbl-barang">
                <thead>
                    <tr>
                        <th style="width: 5%;">NO</th>
                        <th style="width: 45%;">DESKRIPSI BARANG</th>
                        <th style="width: 20%;">HOSTNAME</th>
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

            <!-- INFO & TANGGAL SEJAJAR (Efisiensi ruang vertikal) -->
            <div class="bottom-wrap">
                <table class="info-box">
                    <tr>
                        <td>ASURANSI</td>
                        <td>: <?php echo htmlspecialchars($_GET['asuransi'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td>CHARGE</td>
                        <td>: <?php echo htmlspecialchars($_GET['charge'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td>USER</td>
                        <td>: <?php echo htmlspecialchars($_GET['user'] ?? ''); ?></td>
                    </tr>
                </table>

                <?php $tanggal_cetak = strtoupper(date('d F Y')); ?>
                <div class="tanggal">JAKARTA, <?php echo $tanggal_cetak; ?></div>
            </div>
        </div> <!-- End Top Content -->

        <!-- BUNGKUS BAWAH (TTD + NOTE) -> AKAN SELALU TERKUNCI DI BAWAH KERTAS -->
        <div class="bottom-content">
            <!-- TABEL TANDA TANGAN -->
            <table class="sig-table">
                <tr>
                    <th>PENERIMA</th>
                    <th>EKSPEDISI</th>
                    <th>PENGIRIM</th>
                </tr>
                <tr>
                    <?php if ($isAdmin): ?>
                        <!-- HO -> CABANG -->
                        <td>
                            <span class="sig-line"></span>
                            ( <?php echo htmlspecialchars($_GET['penerima'] ?? ''); ?> )<br>
                            <?php echo htmlspecialchars($_GET['penerima_branch'] ?? ''); ?>
                        </td>
                        <td>
                            <span class="sig-line"></span>
                            ( NAMA JELAS KURIR )
                        </td>
                        <td>
                            <span class="sig-line"></span>
                            ( DENI PRATAMA )<br>
                            IT HEAD OFFICE
                        </td>
                    <?php else: ?>
                        <!-- CABANG -> HO -->
                        <td>
                            <span class="sig-line"></span>
                            ( DENI PRATAMA )<br>
                            IT HEAD OFFICE
                        </td>
                        <td>
                            <span class="sig-line"></span>
                            ( NAMA JELAS KURIR )
                        </td>
                        <td>
                            <span class="sig-line"></span>
                            ( <?php echo htmlspecialchars($_GET['pengirim'] ?? ''); ?> )<br>
                            <?php echo htmlspecialchars($_GET['pengirim_branch'] ?? ''); ?>
                        </td>
                    <?php endif; ?>
                </tr>
            </table>

            <!-- NOTE -->
            <div class="footer-note">
                * NOTE : Apabila sudah diterima dan ditandatangan harap dikonfirmasi ke denipratama@hexindo-tbk.co.id
            </div>
        </div> <!-- End Bottom Content -->
        
    </div> <!-- End Cetak Border -->
</div>

<!-- =========================================================================
     HALAMAN 2 : LABEL PACKING CARGO (DESAIN ASLI DITENGAH)
     ========================================================================= -->
<div class="page-container label-page">
    
    <div class="label-box">
        <div class="label-inner">
            
            <div class="label-section">
                <div class="text-kop">PT HEXINDO ADIPERKASA TBK</div>
                <?php if ($isAdmin): ?>
                    <div class="text-dest">CABANG : <?php echo htmlspecialchars($_GET['penerima_branch'] ?? ''); ?></div>
                    <div class="text-up">UP : <?php echo htmlspecialchars($_GET['penerima'] ?? ''); ?></div>
                <?php else: ?>
                    <div class="text-dest">HEAD OFFICE : JAKARTA</div>
                    <div class="text-up">UP : DENI PRATAMA</div>
                <?php endif; ?>
            </div>
            
            <div class="label-section" style="background-color: #f9f9f9;">
                <?php if ($isAdmin): ?>
                    <div class="text-from">FROM : IT HEAD OFFICE</div>
                <?php else: ?>
                    <div class="text-from">FROM : CABANG <?php echo htmlspecialchars($_GET['pengirim_branch'] ?? ''); ?></div>
                <?php endif; ?>
            </div>
            
            <div class="label-section">
                <div class="text-warning">HATI - HATI KOMPUTER</div>
                <?php if ($isAdmin): ?>
                    <div class="text-warning-bold">JANGAN DI BANTING / PACKING KAYU<br><br>TO : CABANG <?php echo htmlspecialchars($_GET['penerima_branch'] ?? ''); ?></div>
                <?php else: ?>
                    <div class="text-warning-bold">JANGAN DI BANTING / PACKING KAYU<br><br>TO : HEAD OFFICE JAKARTA</div>
                <?php endif; ?>
            </div>

        </div>
    </div>

</div>

<!-- SCRIPT OTOMATIS MUNCULKAN WINDOW PRINT -->
<script>
    window.onload = function() {
        setTimeout(function() {
            window.print();
        }, 500);
    };
</script>

</body>
</html>