<?php
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

// ==========================================
// HANDLE AJAX REQUEST (UNTUK MENCARI SERIAL NUMBER)
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'get_sn') {
    header('Content-Type: application/json');
    $idBarangPilih = (int)($_GET['id_barang'] ?? 0);
    
    // Ambil SN dari tabel barang berdasarkan cabang, id_barang, 
    // dan pastikan barang tersebut belum pernah diajukan pengiriman
    $query_sn = "
        SELECT serial_number, `user` 
        FROM barang 
        WHERE id_barang = ? 
        AND id_branch = ?
        AND serial_number NOT IN (
            SELECT serial_number FROM pengiriman_cabang_ho 
            WHERE status_pengiriman != 'Ditolak' OR status_pengiriman != 'Selesai'
        )
    ";
    
    $stmt_sn = mysqli_prepare($koneksi, $query_sn);
    mysqli_stmt_bind_param($stmt_sn, 'ii', $idBarangPilih, $myBranchId);
    mysqli_stmt_execute($stmt_sn);
    $result_sn = mysqli_stmt_get_result($stmt_sn);
    
    $data_sn = [];
    while ($row = mysqli_fetch_assoc($result_sn)) {
        $data_sn[] = $row;
    }
    echo json_encode($data_sn);
    exit;
}

