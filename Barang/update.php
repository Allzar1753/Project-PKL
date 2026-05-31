<?php

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

require_login();

const STATUS_BELUM_DIKIRIM = 'Belum dikirim';
const STATUS_SEDANG_DIKEMAS = 'Sedang dikemas';
const STATUS_SEDANG_PERJALANAN = 'Sedang perjalanan';
const STATUS_SUDAH_DITERIMA = 'Sudah diterima';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function normalizeNullableString($value): ?string
{
    $value = trim((string) $value);
    return $value !== '' ? $value : null;
}

function uploadImage(string $fieldName, string $targetDir = "../assets/images/"): array
{
    if (!isset($_FILES[$fieldName]) || empty($_FILES[$fieldName]['name'])) {
        return ['status' => 'empty', 'filename' => ''];
    }
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return ['status' => 'error', 'message' => "Format file {$fieldName} tidak diperbolehkan"];
    }
    if ($_FILES[$fieldName]['size'] > 2000000) {
        return ['status' => 'error', 'message' => "Ukuran file {$fieldName} maksimal 2MB"];
    }
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    $filename = uniqid($fieldName . "_", true) . "." . $ext;
    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetDir . $filename)) {
        return ['status' => 'error', 'message' => "Gagal upload file {$fieldName}"];
    }
    return ['status' => 'success', 'filename' => $filename];
}

function jsonResponse(string $status, string $message): void
{
    header('Content-Type: application/json');
    echo json_encode(['status' => $status, 'message' => $message]);
    exit;
}
function jsonError(string $message): void
{
    jsonResponse('error', $message);
}
function jsonSuccess(string $message): void
{
    jsonResponse('success', $message);
}

function getBarangById(mysqli $koneksi, int $id): ?array
{
    $stmt = mysqli_prepare($koneksi, "SELECT * FROM barang WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result) ?: null;
    mysqli_stmt_close($stmt);
    return $row;
}

function getLastPengiriman(mysqli $koneksi, int $idBarang): ?array
{
    $stmt = mysqli_prepare(
        $koneksi,
        "SELECT bp.*, asal.nama_branch AS nama_branch_asal, tujuan.nama_branch AS nama_branch_tujuan
         FROM barang_pengiriman bp
         LEFT JOIN tb_branch asal ON bp.branch_asal = asal.id_branch
         LEFT JOIN tb_branch tujuan ON bp.branch_tujuan = tujuan.id_branch
         WHERE bp.id_barang = ? ORDER BY bp.id_pengiriman DESC LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $idBarang);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result) ?: null;
    mysqli_stmt_close($stmt);
    return $row;
}

function getSelectOptions(mysqli $koneksi, string $sql): array
{
    $rows = [];
    $query = mysqli_query($koneksi, $sql);
    if (!$query) return $rows;
    while ($row = mysqli_fetch_assoc($query)) $rows[] = $row;
    return $rows;
}

function ensureBarangAccess(array $barang, ?array $pengirimanTerakhir): void
{
    if (is_admin()) return;
    $myBranchId = (int) current_user_branch_id();
    $barangBranchId = (int) ($barang['id_branch'] ?? 0);
    $branchTujuan = (int) ($pengirimanTerakhir['branch_tujuan'] ?? 0);
    $statusPengiriman = (string) ($pengirimanTerakhir['status_pengiriman'] ?? '');
    $sedangDikirim = !empty($pengirimanTerakhir) && $statusPengiriman !== STATUS_SUDAH_DITERIMA;

    if ($barangBranchId === $myBranchId) return;
    if ($sedangDikirim && $branchTujuan === $myBranchId) return;
    http_response_code(403);
    exit('Anda tidak boleh mengakses barang cabang lain.');
}

function isSedangDikirim(?array $pengirimanTerakhir): bool
{
    return (!empty($pengirimanTerakhir) && (string) ($pengirimanTerakhir['status_pengiriman'] ?? '') !== STATUS_SUDAH_DITERIMA);
}

function isSudahDiterima(?array $pengirimanTerakhir): bool
{
    return (!empty($pengirimanTerakhir) && (string) ($pengirimanTerakhir['status_pengiriman'] ?? '') === STATUS_SUDAH_DITERIMA);
}

function ensureUserCanReceive(?array $pengirimanTerakhir): void
{
    if (is_admin()) return;
    $myBranchId = (int) current_user_branch_id();
    $branchTujuan = (int) ($pengirimanTerakhir['branch_tujuan'] ?? 0);
    if (!$pengirimanTerakhir || !isSedangDikirim($pengirimanTerakhir) || $branchTujuan !== $myBranchId) {
        http_response_code(403);
        exit('User cabang hanya boleh konfirmasi penerimaan barang untuk branch-nya sendiri.');
    }
}

