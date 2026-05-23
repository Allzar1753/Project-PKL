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
    // 1. Cek duplikat Serial Number (Kecuali ID milik sendiri)
    $stmSerial = mysqli_prepare($koneksi, "SELECT id FROM barang WHERE serial_number = ? AND id != ? LIMIT 1");
    mysqli_stmt_bind_param($stmSerial, 'si', $serialNumber, $id);
    mysqli_stmt_execute($stmSerial);
    $resultSerial = mysqli_stmt_get_result($stmSerial);
    $serialDuplikat = mysqli_fetch_assoc($resultSerial) ?: null;
    mysqli_stmt_close($stmSerial);

    if ($serialDuplikat) return "Serial Number sudah digunakan oleh data lain.";

    // 2. Cek duplikat No Asset (Logika Pasangan CPU & Monitor)
    if (!empty($noAsset)) {
        // Ambil nama barang yang sedang diinput
        $qInput = mysqli_query($koneksi, "SELECT nama_barang FROM tb_barang WHERE id_barang = $id_barang_input LIMIT 1");
        $rowInput = mysqli_fetch_assoc($qInput);
        if (!$rowInput) return "Kategori Master Barang tidak ditemukan.";
        $namaInput = strtolower(trim($rowInput['nama_barang']));

        $pasanganDiizinkan = ['monitor', 'cpu'];
        $inputAdalahPasangan = in_array($namaInput, $pasanganDiizinkan, true);

        // Cari No Asset yang sama di database (Kecuali ID milik sendiri)
        $stmtAsset = mysqli_prepare($koneksi, "SELECT b.id, tb.nama_barang FROM barang b INNER JOIN tb_barang tb ON b.id_barang = tb.id_barang WHERE b.no_asset = ? AND b.id != ?");
        mysqli_stmt_bind_param($stmtAsset, 'si', $noAsset, $id);
        mysqli_stmt_execute($stmtAsset);
        $resultAsset = mysqli_stmt_get_result($stmtAsset);

        $barangSudahAda = [];
        while ($row = mysqli_fetch_assoc($resultAsset)) {
            $namaDb = strtolower(trim($row['nama_barang']));
            $barangSudahAda[] = $namaDb;

            // ATURAN 1: Jika No Asset dipakai barang selain CPU/Monitor (Misal: Notebook)
            if (!in_array($namaDb, $pasanganDiizinkan, true)) {
                mysqli_stmt_close($stmtAsset);
                return "No Asset '$noAsset' sudah digunakan secara eksklusif oleh perangkat " . ucwords($namaDb);
            }
            // ATURAN 2: Jika No Asset dipakai CPU, dan mau diinput CPU lagi (Bentrokan sesama jenis)
            if ($namaDb === $namaInput) {
                mysqli_stmt_close($stmtAsset);
                return "No Asset '$noAsset' Sudah terdaftar " . ucwords($namaInput);
            }
        }
        mysqli_stmt_close($stmtAsset);

        if (count($barangSudahAda) > 0) {
            // ATURAN 3: Jika No Asset dipakai CPU, tapi mau diinput Notebook
            if (!$inputAdalahPasangan) return "No Asset '$noAsset' khusus digunakan untuk pasangan CPU & Monitor.";

            // ATURAN 4: Jika No Asset sudah terisi 2 barang (CPU dan Monitor sudah lengkap)
            if (count($barangSudahAda) >= 2) return "No Asset '$noAsset' ini sudah lengkap terisi oleh (Monitor & CPU).";
        }
    }
    return null; // Lolos semua validasi
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

    // Load Data Kategori Barang
    $barangOptions = getSelectOptions($koneksi, "SELECT * FROM tb_barang ORDER BY nama_barang ASC");

    // Pre-load data Merk & Tipe sesuai ID barang yang sedang di-edit
    $currentIdBarang = (int)$barang['id_barang'];
    $merkOptions = getSelectOptions($koneksi, "SELECT DISTINCT m.id_merk, m.nama_merk FROM tb_tipe t JOIN tb_merk m ON t.id_merk = m.id_merk WHERE t.id_barang = $currentIdBarang ORDER BY m.nama_merk ASC");

    $currentIdMerk = (int)$barang['id_merk'];
    $tipeOptions = getSelectOptions($koneksi, "SELECT id_tipe, nama_tipe FROM tb_tipe WHERE id_barang = $currentIdBarang AND id_merk = $currentIdMerk ORDER BY nama_tipe ASC");

    $jenisOptions = getSelectOptions($koneksi, "SELECT * FROM tb_jenis ORDER BY nama_jenis ASC");
    $branchOptions = getSelectOptions($koneksi, "SELECT * FROM tb_branch ORDER BY nama_branch ASC");
    $tujuanOptions = getSelectOptions($koneksi, "SELECT * FROM tb_branch ORDER BY nama_branch ASC");
    $ekspedisiOptions = getSelectOptions($koneksi, "SELECT * FROM tb_ekspedisi ORDER BY nama_ekspedisi ASC");
