<?php
include '../config/koneksi.php';
require_once '../config/auth.php';
/** @var mysqli $koneksi */

require_login(); // Pastikan user sudah login

$isAdmin = is_admin();
$myBranchId = current_user_branch_id();

// Query dasar untuk mengambil aset yang berstatus 8 (Scrap)
$sql = "
    SELECT b.serial_number, b.no_asset, b.foto, 
           tb.nama_barang, tm.nama_merk AS merek, tt.nama_tipe AS tipe,
           br.nama_branch AS cabang_pemilik,
           ps.alasan_scrap, ps.updated_at AS tanggal_scrap,
           u.username AS disetujui_oleh
    FROM barang b
    JOIN tb_barang tb ON b.id_barang = tb.id_barang
    LEFT JOIN tb_merk tm ON b.id_merk = tm.id_merk
    LEFT JOIN tb_tipe tt ON b.id_tipe = tt.id_tipe
    LEFT JOIN tb_branch br ON b.id_branch_pemilik = br.id_branch
    LEFT JOIN pengajuan_scrap ps ON ps.id_barang = b.id AND ps.status_scrap = 'Disetujui'
    LEFT JOIN users u ON ps.dikonfirmasi_oleh_user = u.id
    WHERE b.id_status = 8
";

// Jika BUKAN Admin, filter hanya tampilkan scrap milik cabangnya sendiri
if (!$isAdmin) {
    $sql .= " AND b.id_branch_pemilik = '$myBranchId'";
}

$sql .= " ORDER BY ps.updated_at DESC";
$query = mysqli_query($koneksi, $sql);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Aset Scrap - IT Asset Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        :root { --orange-1: #E64312; --dark-1: #231F20; --surface-bg: #F4F6F9; --border-soft: #E0E4E8; }
        body { background-color: var(--surface-bg); font-family: 'Plus Jakarta Sans', sans-serif; }
        .page-shell { padding: 24px 32px; }
        .page-hero { background: var(--dark-1); border-top: 4px solid #dc3545; border-radius: 8px; padding: 1.5rem 2rem; margin-bottom: 1.5rem; color:#fff;}
        .ui-card { background: #ffffff; border: 1px solid var(--border-soft); border-radius: 8px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04); overflow: hidden; }
        .table-custom thead th { background-color: #f9fafb; font-size: 0.85rem; text-transform: uppercase; padding: 1.2rem 1.5rem; }
        .table-custom tbody td { padding: 1.2rem 1.5rem; vertical-align: middle; }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="d-flex flex-nowrap w-100 overflow-hidden">
            
            <?php include '../layout/sidebar.php'; ?>
            
            <div id="mainContent" class="flex-grow-1" style="min-width: 0;">
                <div class="page-shell">
                    
                    <div class="page-hero">
                        <h1 class="h4 fw-bold mb-1"><i class="bi bi-trash3-fill text-danger me-2"></i> Riwayat Aset Scrap (Dimusnahkan)</h1>
                        <p class="mb-0 text-secondary">Daftar inventaris aset yang telah resmi dihapus dari sistem karena rusak permanen atau usang.</p>
                    </div>

                    <div class="ui-card">
                        <div class="table-responsive">
                            <table class="table table-custom mb-0">
                                <thead>
                                    <tr>
                                        <th width="50">No</th>
                                        <th>Identitas Aset</th>
                                        <?php if ($isAdmin): ?>
                                            <th>Cabang Pemilik</th>
                                        <?php endif; ?>
                                        <th>Informasi Pemusnahan</th>
                                        <th>Alasan Scrap</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($query && mysqli_num_rows($query) > 0): ?>
                                        <?php $no = 1; while ($row = mysqli_fetch_assoc($query)): ?>
                                            <tr>
                                                <td class="text-muted fw-bold"><?= $no++ ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-3">
                                                        <?php if (!empty($row['foto'])): ?>
                                                            <img src="../assets/images/<?= htmlspecialchars($row['foto']) ?>" alt="Foto" class="rounded" style="width: 50px; height: 50px; object-fit: cover; border: 1px solid #ddd;">
                                                        <?php else: ?>
                                                            <div class="bg-light rounded d-flex align-items-center justify-content-center text-muted" style="width: 50px; height: 50px; border: 1px solid #ddd;"><i class="bi bi-image"></i></div>
                                                        <?php endif; ?>
                                                        
                                                        <div>
                                                            <div class="fw-bold text-dark text-decoration-line-through"><?= htmlspecialchars($row['nama_barang'] ?? 'Unknown') ?></div>
                                                            <div class="small text-muted">SN: <?= htmlspecialchars($row['serial_number'] ?? '-') ?></div>
                                                            <span class="badge bg-secondary mt-1"><?= htmlspecialchars($row['merek'] ?? '-') ?> <?= htmlspecialchars($row['tipe'] ?? '') ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <?php if ($isAdmin): ?>
                                                    <td>
                                                        <span class="badge bg-dark"><i class="bi bi-geo-alt-fill me-1"></i> <?= htmlspecialchars($row['cabang_pemilik'] ?? 'Pusat') ?></span>
                                                    </td>
                                                <?php endif; ?>
                                                <td>
                                                    <div class="text-dark fw-semibold"><i class="bi bi-calendar-x text-danger me-1"></i> <?= date('d M Y', strtotime($row['tanggal_scrap'] ?? 'now')) ?></div>
                                                    <div class="small text-muted mt-1"><i class="bi bi-person-check me-1"></i> ACC: <b><?= htmlspecialchars($row['disetujui_oleh'] ?? 'Admin') ?></b></div>
                                                </td>
                                                <td>
                                                    <div class="text-danger small fst-italic">"<?= htmlspecialchars($row['alasan_scrap'] ?? 'Dimusnahkan (Otomatis)') ?>"</div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?= $isAdmin ? '5' : '4' ?>" class="text-center py-5 text-muted">
                                                <i class="bi bi-box-seam fs-1 d-block mb-3"></i> Belum ada data aset yang di-scrap.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>