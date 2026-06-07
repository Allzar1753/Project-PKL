<?php

/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';
require_once '../config/activity_logger.php';
require_once '../config/warranty_helper.php';
require_once '../config/validation_helper.php';

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
        /* ==========================================
           TEMA BARU MODERN CLEAN (Dashboard Match)
           ========================================== */
        :root {
            --orange-primary: #E64312;
            --orange-hover: #F25C05;
            --dark-main: #231F20;
            --text-dark: #374151;
            --text-muted: #6b7280;
            --border-color: #e5e7eb;
            --bg-light: #f9fafb;
        }

        /* Styling Typography & Label */
        .modern-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            display: block;
        }

        /* Styling Input */
        .modern-input {
            border: 1px solid var(--border-color) !important;
            border-radius: 8px !important;
            padding: 0.6rem 1rem !important;
            font-size: 0.95rem !important;
            color: var(--text-dark) !important;
            background-color: #ffffff !important;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02) !important;
            transition: all 0.2s ease-in-out !important;
            font-weight: 500 !important;
        }
        .modern-input:focus {
            border-color: var(--orange-primary) !important;
            box-shadow: 0 0 0 4px rgba(230, 67, 18, 0.1) !important;
            outline: none !important;
        }
        .modern-input:read-only {
            background-color: var(--bg-light) !important;
            color: var(--text-muted) !important;
        }

        /* Styling Card Wrapper */
        .modern-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .modern-card-header {
            background: var(--bg-light);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            font-weight: 700;
            color: var(--dark-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .modern-card-body {
            padding: 1.5rem;
        }

        /* Divider antar section form */
        .form-section-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--dark-main);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--bg-light);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Tombol Modern */
        .btn-modern-primary {
            background-color: var(--orange-primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 0.6rem 1.5rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-modern-primary:hover {
            background-color: var(--orange-hover);
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(230, 67, 18, 0.2);
        }
        .btn-modern-outline {
            background-color: transparent;
            color: var(--dark-main);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-weight: 600;
            padding: 0.6rem 1.5rem;
            transition: all 0.2s;
        }
        .btn-modern-outline:hover {
            background-color: var(--bg-light);
            border-color: #d1d5db;
        }

        /* ==========================================
           STYLING TABEL LOGISTIK (Lebih Bersih)
           ========================================== */
        .modern-table-wrapper {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
        }
        .modern-table {
            margin-bottom: 0;
            width: 100%;
        }
        .modern-table th {
            background-color: var(--bg-light);
            color: var(--text-muted);
            font-size: 0.75rem;
            text-transform: uppercase;
            font-weight: 700;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            border-top: none;
        }
        .modern-table td {
            padding: 8px 12px;
            vertical-align: middle;
            border-bottom: 1px solid var(--bg-light);
        }
        
        /* Input di dalam tabel dibuat seolah menyatu */
        .table-input {
            border: 1px solid transparent;
            background-color: transparent;
            width: 100%;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 0.9rem;
            color: var(--text-dark);
            transition: 0.2s;
        }
        .table-input:hover { background-color: var(--bg-light); }
        .table-input:focus {
            background-color: #fff;
            border-color: var(--orange-primary);
            box-shadow: 0 0 0 3px rgba(230, 67, 18, 0.1);
            outline: none;
        }
        
        .btn-remove-row-modern {
            color: #ef4444;
            background: rgba(239, 68, 68, 0.1);
            border: none;
            width: 32px; height: 32px;
            border-radius: 6px;
            display: inline-flex; justify-content: center; align-items: center;
            transition: 0.2s;
        }
        .btn-remove-row-modern:hover { background: #ef4444; color: #fff; }

        /* Step Indicators */
        .step-indicator {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 16px; border-radius: 99px;
            font-size: 0.85rem; font-weight: 700;
        }
        .step-active { background-color: rgba(230, 67, 18, 0.1); color: var(--orange-primary); border: 1px solid rgba(230, 67, 18, 0.2); }
        .step-done { background-color: rgba(16, 185, 129, 0.1); color: #059669; border: 1px solid rgba(16, 185, 129, 0.2); }
        .step-pending { background-color: var(--bg-light); color: var(--text-muted); border: 1px solid var(--border-color); }

        /* Select2 override untuk form logistik/master agar sesuai tema */
        .select2-container .select2-selection--single {
            height: 42px !important;
            border: 1px solid var(--border-color) !important;
            border-radius: 8px !important;
            display: flex !important;
            align-items: center !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px !important; }
    </style>

    <form id="formUpdate" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= (int) $barang['id'] ?>">

        <?php if ($type === 'master'): ?>
            <!-- ==========================================
                 FORM MASTER BARANG (Redesign)
                 ========================================== -->
            <input type="hidden" name="form_type" value="master">
            
            <div class="modern-card">
                <div class="modern-card-header">
                    <i class="bi bi-box-seam" style="color: var(--orange-primary);"></i> Update Data Master Barang
                </div>
                <div class="modern-card-body">
                    
                    <div class="form-section-title"><i class="bi bi-tag text-muted"></i> Identitas & Spesifikasi</div>
                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <label class="modern-label">Kode Asset</label>
                            <input type="text" class="form-control modern-input bg-light" value="<?= h($barang['kode_aset'] ?? '-') ?>" readonly disabled>
                            <small class="text-muted">Nomor unik sistem — tidak dapat diubah</small>
                        </div>
                        <div class="col-md-6">
                            <label class="modern-label">No Asset</label>
                            <input type="text" name="no_asset" class="form-control modern-input text-uppercase" value="<?= h($barang['no_asset'] ?? '') ?>" placeholder="Opsional / Boleh dikosongkan">
                        </div>
                        <div class="col-md-6">
                            <label class="modern-label">Serial Number <span class="text-danger">*</span></label>
                            <input type="text" name="serial_number" class="form-control modern-input text-uppercase" value="<?= h($barang['serial_number'] ?? '') ?>" placeholder="Wajib diisi" required>
                        </div>
                        <div class="col-md-6">
                            <label class="modern-label">Kategori Barang <span class="text-danger">*</span></label>
                            <select name="id_barang" id="update_id_barang" class="form-control select2 modern-input" required>
                                <option value="">Pilih Barang...</option>
                                <?php foreach ($barangOptions as $b): ?>
                                    <option value="<?= (int) $b['id_barang'] ?>" <?= (int) $b['id_barang'] === (int) $barang['id_barang'] ? 'selected' : '' ?>><?= strtoupper(h($b['nama_barang'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="modern-label">Jenis <span class="text-danger">*</span></label>
                            <select name="id_jenis" class="form-control select2 modern-input" required>
                                <option value="">Pilih Jenis...</option>
                                <?php foreach ($jenisOptions as $j): ?>
                                    <option value="<?= (int) $j['id_jenis'] ?>" <?= (int) $j['id_jenis'] === (int) $barang['id_jenis'] ? 'selected' : '' ?>><?= strtoupper(h($j['nama_jenis'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="modern-label">Merk <span class="text-danger">*</span></label>
                            <select name="id_merk" id="update_id_merk" class="form-control select2 modern-input" required>
                                <option value="">Pilih Merk...</option>
                                <?php foreach ($merkOptions as $m): ?>
                                    <option value="<?= (int) $m['id_merk'] ?>" <?= (int) $m['id_merk'] === (int) $barang['id_merk'] ? 'selected' : '' ?>><?= strtoupper(h($m['nama_merk'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="modern-label">Tipe <span class="text-danger">*</span></label>
                            <select name="id_tipe" id="update_id_tipe" class="form-control select2 modern-input" required>
                                <option value="">Pilih Tipe...</option>
                                <?php foreach ($tipeOptions as $t): ?>
                                    <option value="<?= (int) $t['id_tipe'] ?>" <?= (int) $t['id_tipe'] === (int) $barang['id_tipe'] ? 'selected' : '' ?>><?= strtoupper(h($t['nama_tipe'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-section-title"><i class="bi bi-geo-alt text-muted"></i> Lokasi & Pengguna</div>
                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <label class="modern-label">Branch Lokasi Saat Ini</label>
                            <select name="id_branch" class="form-control select2 modern-input">
                                <?php foreach ($branchOptions as $b): ?>
                                    <option value="<?= (int) $b['id_branch'] ?>" <?= (int) $b['id_branch'] === (int) $barang['id_branch'] ? 'selected' : '' ?>><?= strtoupper(h($b['nama_branch'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="modern-label">Nama User Pengguna <span class="text-danger">*</span></label>
                            <input type="text" name="user" class="form-control modern-input text-uppercase" value="<?= strtoupper(h($pemilikBarang)) ?>" required>
                        </div>
                    </div>

                    <div class="form-section-title"><i class="bi bi-clipboard-check text-muted"></i> Kondisi & Status</div>
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="modern-label">Status Bermasalah <span class="text-danger">*</span></label>
                            <select name="bermasalah" id="updateBermasalahSelect" class="form-control modern-input" required>
                                <option value="Tidak" <?= ($barang['bermasalah'] ?? '') === 'Tidak' ? 'selected' : '' ?>>Kondisi Normal (TIDAK)</option>
                                <option value="Iya" <?= ($barang['bermasalah'] ?? '') === 'Iya' ? 'selected' : '' ?>>Ada Kendala (IYA)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="modern-label">Tanggal Terima / Pembelian</label>
                            <input type="date" name="tanggal_terima" class="form-control modern-input" value="<?= h($barang['tanggal_terima'] ?? '') ?>" min="<?= date_min_back_tolerance(3) ?>">
                        </div>
                    </div>

                    <div class="form-section-title"><i class="bi bi-shield-check text-muted"></i> Garansi Produk</div>
                    <div class="row g-4 mb-4">
                        <div class="col-md-4">
                            <label class="modern-label">Tanggal Pembelian</label>
                            <input type="date" name="tanggal_pembelian" id="update_tanggal_pembelian" class="form-control modern-input" value="<?= h($barang['tanggal_pembelian'] ?? ($barang['tanggal_terima'] ?? '')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="modern-label">Masa Garansi</label>
                            <?php $garansiBulan = (int) ($barang['masa_garansi_bulan'] ?? 12); ?>
                            <select name="masa_garansi_bulan" id="update_masa_garansi_bulan" class="form-control modern-input">
                                <?php foreach ([6, 12, 24, 36, 48, 60] as $bln): ?>
                                    <option value="<?= $bln ?>" <?= $garansiBulan === $bln ? 'selected' : '' ?>><?= $bln ?> Bulan</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="modern-label">Garansi Berakhir</label>
                            <input type="text" class="form-control modern-input bg-light" readonly value="<?= !empty($barang['tanggal_garansi_berakhir']) ? date('d M Y', strtotime($barang['tanggal_garansi_berakhir'])) : '—' ?>">
                            <?php if (!empty($barang['tanggal_garansi_berakhir'])): ?>
                                <div class="mt-2"><?= warranty_badge_html($barang['tanggal_garansi_berakhir']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-md-12" id="updateKeteranganMasalahDiv" style="<?= ($barang['bermasalah'] === 'Iya') ? '' : 'display:none;' ?>">
                            <label class="modern-label">Keterangan Masalah / Kendala</label>
                            <textarea name="keterangan_masalah" class="form-control modern-input" rows="3" placeholder="Jelaskan detail masalah pada perangkat ini..."><?= h($barang['keterangan_masalah'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="modern-label">Update Foto Barang</label>
                            <input type="file" name="foto" class="form-control modern-input" accept=".jpg,.jpeg,.png,.webp">
                            <small class="text-muted d-block mt-1">Kosongkan jika tidak ingin mengubah foto. Maksimal 2MB.</small>
                        </div>
                    </div>

                </div>
            </div>

            <div class="text-end mb-4">
                <button type="submit" class="btn btn-modern-primary btn-lg"><i class="bi bi-save me-2"></i> Simpan Perubahan</button>
            </div>


        <?php elseif ($type === 'logistik'): ?>
            <?php if (!$pernahDikirim || $sudahDiterima): ?>

                <!-- ==========================================
                     FORM LOGISTIK (HO KE CABANG) - Redesign
                     ========================================== -->
                <div class="mb-4 d-flex gap-2">
                    <span id="stepLabel1" class="step-indicator step-active"><i class="bi bi-1-circle"></i> Konsep Surat & Label</span>
                    <span id="stepLabel2" class="step-indicator step-pending"><i class="bi bi-2-circle"></i> Upload Resi Ekspedisi</span>
                </div>

                <!-- STEP 1: Surat Jalan -->
                <div id="logistikStep1">
                    <div class="modern-card">
                        <div class="modern-card-header justify-content-between">
                            <span><i class="bi bi-envelope-paper"></i> Tanda Terima Pengiriman Barang</span>
                        </div>
                        <div class="modern-card-body">
                            
                            <!-- Bagian Pengirim & Ekspedisi -->
                            <div class="row g-4 mb-4 pb-4" style="border-bottom: 1px dashed var(--border-color);">
                                <div class="col-md-6">
                                    <label class="modern-label">Ekspedisi <span class="text-danger">*</span></label>
                                    <select id="receipt_ekspedisi" class="form-control select2 modern-input" required>
                                        <option value="">-- Pilih Ekspedisi --</option>
                                        <?php foreach ($ekspedisiOptions as $ex): ?>
                                            <option value="<?= strtoupper(h($ex['nama_ekspedisi'])) ?>"><?= strtoupper(h($ex['nama_ekspedisi'])) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="modern-label">Charge (Tujuan Branch Utama)</label>
                                    <select id="receipt_charge" class="form-control select2 modern-input">
                                        <option value="">-- Pilih Cabang --</option>
                                        <?php foreach ($branchOptions as $br): ?>
                                            <option value="<?= (int)$br['id_branch'] ?>" data-label="<?= strtoupper(h($br['nama_branch'])) ?>">
                                                TO HAP <?= strtoupper(h($br['nama_branch'])) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="modern-label">Pengirim (HO)</label>
                                    <input type="text" id="receipt_pengirim" class="form-control modern-input fw-bold" value="DENI PRATAMA (IT HEAD OFFICE)" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="modern-label">Penerima (User Cabang)</label>
                                    <input type="text" id="receipt_penerima" class="form-control modern-input" placeholder="Otomatis terisi..." readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="modern-label">Pemilik Asset / User</label>
                                    <input type="text" id="receipt_user" class="form-control modern-input" value="<?= strtoupper(h($pemilikBarang)) ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="modern-label">Asuransi (Opsional)</label>
                                    <input type="text" id="receipt_asuransi" class="form-control modern-input text-uppercase" placeholder="Isi jika menggunakan asuransi">
                                </div>
                            </div>

                            <!-- Tabel Daftar Barang (Desain Bersih) -->
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <label class="modern-label mb-0"><i class="bi bi-box"></i> Detail Barang yang Dikirim</label>
                                <button type="button" id="btnAddRow" class="btn btn-sm btn-modern-outline"><i class="bi bi-plus"></i> Tambah Baris</button>
                            </div>
                            
                            <div class="modern-table-wrapper mb-4">
                                <table class="table modern-table" id="receipt_table">
                                    <thead>
                                        <tr>
                                            <th style="width:5%; text-align:center;">No</th>
                                            <th style="width:35%;">Deskripsi Item</th>
                                            <th style="width:25%;">Hostname (SN)</th>
                                            <th style="width:10%; text-align:center;">Qty</th>
                                            <th style="width:20%;">Catatan</th>
                                            <th style="width:5%; text-align:center;"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td class="text-center align-middle row-no fw-bold text-muted">1</td>
                                            <td><input type="text" class="table-input row-desc text-uppercase" placeholder="Deskripsi barang..." value="<?= h($deskripsiAuto) ?>"></td>
                                            <td><input type="text" class="table-input row-host text-uppercase" placeholder="Hostname / SN..."></td>
                                            <td><input type="number" id="receipt_qty" class="table-input text-center fw-bold" value="1" min="1" required></td>
                                            <td><input type="text" id="receipt_catatan" class="table-input text-uppercase" placeholder="Catatan opsional..."></td>
                                            <td class="text-center align-middle"><button type="button" class="btn-remove-row-modern btn-remove-row" title="Hapus baris"><i class="bi bi-trash"></i></button></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="alert alert-light border text-muted small mb-0 rounded-3">
                                <i class="bi bi-info-circle me-1"></i> Pastikan detail item sesuai dengan barang fisik yang akan di-packing.
                            </div>
                        </div>
                    </div>

                    <div class="text-end mb-5">
                        <button type="button" id="btnGeneratePdf" class="btn btn-modern-primary btn-lg">
                            Cetak Tanda Terima & Lanjut <i class="bi bi-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>

                <!-- STEP 2: Form Upload Resi -->
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

                    <div class="modern-card">
                        <div class="modern-card-header">
                            <i class="bi bi-send text-success"></i> Form Pengiriman Ekspedisi
                        </div>
                        <div class="modern-card-body">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <label class="modern-label">Tanggal Keluar Barang <span class="text-danger">*</span></label>
                                    <input type="date" name="tanggal_keluar" class="form-control modern-input" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="modern-label">Tujuan Pengiriman (Branch) <span class="text-danger">*</span></label>
                                    <select name="tujuan" class="form-control select2 modern-input" required>
                                        <option value="">-- Pilih Tujuan --</option>
                                        <?php foreach ($tujuanOptions as $bt): ?>
                                            <option value="<?= (int) $bt['id_branch'] ?>"><?= strtoupper(h($bt['nama_branch'])) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="modern-label">Jasa Pengiriman</label>
                                    <input type="text" name="jasa_pengiriman" class="form-control modern-input text-uppercase fw-bold text-muted" readonly required>
                                </div>
                                <div class="col-md-6">
                                    <label class="modern-label">Nomor Resi Keluar <span class="text-danger">*</span></label>
                                    <input type="text" name="nomor_resi" class="form-control modern-input text-uppercase" placeholder="Input nomor resi dari kurir..." required>
                                </div>
                                <div class="col-md-12">
                                    <label class="modern-label">Upload Foto Bukti Resi <span class="text-danger">*</span></label>
                                    <input type="file" name="foto_resi" class="form-control modern-input" accept=".jpg,.jpeg,.png,.webp" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between mb-5">
                        <button type="button" id="btnBackToStep1" class="btn btn-modern-outline"><i class="bi bi-arrow-left me-2"></i> Kembali</button>
                        <button type="submit" class="btn btn-modern-primary btn-lg" id="btnSimpanPengirimanUser">
                            <span class="btn-text"><i class="bi bi-send-fill me-2"></i> Kirim Barang Sekarang</span>
                            <span class="btn-loading d-none">
                                <span class="spinner-border spinner-border-sm me-2"></span> Menyimpan...
                            </span>
                        </button>
                    </div>
                </div>

            <?php elseif ($sedangDikirim): ?>
                <!-- FORM PENERIMAAN -->
                <input type="hidden" name="form_type" value="penerimaan">
                <div class="modern-card border-warning">
                    <div class="modern-card-header bg-warning text-dark bg-opacity-10">
                        <i class="bi bi-box-seam me-2"></i> Konfirmasi Penerimaan Barang
                    </div>
                    <div class="modern-card-body">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="modern-label">Tanggal Diterima <span class="text-danger">*</span></label>
                                <input type="date" name="tanggal_diterima" class="form-control modern-input" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="modern-label">Nama Penerima Paket <span class="text-danger">*</span></label>
                                <input type="text" name="nama_penerima" class="form-control modern-input text-uppercase" placeholder="Nama yang menerima barang..." required>
                            </div>
                            <div class="col-md-12">
                                <label class="modern-label">Upload Foto Barang Diterima <span class="text-danger">*</span></label>
                                <input type="file" name="foto_barang_diterima" class="form-control modern-input" accept=".jpg,.jpeg,.png,.webp" required>
                            </div>
                        </div>
                        <div class="mt-4 text-end">
                            <button type="submit" class="btn btn-success btn-lg" style="border-radius:8px; font-weight:600;">
                                <i class="bi bi-check-circle me-2"></i> Konfirmasi Selesai
                            </button>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <div class="alert alert-success d-flex align-items-center" style="border-radius: 12px; border: 1px solid #a7f3d0; background-color: #ecfdf5; color: #065f46;">
                    <i class="bi bi-check-circle-fill fs-4 me-3"></i>
                    <div>
                        <div class="fw-bold">Proses Logistik Selesai</div>
                        <small>Status pengiriman saat ini: <?= strtoupper(h($pengirimanTerakhir['status_pengiriman'])) ?></small>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </form>

    <script>
        function validateReceiptStep() {
            const qty = parseInt($('#receipt_qty').val(), 10);
            const ekspedisi = $('#receipt_ekspedisi').val().trim();

            if (!qty || qty < 1) {
                Swal.fire('Peringatan', 'Jumlah (Qty) pada baris pertama harus minimal 1.', 'warning');
                return false;
            }
            if (!ekspedisi) {
                Swal.fire('Peringatan', 'Silakan pilih Ekspedisi terlebih dahulu.', 'warning');
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
                    $('#receipt_penerima').val(found.username.toUpperCase());
                    $('#receipt_penerima_branch').val(found.nama_branch.toUpperCase());
                    $('#receipt_penerima_hidden').val(found.username.toUpperCase());
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
                
                // Auto-fill Jasa Pengiriman ke Step 2
                const ekspedisiTerpilih = $('#receipt_ekspedisi').val();
                $('input[name="jasa_pengiriman"]').val(ekspedisiTerpilih);

                // UI Changes
                $('#logistikStep1').hide();
                $('#logistikStep2').show();
                
                $('#stepLabel1').removeClass('step-active').addClass('step-done');
                $('#stepLabel2').removeClass('step-pending').addClass('step-active');
            });

            $('#btnBackToStep1').on('click', function() {
                $('#logistikStep2').hide();
                $('#logistikStep1').show();
                
                $('#stepLabel1').removeClass('step-done').addClass('step-active');
                $('#stepLabel2').removeClass('step-active').addClass('step-pending');
            });

            function renumberRows() {
                $('#receipt_table tbody tr').each(function(i) {
                    $(this).find('.row-no').text(i + 1);
                });
            }

            function addRow(desc = '', host = '') {
                const $tr = $('<tr>');
                $tr.append('<td class="text-center align-middle row-no fw-bold text-muted"></td>');
                $tr.append('<td><input type="text" class="table-input row-desc text-uppercase" placeholder="Deskripsi barang..." value="' + $('<div/>').text(desc).html() + '"></td>');
                $tr.append('<td><input type="text" class="table-input row-host text-uppercase" placeholder="Hostname / SN..." value="' + $('<div/>').text(host).html() + '"></td>');
                $tr.append('<td><input type="number" class="table-input text-center fw-bold" value="1" min="1"></td>');
                $tr.append('<td><input type="text" class="table-input text-uppercase" placeholder="Catatan opsional..."></td>');
                $tr.append('<td class="text-center align-middle"><button type="button" class="btn-remove-row-modern btn-remove-row" title="Hapus baris"><i class="bi bi-trash"></i></button></td>');
                $('#receipt_table tbody').append($tr);
                renumberRows();
                $tr.find('.row-desc').focus();
            }

            $('#btnAddRow').on('click', function() {
                addRow();
            });

            $(document).on('click', '.btn-remove-row', function() {
                if ($('#receipt_table tbody tr').length <= 1) {
                    $(this).closest('tr').find('input').val('');
                } else {
                    $(this).closest('tr').remove();
                    renumberRows();
                }
            });

            $(document).on('keydown', '.row-desc', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    addRow();
                }
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
                        url: 'ajax_dropdown.php',
                        type: 'POST',
                        data: {
                            action: 'get_merk',
                            id_barang: id_barang
                        },
                        success: function(response) {
                            $('#update_id_merk').html(response).trigger('change');
                        }
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
                        url: 'ajax_dropdown.php',
                        type: 'POST',
                        data: {
                            action: 'get_tipe',
                            id_barang: id_barang,
                            id_merk: id_merk
                        },
                        success: function(response) {
                            $('#update_id_tipe').html(response).trigger('change');
                        }
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

        // Log activity for receiving
        log_activity($koneksi, 'receive_barang', "Terima barang - Resi: {$pengirimanTerakhir['nomor_resi_keluar']}, Dari: {$pengirimanTerakhir['branch_asal']}, Tanggal: {$tanggalDiterima}, Penerima: {$namaPenerima}", [
            'id_pengiriman' => $idPeng,
            'id_barang' => $id,
            'nomor_resi' => $pengirimanTerakhir['nomor_resi_keluar'],
            'branch_asal' => $pengirimanTerakhir['branch_asal'],
            'branch_tujuan' => $branchTujuan,
            'nama_penerima' => $namaPenerima
        ]);

        jsonSuccess("Penerimaan barang berhasil dikonfirmasi.");
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        jsonError($e->getMessage());
    }
} elseif ($formType === 'master') {
    $tanggalTerima = $_POST['tanggal_terima'] ?? '';
    if ($tanggalTerima !== '' && !is_date_within_back_tolerance($tanggalTerima, 3)) {
        jsonError('Tanggal terima hanya boleh diisi mulai 3 hari sebelum hari ini.');
    }

    $userName = trim((string) ($_POST['user'] ?? ''));
    $nameError = validate_person_name($userName);
    if ($nameError !== null) {
        jsonError($nameError);
    }

    $noAsset       = trim((string) $_POST['no_asset']);
    $serialNumber  = trim((string) $_POST['serial_number']);
    $duplicate     = validateDuplicateBarang($koneksi, $id, $noAsset, $serialNumber, (int) $_POST['id_barang']);
    if ($duplicate) jsonError($duplicate);

    $upload    = uploadImage('foto');
    $fotoBaru  = $upload['status'] === 'success' ? $upload['filename'] : null;

    $keteranganMasalah = ($_POST['bermasalah'] === 'Iya') ? ($_POST['keterangan_masalah'] ?? null) : null;
    $user_id_sistem    = (int) current_user_id();
    $tanggalPembelian = trim((string) ($_POST['tanggal_pembelian'] ?? ''));
    if ($tanggalPembelian === '') {
        $tanggalPembelian = $tanggalTerima;
    }
    $masaGaransiBulan = normalize_warranty_months($_POST['masa_garansi_bulan'] ?? 12);
    $tanggalGaransiBerakhir = compute_warranty_end_date($tanggalPembelian, $masaGaransiBulan);

    mysqli_begin_transaction($koneksi);
    try {
        $stmt = mysqli_prepare($koneksi, "UPDATE barang SET no_asset=?, serial_number=?, id_barang=?, id_merk=?, id_tipe=?, id_jenis=?, id_branch=?, user=?, user_id=?, bermasalah=?, keterangan_masalah=?, tanggal_terima=?, tanggal_pembelian=?, masa_garansi_bulan=?, tanggal_garansi_berakhir=?, foto=COALESCE(?, foto) WHERE id=?");
        
        mysqli_stmt_bind_param(
            $stmt,
            'ssiiiiisisssisssi',
            $noAsset,
            $serialNumber,
            $_POST['id_barang'],
            $_POST['id_merk'],
            $_POST['id_tipe'],
            $_POST['id_jenis'],
            $_POST['id_branch'],
            $_POST['user'],
            $user_id_sistem,
            $_POST['bermasalah'],
            $keteranganMasalah,
            $tanggalTerima,
            $tanggalPembelian,
            $masaGaransiBulan,
            $tanggalGaransiBerakhir,
            $fotoBaru,
            $id
        );
        mysqli_stmt_execute($stmt);
        mysqli_commit($koneksi);

        // Log activity
        log_activity($koneksi, 'update_barang', "Edit barang - Serial: {$serialNumber}, No Asset: {$noAsset}, Cabang: {$_POST['id_branch']}", [
            'id_barang' => $id,
            'serial_number' => $serialNumber,
            'no_asset' => $noAsset,
            'id_branch' => $_POST['id_branch'],
            'bermasalah' => $_POST['bermasalah']
        ]);

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
            $stmt,
            'iiissssss',
            $id,
            $asal,
            $_POST['tujuan'],
            $_POST['tanggal_keluar'],
            $_POST['jasa_pengiriman'],
            $_POST['nomor_resi'],
            $upload['filename'],
            $st,
            $namaPenerimaKirim
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

        // Log activity for shipping
        log_activity($koneksi, 'send_barang', "Kirim barang - Resi: {$_POST['nomor_resi']}, Dari: {$asal}, Tujuan: {$_POST['tujuan']}, Jasa: {$_POST['jasa_pengiriman']}", [
            'id_barang' => $id,
            'nomor_resi' => $_POST['nomor_resi'],
            'branch_asal' => $asal,
            'branch_tujuan' => $_POST['tujuan'],
            'jasa_pengiriman' => $_POST['jasa_pengiriman']
        ]);

        jsonSuccess("Pengiriman berhasil dibuat.");
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        jsonError($e->getMessage());
    }
}