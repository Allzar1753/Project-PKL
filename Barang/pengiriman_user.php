<?php
// JURUS 1: Tangkap semua sampah output (spasi kosong/error) sejak baris pertama
ob_start();
// JURUS 2: Matikan tampilan error bawaan PHP agar tidak merusak JSON
error_reporting(0);

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'barang.kirim');
if (!is_user_role()) {
    http_response_code(403);
    exit('Halaman ini khusus user cabang.');
}

const STATUS_MENUNGGU_PERSETUJUAN = 'Menunggu persetujuan admin';

$myBranchId = (int) current_user_branch_id();

// Fungsi Cerdas Mencegah Nama Merek Double (Misal: DELL DELL PRO -> DELL PRO)
function formatDeskripsi($merk, $tipe) {
    $m = trim(strtoupper($merk));
    $t = trim(strtoupper($tipe));
    
    // Jika tipe barang sudah diawali dengan nama merek, gunakan tipenya saja
    if (strpos($t, $m) === 0) {
        return $t;
    }
    return $m . ' ' . $t;
}

// ==========================================
// HANDLE AJAX REQUEST (UNTUK MENCARI SERIAL NUMBER) - TIDAK DIUBAH
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'get_barang_by_user') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $username = mysqli_real_escape_string($koneksi, trim($_GET['username'] ?? ''));
    $deskripsi = ''; 

    // Prioritas 1
    $qBarangUser = mysqli_query($koneksi, "
        SELECT m.nama_merk, t.nama_tipe
        FROM barang b
        JOIN tb_merk m ON b.id_merk = m.id_merk
        JOIN tb_tipe t ON b.id_tipe = t.id_tipe
        WHERE b.`user` = '$username'
          AND b.id_branch = $myBranchId
        ORDER BY b.id DESC LIMIT 1
    ");
    if ($row = mysqli_fetch_assoc($qBarangUser)) {
        $deskripsi = formatDeskripsi($row['nama_merk'], $row['nama_tipe']);
    }

    // Prioritas 2
    if (empty($deskripsi)) {
        $qFallback = mysqli_query($koneksi, "
            SELECT m.nama_merk, t.nama_tipe
            FROM pengiriman_cabang_ho p
            JOIN barang b ON p.serial_number = b.serial_number
            JOIN tb_merk m ON b.id_merk = m.id_merk
            JOIN tb_tipe t ON b.id_tipe = t.id_tipe
            WHERE p.pemilik_barang = '$username'
              AND p.branch_asal = $myBranchId
            ORDER BY p.id_pengiriman_ho DESC LIMIT 1
        ");
        if ($row = mysqli_fetch_assoc($qFallback)) {
            $deskripsi = formatDeskripsi($row['nama_merk'], $row['nama_tipe']);
        }
    }

    // Prioritas 3
    if (empty($deskripsi)) {
        $qAllBarang = mysqli_query($koneksi, "
            SELECT m.nama_merk, t.nama_tipe
            FROM barang b
            JOIN tb_merk m ON b.id_merk = m.id_merk
            JOIN tb_tipe t ON b.id_tipe = t.id_tipe
            WHERE b.id_branch = $myBranchId
            ORDER BY b.id DESC LIMIT 1
        ");
        if ($row = mysqli_fetch_assoc($qAllBarang)) {
            $deskripsi = formatDeskripsi($row['nama_merk'], $row['nama_tipe']);
        }
    }

    echo json_encode(['deskripsi' => $deskripsi]); 
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_sn') {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    $idBarangPilih = (int)($_GET['id_barang'] ?? 0);

    $query_sn = "
        SELECT serial_number, `user` 
        FROM barang 
        WHERE id_barang = ? 
        AND id_branch = ?
        AND serial_number NOT IN (
            SELECT serial_number FROM pengiriman_cabang_ho 
            WHERE branch_asal = ?
              AND status_pengiriman NOT IN ('Ditolak', 'Selesai')
        )
    ";

    $stmt_sn = mysqli_prepare($koneksi, $query_sn);
    mysqli_stmt_bind_param($stmt_sn, 'iii', $idBarangPilih, $myBranchId, $myBranchId);
    mysqli_stmt_execute($stmt_sn);
    $result_sn = mysqli_stmt_get_result($stmt_sn);

    $data_sn = [];
    while ($row = mysqli_fetch_assoc($result_sn)) {
        $data_sn[] = $row;
    }
    echo json_encode($data_sn);
    exit;
}

function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

function jsonResponse(string $status, string $message): void {
    if (ob_get_length()) ob_clean(); 
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

function getBarangBranchOptions(mysqli $koneksi, int $branchId): array {
    $rows = [];
    $query = "SELECT DISTINCT tb.id_barang, tb.nama_barang FROM tb_barang tb INNER JOIN barang b ON tb.id_barang = b.id_barang WHERE b.id_branch = $branchId ORDER BY tb.nama_barang ASC";
    $res = mysqli_query($koneksi, $query);
    if (!$res) return [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
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
if (!$jakartaBranch) jsonResponse('error', 'HO Jakarta tidak ditemukan');

// ==========================================
// HANDLE PROSES DATA POST - TIDAK DIUBAH
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idBarang = (int) ($_POST['id_barang'] ?? 0);
    $tanggal = trim((string) ($_POST['tanggal_keluar'] ?? ''));
    $jasa = trim((string) ($_POST['jasa_pengiriman'] ?? ''));
    $resi = trim((string) ($_POST['nomor_resi_keluar'] ?? ''));
    $serial_number = mysqli_real_escape_string($koneksi, trim((string) ($_POST['serial_number'] ?? '')));
    $pemilik_barang = mysqli_real_escape_string($koneksi, trim((string) ($_POST['pemilik_barang'] ?? '')));
    $catatan_user = mysqli_real_escape_string($koneksi, trim((string) ($_POST['catatan_user'] ?? '')));

    $cekSN_pengiriman = mysqli_query($koneksi, "SELECT id_pengiriman_ho FROM pengiriman_cabang_ho WHERE serial_number = '$serial_number' AND status_pengiriman NOT IN ('Ditolak', 'Selesai')");
    if (mysqli_num_rows($cekSN_pengiriman) > 0) jsonResponse('error', 'Serial number ini sudah diajukan pengiriman dan belum selesai.');

    if ($idBarang <= 0) jsonResponse('error', 'Jenis barang wajib dipilih');
    if ($tanggal === '') jsonResponse('error', 'Tanggal wajib diisi');
    if ($resi === '') jsonResponse('error', 'Resi wajib diisi');
    if ($jasa === '') jsonResponse('error', 'Jasa pengiriman wajib dipilih');
    if ($serial_number === '') jsonResponse('error', 'Serial number wajib dipilih');
    if ($catatan_user === '') jsonResponse('error', 'Keterangan kerusakan wajib diisi');

    $pdfGenerated = trim((string) ($_POST['pdf_generated'] ?? '0'));
    if ($pdfGenerated !== '1') jsonResponse('error', 'Silakan cetak/unduh PDF surat pengiriman terlebih dahulu.');

    $upload = uploadImage('foto_resi_keluar');
    if (($upload['status'] ?? '') === 'error') jsonResponse('error', (string) $upload['message']);
    $foto = $upload['filename'] ?? null;

    $stmt = mysqli_prepare($koneksi, "
        INSERT INTO pengiriman_cabang_ho
        (id_barang, serial_number, pemilik_barang, branch_asal, branch_tujuan, tanggal_pengajuan, jasa_pengiriman, nomor_resi_keluar, foto_resi_keluar, status_pengiriman, catatan_user, dibuat_oleh)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $dibuatOleh = current_user_id() ? (int) current_user_id() : null;
    $branchTujuan = (int) ($jakartaBranch['id_branch'] ?? 0);
    $statusPengiriman = STATUS_MENUNGGU_PERSETUJUAN;
    $fotoBind = $foto !== null ? $foto : '';

    mysqli_stmt_bind_param($stmt, 'issiissssssi', $idBarang, $serial_number, $pemilik_barang, $myBranchId, $branchTujuan, $tanggal, $jasa, $resi, $fotoBind, $statusPengiriman, $catatan_user, $dibuatOleh);
    if (!mysqli_stmt_execute($stmt)) jsonResponse('error', 'Gagal menyimpan pengiriman');
    mysqli_stmt_close($stmt);

    mysqli_query($koneksi, "UPDATE barang SET id_status = 3 WHERE serial_number = '$serial_number'");
    createAdminNotification($koneksi, 'Pengiriman cabang → HO (menunggu persetujuan)', "Cabang mengajukan pengiriman barang rusak ke HO Jakarta. Resi: $resi", '../Barang/pengiriman_approval.php');

    jsonResponse('success', 'Pengiriman berhasil diajukan. Barang telah dikunci. Notifikasi masuk ke admin HO.');
}

if (ob_get_length()) ob_clean();

function getBranchName(mysqli $koneksi, int $branchId): string {
    $stmt = mysqli_prepare($koneksi, 'SELECT nama_branch FROM tb_branch WHERE id_branch = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $branchId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return (string) ($row['nama_branch'] ?? '');
}

$branchName = getBranchName($koneksi, $myBranchId);
$currentUserName = (string) ((current_user()['username'] ?? current_user()['name']) ?? '');
$barangList = getBarangBranchOptions($koneksi, $myBranchId);

// Ambil daftar Pemilik Barang murni yang saat ini asetnya ada di cabang
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

<!-- STYLE KHUSUS SINKRON TEMA HEXINDO (CLEAN & MODERN) -->
<style>
    /* Jarak Vertikal Form */
    #formPengirimanUser .col-md-6, 
    #formPengirimanUser .col-md-4,
    #formPengirimanUser .col-md-12,
    #formPengirimanUser .col-12 {
        margin-bottom: 1.2rem;
    }

    /* Penyesuaian Input Form */
    .form-control, .select2-container .select2-selection--single {
        border: 1px solid #E0E4E8 !important;
        border-radius: 6px !important;
        padding: 0.5rem 0.75rem;
        font-size: 0.95rem;
        min-height: 42px; 
        box-shadow: none !important;
        background-color: #ffffff;
        color: #333333;
        font-weight: 400 !important; /* Menghapus fw-black agar clean */
    }
    .form-control:focus {
        border-color: #E64312 !important;
        box-shadow: 0 0 0 0.25rem rgba(230, 67, 18, 0.1) !important;
    }
    .form-control[readonly] {
        background-color: #F4F6F9 !important; /* Warna abu-abu soft khas Hexindo */
        color: #666666 !important;
        font-weight: 600 !important;
    }

    /* Select2 (Dropdown) */
    .select2-container .select2-selection--single {
        height: 42px !important;
        display: flex !important;
        align-items: center !important;
        padding: 0.2rem 0.5rem;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 40px !important;
        right: 8px !important;
    }

    /* Label Form */
    .form-label {
        font-weight: 600 !important;
        color: #333333 !important;
        font-size: 0.88rem !important;
        margin-bottom: 0.5rem;
        display: block;
        text-transform: none !important; /* Menghapus uppercase pada label */
    }
    .text-danger { font-weight: bold; }

    /* Alert / Notifikasi Modern */
    .alert-custom-info {
        background-color: #F0F7FF;
        border-left: 4px solid #0066CC;
        color: #004085;
        border-radius: 6px;
        padding: 1rem;
        font-size: 0.9rem;
    }
    .alert-custom-warning {
        background-color: #FFF8E1;
        border-left: 4px solid #F25C05;
        color: #854D0E;
        border-radius: 6px;
        padding: 1rem;
        font-size: 0.9rem;
    }

    /* Tabel Surat Jalan */
    .table-modern {
        border: 1px solid #E0E4E8;
        border-radius: 8px;
        overflow: hidden;
    }
    .table-modern th {
        background-color: #F4F6F9;
        color: #666666;
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        border-bottom: 1px solid #E0E4E8;
        padding: 1rem;
    }
    .table-modern td {
        padding: 0.75rem;
        vertical-align: middle;
        border-bottom: 1px solid #E0E4E8;
    }

    /* Tombol Aksi */
    .btn-hexindo {
        background-color: #E64312;
        color: white;
        font-weight: 600;
        border: none;
        border-radius: 6px;
        padding: 0.6rem 1.5rem;
        transition: all 0.2s;
    }
    .btn-hexindo:hover { background-color: #F25C05; color: white; }

    /* Badge Steps */
    .step-badge {
        padding: 0.6rem 1.2rem;
        font-weight: 600;
        font-size: 0.85rem;
        border-radius: 999px;
    }
    .step-active { background-color: #F0F7FF; color: #0066CC; border: 1px solid #0066CC; }
    .step-done { background-color: #F0FDF4; color: #16A34A; border: 1px solid #16A34A; }
    .step-wait { background-color: #F4F6F9; color: #666666; border: 1px solid #E0E4E8; }
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
            <i class="bi bi-info-circle-fill me-2"></i> Lengkapi data Tanda Terima Pengiriman Barang di bawah ini, lalu klik <b>Cetak PDF</b>. Sistem akan mengarahkan Anda ke Form Pengiriman (Langkah 2).
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">User (Pemilik Barang) <span class="text-danger">*</span></label>
                <select id="receipt_user_select" class="form-control select2">
                    <option value="">Pilih User...</option>
                    <?php foreach ($daftarUserCabang as $uc): ?>
                        <option value="<?= h($uc['username']) ?>" data-branch="<?= h($uc['nama_branch']) ?>">
                            <?= strtoupper(h($uc['username'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" id="receipt_user" value="">
                <input type="hidden" id="receipt_user_branch" value="<?= h($branchName) ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">Ekspedisi Logistik <span class="text-danger">*</span></label>
                <select id="receipt_ekspedisi" class="form-control select2" required>
                    <option value="">Pilih Ekspedisi...</option>
                    <option value="SAP Express">SAP EXPRESS</option>
                    <option value="PCP Express">PCP EXPRESS</option>
                </select>
            </div>

            <!-- Tabel Barang -->
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
                            <tr>
                                <td class="text-center fw-bold align-middle text-muted">1</td>
                                <td><input type="text" class="form-control row-desc" placeholder="Otomatis terisi saat User dipilih..." readonly></td>
                                <td><input type="text" class="form-control row-host text-uppercase" placeholder="Hostname (opsional)"></td>
                                <td><input type="number" id="receipt_qty" class="form-control text-center fw-bold" value="1" min="1"></td>
                                <td><input type="text" id="receipt_catatan" class="form-control text-uppercase" placeholder="Catatan tambahan..."></td>
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
            <i class="bi bi-check-circle-fill me-2 text-success"></i> PDF Surat Jalan berhasil di-generate. Silakan lanjutkan mengisi form bukti pengiriman di bawah ini.
        </div>
        
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Kategori Barang Rusak <span class="text-danger">*</span></label>
                <select name="id_barang" id="id_barang_select" class="form-control select2" required>
                    <option value="">Pilih Kategori...</option>
                    <?php foreach ($barangList as $b): ?>
                        <option value="<?= (int) $b['id_barang'] ?>"><?= h($b['nama_barang']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Tujuan Pengiriman</label>
                <input type="text" class="form-control" value="Head Office (HO) Jakarta" readonly>
            </div>

            <div class="col-md-6">
                <label class="form-label">Serial Number Aset <span class="text-danger">*</span></label>
                <select name="serial_number" id="serial_number_select" class="form-control select2" required>
                    <option value="">Pilih Kategori Dulu...</option>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">User Pemilik Aset <span class="text-danger">*</span></label>
                <input type="text" name="pemilik_barang" id="pemilik_barang_input" class="form-control text-uppercase" required readonly placeholder="Otomatis terisi...">
            </div>

            <div class="col-md-12">
                <label class="form-label">Detail Keterangan Kerusakan <span class="text-danger">*</span></label>
                <textarea name="catatan_user" class="form-control" rows="2" placeholder="Jelaskan kendala kerusakan pada barang ini..." required></textarea>
            </div>

            <div class="col-md-4">
                <label class="form-label">Tanggal Pengiriman <span class="text-danger">*</span></label>
                <input type="date" name="tanggal_keluar" class="form-control" required value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label">Jasa Ekspedisi</label>
                <input type="text" name="jasa_pengiriman" class="form-control text-uppercase" readonly required>
            </div>

            <div class="col-md-4">
                <label class="form-label">Nomor Resi / AWB <span class="text-danger">*</span></label>
                <input type="text" name="nomor_resi_keluar" class="form-control text-uppercase" placeholder="Masukkan nomor resi..." required>
            </div>

            <div class="col-md-6">
                <label class="form-label">Upload Foto Resi <span class="text-danger">*</span></label>
                <input type="file" name="foto_resi_keluar" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp" required>
                <div class="mt-1 text-muted" style="font-size: 0.8rem;">Maksimal ukuran file 2MB (JPG/PNG).</div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Status Sistem</label>
                <input type="text" class="form-control text-warning" value="<?= h(STATUS_MENUNGGU_PERSETUJUAN) ?>" readonly>
            </div>
            
            <div class="col-12 mt-4 pt-3 border-top d-flex justify-content-between">
                <button type="button" id="btnBackToStep1" class="btn btn-light border px-4 rounded-2"><i class="bi bi-arrow-left me-1"></i> Kembali</button>
                <button type="submit" class="btn btn-hexindo" id="btnSimpanPengirimanUser">
                    <span class="btn-text"><i class="bi bi-send-fill me-1"></i> Ajukan Pengiriman</span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Memproses...
                    </span>
                </button>
            </div>
        </div>
    </div>
</form>

<script>
    const currentUserName = <?= json_encode($currentUserName) ?>;
    const branchName = <?= json_encode($branchName) ?>;

    $(document).ready(function() {
        // Ambil data barang ketika User dipilih
        $('#receipt_user_select').on('select2:select', function(e) {
            const username = e.params.data.id;
            $('#receipt_user').val(username);
            $('#receipt_table tbody tr .row-desc').val('MEMUAT DATA...');

            $.ajax({
                url: 'pengiriman_user.php?action=get_barang_by_user&username=' + encodeURIComponent(username),
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    if (data && data.deskripsi) {
                        $('#receipt_table tbody tr:first .row-desc').val(data.deskripsi.toUpperCase());
                    } else {
                        $('#receipt_table tbody tr:first .row-desc').val('');
                    }
                },
                error: function() { $('#receipt_table tbody tr:first .row-desc').val(''); }
            });
        });

        // Tombol Cetak PDF
        $('#btnGeneratePdf').on('click', function() {
            var userVal = $('#receipt_user_select').val();
            var eksVal = $('#receipt_ekspedisi').val();

            if (!userVal) { Swal.fire('Peringatan', 'Pilih User terlebih dahulu!', 'warning'); return; }
            if (!eksVal) { Swal.fire('Peringatan', 'Pilih Ekspedisi terlebih dahulu!', 'warning'); return; }

            var desc = $('#receipt_table .row-desc').val() || '';
            var host = $('#receipt_table .row-host').val() || '';
            var qty = $('#receipt_qty').val() || '1';
            var catatan = $('#receipt_catatan').val() || '';
            var asuransi = $('#receipt_asuransi').val() || '';
            var charge = $('#receipt_charge').val() || '';
            var userHidden = $('#receipt_user').val() || userVal;

            const params = {
                'description[]': [desc.toUpperCase()],
                'hostname[]': [host.toUpperCase()],
                qty: qty,
                catatan: catatan.toUpperCase(),
                asuransi: asuransi.toUpperCase(),
                charge: charge.toUpperCase(),
                user: userHidden.toUpperCase(),
                ekspedisi: eksVal.toUpperCase(),
                pengirim: (typeof currentUserName !== 'undefined' ? currentUserName : '').toUpperCase(),
                pengirim_branch: (typeof branchName !== 'undefined' ? branchName : '').toUpperCase()
            };

            // Buka PDF
            window.open('print_pengiriman.php?' + $.param(params), '_blank');

            // Auto-fill Ekspedisi di Form ke-2
            $('input[name="jasa_pengiriman"]').val(eksVal.toUpperCase());

            // Pindah Step
            $('#pdf_generated').val('1');
            $('#pengirimanStep1').hide();
            $('#pengirimanStep2').fadeIn();
            
            // Ubah Warna Badge Steps
            $('#stepLabel1').removeClass('step-active').addClass('step-done');
            $('#stepLabel2').removeClass('step-wait').addClass('step-active');
        });

        // Tombol Kembali ke Step 1
        $('#btnBackToStep1').on('click', function() {
            $('#pengirimanStep2').hide();
            $('#pengirimanStep1').fadeIn();
            
            $('#stepLabel1').removeClass('step-done').addClass('step-active');
            $('#stepLabel2').removeClass('step-active').addClass('step-wait');
        });

        // Auto-fill Serial Number
        $('#id_barang_select').on('change', function() {
            var idBarang = $(this).val();
            var snSelect = $('#serial_number_select');
            snSelect.html('<option value="">Sedang memuat...</option>');
            $('#pemilik_barang_input').val('');

            if (idBarang) {
                $.ajax({
                    url: 'pengiriman_user.php?action=get_sn&id_barang=' + idBarang,
                    type: 'GET',
                    dataType: 'json',
                    success: function(data) {
                        var options = '<option value="">Pilih Serial Number...</option>';
                        if (data.length > 0) {
                            $.each(data, function(i, item) {
                                options += '<option value="' + item.serial_number + '" data-user="' + item.user + '">' + item.serial_number + '</option>';
                            });
                        } else { options = '<option value="">Tidak ada aset di cabang</option>'; }
                        snSelect.html(options);
                    }
                });
            } else {
                snSelect.html('<option value="">Pilih Kategori Dulu...</option>');
            }
        });

        $(document).on('change', '#serial_number_select', function() {
            $('#pemilik_barang_input').val($(this).find(':selected').data('user').toUpperCase() || '');
        });
    });
</script>