function validateDuplicateBarang(mysqli $koneksi, int $id, string $noAsset, string $serialNumber, int $id_barang_input): ?string
{
    $stmSerial = mysqli_prepare($koneksi, "SELECT id FROM barang WHERE serial_number = ? AND id != ? LIMIT 1");
    mysqli_stmt_bind_param($stmSerial, 'si', $serialNumber, $id);
    mysqli_stmt_execute($stmSerial);
    $resultSerial = mysqli_stmt_get_result($stmSerial);
    $serialDuplikat = mysqli_fetch_assoc($resultSerial) ?: null;
    mysqli_stmt_close($stmSerial);

    if ($serialDuplikat) return "Serial Number sudah digunakan oleh data lain.";

    if (!empty($noAsset)) {
        $qInput = mysqli_query($koneksi, "SELECT nama_barang FROM tb_barang WHERE id_barang = $id_barang_input LIMIT 1");
        $rowInput = mysqli_fetch_assoc($qInput);
        if (!$rowInput) return "Kategori Master Barang tidak ditemukan.";
        $namaInput = strtolower(trim($rowInput['nama_barang']));

        $pasanganDiizinkan = ['monitor', 'cpu'];
        $inputAdalahPasangan = in_array($namaInput, $pasanganDiizinkan, true);

        $stmtAsset = mysqli_prepare($koneksi, "SELECT b.id, tb.nama_barang FROM barang b INNER JOIN tb_barang tb ON b.id_barang = tb.id_barang WHERE b.no_asset = ? AND b.id != ?");
        mysqli_stmt_bind_param($stmtAsset, 'si', $noAsset, $id);
        mysqli_stmt_execute($stmtAsset);
        $resultAsset = mysqli_stmt_get_result($stmtAsset);

        $barangSudahAda = [];
        while ($row = mysqli_fetch_assoc($resultAsset)) {
            $namaDb = strtolower(trim($row['nama_barang']));
            $barangSudahAda[] = $namaDb;

            if (!in_array($namaDb, $pasanganDiizinkan, true)) {
                mysqli_stmt_close($stmtAsset);
                return "No Asset '$noAsset' sudah digunakan secara eksklusif oleh perangkat " . ucwords($namaDb);
            }
            if ($namaDb === $namaInput) {
                mysqli_stmt_close($stmtAsset);
                return "No Asset '$noAsset' Sudah terdaftar " . ucwords($namaInput);
            }
        }
        mysqli_stmt_close($stmtAsset);

        if (count($barangSudahAda) > 0) {
            if (!$inputAdalahPasangan) return "No Asset '$noAsset' khusus digunakan untuk pasangan CPU & Monitor.";
            if (count($barangSudahAda) >= 2) return "No Asset '$noAsset' ini sudah lengkap terisi oleh (Monitor & CPU).";
        }
    }
    return null;
}

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$type = $_GET['type'] ?? 'master';

if ($id <= 0) exit('ID tidak valid.');

$barang = getBarangById($koneksi, $id);
if (!$barang) exit('Data barang tidak ditemukan.');

$pengirimanTerakhir = getLastPengiriman($koneksi, $id);
ensureBarangAccess($barang, $pengirimanTerakhir);

$pernahDikirim = !empty($pengirimanTerakhir);
$sudahDiterima = isSudahDiterima($pengirimanTerakhir);
$sedangDikirim = isSedangDikirim($pengirimanTerakhir);

