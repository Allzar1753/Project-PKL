<?php
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

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $filename = uniqid($fieldName . "_", true) . "." . $ext;

    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetDir . $filename)) {
        return ['status' => 'error', 'message' => "Gagal upload file {$fieldName}"];
    }

    return ['status' => 'success', 'filename' => $filename];
}

function jsonResponse(string $status, string $message): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'message' => $message
    ]);
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
        "SELECT
            bp.*,
            asal.nama_branch AS nama_branch_asal,
            tujuan.nama_branch AS nama_branch_tujuan
         FROM barang_pengiriman bp
         LEFT JOIN tb_branch asal ON bp.branch_asal = asal.id_branch
         LEFT JOIN tb_branch tujuan ON bp.branch_tujuan = tujuan.id_branch
         WHERE bp.id_barang = ?
         ORDER BY bp.id_pengiriman DESC
         LIMIT 1"
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
    if (!$query) {
        return $rows;
    }
    while ($row = mysqli_fetch_assoc($query)) {
        $rows[] = $row;
    }
    return $rows;
}

function ensureBarangAccess(array $barang, ?array $pengirimanTerakhir): void
{
    if (is_admin()) {
        return;
    }

    $myBranchId = (int) current_user_branch_id();
    $barangBranchId = (int) ($barang['id_branch'] ?? 0);
    $branchTujuan = (int) ($pengirimanTerakhir['branch_tujuan'] ?? 0);
    $statusPengiriman = (string) ($pengirimanTerakhir['status_pengiriman'] ?? '');

    $sedangDikirim = !empty($pengirimanTerakhir) && $statusPengiriman !== STATUS_SUDAH_DITERIMA;

    if ($barangBranchId === $myBranchId) {
        return;
    }

    if ($sedangDikirim && $branchTujuan === $myBranchId) {
        return;
    }

    http_response_code(403);
    exit('Anda tidak boleh mengakses barang cabang lain.');
}

function isSedangDikirim(?array $pengirimanTerakhir): bool
{
    if (empty($pengirimanTerakhir)) {
        return false;
    }
    return (string) ($pengirimanTerakhir['status_pengiriman'] ?? '') !== STATUS_SUDAH_DITERIMA;
}

function isSudahDiterima(?array $pengirimanTerakhir): bool
{
    if (empty($pengirimanTerakhir)) {
        return false;
    }
    return (string) ($pengirimanTerakhir['status_pengiriman'] ?? '') === STATUS_SUDAH_DITERIMA;
}

function ensureUserCanReceive(?array $pengirimanTerakhir): void
{
    if (is_admin()) {
        return;
    }

    $myBranchId = (int) current_user_branch_id();
    $branchTujuan = (int) ($pengirimanTerakhir['branch_tujuan'] ?? 0);

    if (!$pengirimanTerakhir || !isSedangDikirim($pengirimanTerakhir) || $branchTujuan !== $myBranchId) {
        http_response_code(403);
        exit('User cabang hanya boleh konfirmasi penerimaan barang untuk branch-nya sendiri.');
    }
}