?>
    <form id="formUpdate" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= (int) $barang['id'] ?>">

        <?php if ($type === 'master'): ?>
            <input type="hidden" name="form_type" value="master">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">No Asset</label>
                    <input type="text" name="no_asset" class="form-control" value="<?= h($barang['no_asset'] ?? '') ?>" placeholder="Boleh dikosongkan">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Serial Number<span class="text-danger">*</span></label>
                    <input type="text" name="serial_number" class="form-control" value="<?= h($barang['serial_number'] ?? '') ?>" placeholder="Wajib diisi!" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Barang<span class="text-danger">*</span></label>
                    <select name="id_barang" id="update_id_barang" class="form-control select2" required>
                        <option value="">Pilih Barang...</option>
                        <?php foreach ($barangOptions as $b): ?>
                            <option value="<?= (int) $b['id_barang'] ?>" <?= (int) $b['id_barang'] === (int) $barang['id_barang'] ? 'selected' : '' ?>><?= h($b['nama_barang']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Merk<span class="text-danger">*</span></label>
                    <select name="id_merk" id="update_id_merk" class="form-control select2" required>
                        <option value="">Pilih Merk...</option>
                        <?php foreach ($merkOptions as $m): ?>
                            <option value="<?= (int) $m['id_merk'] ?>" <?= (int) $m['id_merk'] === (int) $barang['id_merk'] ? 'selected' : '' ?>><?= h($m['nama_merk']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Tipe<span class="text-danger">*</span></label>
                    <select name="id_tipe" id="update_id_tipe" class="form-control select2" required>
                        <option value="">Pilih Tipe...</option>
                        <?php foreach ($tipeOptions as $t): ?>
                            <option value="<?= (int) $t['id_tipe'] ?>" <?= (int) $t['id_tipe'] === (int) $barang['id_tipe'] ? 'selected' : '' ?>><?= h($t['nama_tipe']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Jenis<span class="text-danger">*</span></label>
                    <select name="id_jenis" class="form-control select2" required>
                        <option value="">Pilih Jenis...</option>
                        <?php foreach ($jenisOptions as $j): ?>
                            <option value="<?= (int) $j['id_jenis'] ?>" <?= (int) $j['id_jenis'] === (int) $barang['id_jenis'] ? 'selected' : '' ?>><?= h($j['nama_jenis']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Branch Lokasi Saat Ini</label>
                    <select name="id_branch" class="form-control select2">
                        <?php foreach ($branchOptions as $b): ?>
                            <option value="<?= (int) $b['id_branch'] ?>" <?= (int) $b['id_branch'] === (int) $barang['id_branch'] ? 'selected' : '' ?>><?= h($b['nama_branch']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">User Pengguna<span class="text-danger">*</span></label>
                    <input type="text" name="user" class="form-control" value="<?= h($barang['user'] ?? '') ?>" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Status Bermasalah<span class="text-danger">*</span></label>
                    <select name="bermasalah" id="updateBermasalahSelect" class="form-control" required>
                        <option value="Tidak" <?= ($barang['bermasalah'] ?? '') === 'Tidak' ? 'selected' : '' ?>>Tidak</option>
                        <option value="Iya" <?= ($barang['bermasalah'] ?? '') === 'Iya' ? 'selected' : '' ?>>Iya</option>
                    </select>
                </div>

                <div class="col-md-6" id="updateKeteranganMasalahDiv" style="<?= ($barang['bermasalah'] === 'Iya') ? '' : 'display:none;' ?>">
                    <label class="form-label">Keterangan Masalah</label>
                    <textarea name="keterangan_masalah" class="form-control" placeholder="Jelaskan masalah barang"><?= h($barang['keterangan_masalah'] ?? '') ?></textarea>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Tanggal Terima</label>
                    <input type="date" name="tanggal_terima" class="form-control" value="<?= h($barang['tanggal_terima'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Update Foto Barang</label>
                    <input type="file" name="foto" class="form-control">
                </div>
            </div>

            <div class="mt-4 text-end">
                <button type="submit" class="btn btn-warning"><i class="bi bi-save me-1"></i> Simpan Perubahan</button>
            </div>

        <?php elseif ($type === 'logistik'): ?>
            <?php if (!$pernahDikirim): ?>
                <div class="mb-4">
                    <div class="d-flex gap-2 flex-wrap">
                        <span id="stepLabel1" class="badge rounded-pill bg-primary">1. Surat Pengiriman</span>
                        <span id="stepLabel2" class="badge rounded-pill bg-secondary text-white">2. Form Pengiriman</span>
                    </div>
                </div>

                <div id="logistikStep1">
                    <div class="alert alert-info">Isi data surat pengiriman terlebih dahulu, lalu cetak/download PDF. Setelah itu lanjut ke form pengiriman ke cabang.</div>
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
                                            <th style="width:6%;"></th>
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
                            <input type="number" id="receipt_qty" class="form-control" value="1" min="1" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Catatan</label>
                            <input type="text" id="receipt_catatan" class="form-control" placeholder="Catatan singkat untuk surat pengiriman">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ATTN</label>
                            <input type="text" id="receipt_attn" class="form-control" placeholder="ATTN tujuan" value="<?= h($barang['user'] ?: '') ?>">
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
                            <input type="text" id="receipt_user" class="form-control" placeholder="User cabang / nama pemakai" value="<?= h($barang['user'] ?: '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Penerima</label>
                            <input type="text" id="receipt_penerima" class="form-control" placeholder="Nama penerima" value="<?= h($barang['user'] ?: '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ekspedisi<span class="text-danger">*</span></label>
                            <select id="receipt_ekspedisi" class="form-control select2" required>
                                <option value="">Pilih Ekspedisi...</option>
                                <?php foreach ($ekspedisiOptions as $ex): ?>
                                    <option value="<?= h($ex['nama_ekspedisi']) ?>"><?= h($ex['nama_ekspedisi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Pengirim</label>
                            <input type="text" id="receipt_pengirim" class="form-control" placeholder="Nama pengirim" value="<?= h(current_user()['username'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mt-4 text-end">
                        <button type="button" id="btnGeneratePdf" class="btn btn-primary"><i class="bi bi-file-earmark-pdf-fill me-1"></i> Download PDF & Lanjutkan</button>
                    </div>
                </div>

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

                    <div class="alert alert-secondary">Form pengiriman cabang hanya muncul setelah surat pengiriman berhasil dibuat / ditampilkan.</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Tanggal Keluar<span class="text-danger">*</span></label>
                            <input type="date" name="tanggal_keluar" class="form-control" required min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tujuan Pengiriman<span class="text-danger">*</span></label>
                            <select name="tujuan" class="form-control select2" required>
                                <option value="">-- Pilih Tujuan --</option>
                                <?php foreach ($tujuanOptions as $bt): ?>
                                    <option value="<?= (int) $bt['id_branch'] ?>"><?= h($bt['nama_branch']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Jasa Pengiriman<span class="text-danger">*</span></label>
                            <select name="jasa_pengiriman" class="form-control select2" required>
                                <option value="">Pilih...</option>
                                <option value="SAP Express">SAP Express</option>
                                <option value="PCP Express">PCP Express</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nomor Resi Keluar<span class="text-danger">*</span></label>
                            <input type="text" name="nomor_resi" class="form-control" placeholder="Masukkan nomor resi keluar" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Foto Resi Keluar / Bukti Kirim<span class="text-danger">*</span></label>
                            <input type="file" name="foto_resi" class="form-control" required>
                        </div>
                    </div>
                    <div class="mt-4 text-end">
                        <button type="button" id="btnBackToStep1" class="btn btn-outline-secondary me-2"><i class="bi bi-arrow-left me-1"></i> Kembali</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Kirim Logistik</button>
                    </div>
                </div>

            <?php elseif ($sedangDikirim): ?>
                <input type="hidden" name="form_type" value="penerimaan">
                <div class="alert alert-warning mb-3">Barang sedang dikirim. Isi form di bawah untuk konfirmasi penerimaan.</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Tanggal Diterima<span class="text-danger">*</span></label>
                        <input type="date" name="tanggal_diterima" class="form-control" required min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nama Penerima<span class="text-danger">*</span></label>
                        <input type="text" name="nama_penerima" class="form-control" placeholder="Nama penerima barang" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Upload Foto Barang Diterima<span class="text-danger">*</span></label>
                        <input type="file" name="foto_barang_diterima" class="form-control" required>
                    </div>
                </div>

                <div class="mt-4 text-end">
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i> Konfirmasi Terima</button>
                </div>

            <?php else: ?>
                <div class="alert alert-success">Barang sudah diterima. Proses logistik selesai.</div>
            <?php endif; ?>
        <?php endif; ?>
    </form>

    <script>
        function validateReceiptStep() {
            const qty = parseInt($('#receipt_qty').val(), 10);
            const penerima = $('#receipt_penerima').val().trim();
            const ekspedisi = $('#receipt_ekspedisi').val().trim();
            const pengirim = $('#receipt_pengirim').val().trim();
            const userCabang = $('#receipt_user').val().trim();
            let hasDescription = false;

            $('#receipt_table tbody tr').each(function() {
                const desc = $(this).find('.row-desc').val().trim();
                if (desc) {
                    hasDescription = true;
                }
            });

            if (!qty || qty < 1) {
                Swal.fire('Validasi', 'Qty harus minimal 1.', 'warning');
                return false;
            }
            if (!hasDescription) {
                Swal.fire('Validasi', 'Deskripsi barang harus diisi.', 'warning');
                return false;
            }
            if (!userCabang) {
                Swal.fire('Validasi', 'User / Cabang harus diisi.', 'warning');
                return false;
            }
            if (!penerima) {
                Swal.fire('Validasi', 'Penerima harus diisi.', 'warning');
                return false;
            }
            if (!ekspedisi) {
                Swal.fire('Validasi', 'Ekspedisi harus diisi.', 'warning');
                return false;
            }
            if (!pengirim) {
                Swal.fire('Validasi', 'Pengirim harus diisi.', 'warning');
                return false;
            }
            return true;
        }

        function moveToStep2() {
            const descs = $('#receipt_table tbody tr').map(function() {
                return $(this).find('.row-desc').val().trim();
            }).get();
            const hosts = $('#receipt_table tbody tr').map(function() {
                return $(this).find('.row-host').val().trim();
            }).get();

            $('#receipt_description_hidden').val(descs.join('\n'));
            $('#receipt_hostname_hidden').val(hosts.join('||'));
            $('#receipt_qty_hidden').val($('#receipt_qty').val());
            $('#receipt_catatan_hidden').val($('#receipt_catatan').val());
            $('#receipt_attn_hidden').val($('#receipt_attn').val());
            $('#receipt_charge_hidden').val($('#receipt_charge').val());
            $('#receipt_penerima_hidden').val($('#receipt_penerima').val());
            $('#receipt_user_hidden').val($('#receipt_user').val());
            $('#receipt_ekspedisi_hidden').val($('#receipt_ekspedisi').val());
            $('#receipt_pengirim_hidden').val($('#receipt_pengirim').val());
            $('#pdf_generated').val('1');
            $('#logistikStep1').hide();
            $('#logistikStep2').show();
            $('#stepLabel1').removeClass('bg-primary').addClass('bg-success');
            $('#stepLabel2').removeClass('bg-secondary').addClass('bg-primary');
        }

        $(document).ready(function() {
            $('#btnGeneratePdf').on('click', function() {
                if (!validateReceiptStep()) return;
                // collect rows into arrays
                const descs = [];
                const hosts = [];
                $('#receipt_table tbody tr').each(function() {
                    descs.push($(this).find('.row-desc').val().trim());
                    hosts.push($(this).find('.row-host').val().trim());
                });

                const paramsObj = {
                    'description[]': descs,
                    'hostname[]': hosts,
                    'qty': $('#receipt_qty').val().trim(),
                    'catatan': $('#receipt_catatan').val().trim(),
                    'user': $('#receipt_user').val().trim(),
                    'attn': $('#receipt_attn').val().trim(),
                    'asuransi': $('#receipt_asuransi').val().trim(),
                    'charge': $('#receipt_charge').val().trim(),
                    'penerima': $('#receipt_penerima').val().trim(),
                    'ekspedisi': $('#receipt_ekspedisi').val().trim(),
                    'pengirim': $('#receipt_pengirim').val().trim()
                };

                const query = $.param(paramsObj);
                window.open('print_pengiriman.php?' + query, '_blank');
                // store concatenated values for the next form step
                $('#receipt_description_hidden').val(descs.join('\n'));
                $('#receipt_hostname_hidden').val(hosts.join('||'));
                $('#receipt_qty_hidden').val($('#receipt_qty').val().trim());
                $('#receipt_catatan_hidden').val($('#receipt_catatan').val().trim());
                $('#receipt_user_hidden').val($('#receipt_user').val().trim());
                $('#receipt_attn_hidden').val($('#receipt_attn').val().trim());
                $('#receipt_asuransi_hidden').val($('#receipt_asuransi').val().trim());
                $('#receipt_charge_hidden').val($('#receipt_charge').val().trim());
                $('#receipt_penerima_hidden').val($('#receipt_penerima').val().trim());
                $('#receipt_ekspedisi_hidden').val($('#receipt_ekspedisi').val().trim());
                $('#receipt_pengirim_hidden').val($('#receipt_pengirim').val().trim());
                $('#pdf_generated').val('1');
                $('#logistikStep1').hide();
                $('#logistikStep2').show();
                $('#stepLabel1').removeClass('bg-primary').addClass('bg-success');
                $('#stepLabel2').removeClass('bg-secondary').addClass('bg-primary');
            });

            $('#btnBackToStep1').on('click', function() {
                $('#logistikStep2').hide();
                $('#logistikStep1').show();
                $('#stepLabel1').removeClass('bg-success').addClass('bg-primary');
                $('#stepLabel2').removeClass('bg-primary').addClass('bg-secondary text-white');
            });

            // Dynamic rows: add / remove / numbering
            function renumberRows() {
                $('#receipt_table tbody tr').each(function(i) {
                    $(this).find('.row-no').text(i + 1);
                });
            }

            function addRow(desc = '', host = '') {
                const $tr = $('<tr>');
                $tr.append('<td class="row-no"></td>');
                $tr.append('<td><input type="text" class="form-control row-desc" placeholder="Deskripsi item" value="' + $('<div/>').text(desc).html() + '"></td>');
                $tr.append('<td><input type="text" class="form-control row-host" placeholder="Hostname (boleh kosong)" value="' + $('<div/>').text(host).html() + '"></td>');
                $tr.append('<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btn-remove-row">×</button></td>');
                $('#receipt_table tbody').append($tr);
                renumberRows();
                $tr.find('.row-desc').focus();
            }

            $('#btnAddRow').on('click', function() { addRow(); });

            $(document).on('click', '.btn-remove-row', function() {
                if ($('#receipt_table tbody tr').length <= 1) {
                    // clear fields if only one row
                    const $row = $(this).closest('tr');
                    $row.find('input').val('');
                } else {
                    $(this).closest('tr').remove();
                    renumberRows();
                }
            });

            // Enter to add row when in description input
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

            // SCRIPT AJAX DYNAMIC DROPDOWN

            // SCRIPT AJAX DYNAMIC DROPDOWN
            $(document).off('change', '#update_id_barang').on('change', '#update_id_barang', function() {
                var id_barang = $(this).val();

                $('#update_id_merk').html('<option value="">Sedang memuat... </option>').trigger('change');
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
    $upload = uploadImage('foto_barang_diterima');
    if ($upload['status'] === 'error') jsonError($upload['message']);

    mysqli_begin_transaction($koneksi);
    try {
        $stmt = mysqli_prepare($koneksi, "UPDATE barang_pengiriman SET status_pengiriman = ?, tanggal_diterima = ?, nama_penerima = ?, foto_barang_diterima = ? WHERE id_pengiriman = ?");
        $idPeng = (int)$pengirimanTerakhir['id_pengiriman'];
        $st = STATUS_SUDAH_DITERIMA;
        mysqli_stmt_bind_param($stmt, 'ssssi', $st, $tanggalDiterima, $namaPenerima, $upload['filename'], $idPeng);
        mysqli_stmt_execute($stmt);

        $branchTujuan = (int)$pengirimanTerakhir['branch_tujuan'];
        mysqli_query($koneksi, "UPDATE barang SET id_status = 4, id_branch = $branchTujuan WHERE id = $id");
        mysqli_commit($koneksi);
        jsonSuccess("Penerimaan barang berhasil.");
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        jsonError($e->getMessage());
    }
} elseif ($formType === 'master') {
    $tanggalTerima = $_POST['tanggal_terima'] ?? '';

    $noAsset = trim((string)$_POST['no_asset']);
    $serialNumber = trim((string)$_POST['serial_number']);
    $duplicate = validateDuplicateBarang($koneksi, $id, $noAsset, $serialNumber, (int)$_POST['id_barang']);
    if ($duplicate) jsonError($duplicate);

    $upload = uploadImage('foto');
    $fotoBaru = $upload['status'] === 'success' ? $upload['filename'] : null;

    $keteranganMasalah = ($_POST['bermasalah'] === 'Iya') ? ($_POST['keterangan_masalah'] ?? null) : null;
    $user_id_sistem = (int) current_user_id();

    mysqli_begin_transaction($koneksi);
    try {
        $stmt = mysqli_prepare($koneksi, "UPDATE barang SET no_asset=?, serial_number=?, id_barang=?, id_merk=?, id_tipe=?, id_jenis=?, id_branch=?, user=?, user_id=?, bermasalah=?, keterangan_masalah=?, tanggal_terima=?, foto=COALESCE(?, foto) WHERE id=?");

        mysqli_stmt_bind_param($stmt, 'ssiiiiiisssssi', $noAsset, $serialNumber, $_POST['id_barang'], $_POST['id_merk'], $_POST['id_tipe'], $_POST['id_jenis'], $_POST['id_branch'], $_POST['user'], $user_id_sistem, $_POST['bermasalah'], $keteranganMasalah, $tanggalTerima, $fotoBaru, $id);

        mysqli_stmt_execute($stmt);
        mysqli_commit($koneksi);
        jsonSuccess("Data berhasil diupdate.");
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        jsonError($e->getMessage());
    }
} elseif ($formType === 'logistik') {
    $tanggalKeluar = $_POST['tanggal_keluar'] ?? '';
    if ($tanggalKeluar < date('Y-m-d')) {
        jsonError("Tanggal keluar logistik tidak boleh tanggal lampau.");
    }
    $upload = uploadImage('foto_resi');
    if ($upload['status'] === 'error') jsonError($upload['message']);

    mysqli_begin_transaction($koneksi);
    try {
        $stmt = mysqli_prepare($koneksi, "INSERT INTO barang_pengiriman (id_barang, branch_asal, branch_tujuan, tanggal_keluar, jasa_pengiriman, nomor_resi_keluar, foto_resi_keluar, status_pengiriman) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $asal = (int)$barang['id_branch'];
        $st = STATUS_SEDANG_PERJALANAN;
        mysqli_stmt_bind_param($stmt, 'iiisssss', $id, $asal, $_POST['tujuan'], $_POST['tanggal_keluar'], $_POST['jasa_pengiriman'], $_POST['nomor_resi'], $upload['filename'], $st);
        mysqli_stmt_execute($stmt);

        mysqli_query($koneksi, "UPDATE barang SET id_status = 3 WHERE id = $id");

        $newPengirimanId = (int) mysqli_insert_id($koneksi);
        $targetBranch = (int) ($_POST['tujuan'] ?? 0);
        if ($targetBranch > 0) {
            $title = 'Pengiriman dalam perjalanan';
            $message = 'Barang dengan resi ' . mysqli_real_escape_string($koneksi, $_POST['nomor_resi']) . ' sedang dalam perjalanan ke cabang Anda.';
            $link = '../Barang/index.php?filter=masuk';
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
