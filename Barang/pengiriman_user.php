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
// HANDLE AJAX REQUEST (UNTUK MENCARI SERIAL NUMBER)
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
// HANDLE PROSES DATA POST
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

<!-- STYLE KHUSUS TAMPILAN FORM AGAR BOLD DAN JELAS -->
<style>
    .fw-black { font-weight: 900 !important; color: #000 !important; }
    .label-bold { font-weight: 900 !important; color: #1a1a1a !important; text-transform: uppercase; font-size: 0.95rem; margin-bottom: 5px; }
    .input-bold { font-weight: bold !important; color: #000 !important; border: 2px solid #ccc !important; }
    .input-bold:focus { border-color: #000 !important; box-shadow: none !important; }
    .card-header-bold { background-color: #212529 !important; color: #fff !important; font-weight: 900 !important; font-size: 1.1rem !important; letter-spacing: 1px; }
    .table-dark-bold th { background-color: #212529 !important; color: #fff !important; font-weight: 900 !important; border: 2px solid #000 !important; }
    .table-bordered-dark { border: 2px solid #000 !important; }
    .table-bordered-dark td { border: 2px solid #000 !important; }
</style>

<form id="formPengirimanUser" method="POST" enctype="multipart/form-data">
    <div class="mb-4">
        <div class="d-flex gap-2 flex-wrap">
            <span id="stepLabel1" class="badge rounded-pill bg-primary fs-6 fw-bold px-3 py-2">1. Konsep Surat Tanda Terima</span>
            <span id="stepLabel2" class="badge rounded-pill bg-secondary text-white fs-6 fw-bold px-3 py-2">2. Form Upload Resi</span>
        </div>
    </div>

    <!-- STEP 1: LAYOUT SURAT JALAN -->
    <div id="pengirimanStep1">
        <div class="alert alert-info fw-black border-info border-2" style="background-color: #cff4fc;">
            <i class="bi bi-info-circle-fill me-2 text-primary"></i> Isi data Tanda Terima Pengiriman Barang terlebih dahulu, lalu CETAK TANDA TERIMA. Nanti otomatis diarahkan ke Form Pengiriman.
        </div>

        <div class="card border-dark mb-4 border-2">
            <div class="card-header card-header-bold text-center">
                TANDA TERIMA PENGIRIMAN BARANG - CABANG KE HO
            </div>
            <div class="card-body" style="background-color: #f8f9fa;">
                <div class="row g-4">
                    <!-- User/Pemilik Barang -->
                    <div class="col-md-6">
                        <label class="label-bold">User (Pemilik Barang) <span class="text-danger">*</span></label>
                        <select id="receipt_user_select" class="form-control select2 input-bold">
                            <option value="">-- PILIH USER --</option>
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
                        <label class="label-bold">Ekspedisi <span class="text-danger">*</span></label>
                        <select id="receipt_ekspedisi" class="form-control select2 input-bold" required>
                            <option value="">-- PILIH EKSPEDISI --</option>
                            <option value="SAP Express">SAP EXPRESS</option>
                            <option value="PCP Express">PCP EXPRESS</option>
                        </select>
                    </div>

                    <!-- Tabel Barang -->
                    <div class="col-12 mt-4">
                        <div class="table-responsive">
                            <table class="table table-bordered table-bordered-dark" id="receipt_table">
                                <thead class="table-dark-bold text-center">
                                    <tr>
                                        <th style="width:5%;">NO</th>
                                        <th>DESKRIPSI BARANG</th>
                                        <th>HOSTNAME</th>
                                        <th style="width:10%;">QTY</th>
                                        <th>CATATAN</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="text-center fw-black align-middle" style="font-size: 1.1rem;">1</td>
                                        <td><input type="text" class="form-control input-bold row-desc text-uppercase" placeholder="OTOMATIS TERISI..." readonly style="background:#e9ecef;"></td>
                                        <td><input type="text" class="form-control input-bold row-host text-uppercase" placeholder="ISI HOSTNAME JIKA ADA..."></td>
                                        <td><input type="number" id="receipt_qty" class="form-control input-bold text-center fw-black" value="1" min="1"></td>
                                        <td><input type="text" id="receipt_catatan" class="form-control input-bold text-uppercase" placeholder="ISI CATATAN..."></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Detail Tambahan -->
                    <div class="col-md-4">
                        <label class="label-bold">Asuransi</label>
                        <input type="text" id="receipt_asuransi" class="form-control input-bold text-uppercase">
                    </div>

                    <!-- Kunci Charge otomatis ke Cabang Asal -->
                    <div class="col-md-4">
                        <label class="label-bold">Charge (Otomatis)</label>
                        <input type="text" id="receipt_charge" class="form-control input-bold fw-black text-uppercase text-danger" value="TO HAP <?= strtoupper(h($branchName)) ?>" readonly style="background:#e9ecef;">
                    </div>

                    <!-- Kunci Penerima otomatis Deni Pratama -->
                    <div class="col-md-4">
                        <label class="label-bold">Penerima (HO)</label>
                        <input type="text" id="receipt_penerima" class="form-control input-bold fw-black text-uppercase text-danger" value="DENI PRATAMA (IT HEAD OFFICE)" readonly style="background:#e9ecef;">
                    </div>
                </div>
            </div>
        </div>

        <div class="text-end">
            <button type="button" id="btnGeneratePdf" class="btn btn-danger btn-lg fw-black px-5 py-3 rounded-pill shadow-sm border-2 border-dark">
                <i class="bi bi-printer-fill me-2"></i> CETAK TANDA TERIMA & LABEL PACKING
            </button>
        </div>
    </div>

    <!-- STEP 2: FORM PENGIRIMAN -->
    <div id="pengirimanStep2" style="display:none;">
        <input type="hidden" name="pdf_generated" id="pdf_generated" value="0">
        
        <div class="alert alert-warning fw-black border-warning border-2" style="background-color: #fff3cd;">
            <i class="bi bi-check-circle-fill me-2 text-success"></i> Dokumen berhasil dicetak. Silakan isi form di bawah ini untuk validasi sistem.
        </div>
        
        <div class="card border-dark border-2">
            <div class="card-body bg-white">
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="label-bold">Jenis Barang Rusak <span class="text-danger">*</span></label>
                        <select name="id_barang" id="id_barang_select" class="form-select select2 input-bold" required>
                            <option value="">-- PILIH JENIS BARANG --</option>
                            <?php foreach ($barangList as $b): ?>
                                <option value="<?= (int) $b['id_barang'] ?>"><?= strtoupper(h($b['nama_barang'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="label-bold">Tujuan</label>
                        <input type="text" class="form-control input-bold fw-black text-danger" value="HO JAKARTA" readonly style="background-color: #e9ecef;">
                    </div>

                    <div class="col-md-6">
                        <label class="label-bold">Serial Number <span class="text-danger">*</span></label>
                        <select name="serial_number" id="serial_number_select" class="form-select select2 input-bold" required>
                            <option value="">PILIH JENIS BARANG DULU...</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="label-bold">Pemilik Barang <span class="text-danger">*</span></label>
                        <input type="text" name="pemilik_barang" id="pemilik_barang_input" class="form-control input-bold text-uppercase" required readonly style="background-color: #e9ecef;">
                    </div>

                    <div class="col-md-12">
                        <label class="label-bold">Keterangan Kerusakan <span class="text-danger">*</span></label>
                        <textarea name="catatan_user" class="form-control input-bold text-uppercase" rows="3" placeholder="JELASKAN KERUSAKAN BARANG..." required></textarea>
                    </div>

                    <div class="col-md-4">
                        <label class="label-bold">Tanggal Kirim</label>
                        <input type="date" name="tanggal_keluar" class="form-control input-bold" required value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="col-md-4">
    <label class="label-bold">Jasa Pengiriman</label>
    <input type="text" name="jasa_pengiriman" class="form-control input-bold fw-black text-primary" readonly style="background-color: #e9ecef;" required>
</div>

                    <div class="col-md-4">
                        <label class="label-bold">Nomor Resi Keluar</label>
                        <input type="text" name="nomor_resi_keluar" class="form-control input-bold text-uppercase" placeholder="INPUT NOMOR RESI..." required>
                    </div>

                    <div class="col-md-6">
                        <label class="label-bold">Upload Foto Resi / Bukti Kirim</label>
                        <input type="file" name="foto_resi_keluar" class="form-control input-bold" accept=".jpg,.jpeg,.png,.gif,.webp">
                    </div>

                    <div class="col-md-6">
                        <label class="label-bold">Status</label>
                        <input type="text" class="form-control input-bold fw-black text-primary" value="<?= strtoupper(h(STATUS_MENUNGGU_PERSETUJUAN)) ?>" readonly style="background-color: #e9ecef;">
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4 text-end">
            <button type="button" id="btnBackToStep1" class="btn btn-secondary btn-lg me-2 fw-black border-dark border-2"><i class="bi bi-arrow-left me-1"></i> KEMBALI</button>
            <button type="submit" class="btn btn-success btn-lg fw-black px-5 rounded-pill shadow-sm border-2 border-dark" id="btnSimpanPengirimanUser">
                <span class="btn-text"><i class="bi bi-send-fill me-2"></i> AJUKAN PENGIRIMAN SEKARANG</span>
                <span class="btn-loading d-none">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> LOADING...
                </span>
            </button>
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

            // Validasi menggunakan SweetAlert sesuai desain awal
            if (!userVal) { 
                Swal.fire('Peringatan', 'Pilih User terlebih dahulu!', 'warning'); 
                return; 
            }
            if (!eksVal) { 
                Swal.fire('Peringatan', 'Pilih Ekspedisi terlebih dahulu!', 'warning'); 
                return; 
            }

            // Mencegah error jika data kosong
            var desc = $('#receipt_table .row-desc').val() || '';
            var host = $('#receipt_table .row-host').val() || '';
            var qty = $('#receipt_qty').val() || '1';
            var catatan = $('#receipt_catatan').val() || '';
            var asuransi = $('#receipt_asuransi').val() || '';
            var charge = $('#receipt_charge').val() || '';
            var userHidden = $('#receipt_user').val() || userVal;

            // Kumpulkan Data untuk dilempar ke print_pengiriman.php
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

            // Buka halaman Print PDF
            window.open('print_pengiriman.php?' + $.param(params), '_blank');

            // --- KODE AUTO-FILL EKSPEDISI (PERMANEN) ---
            // Di sini kita HANYA mengisi valuenya saja tanpa memanggil fungsi select2 agar tidak error
            $('input[name="jasa_pengiriman"]').val(eksVal.toUpperCase());
            // -------------------------------------------

            // Tandai sudah diprint dan pindah ke Step 2
            $('#pdf_generated').val('1');
            $('#pengirimanStep1').hide();
            $('#pengirimanStep2').show();
            $('#stepLabel1').removeClass('bg-primary').addClass('bg-success');
            $('#stepLabel2').removeClass('bg-secondary text-white').addClass('bg-primary');
        });

        // Auto-fill form Step 2
        $('#id_barang_select').on('change', function() {
            var idBarang = $(this).val();
            var snSelect = $('#serial_number_select');
            snSelect.html('<option value="">LOADING...</option>');
            $('#pemilik_barang_input').val('');

            if (idBarang) {
                $.ajax({
                    url: 'pengiriman_user.php?action=get_sn&id_barang=' + idBarang,
                    type: 'GET',
                    dataType: 'json',
                    success: function(data) {
                        var options = '<option value="">-- PILIH SERIAL NUMBER --</option>';
                        if (data.length > 0) {
                            $.each(data, function(i, item) {
                                options += '<option value="' + item.serial_number + '" data-user="' + item.user + '">' + item.serial_number + '</option>';
                            });
                        } else { options = '<option value="">TIDAK ADA ASET</option>'; }
                        snSelect.html(options);
                    }
                });
            } else {
                snSelect.html('<option value="">PILIH JENIS BARANG DULU...</option>');
            }
        });

        $(document).on('change', '#serial_number_select', function() {
            $('#pemilik_barang_input').val($(this).find(':selected').data('user').toUpperCase() || '');
        });
    });
</script>