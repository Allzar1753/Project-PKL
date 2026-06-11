<?php
/** @var mysqli $koneksi */
include '../config/koneksi.php';
require_once '../config/auth.php';

// Hanya user cabang yang punya izin ini yang bisa buka
require_permission($koneksi, 'scrap.approve');

$branchId = current_user_branch_id();

// PERBAIKAN QUERY: Melakukan JOIN ke tabel master (tb_barang, tb_merk, tb_tipe)
$query = mysqli_query($koneksi, "
    SELECT ps.*, 
           b.serial_number, 
           tb.nama_barang, 
           tm.nama_merk AS merek, 
           tt.nama_tipe AS tipe
    FROM pengajuan_scrap ps
    JOIN barang b ON ps.id_barang = b.id
    LEFT JOIN tb_barang tb ON b.id_barang = tb.id_barang
    LEFT JOIN tb_merk tm ON b.id_merk = tm.id_merk
    LEFT JOIN tb_tipe tt ON b.id_tipe = tt.id_tipe
    WHERE ps.id_branch_target = '$branchId' AND ps.status_scrap = 'Pending'
    ORDER BY ps.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approval Scrap Aset - IT Asset Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root { --orange-1: #E64312; --dark-1: #231F20; --surface-bg: #F4F6F9; --border-soft: #E0E4E8; }
        body { background-color: var(--surface-bg); font-family: 'Plus Jakarta Sans', sans-serif; }
        .page-shell { padding: 24px 32px; }
        .page-hero { background: var(--dark-1); border-top: 4px solid var(--orange-1); border-radius: 8px; padding: 1.5rem 2rem; margin-bottom: 1.5rem; color:#fff;}
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
                        <h1 class="h4 fw-bold mb-1"><i class="bi bi-archive text-warning me-2"></i> Approval Scrap Aset</h1>
                        <p class="mb-0 text-secondary">Tarik fisik aset dari user, pastikan kondisinya, lalu setujui permohonan pemusnahan (scrap) dari Admin HO.</p>
                    </div>

                    <div class="ui-card">
                        <div class="table-responsive">
                            <table class="table table-custom mb-0">
                                <thead>
                                    <tr>
                                        <th width="50">No</th>
                                        <th>Identitas Aset</th>
                                        <th>Pemakai Terakhir</th>
                                        <th>Alasan Scrap (Dari Admin)</th>
                                        <th class="text-end">Tindakan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($query && mysqli_num_rows($query) > 0): ?>
                                        <?php $no = 1; while ($row = mysqli_fetch_assoc($query)): ?>
                                            <tr>
                                                <td class="text-muted fw-bold"><?= $no++ ?></td>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= h($row['nama_barang'] ?? 'Unknown') ?></div>
                                                    <div class="small text-muted">SN: <?= h($row['serial_number'] ?? '-') ?></div>
                                                    <span class="badge bg-secondary mt-1"><?= h($row['merek'] ?? '-') ?> <?= h($row['tipe'] ?? '') ?></span>
                                                </td>
                                                <td><i class="bi bi-person text-muted me-1"></i> <?= h($row['nama_pemakai_terakhir'] ?: 'Tidak ada') ?></td>
                                                <td>
                                                    <div class="text-danger small fw-bold mb-1"><i class="bi bi-exclamation-triangle"></i> Dihancurkan karena:</div>
                                                    <div class="text-dark">"<?= h($row['alasan_scrap']) ?>"</div>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex justify-content-end gap-2">
                                                        <button class="btn btn-success btn-sm fw-bold btnSetuju" data-id="<?= $row['id_scrap'] ?>"><i class="bi bi-check-lg me-1"></i> Setujui</button>
                                                        <button class="btn btn-outline-danger btn-sm fw-bold btnTolak" data-id="<?= $row['id_scrap'] ?>"><i class="bi bi-x-lg me-1"></i> Tolak</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-5 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-3"></i> Tidak ada pengajuan scrap yang menunggu persetujuan Anda.
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.querySelectorAll('.btnSetuju').forEach(btn => {
            btn.addEventListener('click', function() {
                const idScrap = this.getAttribute('data-id');
                Swal.fire({
                    title: 'Setujui Scrap?',
                    text: "Pastikan Anda sudah menarik fisik barang ini. Barang akan resmi ditandai sebagai Aset Mati (Scrap).",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#198754',
                    confirmButtonText: 'Ya, Setujui & Musnahkan!'
                }).then((result) => {
                    if (result.isConfirmed) processScrap(idScrap, 'approve', '');
                });
            });
        });

        document.querySelectorAll('.btnTolak').forEach(btn => {
            btn.addEventListener('click', function() {
                const idScrap = this.getAttribute('data-id');
                Swal.fire({
                    title: 'Tolak Scrap?',
                    text: "Masukkan alasan kenapa Anda menolak scrap ini (misal: barang masih bisa dipakai / belum diserahkan user):",
                    input: 'textarea',
                    icon: 'error',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    confirmButtonText: 'Tolak Scrap',
                    preConfirm: (alasan) => {
                        if (!alasan) Swal.showValidationMessage('Alasan penolakan wajib diisi!');
                        return alasan;
                    }
                }).then((result) => {
                    if (result.isConfirmed) processScrap(idScrap, 'reject', result.value);
                });
            });
        });

        function processScrap(idScrap, action, alasan) {
            Swal.fire({ title: 'Memproses...', allowOutsideClick: false, didOpen: () => { Swal.showLoading() } });
            
            const fd = new FormData();
            fd.append('id_scrap', idScrap);
            fd.append('action', action);
            if (alasan) fd.append('alasan', alasan);

            fetch('proses_approval_scrap.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                setTimeout(() => {
                    if (data.status === 'success') {
                        Swal.fire('Berhasil!', data.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Gagal!', data.message, 'error');
                    }
                }, 1500);
            }).catch(() => Swal.fire('Error', 'Gagal menghubungi server.', 'error'));
        }
    </script>
</body>
</html>