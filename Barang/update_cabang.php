<?php
/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';
require_once '../config/activity_logger.php';
require_once '../config/warranty_helper.php';
require_once '../config/validation_helper.php';

// Pastikan hanya user cabang yang bisa akses
if (is_admin()) {
    exit('Akses ditolak. Gunakan form update master untuk admin.');
}

$myBranchId = (int) current_user_branch_id();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

// 1. Ambil Data Barang
$query = mysqli_query($koneksi, "SELECT * FROM barang WHERE id = $id AND id_branch = $myBranchId LIMIT 1");
$data = mysqli_fetch_assoc($query);

if (!$data) {
    exit('Data tidak ditemukan atau Anda tidak memiliki akses ke aset ini.');
}

function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ==========================================
// HANDLE PROSES UPDATE (METODE POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $no_asset      = mysqli_real_escape_string($koneksi, $_POST['no_asset'] ?? '');
    $serial_number = mysqli_real_escape_string($koneksi, $_POST['serial_number'] ?? '');
    $id_barang     = (int) ($_POST['id_barang'] ?? 0);
    $id_merk       = (int) ($_POST['id_merk'] ?? 0);
    $id_tipe       = (int) ($_POST['id_tipe'] ?? 0);
    $id_jenis      = (int) ($_POST['id_jenis'] ?? 0); // Ditambahkan sinkronisasi Jenis
    $user          = mysqli_real_escape_string($koneksi, $_POST['user'] ?? '');

    if (empty($serial_number) || $id_barang === 0 || $id_merk === 0 || $id_tipe === 0 || $id_jenis === 0 || empty($user)) {
        echo json_encode(['status' => 'error', 'message' => 'Semua field bertanda * wajib diisi!']);
        exit;
    }

    $nameError = validate_person_name(trim((string) ($_POST['user'] ?? '')));
    if ($nameError !== null) {
        echo json_encode(['status' => 'error', 'message' => $nameError]);
        exit;
    }

    // Cek Duplikat Serial Number (kecuali milik sendiri)
    $cekSerial = mysqli_query($koneksi, "SELECT id FROM barang WHERE serial_number = '$serial_number' AND id != $id LIMIT 1");
    if (mysqli_num_rows($cekSerial) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Serial Number sudah digunakan aset lain!']);
        exit;
    }

    // 2. LOGIKA KETAT PASANGAN NO ASSET (VERSI BARU)
