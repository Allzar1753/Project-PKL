<?php
include '../config/koneksi.php';
require_once '../config/auth.php';
/** @var mysqli $koneksi */
$isAdmin = is_admin();
if (!$isAdmin) {
    echo "<h1>Akses Ditolak!</h1><p>Halaman ini hanya untuk Administrator.</p>";
    exit;
}
// ==============================================================================
// 1. BACKEND: PROSES CRUD (AJAX)
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    try {
        // --- PROSES KATEGORI BARANG ---
        if ($action === 'save_barang') {
            $id = (int)($_POST['id_barang_edit'] ?? 0);
            $nama = mysqli_real_escape_string($koneksi, trim($_POST['nama_barang']));
            if (empty($nama)) throw new Exception("Nama Barang wajib diisi!");

            if ($id > 0) { // UPDATE
                mysqli_query($koneksi, "UPDATE tb_barang SET nama_barang = '$nama' WHERE id_barang = $id") ?: throw new Exception(mysqli_error($koneksi));
                echo json_encode(['status' => 'success', 'message' => 'Barang berhasil diperbarui!']);
            } else { // INSERT
                $cek = mysqli_query($koneksi, "SELECT id_barang FROM tb_barang WHERE nama_barang = '$nama'");
                if (mysqli_num_rows($cek) > 0) throw new Exception("Barang sudah ada!");
                mysqli_query($koneksi, "INSERT INTO tb_barang (nama_barang, status) VALUES ('$nama', 'Tersedia')") ?: throw new Exception(mysqli_error($koneksi));
                echo json_encode(['status' => 'success', 'message' => 'Barang baru ditambahkan!']);
            }
        }
        elseif ($action === 'delete_barang') {
            $id = (int)$_POST['id'];
            mysqli_query($koneksi, "DELETE FROM tb_barang WHERE id_barang = $id") ?: throw new Exception("Tidak bisa dihapus, barang ini sedang digunakan di tabel lain!");
            echo json_encode(['status' => 'success', 'message' => 'Barang berhasil dihapus!']);
        }

        // --- PROSES MERK ---
        elseif ($action === 'save_merk') {
            $id = (int)($_POST['id_merk_edit'] ?? 0);
            $nama = mysqli_real_escape_string($koneksi, trim($_POST['nama_merk']));
            if (empty($nama)) throw new Exception("Nama Merk wajib diisi!");

            if ($id > 0) { // UPDATE
                mysqli_query($koneksi, "UPDATE tb_merk SET nama_merk = '$nama' WHERE id_merk = $id") ?: throw new Exception(mysqli_error($koneksi));
                echo json_encode(['status' => 'success', 'message' => 'Merk berhasil diperbarui!']);
            } else { // INSERT
                $cek = mysqli_query($koneksi, "SELECT id_merk FROM tb_merk WHERE nama_merk = '$nama'");
                if (mysqli_num_rows($cek) > 0) throw new Exception("Merk sudah ada!");
                mysqli_query($koneksi, "INSERT INTO tb_merk (nama_merk) VALUES ('$nama')") ?: throw new Exception(mysqli_error($koneksi));
                echo json_encode(['status' => 'success', 'message' => 'Merk baru ditambahkan!']);
            }
        }
        elseif ($action === 'delete_merk') {
            $id = (int)$_POST['id'];
            mysqli_query($koneksi, "DELETE FROM tb_merk WHERE id_merk = $id") ?: throw new Exception("Tidak bisa dihapus, merk ini sedang digunakan di tabel lain!");
            echo json_encode(['status' => 'success', 'message' => 'Merk berhasil dihapus!']);
        }

        // --- PROSES TIPE ---
        elseif ($action === 'save_tipe') {
            $id = (int)($_POST['id_tipe_edit'] ?? 0);
            $id_b = (int)$_POST['id_barang'];
            $id_m = (int)$_POST['id_merk'];
            $nama = mysqli_real_escape_string($koneksi, trim($_POST['nama_tipe']));
            
            if (!$id_b || !$id_m || empty($nama)) throw new Exception("Semua kolom Tipe wajib diisi!");

            if ($id > 0) { // UPDATE
                mysqli_query($koneksi, "UPDATE tb_tipe SET id_barang=$id_b, id_merk=$id_m, nama_tipe='$nama' WHERE id_tipe = $id") ?: throw new Exception(mysqli_error($koneksi));
                echo json_encode(['status' => 'success', 'message' => 'Tipe berhasil diperbarui!']);
            } else { // INSERT
                $cek = mysqli_query($koneksi, "SELECT id_tipe FROM tb_tipe WHERE id_barang=$id_b AND id_merk=$id_m AND nama_tipe='$nama'");
                if (mysqli_num_rows($cek) > 0) throw new Exception("Tipe ini sudah ada untuk Barang & Merk tersebut!");
                mysqli_query($koneksi, "INSERT INTO tb_tipe (id_barang, id_merk, nama_tipe) VALUES ($id_b, $id_m, '$nama')") ?: throw new Exception(mysqli_error($koneksi));
                echo json_encode(['status' => 'success', 'message' => 'Tipe baru ditambahkan!']);
            }
        }
        elseif ($action === 'delete_tipe') {
            $id = (int)$_POST['id'];
            mysqli_query($koneksi, "DELETE FROM tb_tipe WHERE id_tipe = $id") ?: throw new Exception("Gagal menghapus tipe.");
            echo json_encode(['status' => 'success', 'message' => 'Tipe berhasil dihapus!']);
        }

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ==============================================================================
// 2. AMBIL DATA UNTUK UI
// ==============================================================================
// 1. Kategori dan Merk diurutkan rapi berdasarkan Abjad A-Z
$dBarang = mysqli_query($koneksi, "SELECT * FROM tb_barang ORDER BY nama_barang ASC");
$dMerk   = mysqli_query($koneksi, "SELECT * FROM tb_merk ORDER BY nama_merk ASC");

// 2. Tipe dikelompokkan: Barang ngumpul sesuai jenisnya -> urut merk -> urut tipe
$dTipe   = mysqli_query($koneksi, "
    SELECT t.*, b.nama_barang, m.nama_merk 
    FROM tb_tipe t 
    JOIN tb_barang b ON t.id_barang = b.id_barang 
    JOIN tb_merk m ON t.id_merk = m.id_merk 
    ORDER BY b.nama_barang ASC, m.nama_merk ASC, t.nama_tipe ASC
");
// Fetch ke array untuk re-use di dropdown
$arrBarang = []; while($b = mysqli_fetch_assoc($dBarang)) $arrBarang[] = $b; mysqli_data_seek($dBarang, 0);
$arrMerk   = []; while($m = mysqli_fetch_assoc($dMerk)) $arrMerk[] = $m; mysqli_data_seek($dMerk, 0);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Master Data - IT Asset Hexindo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root { --hex-orange: #E65100; --hex-dark: #212121; --bg-body: #F4F6F8; }
        body { background-color: var(--bg-body); font-family: 'Plus Jakarta Sans', sans-serif; }
        .page-header { background: var(--hex-dark); color: white; padding: 2rem; border-radius: 12px; margin-bottom: 2rem; border-bottom: 4px solid var(--hex-orange); }
        .nav-tabs .nav-link { color: var(--hex-dark); font-weight: 600; border: none; border-bottom: 3px solid transparent; padding: 1rem 1.5rem; }
        .nav-tabs .nav-link.active { color: var(--hex-orange); border-bottom: 3px solid var(--hex-orange); background: transparent; font-weight: 800; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .card-header-custom { background: white; border-bottom: 2px solid #f0f0f0; padding: 1.2rem; border-radius: 12px 12px 0 0; font-weight: 800; color: var(--hex-dark); }
        .btn-hexindo { background-color: var(--hex-orange); color: white; font-weight: 700; border: none; }
        .table th { background-color: #f8f9fa; color: var(--hex-dark); }
        .table-wrap { max-height: 500px; overflow-y: auto; }
        .action-btn { cursor: pointer; padding: 5px 8px; border-radius: 6px; }
        .action-btn:hover { background: #eee; }
    </style>
</head>
<body>

<div class="container-fluid p-4">
    <div class="d-flex flex-nowrap w-100 overflow-hidden">
        <?php include '../layout/sidebar.php'; ?>

        <div class="flex-grow-1" style="padding-left: 20px;">
            <div class="page-header d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold mb-1"><i class="bi bi-database-gear me-2 text-warning"></i>Kelola Master Data</h2>
                    <p class="mb-0 text-secondary" style="color: #bbb !important;">Pusat pengelolaan Kategori, Merk, dan Tipe untuk inventaris.</p>
                </div>
                <a href="index.php" class="btn btn-outline-light rounded-pill px-4"><i class="bi bi-arrow-left me-2"></i>Kembali ke Data Aset</a>
            </div>

            <!-- TABS MENU -->
            <ul class="nav nav-tabs mb-4" id="masterTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabBarang" type="button"><i class="bi bi-box me-2"></i>Kategori Barang</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabMerk" type="button"><i class="bi bi-tags me-2"></i>Merk Barang</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabTipe" type="button"><i class="bi bi-cpu me-2"></i>Tipe & Spesifikasi</button></li>
            </ul>

            <div class="tab-content" id="masterTabsContent">
                
                <!-- ================= TAB 1: BARANG ================= -->
                <div class="tab-pane fade show active" id="tabBarang">
                    <div class="row g-4">
                        <div class="col-lg-4">
                            <div class="card card-custom h-100">
                                <div class="card-header-custom"><i class="bi bi-pencil-square me-2 text-warning"></i>Form Kategori Barang</div>
                                <div class="card-body p-4">
                                    <form class="crud-form" data-action="save_barang">
                                        <input type="hidden" name="id_barang_edit" id="id_barang_edit" value="0">
                                        <div class="mb-4">
                                            <label class="form-label fw-bold">Nama Kategori <span class="text-danger">*</span></label>
                                            <input type="text" name="nama_barang" id="input_nama_barang" class="form-control" required placeholder="Cth: Monitor">
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-hexindo flex-grow-1 btn-submit"><span class="t-btn">Simpan</span></button>
                                            <button type="button" class="btn btn-light btn-cancel d-none" onclick="resetForm(this)">Batal</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-8">
                            <div class="card card-custom h-100">
                                <div class="card-header-custom">Daftar Kategori Barang</div>
                                <div class="card-body p-0 table-wrap">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="sticky-top"><tr><th class="ps-4">No</th><th>Nama Kategori</th><th class="text-center">Aksi</th></tr></thead>
                                        <tbody>
                                            <?php $no=1; while($row = mysqli_fetch_assoc($dBarang)): ?>
                                            <tr>
                                                <td class="ps-4 text-muted"><?= $no++ ?></td>
                                                <td class="fw-bold"><?= htmlspecialchars($row['nama_barang']) ?></td>
                                                <td class="text-center">
                                                    <a class="action-btn text-primary" onclick="editData('barang', <?= $row['id_barang'] ?>, '<?= htmlspecialchars($row['nama_barang']) ?>')"><i class="bi bi-pencil-fill"></i></a>
                                                    <a class="action-btn text-danger" onclick="deleteData('delete_barang', <?= $row['id_barang'] ?>)"><i class="bi bi-trash-fill"></i></a>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 2: MERK ================= -->
                <div class="tab-pane fade" id="tabMerk">
                    <div class="row g-4">
                        <div class="col-lg-4">
                            <div class="card card-custom h-100">
                                <div class="card-header-custom"><i class="bi bi-pencil-square me-2 text-warning"></i>Form Merk Barang</div>
                                <div class="card-body p-4">
                                    <form class="crud-form" data-action="save_merk">
                                        <input type="hidden" name="id_merk_edit" id="id_merk_edit" value="0">
                                        <div class="mb-4">
                                            <label class="form-label fw-bold">Nama Merk <span class="text-danger">*</span></label>
                                            <input type="text" name="nama_merk" id="input_nama_merk" class="form-control" required placeholder="Cth: Dell">
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-hexindo flex-grow-1 btn-submit"><span class="t-btn">Simpan</span></button>
                                            <button type="button" class="btn btn-light btn-cancel d-none" onclick="resetForm(this)">Batal</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-8">
                            <div class="card card-custom h-100">
                                <div class="card-header-custom">Daftar Merk Terdaftar</div>
                                <div class="card-body p-0 table-wrap">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="sticky-top"><tr><th class="ps-4">No</th><th>Nama Merk</th><th class="text-center">Aksi</th></tr></thead>
                                        <tbody>
                                            <?php $no=1; while($row = mysqli_fetch_assoc($dMerk)): ?>
                                            <tr>
                                                <td class="ps-4 text-muted"><?= $no++ ?></td>
                                                <td class="fw-bold text-primary"><?= htmlspecialchars($row['nama_merk']) ?></td>
                                                <td class="text-center">
                                                    <a class="action-btn text-primary" onclick="editData('merk', <?= $row['id_merk'] ?>, '<?= htmlspecialchars($row['nama_merk']) ?>')"><i class="bi bi-pencil-fill"></i></a>
                                                    <a class="action-btn text-danger" onclick="deleteData('delete_merk', <?= $row['id_merk'] ?>)"><i class="bi bi-trash-fill"></i></a>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ================= TAB 3: TIPE ================= -->
                <div class="tab-pane fade" id="tabTipe">
                    <div class="row g-4">
                        <div class="col-lg-4">
                            <div class="card card-custom h-100">
                                <div class="card-header-custom"><i class="bi bi-pencil-square me-2 text-warning"></i>Form Tipe Barang</div>
                                <div class="card-body p-4">
                                    <form class="crud-form" data-action="save_tipe">
                                        <input type="hidden" name="id_tipe_edit" id="id_tipe_edit" value="0">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Barang <span class="text-danger">*</span></label>
                                            <select name="id_barang" id="input_id_barang" class="form-control select2" required>
                                                <option value="">Pilih...</option>
                                                <?php foreach($arrBarang as $b): ?><option value="<?= $b['id_barang'] ?>"><?= htmlspecialchars($b['nama_barang']) ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Merk <span class="text-danger">*</span></label>
                                            <select name="id_merk" id="input_id_merk" class="form-control select2" required>
                                                <option value="">Pilih...</option>
                                                <?php foreach($arrMerk as $m): ?><option value="<?= $m['id_merk'] ?>"><?= htmlspecialchars($m['nama_merk']) ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-4">
                                            <label class="form-label fw-bold">Nama Tipe <span class="text-danger">*</span></label>
                                            <input type="text" name="nama_tipe" id="input_nama_tipe" class="form-control" required placeholder="Cth: Latitude 3440">
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-hexindo flex-grow-1 btn-submit"><span class="t-btn">Simpan</span></button>
                                            <button type="button" class="btn btn-light btn-cancel d-none" onclick="resetForm(this)">Batal</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-8">
                            <div class="card card-custom h-100">
                                <!-- Bagian Header Diubah agar ada Kotak Pencarian -->
                                <div class="card-header-custom d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <span>Daftar Spesifikasi Tipe</span>
                                    <div class="input-group input-group-sm" style="width: 250px;">
                                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                        <input type="text" id="searchTipe" class="form-control border-start-0 ps-0" placeholder="Cari nama tipe / merk...">
                                    </div>
                                </div>
                                <div class="card-body p-0 table-wrap">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="sticky-top"><tr><th class="ps-4">No</th><th>Kategori & Merk</th><th>Tipe Spesifikasi</th><th class="text-center">Aksi</th></tr></thead>
                                        <tbody>
                                            <?php $no=1; while($row = mysqli_fetch_assoc($dTipe)): ?>
                                            <tr>
                                                <td class="ps-4 text-muted"><?= $no++ ?></td>
                                                <td>
                                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($row['nama_barang']) ?></span><br>
                                                    <span class="small text-primary fw-semibold"><?= htmlspecialchars($row['nama_merk']) ?></span>
                                                </td>
                                                <td class="fw-bold"><?= htmlspecialchars($row['nama_tipe']) ?></td>
                                                <td class="text-center">
                                                    <a class="action-btn text-primary" onclick="editTipe(<?= $row['id_tipe'] ?>, <?= $row['id_barang'] ?>, <?= $row['id_merk'] ?>, '<?= htmlspecialchars($row['nama_tipe']) ?>')"><i class="bi bi-pencil-fill"></i></a>
                                                    <a class="action-btn text-danger" onclick="deleteData('delete_tipe', <?= $row['id_tipe'] ?>)"><i class="bi bi-trash-fill"></i></a>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    $(document).ready(function() {
        $('.select2').select2({ width: '100%' });

        // Menyimpan Tab yang aktif agar tidak reset saat direload
        $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
            localStorage.setItem('activeMasterTab', $(e.target).attr('data-bs-target'));
        });
        let activeTab = localStorage.getItem('activeMasterTab');
        if(activeTab) {
            var tab = new bootstrap.Tab(document.querySelector('button[data-bs-target="' + activeTab + '"]'));
            tab.show();
        }

        // ==========================================
        // PROSES SUBMIT (CREATE / UPDATE) 
        // ==========================================
        $('.crud-form').on('submit', function(e) {
            e.preventDefault();
            let form = $(this);
            let btn = form.find('.btn-submit');
            let actionName = form.data('action');
            let originalText = btn.find('.t-btn').text();

            let formData = new FormData(this);
            formData.append('action', actionName); // Sisipkan nama aksi

            // Animasi Loading
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...');

            $.ajax({
                url: 'master_data.php', type: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
                success: function(res) {
                    setTimeout(function() { // Delay 2 detik animasi
                        if (res.status === 'success') {
                            Swal.fire({ icon: 'success', title: 'Berhasil!', text: res.message, showConfirmButton: false, timer: 1500 })
                            .then(() => location.reload());
                        } else {
                            Swal.fire('Gagal', res.message, 'error');
                            btn.prop('disabled', false).html(`<span class="t-btn">${originalText}</span>`);
                        }
                    }, 2000);
                },
                error: function() {
                    setTimeout(function() {
                        Swal.fire('Error', 'Gagal server', 'error');
                        btn.prop('disabled', false).html(`<span class="t-btn">${originalText}</span>`);
                    }, 2000);
                }
            });
        });
    });

    // ==========================================
    // FUNGSI EDIT MODE
    // ==========================================
    function editData(jenis, id, nama) {
        let form = $('#tab' + jenis.charAt(0).toUpperCase() + jenis.slice(1) + ' form');
        form.find('#id_' + jenis + '_edit').val(id);
        form.find('#input_nama_' + jenis).val(nama);
        form.find('.t-btn').text('Update Data');
        form.find('.btn-hexindo').removeClass('btn-hexindo').addClass('btn-success');
        form.find('.btn-cancel').removeClass('d-none');
    }

    function editTipe(id_tipe, id_barang, id_merk, nama) {
        $('#id_tipe_edit').val(id_tipe);
        $('#input_id_barang').val(id_barang).trigger('change');
        $('#input_id_merk').val(id_merk).trigger('change');
        $('#input_nama_tipe').val(nama);
        
        let form = $('#tabTipe form');
        form.find('.t-btn').text('Update Data');
        form.find('.btn-hexindo').removeClass('btn-hexindo').addClass('btn-success');
        form.find('.btn-cancel').removeClass('d-none');
    }

    function resetForm(btn) {
        let form = $(btn).closest('form');
        form[0].reset();
        form.find('input[type="hidden"]').val('0');
        form.find('.select2').val('').trigger('change');
        form.find('.t-btn').text('Simpan');
        form.find('.btn-success').removeClass('btn-success').addClass('btn-hexindo');
        $(btn).addClass('d-none');
    }

    // ==========================================
    // FUNGSI HAPUS (DELETE)
    // ==========================================
    function deleteData(actionName, id) {
        Swal.fire({
            title: 'Hapus data ini?', text: "Data yang terhubung ke inventaris mungkin tidak bisa dihapus.", icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Ya, Hapus!'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post('master_data.php', { action: actionName, id: id }, function(res) {
                    if(res.status === 'success') {
                        Swal.fire('Dihapus!', res.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Gagal!', res.message, 'error');
                    }
                }, 'json');
            }
        });
    }

    $('#searchTipe').on('input', function() {
        var value = $(this).val().toLowerCase();
        $('#tabTipe table tbody tr').filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });                                    
</script>
</body>
</html>