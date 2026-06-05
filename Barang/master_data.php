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
// 1. BACKEND: PROSES CRUD (AJAX) - 100% TIDAK DIUBAH
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
// 2. AMBIL DATA UNTUK UI - 100% TIDAK DIUBAH
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

    <!-- CSS SINKRONISASI HEXINDO THEME -->
    <style>
        :root {
            /* TEMA HEXINDO / HITACHI */
            --orange-1: #E64312; 
            --orange-2: #F25C05;
            --dark-1: #231F20;
            --text-main: #333333;
            --text-soft: #666666;
            --surface-bg: #F4F6F9;
            --border-soft: #E0E4E8;
            --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.04);
            --radius-xl: 8px; /* Lebih kotak / industrial */
        }

        body { 
            background-color: var(--surface-bg); 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            color: var(--text-main);
        }
        
        .page-shell { padding: 24px 32px; }

        /* Hero Banner tersinkronisasi */
        .page-hero {
            position: relative;
            background: var(--dark-1);
            border-top: 4px solid var(--orange-1);
            border-radius: var(--radius-xl);
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: var(--shadow-soft);
        }

        .page-title { color: #fff; font-size: 1.6rem; font-weight: 700; margin-bottom: 0.25rem; }
        .page-desc { color: #9ca3af; font-size: 0.95rem; margin-bottom: 0; }

        .btn-header-dark {
            background: rgba(255, 255, 255, 0.1);
            color: #fff; font-weight: 600;
            border-radius: var(--radius-xl);
            padding: 0.6rem 1.2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.2s ease;
            font-size: 0.9rem;
            text-decoration: none;
        }
        .btn-header-dark:hover { background: rgba(255, 255, 255, 0.2); color: #fff; }

        /* TABS MENU STYLING */
        .nav-tabs { border-bottom: 2px solid var(--border-soft); margin-bottom: 1.5rem; }
        .nav-tabs .nav-link { 
            color: var(--text-soft); font-weight: 600; border: none; 
            border-bottom: 3px solid transparent; padding: 1rem 1.5rem; 
            transition: all 0.2s ease;
        }
        .nav-tabs .nav-link:hover { color: var(--orange-1); }
        .nav-tabs .nav-link.active { 
            color: var(--orange-1); 
            border-bottom: 3px solid var(--orange-1); 
            background: transparent; font-weight: 700; 
        }

        /* CARD STYLING */
        .ui-card { 
            background: #ffffff; border: 1px solid var(--border-soft); 
            border-radius: var(--radius-xl); box-shadow: var(--shadow-soft); 
        }
        .card-header-custom { 
            background: #fff; border-bottom: 1px solid var(--border-soft); 
            padding: 1.2rem 1.5rem; border-radius: var(--radius-xl) var(--radius-xl) 0 0; 
            font-weight: 700; color: var(--dark-1); 
            display: flex; align-items: center; gap: 8px;
        }

        /* FORM & BUTTON STYLING */
        .form-control, .select2-container .select2-selection--single {
            border: 1px solid var(--border-soft);
            border-radius: 6px;
        }
        .form-control:focus { border-color: var(--orange-1); box-shadow: 0 0 0 0.25rem rgba(230, 67, 18, 0.1); }
        
        .btn-hexindo { background-color: var(--orange-1); color: white; font-weight: 600; border: none; border-radius: 6px; }
        .btn-hexindo:hover { background-color: var(--orange-2); color: white;}
        
        /* State tombol Update */
        .btn-success { background-color: #059669; border-color: #059669; font-weight: 600; border-radius: 6px;}

        /* TABLE STYLING */
        .table > :not(caption) > * > * { padding: 1rem 1.5rem; border-bottom-color: var(--border-soft); }
        .table-light { background-color: #f9fafb !important; color: var(--text-soft); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .table-wrap { max-height: 550px; overflow-y: auto; }

        /* ACTION BUTTONS (Disamakan dengan index.php) */
        .action-btn { 
            width: 32px; height: 32px; display: inline-flex; align-items: center; 
            justify-content: center; border-radius: var(--radius-xl); margin: 2px;
            cursor: pointer; transition: all 0.2s;
        }
        .action-btn:hover { background: var(--surface-bg); }

        /* SOFT BADGE UNTUK TABEL TIPE */
        .badge.rounded-pill { padding: 0.4em 0.8em; font-weight: 600; font-size: 0.75rem; letter-spacing: 0.3px; }
        .badge-soft-secondary { background-color: rgba(107, 114, 128, 0.15); color: #4b5563; }
    </style>
</head>
<body>

<div class="container-fluid p-0">
    <div class="d-flex flex-nowrap w-100 overflow-hidden">
        
        <?php include '../layout/sidebar.php'; ?>

        <div id="mainContent" class="flex-grow-1" style="transition: all 0.28s ease; min-width: 0;">
            <div class="page-shell">
                
                <!-- HEADER HERO SINKRON DENGAN TEMA HEXINDO -->
                <div class="page-hero">
                    <div class="hero-text">
                        <h1 class="page-title">Kelola Master Data</h1>
                        <p class="page-desc">Pusat pengaturan data dasar Kategori, Merk, dan Tipe Spesifikasi.</p>
                    </div>
                    <div class="hero-actions">
                        <a href="index.php" class="btn btn-header-dark"><i class="bi bi-arrow-left me-2"></i>Kembali ke Asset</a>
                    </div>
                </div>

                <!-- TABS MENU -->
                <ul class="nav nav-tabs" id="masterTabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabBarang" type="button"><i class="bi bi-box me-2"></i>Kategori Barang</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabMerk" type="button"><i class="bi bi-tags me-2"></i>Merk Barang</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabTipe" type="button"><i class="bi bi-cpu me-2"></i>Tipe & Spesifikasi</button></li>
                </ul>

                <div class="tab-content" id="masterTabsContent">
                    
                    <!-- ================= TAB 1: BARANG ================= -->
                    <div class="tab-pane fade show active" id="tabBarang">
                        <div class="row g-4">
                            <div class="col-lg-4">
                                <div class="ui-card h-100">
                                    <div class="card-header-custom"><i class="bi bi-pencil-square" style="color: var(--orange-1);"></i> Form Kategori Barang</div>
                                    <div class="card-body p-4">
                                        <form class="crud-form" data-action="save_barang">
                                            <input type="hidden" name="id_barang_edit" id="id_barang_edit" value="0">
                                            <div class="mb-4">
                                                <label class="form-label fw-bold small text-muted">Nama Kategori <span class="text-danger">*</span></label>
                                                <input type="text" name="nama_barang" id="input_nama_barang" class="form-control" required placeholder="Cth: Monitor">
                                            </div>
                                            <div class="d-flex gap-2">
                                                <button type="submit" class="btn btn-hexindo flex-grow-1 btn-submit"><span class="t-btn">Simpan</span></button>
                                                <button type="button" class="btn btn-light border btn-cancel d-none" onclick="resetForm(this)">Batal</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-8">
                                <div class="ui-card h-100">
                                    <div class="card-header-custom"><i class="bi bi-list-ul text-muted"></i> Daftar Kategori Barang</div>
                                    <div class="card-body p-0 table-wrap">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="sticky-top table-light"><tr><th class="ps-4" width="60">No</th><th>Nama Kategori</th><th class="text-center" width="120">Aksi</th></tr></thead>
                                            <tbody>
                                                <?php $no=1; while($row = mysqli_fetch_assoc($dBarang)): ?>
                                                <tr>
                                                    <td class="ps-4 text-muted"><?= $no++ ?></td>
                                                    <td class="fw-bold text-dark"><?= htmlspecialchars($row['nama_barang']) ?></td>
                                                    <td class="text-center">
                                                        <a class="action-btn btn btn-light border btn-sm text-primary" onclick="editData('barang', <?= $row['id_barang'] ?>, '<?= htmlspecialchars($row['nama_barang']) ?>')" title="Edit"><i class="bi bi-pencil-fill"></i></a>
                                                        <a class="action-btn btn btn-light border btn-sm text-danger" onclick="deleteData('delete_barang', <?= $row['id_barang'] ?>)" title="Hapus"><i class="bi bi-trash-fill"></i></a>
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
                                <div class="ui-card h-100">
                                    <div class="card-header-custom"><i class="bi bi-pencil-square" style="color: var(--orange-1);"></i> Form Merk Barang</div>
                                    <div class="card-body p-4">
                                        <form class="crud-form" data-action="save_merk">
                                            <input type="hidden" name="id_merk_edit" id="id_merk_edit" value="0">
                                            <div class="mb-4">
                                                <label class="form-label fw-bold small text-muted">Nama Merk <span class="text-danger">*</span></label>
                                                <input type="text" name="nama_merk" id="input_nama_merk" class="form-control" required placeholder="Cth: Dell">
                                            </div>
                                            <div class="d-flex gap-2">
                                                <button type="submit" class="btn btn-hexindo flex-grow-1 btn-submit"><span class="t-btn">Simpan</span></button>
                                                <button type="button" class="btn btn-light border btn-cancel d-none" onclick="resetForm(this)">Batal</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-8">
                                <div class="ui-card h-100">
                                    <div class="card-header-custom"><i class="bi bi-list-ul text-muted"></i> Daftar Merk Terdaftar</div>
                                    <div class="card-body p-0 table-wrap">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="sticky-top table-light"><tr><th class="ps-4" width="60">No</th><th>Nama Merk</th><th class="text-center" width="120">Aksi</th></tr></thead>
                                            <tbody>
                                                <?php $no=1; while($row = mysqli_fetch_assoc($dMerk)): ?>
                                                <tr>
                                                    <td class="ps-4 text-muted"><?= $no++ ?></td>
                                                    <td class="fw-bold text-primary"><?= htmlspecialchars($row['nama_merk']) ?></td>
                                                    <td class="text-center">
                                                        <a class="action-btn btn btn-light border btn-sm text-primary" onclick="editData('merk', <?= $row['id_merk'] ?>, '<?= htmlspecialchars($row['nama_merk']) ?>')" title="Edit"><i class="bi bi-pencil-fill"></i></a>
                                                        <a class="action-btn btn btn-light border btn-sm text-danger" onclick="deleteData('delete_merk', <?= $row['id_merk'] ?>)" title="Hapus"><i class="bi bi-trash-fill"></i></a>
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
                                <div class="ui-card h-100">
                                    <div class="card-header-custom"><i class="bi bi-pencil-square" style="color: var(--orange-1);"></i> Form Tipe Barang</div>
                                    <div class="card-body p-4">
                                        <form class="crud-form" data-action="save_tipe">
                                            <input type="hidden" name="id_tipe_edit" id="id_tipe_edit" value="0">
                                            <div class="mb-3">
                                                <label class="form-label fw-bold small text-muted">Barang <span class="text-danger">*</span></label>
                                                <select name="id_barang" id="input_id_barang" class="form-control select2" required>
                                                    <option value="">Pilih...</option>
                                                    <?php foreach($arrBarang as $b): ?><option value="<?= $b['id_barang'] ?>"><?= htmlspecialchars($b['nama_barang']) ?></option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-bold small text-muted">Merk <span class="text-danger">*</span></label>
                                                <select name="id_merk" id="input_id_merk" class="form-control select2" required>
                                                    <option value="">Pilih...</option>
                                                    <?php foreach($arrMerk as $m): ?><option value="<?= $m['id_merk'] ?>"><?= htmlspecialchars($m['nama_merk']) ?></option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="mb-4">
                                                <label class="form-label fw-bold small text-muted">Nama Tipe <span class="text-danger">*</span></label>
                                                <input type="text" name="nama_tipe" id="input_nama_tipe" class="form-control" required placeholder="Cth: Latitude 3440">
                                            </div>
                                            <div class="d-flex gap-2">
                                                <button type="submit" class="btn btn-hexindo flex-grow-1 btn-submit"><span class="t-btn">Simpan</span></button>
                                                <button type="button" class="btn btn-light border btn-cancel d-none" onclick="resetForm(this)">Batal</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-8">
                                <div class="ui-card h-100">
                                    <div class="card-header-custom justify-content-between">
                                        <div class="d-flex align-items-center gap-2"><i class="bi bi-list-ul text-muted"></i> Daftar Spesifikasi Tipe</div>
                                        <div class="input-group input-group-sm" style="width: 250px;">
                                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                            <input type="text" id="searchTipe" class="form-control border-start-0 ps-0 shadow-none" placeholder="Cari nama tipe / merk...">
                                        </div>
                                    </div>
                                    <div class="card-body p-0 table-wrap">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="sticky-top table-light"><tr><th class="ps-4" width="60">No</th><th>Kategori & Merk</th><th>Tipe Spesifikasi</th><th class="text-center" width="120">Aksi</th></tr></thead>
                                            <tbody>
                                                <?php $no=1; while($row = mysqli_fetch_assoc($dTipe)): ?>
                                                <tr>
                                                    <td class="ps-4 text-muted"><?= $no++ ?></td>
                                                    <td>
                                                        <span class="badge rounded-pill badge-soft-secondary mb-1"><?= htmlspecialchars($row['nama_barang']) ?></span><br>
                                                        <span class="small text-muted fw-semibold">Merk: <?= htmlspecialchars($row['nama_merk']) ?></span>
                                                    </td>
                                                    <td class="fw-bold text-dark"><?= htmlspecialchars($row['nama_tipe']) ?></td>
                                                    <td class="text-center">
                                                        <a class="action-btn btn btn-light border btn-sm text-primary" onclick="editTipe(<?= $row['id_tipe'] ?>, <?= $row['id_barang'] ?>, <?= $row['id_merk'] ?>, '<?= htmlspecialchars($row['nama_tipe']) ?>')" title="Edit"><i class="bi bi-pencil-fill"></i></a>
                                                        <a class="action-btn btn btn-light border btn-sm text-danger" onclick="deleteData('delete_tipe', <?= $row['id_tipe'] ?>)" title="Hapus"><i class="bi bi-trash-fill"></i></a>
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