if (!empty($no_asset)) {
    // Ambil nama barang yang sedang diinput
    $getBarangInput = mysqli_query($koneksi, "SELECT nama_barang FROM tb_barang WHERE id_barang = $id_barang LIMIT 1");
    $namaBarangInput = strtolower(trim(mysqli_fetch_assoc($getBarangInput)['nama_barang']));
    
    $pasanganDiizinkan = ['monitor', 'cpu'];
    $inputAdalahPasangan = in_array($namaBarangInput, $pasanganDiizinkan);

    // KUNCI UPDATE: Cek ke database, KECUALIKAN ID BARANG INI SENDIRI (b.id != $id)
    $cekNoAsset = mysqli_query($koneksi, "
        SELECT tb.nama_barang 
        FROM barang b 
        INNER JOIN tb_barang tb ON b.id_barang = tb.id_barang 
        WHERE b.no_asset = '$no_asset' AND b.id != $id
    ");

    // Jika No Asset sudah dipakai oleh data LAIN
    if (mysqli_num_rows($cekNoAsset) > 0) {
        $existing_items = [];
        while ($row = mysqli_fetch_assoc($cekNoAsset)) {
            $existing_items[] = strtolower(trim($row['nama_barang']));
        }

        // ATURAN 1: Jika barang yang diedit BUKAN CPU/Monitor (misal RAM)
        // Maka dia TIDAK BOLEH pakai No Asset yang sudah ada. Harus unik!
        if (!$inputAdalahPasangan) {
            jsonResponse('error', "No Asset '$no_asset' sudah digunakan oleh aset lain. Barang selain CPU & Monitor tidak boleh memiliki No Asset yang sama (Harus Unik).");
        }

        // ATURAN 2: Jika barang yang diedit ADALAH CPU/Monitor
        if ($inputAdalahPasangan) {
            
            // Cek apakah No Asset ini telanjur dipakai oleh barang Non-Pasangan (misal: Printer)
            foreach ($existing_items as $ex) {
                if (!in_array($ex, $pasanganDiizinkan)) {
                    jsonResponse('error', "No Asset '$no_asset' bentrok karena sudah digunakan oleh alat lain (" . ucwords($ex) . "). Gunakan nomor lain.");
                }
            }

            // Cek apakah alat yang identik sudah ada di data lain (Mencegah CPU gabung dengan CPU)
            if (in_array($namaBarangInput, $existing_items)) {
                jsonResponse('error', "No Asset '$no_asset' sudah terisi oleh " . strtoupper($namaBarangInput) . ".");
            }
            
            // Cek apakah kuota pasangan sudah penuh di data lain
            if (count($existing_items) >= 2) {
                jsonResponse('error', "No Asset '$no_asset' ini sudah lengkap terisi oleh Pasangan (Monitor & CPU).");
            }
        }
    }
}

    // Handle Upload Foto Baru
    $fotoSql = "";
    if (!empty($_FILES['foto']['name'])) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            echo json_encode(['status' => 'error', 'message' => 'Format foto tidak valid. Gunakan JPG, PNG, atau WEBP.']);
            exit;
        }
        if ($_FILES['foto']['size'] > 2000000) {
            echo json_encode(['status' => 'error', 'message' => 'Ukuran foto maksimal 2MB.']);
            exit;
        }

        $fotoName = uniqid('aset_upd_', true) . '.' . $ext;
        if (move_uploaded_file($_FILES['foto']['tmp_name'], "../assets/images/" . $fotoName)) {
            $fotoSql = ", foto = '$fotoName'";
        }
    }

    // Proses Garansi
    $masaGaransi = normalize_warranty_months($_POST['masa_garansi_bulan'] ?? ($data['masa_garansi_bulan'] ?? 12));
    $tanggalPembelian = mysqli_real_escape_string($koneksi, $_POST['tanggal_pembelian'] ?? ($data['tanggal_pembelian'] ?? $data['tanggal_terima'] ?? date('Y-m-d')));
    $tanggalGaransiBerakhir = compute_warranty_end_date($tanggalPembelian, $masaGaransi);
    
    $garansiSql = ", tanggal_pembelian = '$tanggalPembelian', masa_garansi_bulan = '$masaGaransi', tanggal_garansi_berakhir = " . ($tanggalGaransiBerakhir ? "'$tanggalGaransiBerakhir'" : "NULL");

    $updateQuery = "UPDATE barang SET 
        no_asset = '$no_asset',
        serial_number = '$serial_number',
        id_barang = '$id_barang',
        id_merk = '$id_merk',
        id_tipe = '$id_tipe',
        id_jenis = '$id_jenis',
        `user` = '$user'
        $fotoSql
        $garansiSql
        WHERE id = $id AND id_branch = $myBranchId";

    if (mysqli_query($koneksi, $updateQuery)) {
        log_activity($koneksi, 'update_barang', "User cabang edit barang - Serial: {$serial_number}, No Asset: {$no_asset}", [
            'id' => $id,
            'serial_number' => $serial_number,
            'no_asset' => $no_asset,
            'id_branch' => $myBranchId,
        ]);
        echo json_encode(['status' => 'success', 'message' => 'Data aset berhasil diperbarui.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Gagal update: ' . mysqli_error($koneksi)]);
    }
    exit;
}

// ==========================================
// AMBIL DATA DROPDOWN 
// ==========================================
$currIdBarang = (int) $data['id_barang'];
$currIdMerk   = (int) $data['id_merk'];

$barangs = mysqli_query($koneksi, "SELECT * FROM tb_barang ORDER BY nama_barang ASC");
$merks = mysqli_query($koneksi, "SELECT DISTINCT m.id_merk, m.nama_merk FROM tb_tipe t JOIN tb_merk m ON t.id_merk = m.id_merk WHERE t.id_barang = $currIdBarang ORDER BY m.nama_merk ASC");
$tipes = mysqli_query($koneksi, "SELECT id_tipe, nama_tipe FROM tb_tipe WHERE id_barang = $currIdBarang AND id_merk = $currIdMerk ORDER BY nama_tipe ASC");
$jenis = mysqli_query($koneksi, "SELECT * FROM tb_jenis ORDER BY nama_jenis ASC"); // Tambahan Jenis
?>

