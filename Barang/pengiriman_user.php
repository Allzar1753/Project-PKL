<?php
// JURUS 1: Tangkap semua sampah output (spasi kosong/error) sejak baris pertama
ob_start();
error_reporting(0);

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';
require_once '../config/activity_logger.php';

require_permission($koneksi, 'barang.kirim');
if (!is_user_role()) {
    http_response_code(403);
    exit('Halaman ini khusus user cabang.');
}

const STATUS_MENUNGGU_PERSETUJUAN = 'Menunggu persetujuan admin';
const STATUS_SEDANG_PERJALANAN = 'Sedang perjalanan';
const JENIS_KE_HO = 'ke_ho';
const JENIS_KE_CABANG = 'ke_cabang';

$myBranchId = (int) current_user_branch_id();

function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function formatDeskripsi($merk, $tipe) {
    $m = trim(strtoupper($merk));
    $t = trim(strtoupper($tipe));
    if (strpos($t, $m) === 0) { return $t; }
    return $m . ' ' . $t;
}

// ==========================================
// HANDLE FITUR CETAK SURAT JALAN / PDF (LANDSCAPE + EXACT HEADER)
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'print_pdf') {
    while (ob_get_level()) ob_end_clean(); // Bersihkan output
    
    $descriptions = $_GET['description'] ?? [];
    $hostnames = $_GET['hostname'] ?? [];
    $qtys = $_GET['qty'] ?? [];
    $catatans = $_GET['catatan_array'] ?? [];
    
    $user = h($_GET['user'] ?? '-');
    $ekspedisi = h($_GET['ekspedisi'] ?? '-');
    $asuransi = h($_GET['asuransi'] ?? '-');
    $charge = h($_GET['charge'] ?? '-');
    
    // Admin Cabang yang login (Pengirim)
    $pengirim = h($_GET['pengirim'] ?? '-'); 
    $pengirim_branch = h($_GET['pengirim_branch'] ?? '-'); 
    
    $tanggal_ttd = strtoupper(date('d F Y'));

    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Surat Jalan & Label Pengiriman</title>
        <style>
            /* SETTING KERTAS LANDSCAPE */
            @page { size: A4 landscape; margin: 10mm; }
            body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11px; color: #000; margin: 0; padding: 0; }
            
            /* HALAMAN 1 (SURAT JALAN) */
            .page-1 { border: 2px solid #000; padding: 15px; box-sizing: border-box; min-height: 90vh; position: relative; }
            
            /* HEADER EXACT MATCH */
            .header-table { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
            .header-table td { vertical-align: bottom; padding-bottom: 5px; }
            .logo-wrap { display: flex; align-items: flex-end; }
            
            /* Segitiga Logo Hexindo (Bolong Tengah) */
            .logo-triangle { width: 0; height: 0; border-left: 14px solid transparent; border-right: 14px solid transparent; border-bottom: 24px solid #E64312; position: relative; margin-right: 10px; margin-bottom: 2px; }
            .logo-triangle::after { content: ''; position: absolute; top: 10px; left: -6px; width: 0; height: 0; border-left: 6px solid transparent; border-right: 6px solid transparent; border-bottom: 11px solid #fff; }
            
            .logo-text { font-size: 28px; font-weight: 900; letter-spacing: 1px; color: #000; font-family: 'Arial Black', Impact, sans-serif; line-height: 1; }
            
            .company-name { font-size: 16px; font-weight: bold; color: #000; margin-bottom: 3px; }
            .division-name { font-size: 11px; font-weight: normal; color: #000; letter-spacing: 0.5px; }
            
            /* Garis & Judul Merah */
            .line-thick { border-top: 2px solid #000; width: 100%; }
            .title-red { text-align: center; color: #b30000; font-weight: bold; font-size: 14px; letter-spacing: 1px; padding: 8px 0; text-transform: uppercase; }
            
            /* Tabel Barang */
            .tabel-barang { width: 100%; border-collapse: collapse; border: 2px solid #000; margin-top: 15px; margin-bottom: 15px; }
            .tabel-barang th, .tabel-barang td { border: 1px solid #000; padding: 6px 8px; }
            .tabel-barang th { text-align: center; font-weight: bold; font-size: 10px; }
            
            /* Info Box Kiri Bawah */
            .info-table { width: 45%; border-collapse: collapse; border: 2px solid #000; font-weight: bold; font-size: 10px; }
            .info-table td { border: 1px solid #000; padding: 5px 8px; }
            
            /* Tabel TTD */
            .tabel-ttd { width: 100%; border-collapse: collapse; text-align: center; border: 2px solid #000; font-size: 10px; }
            .tabel-ttd th { border: 1px solid #000; padding: 6px; width: 33.33%; font-weight: bold; }
            .tabel-ttd td { border: 1px solid #000; padding: 40px 10px 10px; vertical-align: bottom; }
            
            /* HALAMAN 2 (LABEL BOX PACKING) - LANDSCAPE ADJUSTMENT */
            .page-break { page-break-before: always; }
            .page-2 { height: 90vh; box-sizing: border-box; padding: 10px; display: flex; align-items: center; justify-content: center; }
            .label-table { width: 100%; height: 100%; border-collapse: collapse; border: 6px solid #000; text-align: center; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-weight: bold; }
            .label-table td { border-bottom: 2px solid #000; padding: 25px; }
            .label-table tr:last-child td { border-bottom: none; }
            
            .text-center { text-align: center; }
            .fw-bold { font-weight: bold; }
        </style>
    </head>
    <body>
        <!-- ==============================================
             HALAMAN 1 : SURAT JALAN
             ============================================== -->
        <div class="page-1">
            <!-- HEADER (SAMA PERSIS DENGAN REFERENSI GAMBAR BARU) -->
            <table class="header-table">
                <tr>
                    <td style="width: 50%; text-align: left;">
                        <div class="logo-wrap">
                            <div class="logo-triangle"></div>
                            <div class="logo-text">HEXINDO</div>
                        </div>
                    </td>
                    <td style="width: 50%; text-align: right;">
                        <div class="company-name">PT HEXINDO ADIPERKASA TBK</div>
                        <div class="division-name">IT DIVISION HEAD OFFICE</div>
                    </td>
                </tr>
            </table>

            <!-- JUDUL MERAH DIAPIT GARIS HITAM -->
            <div class="line-thick"></div>
            <div class="title-red">TANDA TERIMA PENGIRIMAN BARANG</div>
            <div class="line-thick"></div>

            <!-- TABEL BARANG -->
            <table class="tabel-barang">
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
                    <?php 
                    // Minimal 4 baris agar format tabel terjaga sesuai SOP
                    $rowCount = max(4, count($descriptions));
                    for ($i = 0; $i < $rowCount; $i++): 
                    ?>
                        <tr>
                            <td class="text-center fw-bold"><?= $i < count($descriptions) ? ($i + 1) : '&nbsp;' ?></td>
                            <td class="fw-bold"><?= h($descriptions[$i] ?? '') ?></td>
                            <td class="text-center"><?= h($hostnames[$i] ?? '') ?></td>
                            <td class="text-center fw-bold"><?= h($qtys[$i] ?? '') ?></td>
                            <td><?= h($catatans[$i] ?? '') ?></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>

            <table style="width: 100%; margin-bottom: 5px;">
                <tr>
                    <td style="width: 50%; vertical-align: top;">
                        <table class="info-table">
                            <tr><td style="width: 30%;">ASURANSI</td><td>: <?= $asuransi !== '' ? $asuransi : '-' ?></td></tr>
                            <tr><td>CHARGE</td><td>: <?= $charge ?></td></tr>
                            <tr><td>USER</td><td>: <?= $user ?></td></tr>
                        </table>
                    </td>
                    <td style="width: 50%; vertical-align: bottom; text-align: right; font-weight: bold; font-size: 11px; padding-bottom: 5px;">
                        JAKARTA, <?= $tanggal_ttd ?>
                    </td>
                </tr>
            </table>

            <table class="tabel-ttd">
                <tr>
                    <th>PENERIMA</th>
                    <th>EKSPEDISI</th>
                    <th>PENGIRIM</th>
                </tr>
                <tr>
                    <td>
                        .......................................................<br>
                        <strong>( DENI PRATAMA )</strong><br>
                        <strong>IT HEAD OFFICE</strong>
                    </td>
                    <td>
                        .......................................................<br>
                        <strong><?= strtoupper($ekspedisi) ?></strong>
                    </td>
                    <td>
                        .......................................................<br>
                        <strong>( <?= strtoupper($pengirim) ?> )</strong><br>
                        <strong><?= strtoupper($pengirim_branch) ?></strong>
                    </td>
                </tr>
            </table>

            <div class="text-center" style="margin-top: 8px; font-size: 9px; font-weight: bold;">
                * NOTE : Apabila sudah diterima dan ditandatangan harap dikonfirmasi ke denipratama@hexindo-tbk.co.id
            </div>
        </div>

        <!-- ==============================================
             HALAMAN 2 : LABEL PACKING KAYU
             ============================================== -->
        <div class="page-break"></div>
        
        <div class="page-2">
            <table class="label-table">
                <tr>
                    <td>
                        <div style="font-size: 26px; margin-bottom: 15px;">PT HEXINDO ADIPERKASA TBK</div>
                        <div style="font-size: 32px; text-decoration: underline; margin-bottom: 15px;">HEAD OFFICE : JAKARTA</div>
                        <div style="font-size: 26px;">UP : DENI PRATAMA</div>
                    </td>
                </tr>
                <tr>
                    <td>
                        <div style="font-size: 30px;">FROM : CABANG <?= strtoupper($pengirim_branch) ?></div>
                    </td>
                </tr>
                <tr>
                    <td>
                        <div style="font-size: 26px; color: #b30000; margin-bottom: 15px;">HATI - HATI KOMPUTER</div>
                        <div style="font-size: 28px; margin-bottom: 15px;">JANGAN DI BANTING / PACKING KAYU</div>
                        <div style="font-size: 30px;">TO : HEAD OFFICE JAKARTA</div>
                    </td>
                </tr>
            </table>
        </div>

        <script> window.onload = function() { window.print(); }; </script>
    </body>
    </html>
    <?php
    exit;
}

// ==========================================
// HANDLE AJAX REQUEST - GET BARANG
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'get_barang_by_user') {
    while (ob_get_level()) ob_end_clean(); 
    header('Content-Type: application/json');

    $username = mysqli_real_escape_string($koneksi, trim($_GET['username'] ?? ''));
    $data_barang = []; 

    $query = "
        SELECT b.id_barang, b.serial_number, no_asset, m.nama_merk, t.nama_tipe
        FROM barang b
        JOIN tb_merk m ON b.id_merk = m.id_merk
        JOIN tb_tipe t ON b.id_tipe = t.id_tipe
        WHERE b.`user` = '$username'
          AND b.id_branch = $myBranchId
          AND b.id_status != 3
          AND b.serial_number NOT IN (
              SELECT serial_number FROM pengiriman_cabang_ho 
              WHERE branch_asal = $myBranchId AND status_pengiriman NOT IN ('Ditolak', 'Selesai')
          )
        ORDER BY b.id DESC
    ";

    $qBarangUser = mysqli_query($koneksi, $query);

    if ($qBarangUser) {
        while ($row = mysqli_fetch_assoc($qBarangUser)) {
            $row['deskripsi'] = formatDeskripsi($row['nama_merk'], $row['nama_tipe']);
            $data_barang[] = $row;
        }
        echo json_encode(['status' => 'success', 'data' => $data_barang]);
    } else {
        echo json_encode(['status' => 'error', 'message' => mysqli_error($koneksi)]);
    }
    exit;
}

function jsonResponse(string $status, string $message): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => $status, 'message' => $message]);
    exit;
}

function uploadImage($fieldName, $targetDir = "../assets/images/"): array {
    if (empty($_FILES[$fieldName]['name'])) return ['status' => 'empty'];
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return ['status' => 'error', 'message' => 'Format file tidak valid'];
    if ($_FILES[$fieldName]['size'] > 2000000) return ['status' => 'error', 'message' => 'Ukuran file maksimal 2MB'];
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    $filename = uniqid('resi_', true) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetDir . $filename)) {
        return ['status' => 'error', 'message' => 'Upload gagal'];
    }
    return ['status' => 'success', 'filename' => $filename];
}

function getJakartaBranch(mysqli $koneksi): ?array {
    $res = mysqli_query($koneksi, "SELECT id_branch, nama_branch FROM tb_branch WHERE LOWER(TRIM(nama_branch)) IN ('jakarta','cabang jakarta','ho jakarta','head office jakarta') ORDER BY id_branch ASC LIMIT 1");
    return $res ? (mysqli_fetch_assoc($res) ?: null) : null;
}

function getBranchOptionsExcept(mysqli $koneksi, int $excludeBranchId): array {
    $rows = [];
    $stmt = mysqli_prepare($koneksi, 'SELECT id_branch, nama_branch FROM tb_branch WHERE id_branch != ? ORDER BY nama_branch ASC');
    mysqli_stmt_bind_param($stmt, 'i', $excludeBranchId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) { $rows[] = $row; }
    mysqli_stmt_close($stmt);
    return $rows;
}

function createAdminNotification(mysqli $koneksi, string $title, string $message, ?string $link): void {
    $stmt = mysqli_prepare($koneksi, "INSERT INTO system_notifications (target_role, title, message, link, is_read) VALUES ('admin', ?, ?, ?, 0)");
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, 'sss', $title, $message, $link);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

$jakartaBranch = getJakartaBranch($koneksi);

// ==========================================
// HANDLE PROSES DATA POST (MULTI-INSERT BARANG)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $branchTujuan = (int) ($_POST['branch_tujuan'] ?? 0);
    $tanggal = trim((string) ($_POST['tanggal_keluar'] ?? ''));
    $jasa = trim((string) ($_POST['jasa_pengiriman'] ?? ''));
    $resi = trim((string) ($_POST['nomor_resi_keluar'] ?? ''));
    $pemilik_barang = mysqli_real_escape_string($koneksi, trim((string) ($_POST['pemilik_barang'] ?? '')));
    $catatan_user = mysqli_real_escape_string($koneksi, trim((string) ($_POST['catatan_user'] ?? '')));
    
    $arr_id_barang = $_POST['id_barang'] ?? [];
    $arr_serial_number = $_POST['serial_number'] ?? [];

    if (empty($arr_serial_number) || !is_array($arr_serial_number)) jsonResponse('error', 'Tidak ada barang yang dipilih untuk dikirim.');
    if ($branchTujuan <= 0) jsonResponse('error', 'Cabang tujuan wajib dipilih.');
    if ($branchTujuan === $myBranchId) jsonResponse('error', 'Cabang tujuan tidak boleh sama dengan cabang asal.');

    $hoBranchId = (int) ($jakartaBranch['id_branch'] ?? 0);
    $jenisPengiriman = ($hoBranchId > 0 && $branchTujuan === $hoBranchId) ? JENIS_KE_HO : JENIS_KE_CABANG;

    if ($jenisPengiriman === JENIS_KE_HO && !$jakartaBranch) jsonResponse('error', 'HO Jakarta tidak ditemukan di sistem.');
    if ($tanggal === '') jsonResponse('error', 'Tanggal wajib diisi');
    if ($resi === '') jsonResponse('error', 'Resi wajib diisi');
    if ($jasa === '') jsonResponse('error', 'Jasa pengiriman wajib dipilih');
    if ($catatan_user === '') jsonResponse('error', $jenisPengiriman === JENIS_KE_HO ? 'Keterangan kerusakan wajib diisi' : 'Alasan pengiriman antar cabang wajib diisi');

    $pdfGenerated = trim((string) ($_POST['pdf_generated'] ?? '0'));
    if ($pdfGenerated !== '1') jsonResponse('error', 'Silakan cetak/unduh PDF surat pengiriman terlebih dahulu.');

    $upload = uploadImage('foto_resi_keluar');
    if (($upload['status'] ?? '') === 'error') jsonResponse('error', (string) $upload['message']);
    $fotoBind = $upload['filename'] ?? '';

    $dibuatOleh = current_user_id() ? (int) current_user_id() : null;
    $statusPengiriman = STATUS_MENUNGGU_PERSETUJUAN;

    $stmt = mysqli_prepare($koneksi, "
        INSERT INTO pengiriman_cabang_ho
        (id_barang, serial_number, pemilik_barang, branch_asal, branch_tujuan, jenis_pengiriman, tanggal_pengajuan, jasa_pengiriman, nomor_resi_keluar, foto_resi_keluar, status_pengiriman, catatan_user, dibuat_oleh)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $jumlahSukses = 0;

    foreach ($arr_serial_number as $index => $sn_item) {
        $idBarangItem = (int) ($arr_id_barang[$index] ?? 0);
        $sn_clean = mysqli_real_escape_string($koneksi, trim($sn_item));

        $cekSN_pengiriman = mysqli_query($koneksi, "SELECT id_pengiriman_ho FROM pengiriman_cabang_ho WHERE serial_number = '$sn_clean' AND status_pengiriman NOT IN ('Ditolak', 'Selesai')");
        if (mysqli_num_rows($cekSN_pengiriman) > 0) continue; 

        mysqli_stmt_bind_param($stmt, 'issiisssssssi', $idBarangItem, $sn_clean, $pemilik_barang, $myBranchId, $branchTujuan, $jenisPengiriman, $tanggal, $jasa, $resi, $fotoBind, $statusPengiriman, $catatan_user, $dibuatOleh);
        if (mysqli_stmt_execute($stmt)) {
            mysqli_query($koneksi, "UPDATE barang SET id_status = 3 WHERE serial_number = '$sn_clean'");
            $jumlahSukses++;
        }
    }
    mysqli_stmt_close($stmt);

    if ($jumlahSukses === 0) jsonResponse('error', 'Gagal mengirim. Serial Number mungkin sudah diajukan.');

    $stmt_branch = mysqli_prepare($koneksi, 'SELECT nama_branch FROM tb_branch WHERE id_branch = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt_branch, 'i', $branchTujuan);
    mysqli_stmt_execute($stmt_branch);
    $namaTujuan = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_branch))['nama_branch'] ?? '';
    mysqli_stmt_close($stmt_branch);

    if ($jenisPengiriman === JENIS_KE_HO) {
        createAdminNotification($koneksi, 'Pengiriman cabang → HO (menunggu persetujuan)', "Cabang mengirim $jumlahSukses barang rusak ke HO. Resi: $resi", '../Barang/pengiriman_approval.php');
        $successMsg = "$jumlahSukses Aset ke HO berhasil diajukan.";
    } else {
        createAdminNotification($koneksi, 'Pengiriman antar cabang (menunggu persetujuan)', "Cabang mengirim $jumlahSukses aset ke {$namaTujuan}. Resi: $resi", '../Barang/pengiriman_approval.php');
        $successMsg = "$jumlahSukses Aset ke cabang {$namaTujuan} berhasil diajukan.";
    }

    log_activity($koneksi, 'send_pengiriman_cabang', "Kirim $jumlahSukses Aset ($jenisPengiriman) - Resi: {$resi}, Tujuan: {$namaTujuan}", [
        'nomor_resi' => $resi,
        'jumlah_barang' => $jumlahSukses,
        'branch_asal' => $myBranchId,
        'branch_tujuan' => $branchTujuan
    ]);

    jsonResponse('success', $successMsg);
}

if (ob_get_length()) ob_clean();

$stmt_branch = mysqli_prepare($koneksi, 'SELECT nama_branch FROM tb_branch WHERE id_branch = ? LIMIT 1');
mysqli_stmt_bind_param($stmt_branch, 'i', $myBranchId);
mysqli_stmt_execute($stmt_branch);
$branchName = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_branch))['nama_branch'] ?? '';

$currentUserName = (string) ((current_user()['username'] ?? current_user()['name']) ?? '');
$branchTujuanList = getBranchOptionsExcept($koneksi, $myBranchId);
$hoBranchId = (int) ($jakartaBranch['id_branch'] ?? 0);

$qUserCabang = mysqli_query($koneksi, "
    SELECT DISTINCT b.`user` AS nama_user, br.nama_branch
    FROM barang b
    JOIN tb_branch br ON b.id_branch = br.id_branch
    WHERE b.id_branch = $myBranchId 
      AND b.`user` IS NOT NULL 
      AND b.`user` != '' 
      AND b.`user` != '0'
    ORDER BY nama_user ASC
");
$daftarUserCabang = [];
while ($row = mysqli_fetch_assoc($qUserCabang)) {
    $daftarUserCabang[] = ['username' => $row['nama_user'], 'nama_branch' => $row['nama_branch']];
}
?>

<style>
    /* Style Dasar UI Frontend */
    #formPengirimanUser .col-md-6, #formPengirimanUser .col-md-4, #formPengirimanUser .col-md-12, #formPengirimanUser .col-12 { margin-bottom: 1.2rem; }
    .form-control, .select2-container .select2-selection--single {
        border: 1px solid #E0E4E8 !important; border-radius: 6px !important; padding: 0.5rem 0.75rem; font-size: 0.95rem; min-height: 42px; box-shadow: none !important; background-color: #ffffff; color: #333333; font-weight: 400 !important;
    }
    .form-control:focus { border-color: #E64312 !important; box-shadow: 0 0 0 0.25rem rgba(230, 67, 18, 0.1) !important; }
    .form-control[readonly] { background-color: #F4F6F9 !important; color: #666666 !important; font-weight: 600 !important; }
    .select2-container .select2-selection--single { height: 42px !important; display: flex !important; align-items: center !important; padding: 0.2rem 0.5rem; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px !important; right: 8px !important; }
    .form-label { font-weight: 600 !important; color: #333333 !important; font-size: 0.88rem !important; margin-bottom: 0.5rem; display: block; text-transform: none !important; }
    .text-danger { font-weight: bold; }
    .alert-custom-info { background-color: #F0F7FF; border-left: 4px solid #0066CC; color: #004085; border-radius: 6px; padding: 1rem; font-size: 0.9rem; }
    .alert-custom-warning { background-color: #FFF8E1; border-left: 4px solid #F25C05; color: #854D0E; border-radius: 6px; padding: 1rem; font-size: 0.9rem; }
    
    /* Tabel Modern UI Frontend */
    .table-modern { border: 1px solid #E0E4E8; border-radius: 8px; overflow: hidden; }
    .table-modern th { background-color: #F4F6F9; color: #666666; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; border-bottom: 1px solid #E0E4E8; padding: 1rem; }
    .table-modern td { padding: 0.75rem; vertical-align: middle; border-bottom: 1px solid #E0E4E8; }
    
    /* Tombol Aksi */
    .btn-hexindo { background-color: #E64312; color: white; font-weight: 600; border: none; border-radius: 6px; padding: 0.6rem 1.5rem; transition: all 0.2s; }
    .btn-hexindo:hover { background-color: #F25C05; color: white; }
    
    /* Badge Steps */
    .step-badge { padding: 0.6rem 1.2rem; font-weight: 600; font-size: 0.85rem; border-radius: 999px; }
    .step-active { background-color: #F0F7FF; color: #0066CC; border: 1px solid #0066CC; }
    .step-done { background-color: #F0FDF4; color: #16A34A; border: 1px solid #16A34A; }
    .step-wait { background-color: #F4F6F9; color: #666666; border: 1px solid #E0E4E8; }
    
    /* CSS Card Checkbox */
    .aset-check-card { display: flex; align-items: center; padding: 12px 15px; border: 1px solid #E0E4E8; border-radius: 6px; cursor: pointer; transition: all 0.2s ease-in-out; background-color: #ffffff; margin-bottom: 10px; }
    .aset-check-card:hover { border-color: #E64312; background-color: #fffaf8; }
    .aset-check-card input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; accent-color: #E64312; margin-right: 12px; }
    
    /* CSS Card Identitas Aset (Step 2) */
    .card-aset { border: 1px solid #E0E4E8; border-radius: 8px; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
    .card-aset-header { background: #F4F6F9; padding: 0.6rem 1rem; border-bottom: 1px solid #E0E4E8; font-weight: bold; font-size: 0.85rem; color: #333; border-radius: 8px 8px 0 0; }
    .card-aset-body { padding: 1rem; }
    .card-aset-detail { font-size: 0.9rem; margin-bottom: 0.3rem; }
</style>

<form id="formPengirimanUser" method="POST" enctype="multipart/form-data">
    <div class="mb-4">
        <div class="d-flex gap-2 flex-wrap pb-3 border-bottom">
            <span id="stepLabel1" class="step-badge step-active"><i class="bi bi-1-circle me-1"></i> Konsep Surat Tanda Terima</span>
            <span id="stepLabel2" class="step-badge step-wait"><i class="bi bi-2-circle me-1"></i> Upload Bukti Resi Kirim</span>
        </div>
    </div>

    <!-- STEP 1: LAYOUT SURAT JALAN -->
    <div id="pengirimanStep1">
        <div class="alert alert-custom-info mb-4">
            <i class="bi bi-info-circle-fill me-2"></i> Lengkapi data Tanda Terima Pengiriman Barang di bawah ini, lalu klik <b>Cetak PDF</b>.
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">User (Pemilik Barang) <span class="text-danger">*</span></label>
                <select id="receipt_user_select" class="form-control select2">
                    <option value="">Pilih User...</option>
                    <?php foreach ($daftarUserCabang as $uc): ?>
                        <option value="<?= h($uc['username']) ?>">
                            <?= strtoupper(h($uc['username'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" id="receipt_user" value="">
            </div>

            <div class="col-md-6">
                <label class="form-label">Ekspedisi Logistik <span class="text-danger">*</span></label>
                <select id="receipt_ekspedisi" class="form-control select2" required>
                    <option value="">Pilih Ekspedisi...</option>
                    <option value="SAP Express">SAP EXPRESS</option>
                    <option value="PCP Express">PCP EXPRESS</option>
                </select>
            </div>
            
            <!-- CONTAINER LIST CHECKBOX ASET -->
            <div id="wrapper_aset_list" class="col-12 mt-1 mb-2" style="display:none;">
                <label class="form-label text-dark"><i class="bi bi-box-seam me-1"></i> Pilih Aset yang Akan Dikirim <span class="text-danger">*</span></label>
                <div class="row g-2" id="aset_list_checkboxes">
                    <!-- Checkbox Card dirender di sini via JS -->
                </div>
            </div>

            <!-- Tabel Barang (Otomatis Tambah Baris) -->
            <div class="col-12 mt-2">
                <div class="table-responsive">
                    <table class="table table-modern" id="receipt_table">
                        <thead class="text-center">
                            <tr>
                                <th style="width:5%;">No</th>
                                <th style="width:35%;">Deskripsi Barang</th>
                                <th>Hostname</th>
                                <th style="width:10%;">Qty</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr id="row_empty">
                                <td colspan="5" class="text-center text-muted fst-italic">Pilih user dan centang aset di atas...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Detail Tambahan -->
            <div class="col-md-4 mt-0">
                <label class="form-label">Asuransi (Opsional)</label>
                <input type="text" id="receipt_asuransi" class="form-control text-uppercase" placeholder="Contoh: YA">
            </div>

            <div class="col-md-4 mt-0">
                <label class="form-label">Charge Cabang (Otomatis)</label>
                <input type="text" id="receipt_charge" class="form-control text-uppercase" value="TO HAP <?= strtoupper(h($branchName)) ?>" readonly>
            </div>

            <div class="col-md-4 mt-0">
                <label class="form-label">Penerima di Pusat (Otomatis)</label>
                <input type="text" id="receipt_penerima" class="form-control text-uppercase" value="DENI PRATAMA (IT HEAD OFFICE)" readonly>
            </div>

            <div class="col-12 mt-4 pt-3 border-top text-end">
                <button type="button" class="btn btn-light border px-4 me-2 rounded-2" data-bs-dismiss="modal">Batal</button>
                <button type="button" id="btnGeneratePdf" class="btn btn-hexindo">
                    <i class="bi bi-printer-fill me-1"></i> Lanjut Cetak PDF
                </button>
            </div>
        </div>
    </div>

    <!-- STEP 2: FORM PENGIRIMAN -->
    <div id="pengirimanStep2" style="display:none;">
        <input type="hidden" name="pdf_generated" id="pdf_generated" value="0">
        
        <div class="alert alert-custom-warning mb-4">
            <i class="bi bi-check-circle-fill me-2 text-success"></i> PDF Surat Jalan berhasil di-generate. Silakan isi form di bawah.
        </div>
        
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Cabang Tujuan <span class="text-danger">*</span></label>
                <select name="branch_tujuan" id="branch_tujuan_select" class="form-control select2" required>
                    <option value="">Pilih Cabang Tujuan...</option>
                    <?php if ($hoBranchId > 0): ?>
                        <option value="<?= $hoBranchId ?>" data-jenis="ke_ho">Head Office (HO) Jakarta — Barang Rusak</option>
                    <?php endif; ?>
                    <?php foreach ($branchTujuanList as $br): ?>
                        <?php if ((int) $br['id_branch'] === $hoBranchId) continue; ?>
                        <option value="<?= (int) $br['id_branch'] ?>" data-jenis="ke_cabang"><?= h($br['nama_branch']) ?> — Transfer Antar Cabang</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">User Pemilik Aset <span class="text-danger">*</span></label>
                <input type="text" name="pemilik_barang" id="pemilik_barang_input" class="form-control text-uppercase" required readonly>
            </div>

            <!-- CONTAINER CARD IDENTITAS ASET -->
            <div class="col-12 mt-1 mb-2">
                <label class="form-label">Aset yang Akan Dikirim <span class="text-danger">*</span></label>
                <div class="row g-3" id="card_aset_container">
                    <!-- Card Asset di-render via JS disini -->
                </div>
            </div>

            <div class="col-md-12">
                <label class="form-label" id="label_catatan_pengiriman">Detail Keterangan / Alasan Pengiriman <span class="text-danger">*</span></label>
                <textarea name="catatan_user" id="catatan_user_input" class="form-control" rows="2" placeholder="Berlaku untuk semua aset yang dipilih..." required></textarea>
            </div>

            <div class="col-md-4">
                <label class="form-label">Tanggal Pengiriman <span class="text-danger">*</span></label>
                <input type="date" name="tanggal_keluar" class="form-control" required value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label">Jasa Ekspedisi</label>
                <input type="text" name="jasa_pengiriman" id="step2_ekspedisi" class="form-control text-uppercase" readonly required>
            </div>

            <div class="col-md-4">
                <label class="form-label">Nomor Resi / AWB <span class="text-danger">*</span></label>
                <input type="text" name="nomor_resi_keluar" class="form-control text-uppercase" placeholder="Masukkan nomor resi..." required>
            </div>

            <div class="col-md-6">
                <label class="form-label">Upload Foto Resi <span class="text-danger">*</span></label>
                <input type="file" name="foto_resi_keluar" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp" required>
            </div>

            <div class="col-md-6">
                <label class="form-label">Status Pengiriman</label>
                <input type="text" class="form-control text-warning" value="<?= h(STATUS_MENUNGGU_PERSETUJUAN) ?>" readonly>
            </div>
            
            <div class="col-12 mt-4 pt-3 border-top d-flex justify-content-between">
                <button type="button" id="btnBackToStep1" class="btn btn-light border px-4 rounded-2"><i class="bi bi-arrow-left me-1"></i> Kembali</button>
                <button type="submit" class="btn btn-hexindo" id="btnSimpanPengirimanUser">
                    <span class="btn-text"><i class="bi bi-send-fill me-1"></i> Ajukan Pengiriman</span>
                </button>
            </div>
        </div>
    </div>
</form>

<script>
    const currentUserName = <?= json_encode($currentUserName) ?>;
    const branchName = <?= json_encode($branchName) ?>;
    let selectedAssetsData = {}; 

    $(document).ready(function() {

        // 1. KETIKA USER DIPILIH
        $('#receipt_user_select').on('change', function() {
            const username = $(this).val();
            
            if (!username) {
                $('#wrapper_aset_list').fadeOut();
                $('#receipt_user').val('');
                $('#pemilik_barang_input').val('');
                selectedAssetsData = {};
                renderTabelStep1();
                renderCardStep2();
                return;
            }

            $('#receipt_user').val(username);
            $('#pemilik_barang_input').val(username.toUpperCase()); 
            
            $('#wrapper_aset_list').fadeIn();
            $('#aset_list_checkboxes').html('<div class="col-12 p-2 text-muted small"><i class="spinner-border spinner-border-sm me-1"></i> Memuat daftar aset...</div>');
            $('#receipt_table tbody').html('<tr id="row_empty"><td colspan="5" class="text-center text-muted fst-italic">Pilih aset dari daftar di atas...</td></tr>');
            
            selectedAssetsData = {}; 

            // FIX URL AJAX: Dikembalikan ke pemanggilan file langsung
            $.ajax({
                url: 'pengiriman_user.php?action=get_barang_by_user&username=' + encodeURIComponent(username),
                type: 'GET',
                dataType: 'json',
                success: function(res) {
                    let htmlList = '';
                    if (res.status === 'success' && res.data.length > 0) {
                        res.data.forEach(function(item) {
                            // Cek jika no_asset kosong, tampilkan strip (-)
let noAssetDisplay = item.no_asset ? item.no_asset : '-';

htmlList += `
    <div class="col-md-6 col-lg-6">
        <label class="aset-check-card w-100 m-0 position-relative">
            <input type="checkbox" class="check-aset" value="${item.serial_number}" 
                data-id="${item.id_barang}" data-desc="${item.deskripsi}" 
                data-merk="${item.nama_merk}" data-tipe="${item.nama_tipe}" data-noasset="${noAssetDisplay}">
            <div class="w-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="fw-bold text-dark" style="font-size:0.9rem;">${item.deskripsi.toUpperCase()}</div>
                    <span class="badge bg-secondary" style="font-size:0.7rem;">Asset: ${noAssetDisplay}</span>
                </div>
                <div class="text-muted mt-1" style="font-size:0.8rem;"><i class="bi bi-upc-scan"></i> SN: ${item.serial_number}</div>
            </div>
        </label>
    </div>
`;
                        });
                    } else {
                        htmlList = '<div class="col-12 p-2 text-danger small">Tidak ada aset (yang belum dikirim) di tangan user ini.</div>';
                    }
                    $('#aset_list_checkboxes').html(htmlList);
                },
                error: function(xhr, status, error) { 
                    console.error("Error AJAX:", xhr.responseText);
                    $('#aset_list_checkboxes').html('<div class="col-12 p-2 text-danger small">Gagal memuat data dari server. (Cek console log)</div>'); 
                }
            });
        });

        // 2. KETIKA CARD CHECKBOX DICENTANG
        $(document).on('change', '.check-aset', function() {
            const sn = $(this).val();
            
            if ($(this).is(':checked')) {
                $(this).closest('.aset-check-card').css({'border-color': '#E64312', 'background-color': '#fffaf8'});
                selectedAssetsData[sn] = { 
                    id_barang: $(this).data('id'), 
                    desc: $(this).data('desc').toUpperCase(), 
                    merk: $(this).data('merk'), 
                    tipe: $(this).data('tipe') 
                };
            } else { 
                $(this).closest('.aset-check-card').css({'border-color': '#E0E4E8', 'background-color': '#ffffff'});
                delete selectedAssetsData[sn]; 
            }
            renderTabelStep1();
            renderCardStep2();
        });

        function renderTabelStep1() {
            const tbody = $('#receipt_table tbody');
            tbody.empty();
            let no = 1;
            if (Object.keys(selectedAssetsData).length === 0) {
                tbody.html('<tr id="row_empty"><td colspan="5" class="text-center text-muted fst-italic">Pilih user dan centang aset di atas...</td></tr>');
                return;
            }
            for (let sn in selectedAssetsData) {
                let item = selectedAssetsData[sn];
                
                // Menghapus tulisan SN pada deskripsi tabel surat jalan
                let combinedDesc = item.desc;
                
                tbody.append(`
                    <tr class="row-aset-item">
                        <td class="text-center fw-bold align-middle text-muted">${no}</td>
                        <td><input type="text" class="form-control row-desc bg-light" value="${combinedDesc}" readonly></td>
                        <td><input type="text" class="form-control row-host text-uppercase" placeholder="Hostname (opsional)"></td>
                        <td><input type="number" class="form-control text-center fw-bold row-qty bg-light" value="1" readonly></td>
                        <td><input type="text" class="form-control text-uppercase row-catatan" placeholder="Catatan..."></td>
                    </tr>
                `);
                no++;
            }
        }

        function renderCardStep2() {
            const container = $('#card_aset_container');
            container.empty();
            for (let sn in selectedAssetsData) {
                let item = selectedAssetsData[sn];
                container.append(`
                    <div class="col-md-6 col-xl-6">
                        <div class="card-aset h-100">
                            <div class="card-aset-header d-flex justify-content-between align-items-center" style="background-color: #f4f6f9;">
                                <span class="fw-bold text-dark"><i class="bi bi-upc-scan me-1"></i> SN: ${sn}</span>
                            </div>
                            <div class="card-aset-body p-3">
                                <div class="text-muted mb-1" style="font-size:0.8rem; font-weight: 600;">Merek dan Tipe Barang:</div>
                                <div class="fw-bold text-primary" style="font-size:0.95rem;">${item.desc}</div>
                                
                                <!-- Hidden input WAJIB untuk dikirim ke PHP -->
                                <input type="hidden" name="serial_number[]" value="${sn}">
                                <input type="hidden" name="id_barang[]" value="${item.id_barang}">
                            </div>
                        </div>
                    </div>
                `);
            }
        }

        // 3. TOMBOL CETAK PDF (INLINE PDF SOP HEXINDO)
        $('#btnGeneratePdf').on('click', function() {
            var userVal = $('#receipt_user').val();
            var eksVal = $('#receipt_ekspedisi').val();

            if (!userVal) { Swal.fire('Peringatan', 'Pilih User terlebih dahulu!', 'warning'); return; }
            if (Object.keys(selectedAssetsData).length === 0) { Swal.fire('Peringatan', 'Centang minimal 1 Aset dari daftar untuk dikirim!', 'warning'); return; }
            if (!eksVal) { Swal.fire('Peringatan', 'Pilih Ekspedisi terlebih dahulu!', 'warning'); return; }

            var paramDesc = [], paramHost = [], paramQty = [], paramCatatan = [];
            
            $('#receipt_table tbody tr.row-aset-item').each(function() {
                paramDesc.push($(this).find('.row-desc').val());
                paramHost.push($(this).find('.row-host').val() || '');
                paramQty.push('1');
                paramCatatan.push($(this).find('.row-catatan').val() || '-');
            });

            const params = {
                'action': 'print_pdf',
                'description': paramDesc, 
                'hostname': paramHost,
                'qty': paramQty, 
                'catatan_array': paramCatatan, 
                'asuransi': ($('#receipt_asuransi').val() || '').toUpperCase(),
                'charge': ($('#receipt_charge').val() || '').toUpperCase(),
                'user': userVal.toUpperCase(),
                'ekspedisi': eksVal.toUpperCase(),
                'pengirim': (typeof currentUserName !== 'undefined' ? currentUserName : '').toUpperCase(),
                'pengirim_branch': (typeof branchName !== 'undefined' ? branchName : '').toUpperCase()
            };

            // FIX URL CETAK: Dikembalikan ke pemanggilan file langsung
            window.open('pengiriman_user.php?' + $.param(params), '_blank');

            $('#step2_ekspedisi').val(eksVal.toUpperCase());
            $('#pdf_generated').val('1');
            $('#pengirimanStep1').hide();
            $('#pengirimanStep2').fadeIn();
            $('#stepLabel1').removeClass('step-active').addClass('step-done');
            $('#stepLabel2').removeClass('step-wait').addClass('step-active');
        });

        $('#btnBackToStep1').on('click', function() {
            $('#pengirimanStep2').hide();
            $('#pengirimanStep1').fadeIn();
            $('#stepLabel1').removeClass('step-done').addClass('step-active');
            $('#stepLabel2').removeClass('step-active').addClass('step-wait');
        });

        $('#branch_tujuan_select').on('change', function() {
            const jenis = $(this).find(':selected').data('jenis');
            if (jenis === 'ke_ho') {
                $('#label_catatan_pengiriman').html('Detail Keterangan Kerusakan <span class="text-danger">*</span>');
                $('#catatan_user_input').attr('placeholder', 'Jelaskan kendala kerusakan pada barang-barang ini...');
            } else {
                $('#label_catatan_pengiriman').html('Alasan Pengiriman Antar Cabang <span class="text-danger">*</span>');
                $('#catatan_user_input').attr('placeholder', 'Jelaskan alasan transfer aset ke cabang tujuan...');
            }
        });
    });
</script>