function validateDuplicateBarang(mysqli $koneksi, int $id, string $noAsset, string $serialNumber): ?string
{
    $stmtCurrent = mysqli_prepare(
        $koneksi,
        "SELECT tb.nama_barang
         FROM barang b
         INNER JOIN tb_barang tb ON b.id_barang = tb.id_barang
         WHERE b.id = ?
         LIMIT 1"
    );

    mysqli_stmt_bind_param($stmtCurrent, 'i', $id);
    mysqli_stmt_execute($stmtCurrent);
    $resultCurrent = mysqli_stmt_get_result($stmtCurrent);
    $currentRow = mysqli_fetch_assoc($resultCurrent) ?: null;
    mysqli_stmt_close($stmtCurrent);

    if (!$currentRow) return "Data barang saat ini tidak ditemukan.";

    $namaBarangInput = strtolower(trim($currentRow['nama_barang']));
    $pasanganBarang = ['monitor', 'cpu'];

    $stmSerial = mysqli_prepare($koneksi, "SELECT id FROM barang WHERE serial_number = ? AND id != ? LIMIT 1");
    mysqli_stmt_bind_param($stmSerial, 'si', $serialNumber, $id);
    mysqli_stmt_execute($stmSerial);
    $resultSerial = mysqli_stmt_get_result($stmSerial);
    $serialDuplikat = mysqli_fetch_assoc($resultSerial) ?: null;
    mysqli_stmt_close($stmSerial);

    if ($serialDuplikat) return "Serial Number sudah digunakan oleh data lain.";

    $stmtAsset = mysqli_prepare(
        $koneksi,
        "SELECT b.id, b.no_asset, tb.nama_barang
        FROM barang b 
        INNER JOIN tb_barang tb ON b.id_barang = tb.id_barang 
        WHERE b.no_asset = ? AND b.id != ?"
    );
    mysqli_stmt_bind_param($stmtAsset, 'si', $noAsset, $id);
    mysqli_stmt_execute($stmtAsset);
    $resultAsset = mysqli_stmt_get_result($stmtAsset);

    $barangSudahAda = [];
    $inputTermasukPasangan = in_array($namaBarangInput, $pasanganBarang, true);

    while ($row = mysqli_fetch_assoc($resultAsset)) {
        $namaBarangDB = strtolower(trim($row['nama_barang']));
        $barangSudahAda[] = $namaBarangDB;

        if ($namaBarangDB === $namaBarangInput) {
            mysqli_stmt_close($stmtAsset);
            return "Barang tersebut sudah tersedia di daftar barang.";
        }
        if (!in_array($namaBarangDB, $pasanganBarang, true)) {
            mysqli_stmt_close($stmtAsset);
            return "No Asset sudah digunakan oleh data lain.";
        }
    }
    mysqli_stmt_close($stmtAsset);

    $barangSudahAda = array_unique($barangSudahAda);
    if (count($barangSudahAda) > 0) {
        if (!$inputTermasukPasangan) return "No Asset sudah digunakan oleh data lain.";
        if (count($barangSudahAda) >= 2) return "No Asset ini sudah dipakai untuk Monitor dan CPU.";
    }

    return null;
}

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$type = $_GET['type'] ?? 'master'; // Type dari index.php: 'master' atau 'logistik'

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

    $merkOptions = getSelectOptions($koneksi, "SELECT * FROM tb_merk ORDER BY nama_merk ASC");
    $tipeOptions = getSelectOptions($koneksi, "SELECT * FROM tb_tipe ORDER BY nama_tipe ASC");
    $jenisOptions = getSelectOptions($koneksi, "SELECT * FROM tb_jenis ORDER BY nama_jenis ASC");
    $branchOptions = getSelectOptions($koneksi, "SELECT * FROM tb_branch ORDER BY nama_branch ASC");
    $tujuanOptions = getSelectOptions($koneksi, "SELECT * FROM tb_branch ORDER BY nama_branch ASC");

    $statusPengirimanBaruOptions = [STATUS_SEDANG_DIKEMAS, STATUS_SEDANG_PERJALANAN];
    $statusPenerimaanOptions = [STATUS_SEDANG_DIKEMAS, STATUS_SEDANG_PERJALANAN, STATUS_SUDAH_DITERIMA];