// ==========================================
// HANDLE RENDER FORM HTML (METODE GET)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (is_admin()) {
        if ($sedangDikirim) require_permission($koneksi, 'barang.kirim');
        elseif (!$pernahDikirim) require_permission($koneksi, 'barang.update');
        else require_permission($koneksi, 'barang.view');
    } else {
        ensureUserCanReceive($pengirimanTerakhir);
        require_permission($koneksi, 'barang.kirim');
    }

    $barangOptions    = getSelectOptions($koneksi, "SELECT * FROM tb_barang ORDER BY nama_barang ASC");
    $currentIdBarang  = (int) $barang['id_barang'];
    $merkOptions      = getSelectOptions($koneksi, "SELECT DISTINCT m.id_merk, m.nama_merk FROM tb_tipe t JOIN tb_merk m ON t.id_merk = m.id_merk WHERE t.id_barang = $currentIdBarang ORDER BY m.nama_merk ASC");
    $currentIdMerk    = (int) $barang['id_merk'];
    $tipeOptions      = getSelectOptions($koneksi, "SELECT id_tipe, nama_tipe FROM tb_tipe WHERE id_barang = $currentIdBarang AND id_merk = $currentIdMerk ORDER BY nama_tipe ASC");
    $jenisOptions     = getSelectOptions($koneksi, "SELECT * FROM tb_jenis ORDER BY nama_jenis ASC");
    $branchOptions    = getSelectOptions($koneksi, "SELECT * FROM tb_branch ORDER BY nama_branch ASC");
    $tujuanOptions    = getSelectOptions($koneksi, "SELECT * FROM tb_branch ORDER BY nama_branch ASC");
    $ekspedisiOptions = getSelectOptions($koneksi, "SELECT * FROM tb_ekspedisi ORDER BY nama_ekspedisi ASC");

    // Ambil data lengkap barang
    $qDetailBarang = mysqli_query($koneksi, "
        SELECT tb.nama_barang, m.nama_merk, t.nama_tipe, br.nama_branch AS nama_branch_barang
        FROM barang b
        JOIN tb_barang tb ON b.id_barang = tb.id_barang
        LEFT JOIN tb_merk m ON b.id_merk = m.id_merk
        LEFT JOIN tb_tipe t ON b.id_tipe = t.id_tipe
        LEFT JOIN tb_branch br ON b.id_branch = br.id_branch
        WHERE b.id = {$barang['id']}
        LIMIT 1
    ");
    $detailBarang = mysqli_fetch_assoc($qDetailBarang) ?: [];
    
    // LOGIKA FILTER NAMA BARANG (Mencegah nama merek terulang: DELL DELL PRO -> DELL PRO)
    $merk = trim(strtoupper($detailBarang['nama_merk'] ?? ''));
    $tipe = trim(strtoupper($detailBarang['nama_tipe'] ?? ''));
    if (strpos($tipe, $merk) === 0) {
        $namaBarangAuto = $tipe;
    } else {
        $namaBarangAuto = $merk . ' ' . $tipe;
    }
    
    $deskripsiAuto = $namaBarangAuto;
    $serialBarang  = $barang['serial_number'] ?? '';

    // Ambil data Pemilik (User)
    $pemilikBarang = $barang['user'] ?? '';
    if (empty($pemilikBarang) || $pemilikBarang === '0') {
        $snEscaped = mysqli_real_escape_string($koneksi, $serialBarang);
        $qPemilik = mysqli_query($koneksi, "SELECT pemilik_barang FROM pengiriman_cabang_ho WHERE serial_number = '$snEscaped' AND pemilik_barang IS NOT NULL AND pemilik_barang != '' AND pemilik_barang != '0' ORDER BY id_pengiriman_ho DESC LIMIT 1");
        $rowPemilik    = mysqli_fetch_assoc($qPemilik) ?: [];
        $pemilikBarang = $rowPemilik['pemilik_barang'] ?? '';
    }

    $qUserCabang = mysqli_query($koneksi, "SELECT u.username, u.id_branch, br.nama_branch FROM users u JOIN tb_branch br ON u.id_branch = br.id_branch WHERE u.`role` = 'user' ORDER BY br.nama_branch ASC");
    $daftarUserCabang = [];
    while ($row = mysqli_fetch_assoc($qUserCabang)) {
        $daftarUserCabang[] = $row;
    }
?>

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

    <form id="formUpdate" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= (int) $barang['id'] ?>">

        <?php if ($type === 'master'): ?>
            <!-- FORM MASTER BARANG -->
            <input type="hidden" name="form_type" value="master">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="label-bold">No Asset</label>
                    <input type="text" name="no_asset" class="form-control input-bold" value="<?= h($barang['no_asset'] ?? '') ?>" placeholder="Boleh dikosongkan">
                </div>
                <div class="col-md-6">
                    <label class="label-bold">Serial Number<span class="text-danger">*</span></label>
                    <input type="text" name="serial_number" class="form-control input-bold text-uppercase" value="<?= h($barang['serial_number'] ?? '') ?>" placeholder="Wajib diisi!" required>
                </div>
                <div class="col-md-6">
                    <label class="label-bold">Barang<span class="text-danger">*</span></label>
                    <select name="id_barang" id="update_id_barang" class="form-control select2 input-bold" required>
                        <option value="">Pilih Barang...</option>
                        <?php foreach ($barangOptions as $b): ?>
                            <option value="<?= (int) $b['id_barang'] ?>" <?= (int) $b['id_barang'] === (int) $barang['id_barang'] ? 'selected' : '' ?>><?= strtoupper(h($b['nama_barang'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="label-bold">Merk<span class="text-danger">*</span></label>
                    <select name="id_merk" id="update_id_merk" class="form-control select2 input-bold" required>
                        <option value="">Pilih Merk...</option>
                        <?php foreach ($merkOptions as $m): ?>
                            <option value="<?= (int) $m['id_merk'] ?>" <?= (int) $m['id_merk'] === (int) $barang['id_merk'] ? 'selected' : '' ?>><?= strtoupper(h($m['nama_merk'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="label-bold">Tipe<span class="text-danger">*</span></label>
                    <select name="id_tipe" id="update_id_tipe" class="form-control select2 input-bold" required>
                        <option value="">Pilih Tipe...</option>
                        <?php foreach ($tipeOptions as $t): ?>
                            <option value="<?= (int) $t['id_tipe'] ?>" <?= (int) $t['id_tipe'] === (int) $barang['id_tipe'] ? 'selected' : '' ?>><?= strtoupper(h($t['nama_tipe'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="label-bold">Jenis<span class="text-danger">*</span></label>
                    <select name="id_jenis" class="form-control select2 input-bold" required>
                        <option value="">Pilih Jenis...</option>
                        <?php foreach ($jenisOptions as $j): ?>
                            <option value="<?= (int) $j['id_jenis'] ?>" <?= (int) $j['id_jenis'] === (int) $barang['id_jenis'] ? 'selected' : '' ?>><?= strtoupper(h($j['nama_jenis'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="label-bold">Branch Lokasi Saat Ini</label>
                    <select name="id_branch" class="form-control select2 input-bold">
                        <?php foreach ($branchOptions as $b): ?>
                            <option value="<?= (int) $b['id_branch'] ?>" <?= (int) $b['id_branch'] === (int) $barang['id_branch'] ? 'selected' : '' ?>><?= strtoupper(h($b['nama_branch'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="label-bold">User Pengguna<span class="text-danger">*</span></label>
                    <input type="text" name="user" class="form-control input-bold text-uppercase" value="<?= h($barang['user'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="label-bold">Status Bermasalah<span class="text-danger">*</span></label>
                    <select name="bermasalah" id="updateBermasalahSelect" class="form-control input-bold" required>
                        <option value="Tidak" <?= ($barang['bermasalah'] ?? '') === 'Tidak' ? 'selected' : '' ?>>TIDAK</option>
                        <option value="Iya" <?= ($barang['bermasalah'] ?? '') === 'Iya' ? 'selected' : '' ?>>IYA</option>
                    </select>
                </div>
                <div class="col-md-6" id="updateKeteranganMasalahDiv" style="<?= ($barang['bermasalah'] === 'Iya') ? '' : 'display:none;' ?>">
                    <label class="label-bold">Keterangan Masalah</label>
                    <textarea name="keterangan_masalah" class="form-control input-bold text-uppercase" placeholder="Jelaskan masalah barang"><?= h($barang['keterangan_masalah'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="label-bold">Tanggal Terima</label>
                    <input type="date" name="tanggal_terima" class="form-control input-bold" value="<?= h($barang['tanggal_terima'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="label-bold">Update Foto Barang</label>
                    <input type="file" name="foto" class="form-control input-bold">
                </div>
            </div>
            <div class="mt-4 text-end">
                <button type="submit" class="btn btn-warning btn-lg fw-black border-dark border-2 px-5"><i class="bi bi-save me-1"></i> SIMPAN PERUBAHAN</button>
            </div>


        <?php elseif ($type === 'logistik'): ?>
            <?php if (!$pernahDikirim || $sudahDiterima): ?>
                
                <!-- TAMPILAN PENGIRIMAN LOGISTIK (HO KE CABANG) -->
                <div class="mb-4">
                    <div class="d-flex gap-2 flex-wrap">
                        <span id="stepLabel1" class="badge rounded-pill bg-primary fs-6 fw-bold px-3 py-2">1. Konsep Surat Tanda Terima</span>
                        <span id="stepLabel2" class="badge rounded-pill bg-secondary text-white fs-6 fw-bold px-3 py-2">2. Form Upload Resi</span>
                    </div>
                </div>

                <div id="logistikStep1">
                    <div class="alert alert-info fw-black border-info border-2" style="background-color: #cff4fc;">
                        <i class="bi bi-info-circle-fill me-2 text-primary"></i> Isi data Tanda Terima Pengiriman Barang terlebih dahulu, lalu CETAK TANDA TERIMA. Nanti otomatis diarahkan ke Form Pengiriman.
                    </div>

                    <div class="card border-dark mb-4 border-2">
                        <div class="card-header card-header-bold text-center">
                            TANDA TERIMA PENGIRIMAN BARANG - HO KE CABANG
                        </div>
                        <div class="card-body" style="background-color: #f8f9fa;">
                            <div class="row g-4">
                                <!-- Ekspedisi -->
                                <div class="col-md-6">
                                    <label class="label-bold">Ekspedisi<span class="text-danger">*</span></label>
                                    <select id="receipt_ekspedisi" class="form-control select2 input-bold" required>
                                        <option value="">-- PILIH EKSPEDISI --</option>
                                        <?php foreach ($ekspedisiOptions as $ex): ?>
                                            <option value="<?= strtoupper(h($ex['nama_ekspedisi'])) ?>"><?= strtoupper(h($ex['nama_ekspedisi'])) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <!-- Charge / Branch Penanggung -->
                                <div class="col-md-6">
                                    <label class="label-bold">Charge (Branch Penanggung)</label>
                                    <select id="receipt_charge" class="form-control select2 input-bold">
                                        <option value="">-- PILIH BRANCH --</option>
                                        <?php foreach ($branchOptions as $br): ?>
                                            <option value="<?= (int)$br['id_branch'] ?>" data-label="<?= strtoupper(h($br['nama_branch'])) ?>">
                                                TO HAP <?= strtoupper(h($br['nama_branch'])) ?>
                                            </option>
                                        <?php endforeach; ?>
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
                                                    <th style="width:5%;"></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td class="text-center fw-black align-middle row-no" style="font-size: 1.1rem;">1</td>
                                                    <td><input type="text" class="form-control input-bold row-desc text-uppercase" placeholder="DESKRIPSI ITEM" value="<?= h($deskripsiAuto) ?>"></td>
                                                    <td><input type="text" class="form-control input-bold row-host text-uppercase" placeholder="ISI HOSTNAME JIKA ADA..."></td>
                                                    <td><input type="number" id="receipt_qty" class="form-control input-bold text-center fw-black" value="1" min="1" required></td>
                                                    <td><input type="text" id="receipt_catatan" class="form-control input-bold text-uppercase" placeholder="ISI CATATAN..."></td>
                                                    <td class="text-center align-middle"><button type="button" class="btn btn-sm btn-danger btn-remove-row fw-bold">X</button></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="mt-2 text-start">
                                        <button type="button" id="btnAddRow" class="btn btn-dark fw-bold border-2"><i class="bi bi-plus-lg me-1"></i> TAMBAH BARIS</button>
                                    </div>
                                </div>

                                <!-- Detail Tambahan -->
                                <div class="col-md-6">
                                    <label class="label-bold">Asuransi</label>
                                    <input type="text" id="receipt_asuransi" class="form-control input-bold text-uppercase">
                                </div>

                                <div class="col-md-6">
                                    <label class="label-bold">User (Pemilik Barang)</label>
                                    <input type="text" id="receipt_user" class="form-control input-bold text-uppercase text-primary" placeholder="OTOMATIS SAAT CHARGE DIPILIH..." value="<?= strtoupper(h($pemilikBarang)) ?>">
                                </div>

                                <!-- Pengirim (Dikunci ke Deni Pratama / HO) -->
                                <div class="col-md-6">
                                    <label class="label-bold">Pengirim (HO)</label>
                                    <input type="text" id="receipt_pengirim" class="form-control input-bold fw-black text-uppercase text-danger" value="DENI PRATAMA (IT HEAD OFFICE)" readonly style="background:#e9ecef;">
                                </div>

                                <!-- Penerima (Otomatis berdasarkan Charge/Tujuan) -->
                                <div class="col-md-6">
                                    <label class="label-bold">Penerima (Cabang Tujuan)</label>
                                    <input type="text" id="receipt_penerima" class="form-control input-bold fw-black text-uppercase text-primary" placeholder="OTOMATIS SAAT CHARGE DIPILIH..." readonly style="background:#e9ecef;">
                                    <small class="text-muted fw-bold">Otomatis dari user cabang tujuan</small>
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

                <!-- STEP 2 -->
                <div id="logistikStep2" style="display:none;">
                    <input type="hidden" name="form_type" value="logistik">
                    <input type="hidden" name="pdf_generated" id="pdf_generated" value="0">
                    <input type="hidden" name="receipt_hostname" id="receipt_hostname_hidden">
                    <input type="hidden" name="receipt_qty" id="receipt_qty_hidden">
                    <input type="hidden" name="receipt_catatan" id="receipt_catatan_hidden">
                    <input type="hidden" name="receipt_description" id="receipt_description_hidden">
                    <input type="hidden" name="receipt_attn" id="receipt_attn_hidden">
                    <input type="hidden" name="receipt_asuransi" id="receipt_asuransi_hidden">
                    <input type="hidden" name="receipt_charge" id="receipt_charge_hidden">
                    <input type="hidden" name="receipt_penerima" id="receipt_penerima_hidden">
                    <input type="hidden" name="receipt_user" id="receipt_user_hidden">
                    <input type="hidden" name="receipt_ekspedisi" id="receipt_ekspedisi_hidden">
                    <input type="hidden" name="receipt_pengirim" id="receipt_pengirim_hidden">
                    <input type="hidden" name="receipt_penerima_branch" id="receipt_penerima_branch">
                    
                    <div class="alert alert-warning fw-black border-warning border-2" style="background-color: #fff3cd;">
                        <i class="bi bi-check-circle-fill me-2 text-success"></i> Dokumen berhasil dicetak. Silakan isi form di bawah ini untuk validasi sistem.
                    </div>
                    
                    <div class="card border-dark border-2">
                        <div class="card-body bg-white">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <label class="label-bold">Tanggal Keluar<span class="text-danger">*</span></label>
                                    <input type="date" name="tanggal_keluar" class="form-control input-bold" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="label-bold">Tujuan Pengiriman (Cabang)<span class="text-danger">*</span></label>
                                    <select name="tujuan" class="form-control select2 input-bold" required>
                                        <option value="">-- PILIH TUJUAN --</option>
                                        <?php foreach ($tujuanOptions as $bt): ?>
                                            <option value="<?= (int) $bt['id_branch'] ?>"><?= strtoupper(h($bt['nama_branch'])) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="label-bold">Jasa Pengiriman<span class="text-danger">*</span></label>
                                    <select name="jasa_pengiriman" class="form-control select2 input-bold" required>
                                        <option value="">-- PILIH JASA PENGIRIMAN --</option>
                                        <option value="SAP Express">SAP EXPRESS</option>
                                        <option value="PCP Express">PCP EXPRESS</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="label-bold">Nomor Resi Keluar<span class="text-danger">*</span></label>
                                    <input type="text" name="nomor_resi" class="form-control input-bold text-uppercase" placeholder="INPUT NOMOR RESI..." required>
                                </div>
                                
                                <div class="col-md-12">
                                    <label class="label-bold">Foto Resi Keluar / Bukti Kirim<span class="text-danger">*</span></label>
                                    <input type="file" name="foto_resi" class="form-control input-bold" accept=".jpg,.jpeg,.png,.gif,.webp" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 text-end">
                        <button type="button" id="btnBackToStep1" class="btn btn-secondary btn-lg me-2 fw-black border-dark border-2"><i class="bi bi-arrow-left me-1"></i> KEMBALI</button>
                        <button type="submit" class="btn btn-success btn-lg fw-black px-5 rounded-pill shadow-sm border-2 border-dark" id="btnSimpanPengirimanUser">
                            <span class="btn-text"><i class="bi bi-send-fill me-2"></i> KIRIM BARANG SEKARANG</span>
                            <span class="btn-loading d-none">
                                <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> LOADING...
                            </span>
                        </button>
                    </div>
                </div>

            <?php elseif ($sedangDikirim): ?>
                <input type="hidden" name="form_type" value="penerimaan">
                <div class="alert alert-warning fw-black border-warning border-2" style="background-color: #fff3cd;">
                    <i class="bi bi-box-seam text-primary me-2"></i> Barang sedang dikirim. Isi form di bawah untuk konfirmasi penerimaan.
                </div>
                <div class="card border-dark border-2">
                    <div class="card-body bg-light">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="label-bold">Tanggal Diterima<span class="text-danger">*</span></label>
                                <input type="date" name="tanggal_diterima" class="form-control input-bold" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="label-bold">Nama Penerima<span class="text-danger">*</span></label>
                                <input type="text" name="nama_penerima" class="form-control input-bold text-uppercase" placeholder="NAMA PENERIMA BARANG..." required>
                            </div>
                            <div class="col-md-12">
                                <label class="label-bold">Upload Foto Barang Diterima<span class="text-danger">*</span></label>
                                <input type="file" name="foto_barang_diterima" class="form-control input-bold" accept=".jpg,.jpeg,.png,.gif,.webp" required>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 text-end">
                    <button type="submit" class="btn btn-success btn-lg fw-black px-5 rounded-pill shadow-sm border-2 border-dark">
                        <i class="bi bi-check-circle-fill me-2"></i> KONFIRMASI TERIMA BARANG
                    </button>
                </div>

            <?php else: ?>
                <div class="alert alert-success fw-black border-success border-2">
                    <i class="bi bi-check-all me-2"></i> Status pengiriman saat ini: <?= strtoupper(h($pengirimanTerakhir['status_pengiriman'])) ?>. Proses logistik selesai.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </form>

    <script>
    function validateReceiptStep() {
        const qty = parseInt($('#receipt_qty').val(), 10);
        const ekspedisi = $('#receipt_ekspedisi').val().trim();

        if (!qty || qty < 1) {
            Swal.fire('Validasi', 'Qty harus minimal 1.', 'warning');
            return false;
        }
        if (!ekspedisi) {
            Swal.fire('Validasi', 'Ekspedisi harus dipilih.', 'warning');
            return false;
        }
        return true;
    }

    $(document).ready(function() {

        const userCabangData = <?= json_encode($daftarUserCabang) ?>;

        // Auto-fill penerima DAN USER berdasarkan id branch (Charge)
        function autoFillPenerima(idBranch) {
            const found = userCabangData.find(u => parseInt(u.id_branch, 10) === parseInt(idBranch, 10));
            if (found) {
                // Set Penerima
                $('#receipt_penerima').val(found.username.toUpperCase());
                $('#receipt_penerima_branch').val(found.nama_branch.toUpperCase());
                $('#receipt_penerima_hidden').val(found.username.toUpperCase());
                
                // Set User juga agar otomatis sinkron!
                $('#receipt_user').val(found.username.toUpperCase());
            } else {
                $('#receipt_penerima').val('');
                $('#receipt_penerima_branch').val('');
                $('#receipt_penerima_hidden').val('');
            }
        }

        // Event Charge -> Sync ke Penerima & Tujuan & USER
        $('#receipt_charge').on('select2:select', function(e) {
            const idBranch = e.params.data.id;
            autoFillPenerima(idBranch);
            $('select[name="tujuan"]').val(idBranch).trigger('change.select2');
        });

        $('#receipt_charge').on('select2:unselect', function() {
            $('#receipt_penerima').val('');
            $('#receipt_penerima_branch').val('');
            $('#receipt_penerima_hidden').val('');
        });

        // Event Tujuan (Step 2) -> Sync ke Penerima jika diubah manual
        $(document).on('select2:select', 'select[name="tujuan"]', function(e) {
            autoFillPenerima(e.params.data.id);
        });

        // Tombol Cetak PDF
        $('#btnGeneratePdf').on('click', function() {
            if (!validateReceiptStep()) return;

            const descs = [];
            const hosts = [];
            $('#receipt_table tbody tr').each(function() {
                descs.push($(this).find('.row-desc').val().trim().toUpperCase());
                hosts.push($(this).find('.row-host').val().trim().toUpperCase());
            });

            // PARAMETER UNTUK ADMIN (HO -> CABANG)
            const paramsObj = {
                'description[]': descs,
                'hostname[]': hosts,
                'qty': $('#receipt_qty').val().trim(),
                'catatan': $('#receipt_catatan').val().trim().toUpperCase(),
                'user': $('#receipt_user').val().trim().toUpperCase(),
                'asuransi': $('#receipt_asuransi').val().trim().toUpperCase(),
                'charge': $('#receipt_charge option:selected').text().trim().toUpperCase(),
                'ekspedisi': $('#receipt_ekspedisi').val().trim().toUpperCase(),
                
                'penerima': $('#receipt_penerima').val().trim().toUpperCase(),
                'penerima_branch': $('#receipt_penerima_branch').val().trim().toUpperCase(),
                
                'pengirim': 'DENI PRATAMA',
                'pengirim_branch': 'IT HEAD OFFICE',
                
                'is_admin': '1' // KODE INI YANG BIKIN HALAMAN 2 BERUBAH JADI HO KE CABANG
            };

            window.open('print_pengiriman.php?' + $.param(paramsObj), '_blank');

            // Simpan ke hidden fields
            $('#receipt_description_hidden').val(descs.join('\n'));
            $('#receipt_hostname_hidden').val(hosts.join('||'));
            $('#receipt_qty_hidden').val($('#receipt_qty').val().trim());
            $('#receipt_catatan_hidden').val($('#receipt_catatan').val().trim().toUpperCase());
            $('#receipt_user_hidden').val($('#receipt_user').val().trim().toUpperCase());
            $('#receipt_attn_hidden').val('');
            $('#receipt_asuransi_hidden').val($('#receipt_asuransi').val().trim().toUpperCase());
            $('#receipt_charge_hidden').val($('#receipt_charge option:selected').text().trim().toUpperCase());
            $('#receipt_penerima_hidden').val($('#receipt_penerima').val().trim().toUpperCase());
            $('#receipt_penerima_branch').val($('#receipt_penerima_branch').val().trim().toUpperCase());
            $('#receipt_ekspedisi_hidden').val($('#receipt_ekspedisi').val().trim().toUpperCase());
            $('#receipt_pengirim_hidden').val('DENI PRATAMA');
            $('#pdf_generated').val('1');

            $('#logistikStep1').hide();
            $('#logistikStep2').show();
            $('#stepLabel1').removeClass('bg-primary').addClass('bg-success');
            $('#stepLabel2').removeClass('bg-secondary text-white').addClass('bg-primary');
        });

        $('#btnBackToStep1').on('click', function() {
            $('#logistikStep2').hide();
            $('#logistikStep1').show();
            $('#stepLabel1').removeClass('bg-success').addClass('bg-primary');
            $('#stepLabel2').removeClass('bg-primary').addClass('bg-secondary text-white');
        });

        function renumberRows() {
            $('#receipt_table tbody tr').each(function(i) {
                $(this).find('.row-no').text(i + 1);
            });
        }

        function addRow(desc = '', host = '') {
            const $tr = $('<tr>');
            $tr.append('<td class="text-center fw-black align-middle row-no" style="font-size: 1.1rem;"></td>');
            $tr.append('<td><input type="text" class="form-control input-bold row-desc text-uppercase" placeholder="DESKRIPSI ITEM" value="' + $('<div/>').text(desc).html() + '"></td>');
            $tr.append('<td><input type="text" class="form-control input-bold row-host text-uppercase" placeholder="ISI HOSTNAME JIKA ADA..." value="' + $('<div/>').text(host).html() + '"></td>');
            $tr.append('<td><input type="number" class="form-control input-bold text-center fw-black" value="1" min="1"></td>');
            $tr.append('<td><input type="text" class="form-control input-bold text-uppercase" placeholder="ISI CATATAN..."></td>');
            $tr.append('<td class="text-center align-middle"><button type="button" class="btn btn-sm btn-danger btn-remove-row fw-bold">X</button></td>');
            $('#receipt_table tbody').append($tr);
            renumberRows();
            $tr.find('.row-desc').focus();
        }

        $('#btnAddRow').on('click', function() { addRow(); });

        $(document).on('click', '.btn-remove-row', function() {
            if ($('#receipt_table tbody tr').length <= 1) {
                $(this).closest('tr').find('input').val('');
            } else {
                $(this).closest('tr').remove();
                renumberRows();
            }
        });

        $(document).on('keydown', '.row-desc', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); addRow(); }
        });

        $('#updateBermasalahSelect').on('change', function() {
            if ($(this).val() === 'Iya') {
                $('#updateKeteranganMasalahDiv').slideDown();
                $('textarea[name="keterangan_masalah"]').attr('required', true);
            } else {
                $('#updateKeteranganMasalahDiv').slideUp();
                $('textarea[name="keterangan_masalah"]').removeAttr('required').val('');
            }
        });

        $(document).off('change', '#update_id_barang').on('change', '#update_id_barang', function() {
            var id_barang = $(this).val();
            $('#update_id_merk').html('<option value="">Sedang memuat...</option>').trigger('change');
            $('#update_id_tipe').html('<option value="">Pilih Merk Dulu...</option>').trigger('change');
            if (id_barang) {
                $.ajax({
                    url: 'ajax_dropdown.php', type: 'POST',
                    data: { action: 'get_merk', id_barang: id_barang },
                    success: function(response) { $('#update_id_merk').html(response).trigger('change'); }
                });
            } else {
                $('#update_id_merk').html('<option value="">Pilih Barang Dulu...</option>').trigger('change');
            }
        });

        $(document).off('change', '#update_id_merk').on('change', '#update_id_merk', function() {
            var id_barang = $('#update_id_barang').val();
            var id_merk = $(this).val();
            $('#update_id_tipe').html('<option value="">Sedang memuat...</option>').trigger('change');
            if (id_merk && id_barang) {
                $.ajax({
                    url: 'ajax_dropdown.php', type: 'POST',
                    data: { action: 'get_tipe', id_barang: id_barang, id_merk: id_merk },
                    success: function(response) { $('#update_id_tipe').html(response).trigger('change'); }
                });
            } else {
                $('#update_id_tipe').html('<option value="">Pilih Merk Dulu...</option>').trigger('change');
            }
        });
    });
    </script>
<?php
    exit;
}

// ==========================================
// HANDLE PROSES DATA (METODE POST)
// ==========================================
$formType = $_POST['form_type'] ?? '';

if ($formType === 'penerimaan') {
    $tanggalDiterima = normalizeNullableString($_POST['tanggal_diterima'] ?? '');
    if ($tanggalDiterima < date('Y-m-d')) jsonError("Tanggal diterima tidak boleh tanggal yang lampau.");

    $namaPenerima = normalizeNullableString($_POST['nama_penerima'] ?? '');
    if (empty($namaPenerima)) jsonError("Nama penerima wajib diisi.");

    $upload = uploadImage('foto_barang_diterima');
    if ($upload['status'] === 'error') jsonError($upload['message']);

    mysqli_begin_transaction($koneksi);
    try {
        $stmt = mysqli_prepare($koneksi, "UPDATE barang_pengiriman SET status_pengiriman = ?, tanggal_diterima = ?, nama_penerima = ?, foto_barang_diterima = ? WHERE id_pengiriman = ?");
        $idPeng = (int) $pengirimanTerakhir['id_pengiriman'];
        $st = STATUS_SUDAH_DITERIMA;
        mysqli_stmt_bind_param($stmt, 'ssssi', $st, $tanggalDiterima, $namaPenerima, $upload['filename'], $idPeng);
        mysqli_stmt_execute($stmt);

        $branchTujuan = (int) $pengirimanTerakhir['branch_tujuan'];
        mysqli_query($koneksi, "UPDATE barang SET id_status = 4, id_branch = $branchTujuan, status = 'Tersedia' WHERE id = $id");
        mysqli_commit($koneksi);
        jsonSuccess("Penerimaan barang berhasil dikonfirmasi.");
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        jsonError($e->getMessage());
    }
} elseif ($formType === 'master') {
    $tanggalTerima = $_POST['tanggal_terima'] ?? '';
    $noAsset       = trim((string) $_POST['no_asset']);
    $serialNumber  = trim((string) $_POST['serial_number']);
    $duplicate     = validateDuplicateBarang($koneksi, $id, $noAsset, $serialNumber, (int) $_POST['id_barang']);
    if ($duplicate) jsonError($duplicate);

    $upload    = uploadImage('foto');
    $fotoBaru  = $upload['status'] === 'success' ? $upload['filename'] : null;

    $keteranganMasalah = ($_POST['bermasalah'] === 'Iya') ? ($_POST['keterangan_masalah'] ?? null) : null;
    $user_id_sistem    = (int) current_user_id();

    mysqli_begin_transaction($koneksi);
    try {
        $stmt = mysqli_prepare($koneksi, "UPDATE barang SET no_asset=?, serial_number=?, id_barang=?, id_merk=?, id_tipe=?, id_jenis=?, id_branch=?, user=?, user_id=?, bermasalah=?, keterangan_masalah=?, tanggal_terima=?, foto=COALESCE(?, foto) WHERE id=?");
        mysqli_stmt_bind_param(
            $stmt, 'ssiiiiiisssssi',
            $noAsset, $serialNumber, $_POST['id_barang'], $_POST['id_merk'], $_POST['id_tipe'], $_POST['id_jenis'], $_POST['id_branch'], $_POST['user'], $user_id_sistem, $_POST['bermasalah'], $keteranganMasalah, $tanggalTerima, $fotoBaru, $id
        );
        mysqli_stmt_execute($stmt);
        mysqli_commit($koneksi);
        jsonSuccess("Data berhasil diupdate.");
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        jsonError($e->getMessage());
    }
} elseif ($formType === 'logistik') {
    $tanggalKeluar = $_POST['tanggal_keluar'] ?? '';
    if ($tanggalKeluar < date('Y-m-d')) jsonError("Tanggal keluar logistik tidak boleh tanggal lampau.");

    $upload = uploadImage('foto_resi');
    if ($upload['status'] === 'error') jsonError($upload['message']);

    $namaPenerimaKirim = trim($_POST['receipt_penerima'] ?? $_POST['receipt_user'] ?? '');

    mysqli_begin_transaction($koneksi);
    try {
        $stmt = mysqli_prepare(
            $koneksi,
            "INSERT INTO barang_pengiriman
                (id_barang, branch_asal, branch_tujuan, tanggal_keluar, jasa_pengiriman, nomor_resi_keluar, foto_resi_keluar, status_pengiriman, nama_penerima)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $asal = (int) $barang['id_branch'];
        $st   = STATUS_SEDANG_PERJALANAN;
        mysqli_stmt_bind_param(
            $stmt, 'iiissssss',
            $id, $asal, $_POST['tujuan'], $_POST['tanggal_keluar'], $_POST['jasa_pengiriman'], $_POST['nomor_resi'], $upload['filename'], $st, $namaPenerimaKirim
        );
        mysqli_stmt_execute($stmt);

        mysqli_query($koneksi, "UPDATE barang SET id_status = 3 WHERE id = $id");

        $newPengirimanId = (int) mysqli_insert_id($koneksi);
        $targetBranch    = (int) ($_POST['tujuan'] ?? 0);
        if ($targetBranch > 0) {
            $title   = 'Pengiriman dalam perjalanan';
            $message = 'Barang dengan resi ' . mysqli_real_escape_string($koneksi, $_POST['nomor_resi']) . ' sedang dalam perjalanan ke cabang Anda.';
            $link    = '../Barang/index.php?filter=masuk';
            $stmtNotif = mysqli_prepare($koneksi, "INSERT INTO system_notifications (target_role, target_branch_id, title, message, link, is_read) VALUES ('user', ?, ?, ?, ?, 0)");
            if ($stmtNotif) {
                mysqli_stmt_bind_param($stmtNotif, 'isss', $targetBranch, $title, $message, $link);
                mysqli_stmt_execute($stmtNotif);
                mysqli_stmt_close($stmtNotif);
            }
        }

        mysqli_commit($koneksi);
        jsonSuccess("Pengiriman berhasil dibuat.");
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        jsonError($e->getMessage());
    }
}