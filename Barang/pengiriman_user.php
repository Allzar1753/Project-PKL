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

// ==========================================
// HANDLE AJAX REQUEST (UNTUK MENCARI SERIAL NUMBER)
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'get_sn') {
    if (ob_get_length()) ob_clean(); // Sapu bersih sebelum cetak JSON
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

function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// JURUS 3: Modifikasi fungsi jsonResponse agar anti-gagal
function jsonResponse(string $status, string $message): void
{
    if (ob_get_length()) ob_clean(); // Sapu bersih semua output nyasar sebelum JSON
    header('Content-Type: application/json');
    echo json_encode(['status' => $status, 'message' => $message]);
    exit;
}

function uploadImage($fieldName, $targetDir = "../assets/images/"): array
{
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

function getJakartaBranch(mysqli $koneksi): ?array
{
    $res = mysqli_query($koneksi, "SELECT id_branch, nama_branch FROM tb_branch WHERE LOWER(TRIM(nama_branch)) IN ('jakarta','cabang jakarta','ho jakarta','head office jakarta') ORDER BY id_branch ASC LIMIT 1");
    return $res ? (mysqli_fetch_assoc($res) ?: null) : null;
}

function getBarangBranchOptions(mysqli $koneksi, int $branchId): array
{
    $rows = [];
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

function createAdminNotification(mysqli $koneksi, string $title, string $message, ?string $link): void
{
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
    if (mysqli_num_rows($cekSN_pengiriman) > 0) {
        jsonResponse('error', 'Serial number ini sudah diajukan pengiriman dan belum selesai.');
    }

    if ($idBarang <= 0) jsonResponse('error', 'Jenis barang wajib dipilih');
    if ($tanggal === '') jsonResponse('error', 'Tanggal wajib diisi');
    if ($resi === '') jsonResponse('error', 'Resi wajib diisi');
    if ($jasa === '') jsonResponse('error', 'Jasa pengiriman wajib dipilih');
    if ($serial_number === '') jsonResponse('error', 'Serial number wajib dipilih');
    if ($catatan_user === '') jsonResponse('error', 'Keterangan kerusakan wajib diisi');

    $pdfGenerated = trim((string) ($_POST['pdf_generated'] ?? '0'));
    if ($pdfGenerated !== '1') {
        jsonResponse('error', 'Silakan cetak/unduh PDF surat pengiriman terlebih dahulu sebelum melanjutkan.');
    }

    $stmtPending = mysqli_prepare($koneksi, "SELECT id_pengiriman_ho FROM pengiriman_cabang_ho WHERE serial_number = ? AND branch_asal = ? AND COALESCE(status_pengiriman,'') = ? LIMIT 1");
    if ($stmtPending) {
        $statusPending = STATUS_MENUNGGU_PERSETUJUAN;
        mysqli_stmt_bind_param($stmtPending, 'sis', $serial_number, $myBranchId, $statusPending);
        mysqli_stmt_execute($stmtPending);
        $resPending = mysqli_stmt_get_result($stmtPending);
        $rowPending = mysqli_fetch_assoc($resPending) ?: null;
        mysqli_stmt_close($stmtPending);
        if ($rowPending) jsonResponse('error', 'Aset ini masih menunggu persetujuan admin.');
    }

    $upload = uploadImage('foto_resi_keluar');
    if (($upload['status'] ?? '') === 'error') jsonResponse('error', (string) $upload['message']);
    $foto = $upload['filename'] ?? null;

    $stmt = mysqli_prepare($koneksi, "
        INSERT INTO pengiriman_cabang_ho
        (id_barang, serial_number, pemilik_barang, branch_asal, branch_tujuan, tanggal_pengajuan, jasa_pengiriman, nomor_resi_keluar, foto_resi_keluar, status_pengiriman, catatan_user, dibuat_oleh)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) jsonResponse('error', 'Gagal menyiapkan penyimpanan pengiriman.');

    $dibuatOleh = current_user_id() ? (int) current_user_id() : null;
    $branchTujuan = (int) ($jakartaBranch['id_branch'] ?? 0);
    $statusPengiriman = STATUS_MENUNGGU_PERSETUJUAN;

    // Fallback if null photo (menghindari warning di versi PHP baru)
    $fotoBind = $foto !== null ? $foto : '';

    mysqli_stmt_bind_param(
        $stmt,
        'issiissssssi',
        $idBarang,
        $serial_number,
        $pemilik_barang,
        $myBranchId,
        $branchTujuan,
        $tanggal,
        $jasa,
        $resi,
        $fotoBind,
        $statusPengiriman,
        $catatan_user,
        $dibuatOleh
    );

    if (!mysqli_stmt_execute($stmt)) jsonResponse('error', 'Gagal menyimpan pengiriman: ' . mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);

    // AUTO-LOCK
    $stmtLock = mysqli_prepare($koneksi, "UPDATE barang SET id_status = 3 WHERE serial_number = ?");
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

    jsonResponse('success', 'Pengiriman berhasil diajukan. Barang telah dikunci. Notifikasi masuk ke admin HO.');
    // Ingat, proses berhenti di dalam jsonResponse (ada exit), jadi HTML form tidak akan ikut tercetak saat AJAX POST.
}

// Bersihkan output lagi khusus sebelum mencetak tampilan HTML jika ini request GET biasa (Load Modal)
if (ob_get_length()) ob_clean();

function getBranchName(mysqli $koneksi, int $branchId): string
{
    $stmt = mysqli_prepare($koneksi, 'SELECT nama_branch FROM tb_branch WHERE id_branch = ? LIMIT 1');
    if (!$stmt) return '';
    mysqli_stmt_bind_param($stmt, 'i', $branchId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res) ?: null;
    mysqli_stmt_close($stmt);
    return (string) ($row['nama_branch'] ?? '');
}

$branchName = getBranchName($koneksi, $myBranchId);
$currentUserName = (string) ((current_user()['username'] ?? current_user()['name']) ?? '');

$barangList = getBarangBranchOptions($koneksi, $myBranchId);
?>

<form id="formPengirimanUser" method="POST" enctype="multipart/form-data">
    <div class="mb-4">
        <div class="d-flex gap-2 flex-wrap">
            <span id="stepLabel1" class="badge rounded-pill bg-primary">1. Surat Pengiriman</span>
            <span id="stepLabel2" class="badge rounded-pill bg-secondary text-white">2. Form Pengiriman</span>
        </div>
    </div>

    <div id="pengirimanStep1">
        <div class="alert alert-info">Isi data surat pengiriman terlebih dahulu, lalu cetak/download PDF. Setelah itu lanjut ke form pengiriman ke HO Jakarta.</div>
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label">Deskripsi Barang<span class="text-danger">*</span></label>
                <div class="table-responsive">
                    <table class="table table-bordered" id="receipt_table">
                        <thead>
                            <tr>
                                <th style="width:6%;">NO</th>
                                <th>DESKRIPSI BARANG</th>
                                <th>HOSTNAME</th>
                                <th style="width:8%;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="row-no">1</td>
                                <td><input type="text" class="form-control row-desc" placeholder="Deskripsi item"></td>
                                <td><input type="text" class="form-control row-host" placeholder="Hostname (boleh kosong)"></td>
                                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btn-remove-row">×</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-2">
                    <button type="button" id="btnAddRow" class="btn btn-sm btn-secondary">Tambah Baris</button>
                </div>
                <div class="form-text mt-1">Tekan Enter saat fokus di kolom deskripsi untuk menambah baris baru.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label">Qty</label>
                <input type="number" id="receipt_qty" class="form-control" value="1" min="1">
            </div>
            <div class="col-md-8">
                <label class="form-label">Catatan</label>
                <input type="text" id="receipt_catatan" class="form-control" placeholder="Catatan singkat untuk surat pengiriman">
            </div>
            <div class="col-md-6">
                <label class="form-label">ATTN</label>
                <input type="text" id="receipt_attn" class="form-control" placeholder="ATTN tujuan" value="<?= h($branchName) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Asuransi</label>
                <input type="text" id="receipt_asuransi" class="form-control" placeholder="Contoh: Rp. 8.000.000">
            </div>
            <div class="col-md-6">
                <label class="form-label">Charge</label>
                <input type="text" id="receipt_charge" class="form-control" placeholder="Charge / Biaya">
            </div>
            <div class="col-md-6">
                <label class="form-label">User / Cabang</label>
                <input type="text" id="receipt_user" class="form-control" placeholder="Nama cabang atau user" value="<?= h($branchName) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Penerima</label>
                <input type="text" id="receipt_penerima" class="form-control" placeholder="Nama penerima">
            </div>
            <div class="col-md-6">
                <label class="form-label">Ekspedisi<span class="text-danger">*</span></label>
                <select id="receipt_ekspedisi" class="form-control select2" required>
                    <option value="">Pilih Ekspedisi...</option>
                    <option value="SAP Express">SAP Express</option>
                    <option value="PCP Express">PCP Express</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Pengirim</label>
                <input type="text" id="receipt_pengirim" class="form-control" placeholder="Nama pengirim" value="<?= h($currentUserName) ?>">
            </div>
        </div>

        <div class="mt-4 text-end">
            <button type="button" id="btnGeneratePdf" class="btn btn-primary"><i class="bi bi-file-earmark-pdf-fill me-1"></i> Download PDF & Lanjutkan</button>
        </div>
    </div>

    <div id="pengirimanStep2" style="display:none;">
        <input type="hidden" name="pdf_generated" id="pdf_generated" value="0">
        <input type="hidden" name="receipt_description" id="receipt_description_hidden">
        <input type="hidden" name="receipt_hostname" id="receipt_hostname_hidden">
        <input type="hidden" name="receipt_qty" id="receipt_qty_hidden">
        <input type="hidden" name="receipt_catatan" id="receipt_catatan_hidden">
        <input type="hidden" name="receipt_attn" id="receipt_attn_hidden">
        <input type="hidden" name="receipt_asuransi" id="receipt_asuransi_hidden">
        <input type="hidden" name="receipt_charge" id="receipt_charge_hidden">
        <input type="hidden" name="receipt_user" id="receipt_user_hidden">
        <input type="hidden" name="receipt_penerima" id="receipt_penerima_hidden">
        <input type="hidden" name="receipt_ekspedisi" id="receipt_ekspedisi_hidden">
        <input type="hidden" name="receipt_pengirim" id="receipt_pengirim_hidden">

        <div class="alert alert-secondary">Form pengiriman ke HO Jakarta hanya muncul setelah surat pengiriman berhasil dibuat/ditampilkan.</div>
        <div class="row g-3">
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
                <select name="serial_number" id="serial_number_select" class="form-select select2" required>
                    <option value="">Pilih Jenis Barang Dulu...</option>
                </select>
                <small class="text-muted">Pilih SN yang tersedia di cabang Anda.</small>
            </div>

            <div class="col-md-6 mb-3">
                <label>Pemilik Barang (User) <span class="text-danger">*</span></label>
                <input type="text" name="pemilik_barang" id="pemilik_barang_input" class="form-control" required placeholder="Akan terisi otomatis" readonly style="background-color: #f8f9fa;">
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">Keterangan Kerusakan <span class="text-danger">*</span></label>
                <textarea name="catatan_user" class="form-control" rows="3" placeholder="Jelaskan kerusakan barang dengan jelas" required></textarea>
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
        </div>

        <div class="mt-4 text-end">
            <button type="button" id="btnBackToStep1" class="btn btn-outline-secondary me-2"><i class="bi bi-arrow-left me-1"></i> Kembali</button>
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
    function validateReceiptStep() {
        let hasDescription = false;
        $('#receipt_table tbody tr').each(function() {
            if ($(this).find('.row-desc').val().trim()) {
                hasDescription = true;
            }
        });

        if (!hasDescription) {
            Swal.fire('Validasi', 'Deskripsi barang harus diisi minimal satu baris.', 'warning');
            return false;
        }
        if (!$('#receipt_ekspedisi').val()) {
            Swal.fire('Validasi', 'Pilih ekspedisi terlebih dahulu.', 'warning');
            return false;
        }
        if (!$('#receipt_pengirim').val().trim()) {
            Swal.fire('Validasi', 'Nama pengirim harus diisi.', 'warning');
            return false;
        }
        return true;
    }

    function moveToStep2() {
        const descs = [];
        const hosts = [];
        $('#receipt_table tbody tr').each(function() {
            descs.push($(this).find('.row-desc').val().trim());
            hosts.push($(this).find('.row-host').val().trim());
        });

        $('#receipt_description_hidden').val(descs.join('\n'));
        $('#receipt_hostname_hidden').val(hosts.join('||'));
        $('#receipt_qty_hidden').val($('#receipt_qty').val().trim());
        $('#receipt_catatan_hidden').val($('#receipt_catatan').val().trim());
        $('#receipt_attn_hidden').val($('#receipt_attn').val().trim());
        $('#receipt_asuransi_hidden').val($('#receipt_asuransi').val().trim());
        $('#receipt_charge_hidden').val($('#receipt_charge').val().trim());
        $('#receipt_user_hidden').val($('#receipt_user').val().trim());
        $('#receipt_penerima_hidden').val($('#receipt_penerima').val().trim());
        $('#receipt_ekspedisi_hidden').val($('#receipt_ekspedisi').val().trim());
        $('#receipt_pengirim_hidden').val($('#receipt_pengirim').val().trim());
        $('#pdf_generated').val('1');

        $('#pengirimanStep1').hide();
        $('#pengirimanStep2').show();
        $('#stepLabel1').removeClass('bg-primary').addClass('bg-success');
        $('#stepLabel2').removeClass('bg-secondary text-white').addClass('bg-primary');
    }

    $(document).ready(function() {
        $('#btnAddRow').on('click', function() {
            const rowCount = $('#receipt_table tbody tr').length + 1;
            $('#receipt_table tbody').append(`
                <tr>
                    <td class="row-no">${rowCount}</td>
                    <td><input type="text" class="form-control row-desc" placeholder="Deskripsi item"></td>
                    <td><input type="text" class="form-control row-host" placeholder="Hostname (boleh kosong)"></td>
                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btn-remove-row">×</button></td>
                </tr>
            `);
        });

        $(document).on('click', '.btn-remove-row', function() {
            if ($('#receipt_table tbody tr').length === 1) return;
            $(this).closest('tr').remove();
            $('#receipt_table tbody tr').each(function(index) {
                $(this).find('.row-no').text(index + 1);
            });
        });

        $('#btnGeneratePdf').on('click', function() {
            if (!validateReceiptStep()) return;

            const descs = [];
            const hosts = [];
            $('#receipt_table tbody tr').each(function() {
                descs.push($(this).find('.row-desc').val().trim());
                hosts.push($(this).find('.row-host').val().trim());
            });

            const params = {
                'description[]': descs,
                'hostname[]': hosts,
                qty: $('#receipt_qty').val().trim(),
                catatan: $('#receipt_catatan').val().trim(),
                attn: $('#receipt_attn').val().trim(),
                asuransi: $('#receipt_asuransi').val().trim(),
                charge: $('#receipt_charge').val().trim(),
                user: $('#receipt_user').val().trim(),
                penerima: $('#receipt_penerima').val().trim(),
                ekspedisi: $('#receipt_ekspedisi').val().trim(),
                pengirim: $('#receipt_pengirim').val().trim()
            };

            const query = $.param(params);
            window.open('print_pengiriman.php?' + query, '_blank');
            moveToStep2();
        });

        $('#btnBackToStep1').on('click', function() {
            $('#pengirimanStep2').hide();
            $('#pengirimanStep1').show();
            $('#stepLabel1').removeClass('bg-success').addClass('bg-primary');
            $('#stepLabel2').removeClass('bg-primary').addClass('bg-secondary text-white');
        });

        $('#id_barang_select').on('change', function() {
            var idBarang = $(this).val();
            var snSelect = $('#serial_number_select');
            var pemilikInput = $('#pemilik_barang_input');

            snSelect.html('<option value="">Loading...</option>');
            pemilikInput.val('');

            if (idBarang) {
                $.ajax({
                    url: 'pengiriman_user.php?action=get_sn&id_barang=' + idBarang,
                    type: 'GET',
                    dataType: 'json',
                    success: function(data) {
                        var options = '<option value="">Pilih Serial Number...</option>';
                        if (data.length > 0) {
                            $.each(data, function(index, item) {
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

        $(document).on('change', '#serial_number_select', function() {
            var selectedUser = $(this).find(':selected').data('user');
            if (selectedUser) {
                $('#pemilik_barang_input').val(selectedUser);
            } else {
                $('#pemilik_barang_input').val('');
            }
        });
    });
</script>