function h($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
function jsonResponse(string $status, string $message): void {
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

// FUNGSI DIUPDATE: Hanya ambil jenis barang yang ada di cabang saat ini
function getBarangBranchOptions(mysqli $koneksi, int $branchId): array {
    $rows = [];
    // Join dengan tabel barang untuk memfilter berdasarkan id_branch
    $query = "
        SELECT DISTINCT tb.id_barang, tb.nama_barang 
        FROM tb_barang tb
        INNER JOIN barang b ON tb.id_barang = b.id_barang
        WHERE b.id_branch = $branchId
        ORDER BY tb.nama_barang ASC
    ";
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
if (!$jakartaBranch) exit('HO Jakarta tidak ditemukan');

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

    $cekSN_pengiriman = mysqli_query($koneksi, "SELECT id_pengiriman_ho FROM pengiriman_cabang_ho WHERE serial_number = '$serial_number'");
    if (mysqli_num_rows($cekSN_pengiriman) > 0) {
        jsonResponse('error', 'Serial number sudah pernah diajukan untuk pengiriman.');
    }

    if ($idBarang <= 0) jsonResponse('error', 'Jenis barang wajib dipilih');
    if ($tanggal === '') jsonResponse('error', 'Tanggal wajib diisi');
    if ($resi === '') jsonResponse('error', 'Resi wajib diisi');
    if ($jasa === '') jsonResponse('error', 'Jasa pengiriman wajib dipilih');
    if ($serial_number === '') jsonResponse('error', 'Serial number wajib dipilih');
    
    // Hanya 1 pengajuan pending per jenis barang per cabang (mencegah dobel)
    $stmtPending = mysqli_prepare($koneksi, "SELECT id_pengiriman_ho FROM pengiriman_cabang_ho WHERE id_barang = ? AND branch_asal = ? AND COALESCE(status_pengiriman,'') = ? LIMIT 1");
    if ($stmtPending) {
        $statusPending = STATUS_MENUNGGU_PERSETUJUAN;
        mysqli_stmt_bind_param($stmtPending, 'iis', $idBarang, $myBranchId, $statusPending);
        mysqli_stmt_execute($stmtPending);
        $resPending = mysqli_stmt_get_result($stmtPending);
        $rowPending = mysqli_fetch_assoc($resPending) ?: null;
        mysqli_stmt_close($stmtPending);
        if ($rowPending) jsonResponse('error', 'Jenis barang ini masih menunggu persetujuan admin.');
    }

    $upload = uploadImage('foto_resi_keluar');
    if (($upload['status'] ?? '') === 'error') jsonResponse('error', (string) $upload['message']);
    $foto = $upload['filename'] ?? null;

    $stmt = mysqli_prepare($koneksi, "
        INSERT INTO pengiriman_cabang_ho
        (id_barang, serial_number, pemilik_barang, branch_asal, branch_tujuan, tanggal_pengajuan, jasa_pengiriman, nomor_resi_keluar, foto_resi_keluar, status_pengiriman, dibuat_oleh)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) jsonResponse('error', 'Gagal menyiapkan penyimpanan pengiriman.');

    $dibuatOleh = current_user_id() ? (int) current_user_id() : null;
    $branchTujuan = (int) ($jakartaBranch['id_branch'] ?? 0);
    $statusPengiriman = STATUS_MENUNGGU_PERSETUJUAN;
    mysqli_stmt_bind_param(
        $stmt,
        'issiisssssi',
        $idBarang,
        $serial_number,
        $pemilik_barang,
        $myBranchId,
        $branchTujuan,
        $tanggal,
        $jasa,
        $resi,
        $foto,
        $statusPengiriman,
        $dibuatOleh
    );
    if (!mysqli_stmt_execute($stmt)) jsonResponse('error', 'Gagal menyimpan pengiriman: ' . mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);

    // AUTO-LOCK: Otomatis kunci barang saat dikirim (tidak perlu menunggu approve admin)
    $stmtLock = mysqli_prepare($koneksi, "UPDATE barang SET status = 'Terkunci', id_branch = id_branch WHERE serial_number = ?");
    if ($stmtLock) {
        mysqli_stmt_bind_param($stmtLock, 's', $serial_number);
        mysqli_stmt_execute($stmtLock);
        mysqli_stmt_close($stmtLock);
    }

    $namaBarang = '-';
    $stmtNama = mysqli_prepare($koneksi, "SELECT nama_barang FROM tb_barang WHERE id_barang = ? LIMIT 1");
    if ($stmtNama) {
        mysqli_stmt_bind_param($stmtNama, 'i', $idBarang);
        mysqli_stmt_execute($stmtNama);
        $resNama = mysqli_stmt_get_result($stmtNama);
        $rowNama = mysqli_fetch_assoc($resNama) ?: null;
        mysqli_stmt_close($stmtNama);
        $namaBarang = (string) ($rowNama['nama_barang'] ?? '-');
    }

    createAdminNotification(
        $koneksi,
        'Pengiriman cabang → HO (menunggu persetujuan)',
        'Cabang mengajukan pengiriman barang rusak (' . $namaBarang . ') ke HO Jakarta. Resi: ' . $resi,
        '../Barang/pengiriman_approval.php'
    );

    jsonResponse('success', 'Pengiriman berhasil diajukan. Barang otomatis dikunci. Notifikasi sudah masuk ke admin HO.');
}

// Mengambil list barang HANYA yang ada di branch user
$barangList = getBarangBranchOptions($koneksi, $myBranchId);
?>

<form id="formPengirimanUser" method="POST" enctype="multipart/form-data">
    <div class="row g-3">
        <div class="col-12">
            <div class="alert alert-info mb-0">
                <b>Catatan:</b> Ini input pengiriman baru dari cabang ke HO Jakarta untuk barang yang perlu diperbaiki.
                Setelah diajukan, admin HO akan memproses dan memberi persetujuan saat barang sudah diterima.
            </div>
        </div>

        <div class="col-md-6">
            <label class="form-label">Jenis Barang Rusak <span class="text-danger">*</span></label>
            <select name="id_barang" id="id_barang_select" class="form-select select2" required>
                <option value="">Pilih Jenis Barang...</option>
                <?php foreach ($barangList as $b): ?>
                    <option value="<?= (int) $b['id_barang'] ?>"><?= h($b['nama_barang']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Tujuan</label>
            <input type="text" class="form-control" value="<?= h($jakartaBranch['nama_branch']) ?>" readonly>
        </div>

        <div class="col-md-6 mb-3">
            <label>Serial Number / Service Tag <span class="text-danger">*</span></label>
            <!-- Diubah dari Input menjadi Select Dropdown -->
            <select name="serial_number" id="serial_number_select" class="form-select select2" required>
                <option value="">Pilih Jenis Barang Dulu...</option>
            </select>
            <small class="text-muted">Pilih SN yang tersedia di cabang Anda.</small>
        </div>

        <div class="col-md-6 mb-3">
            <label>Pemilik Barang (User) <span class="text-danger">*</span></label>
            <!-- Menambahkan ID agar mudah dikontrol oleh JavaScript -->
            <input type="text" name="pemilik_barang" id="pemilik_barang_input" class="form-control" required placeholder="Akan terisi otomatis" readonly style="background-color: #f8f9fa;">
        </div>

        <div class="col-md-6">
            <label class="form-label">Tanggal Kirim</label>
            <input type="date" name="tanggal_keluar" class="form-control" required value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
        </div>

        <div class="col-md-6">
            <label class="form-label">Jasa Pengiriman</label>
            <select name="jasa_pengiriman" class="form-control select2" required>
                <option value="">Pilih Jasa Pengiriman...</option>
                <option value="SAP Express">SAP Express</option>
                <option value="PCP Express">PCP Express</option>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Nomor Resi Keluar</label>
            <input type="text" name="nomor_resi_keluar" class="form-control" placeholder="Isi nomor resi dari ekspedisi" required>
        </div>

        <div class="col-md-6">
            <label class="form-label">Foto Resi / Bukti Kirim</label>
            <input type="file" name="foto_resi_keluar" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
        </div>

        <div class="col-md-6">
            <label class="form-label">Status Pengiriman</label>
            <input type="text" class="form-control" value="<?= h(STATUS_MENUNGGU_PERSETUJUAN) ?>" readonly>
        </div>

        <div class="col-12 text-end">
            <button type="submit" class="btn btn-warning w-100 fw-bold rounded-3" id="btnSimpanPengirimanUser">
                <span class="btn-text">Ajukan Pengiriman ke HO Jakarta</span>
                <span class="btn-loading d-none">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                    Menyimpan...
                </span>
            </button>
        </div>
    </div>
</form>

<script>
$(document).ready(function() {
    // Ketika Jenis Barang berubah
    $('#id_barang_select').on('change', function() {
        var idBarang = $(this).val();
        var snSelect = $('#serial_number_select');
        var pemilikInput = $('#pemilik_barang_input');
        
        // Kosongkan form ke default state
        snSelect.html('<option value="">Loading...</option>');
        pemilikInput.val('');

        if (idBarang) {
            // Panggil file ini sendiri menggunakan AJAX dengan parameter action=get_sn
            $.ajax({
                url: 'pengiriman_user.php?action=get_sn&id_barang=' + idBarang,
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    var options = '<option value="">Pilih Serial Number...</option>';
                    if (data.length > 0) {
                        $.each(data, function(index, item) {
                            // Simpan data user di attribute 'data-user' agar gampang diambil
                            options += '<option value="' + item.serial_number + '" data-user="' + item.user + '">' + item.serial_number + '</option>';
                        });
                    } else {
                        options = '<option value="">Tidak ada aset tersedia/Telah dikirim</option>';
                    }
                    snSelect.html(options);
                },
                error: function() {
                    snSelect.html('<option value="">Gagal mengambil data</option>');
                }
            });
        } else {
            snSelect.html('<option value="">Pilih Jenis Barang Dulu...</option>');
        }
    });

    // Ketika Serial Number dipilih
    $('#serial_number_select').on('change', function() {
        // Ambil value dari attribute data-user pada option yang di-select
        var selectedUser = $(this).find(':selected').data('user');
        
        // Isi ke input text Pemilik Barang
        if (selectedUser) {
            $('#pemilik_barang_input').val(selectedUser);
        } else {
            $('#pemilik_barang_input').val('');
        }
    });
});
</script>