?>
    <form id="formUpdate" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= (int) $barang['id'] ?>">

        <?php if ($type === 'master'): ?>
            <!-- ============================================== -->
            <!-- FORM MASTER DATA BARANG (Tombol Kuning / Edit) -->
            <!-- ============================================== -->
            <input type="hidden" name="form_type" value="master">
            <input type="hidden" name="bermasalah_awal" id="bermasalahAwal" value="<?= h($barang['bermasalah'] ?? 'Tidak') ?>">

            <?php if ($pernahDikirim): ?>
                <div class="alert alert-warning mb-3">
                    <i class="bi bi-info-circle me-1"></i> Barang ini sudah masuk logistik pengiriman. Mengedit data master harap dilakukan dengan hati-hati.
                </div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">No Asset</label>
                    <input type="text" name="no_asset" class="form-control" value="<?= h($barang['no_asset'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Serial Number</label>
                    <input type="text" name="serial_number" class="form-control" value="<?= h($barang['serial_number'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Merk</label>
                    <select name="id_merk" class="form-control select2">
                        <?php foreach ($merkOptions as $m): ?>
                            <option value="<?= (int) $m['id_merk'] ?>" <?= (int) $m['id_merk'] === (int) $barang['id_merk'] ? 'selected' : '' ?>><?= h($m['nama_merk']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tipe</label>
                    <select name="id_tipe" class="form-control select2">
                        <?php foreach ($tipeOptions as $t): ?>
                            <option value="<?= (int) $t['id_tipe'] ?>" <?= (int) $t['id_tipe'] === (int) $barang['id_tipe'] ? 'selected' : '' ?>><?= h($t['nama_tipe']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Jenis</label>
                    <select name="id_jenis" class="form-control select2">
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
                    <label class="form-label">User Pengguna</label>
                    <input type="text" name="user" class="form-control" value="<?= h($barang['user'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status Bermasalah</label>
                    <select name="bermasalah" id="bermasalahUpdate" class="form-control">
                        <option value="Tidak" <?= ($barang['bermasalah'] ?? '') === 'Tidak' ? 'selected' : '' ?>>Tidak</option>
                        <option value="Iya" <?= ($barang['bermasalah'] ?? '') === 'Iya' ? 'selected' : '' ?>>Iya</option>
                    </select>
                </div>
                <div class="col-md-12" id="keteranganMasalahWrap">
                    <label class="form-label">Keterangan Masalah</label>
                    <textarea name="keterangan_masalah" id="keteranganMasalahUpdate" class="form-control"><?= h($barang['keterangan_masalah'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tanggal Input/Kirim Master</label>
                    <input type="date" name="tanggal_kirim" class="form-control" value="<?= h($barang['tanggal_kirim'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Update Foto Barang</label>
                    <input type="file" name="foto" class="form-control">
                </div>
                <?php if (!empty($barang['foto'])): ?>
                    <div class="col-md-6">
                        <label class="form-label">Foto Barang Saat Ini</label>
                        <div>
                            <button type="button" class="btn btn-outline-secondary previewFoto" data-foto="../assets/images/<?= h($barang['foto']) ?>">
                                <i class="bi bi-image"></i> Lihat Foto
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- <script>
                (function() {
                    const bermasalahAwal = document.getElementById('bermasalahAwal');
                    const bermasalahSelect = document.getElementById('bermasalahUpdate');
                    const keteranganWrap = document.getElementById('keteranganMasalahWrap');
                    const keteranganInput = document.getElementById('keteranganMasalahUpdate');

                    if (!bermasalahAwal || !bermasalahSelect || !keteranganWrap || !keteranganInput) return;

                    function applyBermasalahState(value) {
                        if (value === 'Iya') {
                            keteranganWrap.style.display = '';
                            keteranganInput.setAttribute('required', 'required');
                        } else {
                            keteranganWrap.style.display = 'none';
                            keteranganInput.removeAttribute('required');
                        }
                    }

                    applyBermasalahState(bermasalahSelect.value);

                    bermasalahSelect.addEventListener('change', function() {
                        const nilaiAwal = bermasalahAwal.value;
                        const nilaiSekarang = this.value;

                        if (nilaiAwal === 'Iya' && nilaiSekarang === 'Tidak') {
                            Swal.fire({
                                icon: 'question',
                                title: 'Konfirmasi',
                                text: 'Barang ini sudah diperbaiki atau tidak bermasalah lagi?',
                                showCancelButton: true,
                                confirmButtonText: 'Ya, sudah',
                                cancelButtonText: 'Batal'
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    keteranganInput.value = '';
                                    applyBermasalahState('Tidak');
                                } else {
                                    bermasalahSelect.value = 'Iya';
                                    applyBermasalahState('Iya');
                                }
                            });
                            return;
                        }

                        if (nilaiSekarang === 'Iya') applyBermasalahState('Iya');
                        else {
                            keteranganInput.value = '';
                            applyBermasalahState('Tidak');
                        }
                    });
                })();
            </script> -->

        <?php elseif ($type === 'logistik'): ?>
            <!-- ============================================== -->
            <!-- FORM LOGISTIK PENGIRIMAN / PENERIMAAN          -->
            <!-- ============================================== -->
            <?php if (!$pernahDikirim): ?>

                <input type="hidden" name="form_type" value="logistik">

                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle me-1"></i> Setelah data pengiriman disimpan, barang akan menjadi <b>Barang Keluar</b> permanen.
                </div>
                <div class="row g-3">
                    <!-- Baris 1 -->
                    <div class="col-md-6">
                        <label class="form-label">Tanggal Keluar</label>
                        <input type="date" name="tanggal_keluar" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tujuan Pengiriman</label>
                        <select name="tujuan" class="form-control select2" required>
                            <option value="">-- Pilih Tujuan --</option>
                            <?php foreach ($tujuanOptions as $bt): ?>
                                <option value="<?= (int) $bt['id_branch'] ?>"><?= h($bt['nama_branch']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Baris 2 -->
                    <div class="col-md-6">
                        <label class="form-label">Jasa Pengiriman</label>
                        <select name="jasa_pengiriman" class="form-control select2" required>
                            <option value="">Pilih...</option>
                            <option value="SAPX Express">SAPX Express</option>
                            <option value="Ekspedisi 2">Ekspedisi 2 (Menyusul)</option>
                            <option value="Ekspedisi 3">Ekspedisi 3 (Menyusul)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nomor Resi Keluar</label>
                        <input type="text" name="nomor_resi" class="form-control" placeholder="Masukkan nomor resi keluar" required>
                    </div>

                    <!-- Baris 3 (Dibuat Sejajar) -->
                    <div class="col-md-6">
                        <label class="form-label">Status Pengiriman</label>
                        <input type="hidden" name="status_pengiriman_baru" value="<?= STATUS_SEDANG_PERJALANAN ?>">
                        <input type="text" class="form-control bg-light" value="<?= STATUS_SEDANG_PERJALANAN ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Foto Resi Keluar / Bukti Kirim</label>
                        <input type="file" name="foto_resi" class="form-control">
                    </div>
                </div>
                
            <?php elseif ($sedangDikirim): ?>

                <input type="hidden" name="form_type" value="penerimaan">

                <div class="alert alert-warning mb-3">
                    Barang ini <b>sedang dikirim</b>. Silakan isi form penerimaan jika barang telah sampai di cabang tujuan.
                </div>
                <div class="row g-3">
                    <div class="col-12">
                        <div class="alert alert-light border mb-2">
                            <b>Ringkasan Info Pengiriman</b><br>
                            Tujuan: <?= h($pengirimanTerakhir['nama_branch_tujuan'] ?? '-') ?><br>
                            Status Saat Ini: <?= h($pengirimanTerakhir['status_pengiriman'] ?? '-') ?><br>
                            Resi Keluar: <?= h($pengirimanTerakhir['nomor_resi_keluar'] ?? '-') ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status Penerimaan</label>
                        <input type="hidden" name="status_penerimaan" value="<?= STATUS_SUDAH_DITERIMA ?>">
                        <input type="text" class="form-control bg-light" value="<?= STATUS_SUDAH_DITERIMA ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tanggal Diterima</label>
                        <input type="date" name="tanggal_diterima" class="form-control" value="<?= h($pengirimanTerakhir['tanggal_diterima'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nama Penerima</label>
                        <input type="text" name="nama_penerima" class="form-control" placeholder="Nama penerima barang" value="<?= h($pengirimanTerakhir['nama_penerima'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nomor Resi Masuk</label>
                        <input type="text" name="nomor_resi_masuk" class="form-control" readonly value="<?= h($pengirimanTerakhir['nomor_resi_masuk'] ?? ($pengirimanTerakhir['nomor_resi_keluar'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Upload Foto Barang Diterima</label>
                        <input type="file" name="foto_barang_diterima" class="form-control">
                    </div>
                    <?php if (!empty($pengirimanTerakhir['foto_barang_diterima'])): ?>
                        <div class="col-md-6">
                            <label class="form-label">Foto Bukti Penerimaan Saat Ini</label>
                            <div>
                                <button type="button" class="btn btn-outline-secondary previewFoto" data-foto="../assets/images/<?= h($pengirimanTerakhir['foto_barang_diterima']) ?>">
                                    <i class="bi bi-image"></i> Lihat Foto
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>

                <div class="alert alert-success mb-3">
                    <i class="bi bi-check-circle-fill me-1"></i> Barang ini sudah berstatus <b>Diterima</b>. Proses logistik telah selesai.
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Tanggal Keluar</label>
                        <input type="text" class="form-control" value="<?= h($pengirimanTerakhir['tanggal_keluar'] ?? '') ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status Pengiriman</label>
                        <input type="text" class="form-control" value="<?= h($pengirimanTerakhir['status_pengiriman'] ?? '') ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tanggal Diterima</label>
                        <input type="text" class="form-control" value="<?= h($pengirimanTerakhir['tanggal_diterima'] ?? '') ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nama Penerima</label>
                        <input type="text" class="form-control" value="<?= h($pengirimanTerakhir['nama_penerima'] ?? '') ?>" readonly>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$sudahDiterima): ?>
            <div class="mt-4 text-end">
                <button type="submit" class="btn btn-warning"><i class="bi bi-save me-1"></i> Simpan Perubahan</button>
            </div>
        <?php endif; ?>
    </form>
<?php
    exit;
}

// ==========================================
// HANDLE PROSES DATA (METODE POST)
// ==========================================

$formType = $_POST['form_type'] ?? '';

// --- 1. PROSES PENERIMAAN LOGISTIK ---
if ($formType === 'penerimaan') {
    if ($sudahDiterima) jsonError("Barang yang sudah diterima tidak bisa diubah lagi.");
    require_permission($koneksi, 'barang.kirim');
    if (!is_admin()) ensureUserCanReceive($pengirimanTerakhir);

    $allowedStatus = [STATUS_SEDANG_DIKEMAS, STATUS_SEDANG_PERJALANAN, STATUS_SUDAH_DITERIMA];
    $statusPenerimaan = trim((string) ($_POST['status_penerimaan'] ?? ($pengirimanTerakhir['status_pengiriman'] ?? STATUS_SEDANG_PERJALANAN)));
    if (!in_array($statusPenerimaan, $allowedStatus, true)) $statusPenerimaan = (string) ($pengirimanTerakhir['status_pengiriman'] ?? STATUS_SEDANG_PERJALANAN);

    $tanggalDiterima = normalizeNullableString($_POST['tanggal_diterima'] ?? '');
    $namaPenerima = normalizeNullableString($_POST['nama_penerima'] ?? '');
    $nomorResiMasuk = normalizeNullableString($_POST['nomor_resi_masuk'] ?? ($pengirimanTerakhir['nomor_resi_keluar'] ?? ''));

    if ($statusPenerimaan === STATUS_SUDAH_DITERIMA) {
        if ($tanggalDiterima === null) jsonError("Tanggal diterima wajib diisi jika status sudah diterima.");
        if ($namaPenerima === null) jsonError("Nama penerima wajib diisi jika status sudah diterima.");
    }

    $uploadFotoResiMasuk = uploadImage('foto_barang_diterima');
    if ($uploadFotoResiMasuk['status'] === 'error') jsonError($uploadFotoResiMasuk['message']);
    $fotoResiMasukBaru = $uploadFotoResiMasuk['status'] === 'success' ? $uploadFotoResiMasuk['filename'] : null;

    $idStatusBarang = (($barang['bermasalah'] ?? '') === 'Iya') ? 5 : 3;
    $idBranchFinal = (int) $barang['id_branch'];

    if ($statusPenerimaan === STATUS_SUDAH_DITERIMA) {
        $idStatusBarang = (($barang['bermasalah'] ?? '') === 'Iya') ? 5 : 4;
        if ((int) ($pengirimanTerakhir['branch_tujuan'] ?? 0) > 0) {
            $idBranchFinal = (int) $pengirimanTerakhir['branch_tujuan'];
        }
    }

    mysqli_begin_transaction($koneksi);
    try {
        $stmtUpdatePengiriman = mysqli_prepare(
            $koneksi,
            "UPDATE barang_pengiriman SET status_pengiriman = ?, tanggal_diterima = ?, nama_penerima = ?, nomor_resi_masuk = ?, foto_barang_diterima = COALESCE(?, foto_barang_diterima) WHERE id_pengiriman = ?"
        );
        $idPengiriman = (int) $pengirimanTerakhir['id_pengiriman'];
        mysqli_stmt_bind_param($stmtUpdatePengiriman, 'sssssi', $statusPenerimaan, $tanggalDiterima, $namaPenerima, $nomorResiMasuk, $fotoResiMasukBaru, $idPengiriman);
        if (!mysqli_stmt_execute($stmtUpdatePengiriman)) throw new Exception("Update logistik penerimaan gagal");
        mysqli_stmt_close($stmtUpdatePengiriman);

        $stmtUpdateBarang = mysqli_prepare($koneksi, "UPDATE barang SET id_status = ?, id_branch = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmtUpdateBarang, 'iii', $idStatusBarang, $idBranchFinal, $id);
        if (!mysqli_stmt_execute($stmtUpdateBarang)) throw new Exception("Update status barang gagal");
        mysqli_stmt_close($stmtUpdateBarang);

        mysqli_commit($koneksi);
        if ($statusPenerimaan === STATUS_SUDAH_DITERIMA) jsonSuccess("Barang sudah diterima. Status logistik dikunci.");
        jsonSuccess("Logistik penerimaan berhasil diupdate.");
    } catch (Throwable $e) {
        mysqli_rollback($koneksi);
        jsonError($e->getMessage());
    }
}

// --- 2. PROSES UPDATE MASTER DATA BARANG ---
elseif ($formType === 'master') {
    if (is_user_role()) jsonError("User cabang tidak boleh mengubah data master barang.");
    require_permission($koneksi, 'barang.update');

    $noAsset = trim((string) ($_POST['no_asset'] ?? ''));
    $serialNumber = trim((string) ($_POST['serial_number'] ?? ''));
    $idMerk = (int) ($_POST['id_merk'] ?? 0);
    $idTipe = (int) ($_POST['id_tipe'] ?? 0);
    $idJenis = (int) ($_POST['id_jenis'] ?? 0);
    $idBranch = (int) ($_POST['id_branch'] ?? 0);
    $userBarang = trim((string) ($_POST['user'] ?? ''));
    $bermasalah = trim((string) ($_POST['bermasalah'] ?? 'Tidak'));
    $keteranganMasalah = normalizeNullableString($_POST['keterangan_masalah'] ?? '');
    $tanggalKirim = normalizeNullableString($_POST['tanggal_kirim'] ?? '');

    if ($bermasalah == 'Iya' && $keteranganMasalah === null) jsonError("Keterangan masalah wajib diisi jika bermasalah.");
    if ($bermasalah !== 'Iya') $keteranganMasalah = null;

    if ($noAsset === '' || $serialNumber === '') jsonError("No Asset dan Serial Number wajib diisi.");
    if ($idMerk <= 0 || $idTipe <= 0 || $idJenis <= 0 || $idBranch <= 0) jsonError("Merk, tipe, jenis, dan branch wajib dipilih.");

    $duplicateMessage = validateDuplicateBarang($koneksi, $id, $noAsset, $serialNumber);
    if ($duplicateMessage !== null) jsonError($duplicateMessage);

    $uploadFotoBarang = uploadImage('foto');
    if ($uploadFotoBarang['status'] === 'error') jsonError($uploadFotoBarang['message']);
    $fotoBarangBaru = $uploadFotoBarang['status'] === 'success' ? $uploadFotoBarang['filename'] : null;

    $idStatusBarang = ($bermasalah === 'Iya') ? 5 : 4;

    // Jika barang sudah dalam pengiriman, pertahankan status pengiriman (Status 3 = sedang dikirim)
    // Kecuali dia ubah jadi bermasalah, maka prioritas ke status 5
    if ($sedangDikirim && $bermasalah !== 'Iya') {
        $idStatusBarang = 3;
    }

    mysqli_begin_transaction($koneksi);
    try {
        $stmtUpdateBarang = mysqli_prepare(
            $koneksi,
            "UPDATE barang SET no_asset = ?, serial_number = ?, id_merk = ?, id_tipe = ?, id_jenis = ?, id_status = ?, id_branch = ?, `user` = ?, bermasalah = ?, keterangan_masalah = ?, tanggal_kirim = ?, foto = COALESCE(?, foto) WHERE id = ?"
        );
        mysqli_stmt_bind_param($stmtUpdateBarang, 'ssiiiiisssssi', $noAsset, $serialNumber, $idMerk, $idTipe, $idJenis, $idStatusBarang, $idBranch, $userBarang, $bermasalah, $keteranganMasalah, $tanggalKirim, $fotoBarangBaru, $id);

        if (!mysqli_stmt_execute($stmtUpdateBarang)) throw new Exception("Update barang gagal.");
        mysqli_stmt_close($stmtUpdateBarang);

        mysqli_commit($koneksi);
        jsonSuccess("Data master barang berhasil diupdate.");
    } catch (Throwable $e) {
        mysqli_rollback($koneksi);
        jsonError($e->getMessage());
    }
}

// --- 3. PROSES PEMBUATAN LOGISTIK PENGIRIMAN AWAL ---
elseif ($formType === 'logistik') {
    require_permission($koneksi, 'barang.kirim');

    if (($barang['bermasalah'] ?? '') === 'Iya') {
        jsonError("Barang berstatus bermasalah dan tidak boleh dikirim dari sistem.");
    }

    $tanggalKeluar = normalizeNullableString($_POST['tanggal_keluar'] ?? '');
    $tujuan = isset($_POST['tujuan']) && trim((string) $_POST['tujuan']) !== '' ? (int) $_POST['tujuan'] : 0;
    $jasaPengiriman = normalizeNullableString($_POST['jasa_pengiriman'] ?? '');
    $allowedJasa = ['SAPX Express', 'Ekspedisi 2', 'Ekspedisi 3'];
    if (!in_array($jasaPengiriman, $allowedJasa, true)) {
        jsonError("Jasa pengiriman belum dipilih.");
    }



    $nomorResiKeluar = normalizeNullableString($_POST['nomor_resi'] ?? '');
    $statusPengirimanBaru = trim((string) ($_POST['status_pengiriman_baru'] ?? STATUS_SEDANG_DIKEMAS));

    if (!in_array($statusPengirimanBaru, [STATUS_SEDANG_DIKEMAS, STATUS_SEDANG_PERJALANAN], true)) {
        $statusPengirimanBaru = STATUS_SEDANG_DIKEMAS;
    }

    if ($tanggalKeluar === null) jsonError("Tanggal keluar wajib diisi.");
    if ($tujuan <= 0) jsonError("Tujuan pengiriman wajib dipilih.");

    $uploadFotoResiKeluar = uploadImage('foto_resi');
    if ($uploadFotoResiKeluar['status'] === 'error') jsonError($uploadFotoResiKeluar['message']);
    $fotoResiKeluarBaru = $uploadFotoResiKeluar['status'] === 'success' ? $uploadFotoResiKeluar['filename'] : null;

    mysqli_begin_transaction($koneksi);
    try {
        $branchAsalTransaksi = (int) $barang['id_branch'];
        $dibuatOleh = (int) current_user_id();

        $stmtInsertPengiriman = mysqli_prepare(
            $koneksi,
            "INSERT INTO barang_pengiriman (id_barang, branch_asal, branch_tujuan, tanggal_keluar, jasa_pengiriman, nomor_resi_keluar, foto_resi_keluar, status_pengiriman, dibuat_oleh) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($stmtInsertPengiriman, 'iiisssssi', $id, $branchAsalTransaksi, $tujuan, $tanggalKeluar, $jasaPengiriman, $nomorResiKeluar, $fotoResiKeluarBaru, $statusPengirimanBaru, $dibuatOleh);

        if (!mysqli_stmt_execute($stmtInsertPengiriman)) throw new Exception("Simpan logistik gagal.");
        mysqli_stmt_close($stmtInsertPengiriman);

        // Update status master barang menjadi "Sedang Dikirim" (id_status = 3)
        $idStatusBarang = 3;
        $stmtUpdateBarang = mysqli_prepare($koneksi, "UPDATE barang SET id_status = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmtUpdateBarang, 'ii', $idStatusBarang, $id);
        mysqli_stmt_execute($stmtUpdateBarang);
        mysqli_stmt_close($stmtUpdateBarang);

        mysqli_commit($koneksi);
        jsonSuccess("Logistik pengiriman berhasil dibuat. Barang sekarang berada di proses pengiriman.");
    } catch (Throwable $e) {
        mysqli_rollback($koneksi);
        jsonError($e->getMessage());
    }
}

// Jika tidak ada parameter form_type yang valid
else {
    jsonError("Permintaan tidak dapat diproses (Form Type Tidak Dikenali).");
}
