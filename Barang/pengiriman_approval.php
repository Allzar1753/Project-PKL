<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'barang.kirim');
if (!is_admin()) {
    http_response_code(403);
    exit('Halaman ini khusus administrator.');
}

const STATUS_MENUNGGU_PERSETUJUAN = 'Menunggu persetujuan admin';
const STATUS_SUDAH_DITERIMA = 'Sudah diterima HO';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function jsonOut(array $payload): void
{
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function getJakartaBranchId(mysqli $koneksi): ?int
{
    $query = mysqli_query($koneksi, "SELECT id_branch FROM tb_branch WHERE LOWER(TRIM(nama_branch)) IN ('jakarta','cabang jakarta','ho jakarta','head office jakarta') ORDER BY id_branch ASC LIMIT 1");
    if (!$query) return null;
    $row = mysqli_fetch_assoc($query) ?: null;
    return $row ? (int) $row['id_branch'] : null;
}

function createUserBranchNotification(mysqli $koneksi, int $branchId, string $title, string $message, ?string $link): void
{
    $stmt = mysqli_prepare($koneksi, "INSERT INTO system_notifications (target_role, target_branch_id, title, message, link, is_read) VALUES ('user', ?, ?, ?, ?, 0)");
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, 'isss', $branchId, $title, $message, $link);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

$jakartaBranchId = getJakartaBranchId($koneksi);
if (!$jakartaBranchId) {
    http_response_code(500);
    exit('Branch Jakarta (HO) tidak ditemukan.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idPengiriman = (int) ($_POST['id_pengiriman'] ?? 0);
    if ($idPengiriman <= 0) jsonOut(['status' => 'error', 'message' => 'ID pengiriman tidak valid.']);

    mysqli_begin_transaction($koneksi);
    try {
        $adminId = current_user_id() ? (int) current_user_id() : null;
        $now = date('Y-m-d H:i:s');
        $catatanAdmin = trim((string) ($_POST['nama_penerima'] ?? ''));
        if ($catatanAdmin === '') $catatanAdmin = 'Admin HO Jakarta';

        $stmt = mysqli_prepare(
            $koneksi,
            "UPDATE pengiriman_cabang_ho
             SET status_pengiriman = ?, disetujui_oleh = ?, disetujui_pada = ?, catatan_admin = ?
             WHERE id_pengiriman_ho = ? AND branch_tujuan = ? AND COALESCE(status_pengiriman,'') = ?"
        );
        if (!$stmt) throw new Exception('Gagal menyiapkan approval.');

        $statusDone = STATUS_SUDAH_DITERIMA;
        $statusWait = STATUS_MENUNGGU_PERSETUJUAN;
        mysqli_stmt_bind_param($stmt, 'sissiis', $statusDone, $adminId, $now, $catatanAdmin, $idPengiriman, $jakartaBranchId, $statusWait);
        mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        if ($affected <= 0) throw new Exception('Data tidak ditemukan / sudah diproses.');

        $stmtInfo = mysqli_prepare($koneksi, "SELECT branch_asal, nomor_resi_keluar FROM pengiriman_cabang_ho WHERE id_pengiriman_ho = ? LIMIT 1");
        if ($stmtInfo) {
            mysqli_stmt_bind_param($stmtInfo, 'i', $idPengiriman);
            mysqli_stmt_execute($stmtInfo);
            $res = mysqli_stmt_get_result($stmtInfo);
            $row = mysqli_fetch_assoc($res) ?: null;
            mysqli_stmt_close($stmtInfo);
            if ($row) {
                $branchAsal = (int) ($row['branch_asal'] ?? 0);
                $resi = (string) ($row['nomor_resi_keluar'] ?? '-');
                if ($branchAsal > 0) {
                    createUserBranchNotification(
                        $koneksi,
                        $branchAsal,
                        'Barang cabang sudah diterima HO Jakarta',
                        'Pengiriman dengan resi ' . $resi . ' sudah diterima oleh Admin HO Jakarta.',
                        '../Barang/index.php?filter=keluar'
                    );
                }

                // Notifikasi admin bersifat sementara: setelah approval, tandai notifikasi terkait sebagai sudah dibaca.
                $adminRole = 'admin';
                $notifLikeResi = '%' . $resi . '%';
                $notifLink = '../Barang/pengiriman_approval.php';
                $stmtNotif = mysqli_prepare(
                    $koneksi,
                    "UPDATE system_notifications
                     SET is_read = 1
                     WHERE target_role = ?
                       AND is_read = 0
                       AND link = ?
                       AND message LIKE ?"
                );
                if ($stmtNotif) {
                    mysqli_stmt_bind_param($stmtNotif, 'sss', $adminRole, $notifLink, $notifLikeResi);
                    mysqli_stmt_execute($stmtNotif);
                    mysqli_stmt_close($stmtNotif);
                }
            }
        }

        mysqli_commit($koneksi);
        jsonOut(['status' => 'success', 'message' => 'Barang dari cabang sudah dikonfirmasi diterima HO. Notifikasi dikirim ke user cabang.']);
    } catch (Throwable $e) {
        mysqli_rollback($koneksi);
        jsonOut(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

$q = mysqli_query($koneksi, "
    SELECT
        p.id_pengiriman_ho AS id_pengiriman,
        p.tanggal_pengajuan AS tanggal_keluar,
        p.status_pengiriman,
        p.nomor_resi_keluar,
        p.jasa_pengiriman,
        p.serial_number,
        p.pemilik_barang,
        asal.nama_branch AS nama_branch_asal,
        tujuan.nama_branch AS nama_branch_tujuan,
        tb_barang.nama_barang
    FROM pengiriman_cabang_ho p
    LEFT JOIN tb_barang ON p.id_barang = tb_barang.id_barang
    LEFT JOIN tb_branch asal ON p.branch_asal = asal.id_branch
    LEFT JOIN tb_branch tujuan ON p.branch_tujuan = tujuan.id_branch
    WHERE p.branch_tujuan = {$jakartaBranchId}
      AND COALESCE(p.status_pengiriman, '') = '" . STATUS_MENUNGGU_PERSETUJUAN . "'
    ORDER BY p.id_pengiriman_ho DESC
");
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approval Pengiriman HO - IT Asset Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <?php require_once '../layout/sidebar.php'; ?>
            <div class="col-md-10 ms-auto">
                <div class="p-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <h4 class="fw-bold mb-1"><i class="bi bi-inboxes me-2"></i>Approval Pengiriman Cabang → HO Jakarta</h4>
                            <div class="text-muted">Konfirmasi barang rusak dari cabang yang sudah sampai dan diterima oleh HO Jakarta.</div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Barang</th>
                                        <th>Asal → Tujuan</th>
                                        <th>Resi / Jasa</th>
                                        <th>Status</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($q && mysqli_num_rows($q) > 0): ?>
                                        <?php $no = 1; ?>
                                        <?php while ($row = mysqli_fetch_assoc($q)): ?>
                                            <?php
                                            $status = (string) ($row['status_pengiriman'] ?? '');
                                            $canApprove = $status === STATUS_MENUNGGU_PERSETUJUAN;
                                            ?>
                                            <tr>
                                                <td><?= $no++ ?></td>
                                                <td>
                                                    <div class="fw-bold"><?= h($row['nama_barang'] ?? '-') ?></div>
                                                    <div class="text-muted small">Kategori barang dari pengajuan user cabang
                                                        <i class="bi bi-upc-scan me-1"></i> SN: <strong><?= h($row['serial_number'] ?? 'Belum ada SN') ?></strong><br>
                                                        <i class="bi bi-person me-1"></i> User: <strong><?= h($row['pemilik_barang'] ?? 'Belum ada User') ?></strong>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?= h($row['nama_branch_asal'] ?? '-') ?> → <?= h($row['nama_branch_tujuan'] ?? '-') ?></div>
                                                    <div class="text-muted small">Tgl kirim: <?= h($row['tanggal_keluar'] ?? '-') ?></div>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?= h($row['nomor_resi_keluar'] ?? '-') ?></div>
                                                    <div class="text-muted small"><?= h($row['jasa_pengiriman'] ?? '-') ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge <?= $canApprove ? 'bg-warning text-dark' : 'bg-primary' ?> rounded-pill">
                                                        <?= h($status !== '' ? $status : '-') ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex justify-content-end gap-2 flex-wrap">
                                                        <?php if ($canApprove): ?>
                                                            <button class="btn btn-success btn-sm btnApprove" data-id="<?= (int) $row['id_pengiriman'] ?>">
                                                                <i class="bi bi-box-arrow-in-down me-1"></i>Approve Barang Sudah Sampai HO
                                                            </button>
                                                        <?php else: ?>
                                                            <button class="btn btn-outline-secondary btn-sm" disabled>
                                                                <i class="bi bi-check2-circle me-1"></i>Sudah Diproses
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted p-4">
                                                <i class="bi bi-inbox d-block fs-3 mb-2"></i>
                                                Tidak ada pengiriman cabang → HO yang perlu diproses.
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
        document.addEventListener('click', async (e) => {
            const approveBtn = e.target.closest('.btnApprove');

            if (approveBtn) {
                const id = approveBtn.getAttribute('data-id');
                const res = await fetch('pengiriman_approval.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        id_pengiriman: id,
                        nama_penerima: 'Admin HO Jakarta'
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Berhasil',
                        text: data.message
                    });
                    location.reload();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal',
                        text: data.message || 'Terjadi kesalahan'
                    });
                }
            }

        });
    </script>
</body>

</html>