<!-- STYLE KHUSUS -->
<style>
    #formUpdateCabang .col-md-6, #formUpdateCabang .col-md-12, #formUpdateCabang .col-md-4 { margin-bottom: 1.2rem; }
    .form-control { border: 1px solid #E0E4E8; border-radius: 6px; padding: 0.5rem 0.75rem; font-size: 0.95rem; min-height: 42px; box-shadow: none !important; }
    .form-control:focus { border-color: #E64312; box-shadow: 0 0 0 0.25rem rgba(230, 67, 18, 0.1) !important; }
    .select2-container .select2-selection--single { border: 1px solid #E0E4E8 !important; border-radius: 6px !important; height: 42px !important; display: flex !important; align-items: center !important; padding: 0.2rem 0.5rem; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px !important; right: 8px !important; }
    .form-label { font-weight: 600; color: #333333; font-size: 0.88rem; margin-bottom: 0.5rem; display: block; }
    .text-danger { font-weight: bold; color: #D32F2F !important; }
    .btn-hexindo { background-color: #E64312; color: white; font-weight: 600; border: none; border-radius: 6px; padding: 0.6rem 1.5rem; transition: all 0.2s; }
    .btn-hexindo:hover { background-color: #F25C05; color: white; }
    .select2-container--open { z-index: 9999999 !important; } /* Fix dropdown tertutup modal */
</style>

<form id="formUpdateCabang" action="update_cabang.php" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="id" value="<?= $id ?>">
    
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Kode Asset</label>
            <input type="text" class="form-control bg-light" value="<?= h($data['kode_aset'] ?? '-') ?>" readonly disabled>
        </div>

        <div class="col-md-6">
            <label class="form-label">No Asset</label>
            <input type="text" name="no_asset" class="form-control" value="<?= h($data['no_asset']) ?>" placeholder="Opsional (Otomatis untuk CPU/Monitor)">
        </div>

        <div class="col-md-6">
            <label class="form-label">Serial Number / Service Tag <span class="text-danger">*</span></label>
            <input type="text" name="serial_number" class="form-control" required value="<?= h($data['serial_number']) ?>">
        </div>

        <div class="col-md-6">
            <label class="form-label">Kategori Barang <span class="text-danger">*</span></label>
            <select name="id_barang" id="cb_upd_id_barang" class="form-control select2" required>
                <option value="">Pilih Barang...</option>
                <?php mysqli_data_seek($barangs, 0); while ($b = mysqli_fetch_assoc($barangs)): ?>
                    <option value="<?= $b['id_barang'] ?>" <?= $b['id_barang'] == $data['id_barang'] ? 'selected' : '' ?>>
                        <?= h($b['nama_barang']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Merk Barang <span class="text-danger">*</span></label>
            <select name="id_merk" id="cb_upd_id_merk" class="form-control select2" required>
                <option value="">Pilih Merk...</option>
                <?php mysqli_data_seek($merks, 0); while ($m = mysqli_fetch_assoc($merks)): ?>
                    <option value="<?= $m['id_merk'] ?>" <?= $m['id_merk'] == $data['id_merk'] ? 'selected' : '' ?>>
                        <?= h($m['nama_merk']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Tipe / Spesifikasi <span class="text-danger">*</span></label>
            <select name="id_tipe" id="cb_upd_id_tipe" class="form-control select2" required>
                <option value="">Pilih Tipe...</option>
                <?php mysqli_data_seek($tipes, 0); while ($t = mysqli_fetch_assoc($tipes)): ?>
                    <option value="<?= $t['id_tipe'] ?>" <?= $t['id_tipe'] == $data['id_tipe'] ? 'selected' : '' ?>>
                        <?= h($t['nama_tipe']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <!-- TAMBAHAN: Jenis Kepemilikan -->
        <div class="col-md-6">
            <label class="form-label">Jenis Kepemilikan <span class="text-danger">*</span></label>
            <select name="id_jenis" class="form-control select2" required>
                <option value="">Pilih Jenis...</option>
                <?php mysqli_data_seek($jenis, 0); while ($j = mysqli_fetch_assoc($jenis)): ?>
                    <option value="<?= $j['id_jenis'] ?>" <?= $j['id_jenis'] == $data['id_jenis'] ? 'selected' : '' ?>>
                        <?= h($j['nama_jenis']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Nama User Pengguna Aset <span class="text-danger">*</span></label>
            <input type="text" name="user" class="form-control" required value="<?= h($data['user']) ?>">
        </div>

        <!-- TAMBAHAN: Input Garansi -->
        <div class="col-12"><hr class="my-1"><div class="fw-semibold text-muted small mb-2"><i class="bi bi-shield-check me-1"></i> Informasi Garansi Produk</div></div>

        <div class="col-md-4">
            <label class="form-label">Tanggal Pembelian</label>
            <input type="date" name="tanggal_pembelian" id="upd_tanggal_pembelian" class="form-control" value="<?= h($data['tanggal_pembelian']) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Masa Garansi</label>
            <select name="masa_garansi_bulan" id="upd_masa_garansi_bulan" class="form-control">
                <?php $m = (int)($data['masa_garansi_bulan'] ?? 12); ?>
                <option value="6" <?= $m === 6 ? 'selected' : '' ?>>6 Bulan</option>
                <option value="12" <?= $m === 12 ? 'selected' : '' ?>>12 Bulan (1 Tahun)</option>
                <option value="24" <?= $m === 24 ? 'selected' : '' ?>>24 Bulan (2 Tahun)</option>
                <option value="36" <?= $m === 36 ? 'selected' : '' ?>>36 Bulan (3 Tahun)</option>
                <option value="48" <?= $m === 48 ? 'selected' : '' ?>>48 Bulan (4 Tahun)</option>
                <option value="60" <?= $m === 60 ? 'selected' : '' ?>>60 Bulan (5 Tahun)</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Garansi Berakhir</label>
            <input type="text" id="upd_preview_garansi" class="form-control bg-light" readonly placeholder="Otomatis dihitung">
        </div>

        <!-- UPDATE FOTO -->
        <div class="col-md-12 border-top pt-3 mt-2">
            <label class="form-label">Upload Foto Fisik Barang</label>
            <input type="file" name="foto" class="form-control" id="fotoUpdateCabang" accept=".jpg,.jpeg,.png,.gif,.webp">
            <div class="mt-1 text-muted" style="font-size: 0.8rem;">Biarkan kosong jika tidak ingin mengganti foto. Format: JPG, PNG, WEBP (Max 2MB).</div>
            
            <?php if (!empty($data['foto'])): ?>
                <div class="mt-3 p-2 border rounded bg-light d-inline-block">
                    <span class="d-block mb-2 text-muted" style="font-size: 0.8rem; font-weight: 600;"><i class="bi bi-image me-1"></i> Foto Saat Ini:</span>
                    <img src="../assets/images/<?= h($data['foto']) ?>" class="rounded shadow-sm" style="height: 100px; object-fit: cover;">
                </div>
            <?php endif; ?>
            <img id="previewFotoUpdateCabang" class="shadow-sm border mt-3" style="max-height: 100px; display: none; border-radius: 8px; object-fit: cover;">
        </div>

        <div class="col-12 mt-4 pt-3 border-top text-end">
            <button type="button" class="btn btn-light border px-4 me-2 rounded-2" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-hexindo">
                <span class="btn-text"><i class="bi bi-save me-1"></i> Simpan Perubahan</span>
            </button>
        </div>
    </div>
</form>

<script>
    $(document).ready(function() {
        
        // Init Select2 untuk Modal
        if($.fn.select2) {
            $('#formUpdateCabang .select2').select2({ width: '100%', dropdownParent: $('#formUpdateCabang').parent() });
        }

        // Preview Foto Baru
        $('#fotoUpdateCabang').change(function() {
            if (!this.files || !this.files[0]) return;
            let reader = new FileReader();
            reader.onload = function(e) {
                $('#previewFotoUpdateCabang').attr('src', e.target.result).fadeIn();
            };
            reader.readAsDataURL(this.files[0]);
        });

        // Dropdown Ajax
        $(document).off('change', '#cb_upd_id_barang').on('change', '#cb_upd_id_barang', function() {
            var id_barang = $(this).val();
            $('#cb_upd_id_merk').html('<option value="">Sedang memuat...</option>').trigger('change');
            $('#cb_upd_id_tipe').html('<option value="">Pilih Merk Dulu...</option>').trigger('change');
            
            if (id_barang) {
                $.ajax({
                    url: 'ajax_dropdown.php', type: 'POST', data: { action: 'get_merk', id_barang: id_barang },
                    success: function(response) { $('#cb_upd_id_merk').html('<option value="">Pilih Merk...</option>' + response).trigger('change'); }
                });
            } else {
                $('#cb_upd_id_merk').html('<option value="">Pilih Barang Dulu...</option>').trigger('change');
            }
        });

        $(document).off('change', '#cb_upd_id_merk').on('change', '#cb_upd_id_merk', function() {
            var id_barang = $('#cb_upd_id_barang').val();
            var id_merk = $(this).val();
            $('#cb_upd_id_tipe').html('<option value="">Sedang memuat...</option>').trigger('change');
            
            if (id_merk && id_barang) {
                $.ajax({
                    url: 'ajax_dropdown.php', type: 'POST', data: { action: 'get_tipe', id_barang: id_barang, id_merk: id_merk },
                    success: function(response) { $('#cb_upd_id_tipe').html('<option value="">Pilih Tipe / Spesifikasi...</option>' + response).trigger('change'); }
                });
            } else {
                $('#cb_upd_id_tipe').html('<option value="">Pilih Merk Dulu...</option>').trigger('change');
            }
        });

        // Hitung Garansi Otomatis
        function refreshWarrantyUpdate() {
            let purchase = $('#upd_tanggal_pembelian').val();
            if (!purchase) return;
            const months = parseInt($('#upd_masa_garansi_bulan').val() || '12', 10);
            const d = new Date(purchase);
            d.setMonth(d.getMonth() + months);
            $('#upd_preview_garansi').val(d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }));
        }
        $('#upd_tanggal_pembelian, #upd_masa_garansi_bulan').on('change input', refreshWarrantyUpdate);
        refreshWarrantyUpdate(); // Hitung saat form pertama kali dibuka
    });
</script>