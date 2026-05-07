<?php
/** @var mysqli $koneksi */ //
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
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <style>
        :root {
            --orange-1: #ff7a00; 
            --orange-2: #ff9800; 
            --orange-3: #ffb000;
            --dark-1: #111111; 
            --text-main: #1e1e1e; 
            --text-soft: #6b7280;
            --surface: #ffffff; 
            --border-soft: rgba(255, 152, 0, 0.14);
            --shadow-soft: 0 12px 36px rgba(17, 17, 17, 0.07); 
            --radius-xl: 28px;
        }

        body {
            background: radial-gradient(circle at top left, rgba(255, 176, 0, 0.16), transparent 28%),
                        linear-gradient(180deg, #fff8f1 0%, #ffffff 100%);
            font-family: 'Plus Jakarta Sans', sans-serif; 
            color: var(--text-main); 
            min-height: 100vh;
        }

        .page-shell { padding: 25px; }

        /* Style Card Header Gradient */
        .page-hero {
            position: relative; 
            overflow: hidden; 
            border-radius: var(--radius-xl);
            background: linear-gradient(135deg, rgba(17, 17, 17, 0.94) 0%, rgba(255, 122, 0, 0.96) 100%);
            box-shadow: 0 18px 45px rgba(255, 122, 0, 0.20); 
            padding: 1.8rem 2rem; 
            margin-bottom: 1.5rem;
        }

        .page-title { 
            color: #fff; 
            font-size: 1.8rem; 
            font-weight: 800; 
            letter-spacing: -0.02em; 
            margin-bottom: 0.3rem;
        }

        .page-desc { 
            color: rgba(255, 255, 255, 0.84); 
            font-size: .95rem; 
            max-width: 800px; 
            margin-bottom: 0;
        }

        /* Style Card Table Putih */
        .ui-card { 
            background: var(--surface); 
            border: 1px solid var(--border-soft); 
            border-radius: 22px; 
            box-shadow: var(--shadow-soft); 
            overflow: hidden;
        }

        /* Styling Table Modern */
        .table-custom { margin-bottom: 0; }
        .table-custom thead th {
            background-color: #fcfcfc;
            color: #555;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            padding: 1rem 1.5rem;
            border-bottom: 2px solid #eee;
        }
        
        .table-custom tbody td {
            padding: 1.2rem 1.5rem;
            vertical-align: middle;
            border-bottom: 1px solid #f5f5f5;
        }

        /* Badge and Meta Line Customization */
        .meta-line { display: block; font-size: 0.88rem; margin-bottom: 3px; color: var(--text-soft); }
        .meta-strong { color: var(--dark-1); font-weight: 700; }
        
        .badge-custom {
            padding: 0.55em 1em;
            font-weight: 700;
            font-size: 0.8rem;
            border-radius: 999px;
            background-color: #fff4e6;
            color: #d97706;
            border: 1px solid rgba(255, 152, 0, 0.2);
        }

        /* Custom Modern Button */
        .btn-modern {
            background: linear-gradient(135deg, var(--dark-1), #333);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 0.6rem 1.2rem;
            font-weight: 700;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }

        .btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 25px rgba(0,0,0,0.25);
            background: linear-gradient(135deg, var(--orange-1), var(--orange-2));
            color: white;
        }

        /* Empty State */
        .empty-state {
            padding: 4rem 2rem;
            text-align: center;
        }
        .empty-state i {
            font-size: 3.5rem;
            color: #ffd8a8;
            margin-bottom: 1rem;
            display: block;
        }
        .empty-state-title {
            font-weight: 800;
            color: var(--dark-1);
            font-size: 1.2rem;
        }
        .empty-state-desc {
            color: var(--text-soft);
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <?php require_once '../layout/sidebar.php'; ?>
            
            <div class="col-md-10">
                <div class="page-shell">
                    
                    <!-- Header Hero Gradient -->
                    <div class="page-hero">
                        <div class="hero-content">
                            <h1 class="page-title"><i class="bi bi-inboxes-fill me-2 text-warning"></i>Approval Pengiriman ke HO</h1>
                            <p class="page-desc">Konfirmasi penerimaan barang rusak dari cabang yang sudah tiba secara fisik di Head Office Jakarta.</p>
                        </div>
                    </div>

                    <!-- Container Table -->
                    <div class="ui-card">
                        <div class="table-responsive">
                            <table class="table table-custom">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Informasi Barang & User</th>
                                        <th>Rute & Tanggal</th>
                                        <th>Jasa Logistik</th>
                                        <th>Status Pengajuan</th>
                                        <th class="text-end">Tindakan</th>
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
                                                <td class="text-muted fw-bold"><?= $no++ ?></td>
                                                <td>
                                                    <div class="fw-bold fs-6 text-dark mb-1"><?= h($row['nama_barang'] ?? '-') ?></div>
                                                    <span class="meta-line"><i class="bi bi-upc-scan me-1"></i> SN: <span class="meta-strong"><?= h($row['serial_number'] ?? 'Belum ada SN') ?></span></span>
                                                    <span class="meta-line"><i class="bi bi-person-badge me-1"></i> User: <span class="meta-strong"><?= h($row['pemilik_barang'] ?? 'Belum ada User') ?></span></span>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark mb-1">
                                                        <?= h($row['nama_branch_asal'] ?? '-') ?> <i class="bi bi-arrow-right mx-1 text-warning"></i> <?= h($row['nama_branch_tujuan'] ?? '-') ?>
                                                    </div>
                                                    <span class="meta-line"><i class="bi bi-calendar-event me-1"></i> Tgl kirim: <?= h($row['tanggal_keluar'] ?? '-') ?></span>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark mb-1"><?= h($row['nomor_resi_keluar'] ?? '-') ?></div>
                                                    <span class="meta-line"><i class="bi bi-truck me-1"></i> <?= h($row['jasa_pengiriman'] ?? '-') ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge-custom">
                                                        <i class="bi bi-clock-history me-1"></i><?= h($status !== '' ? $status : '-') ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($canApprove): ?>
                                                        <button class="btn btn-modern btnApprove" data-id="<?= (int) $row['id_pengiriman'] ?>">
                                                            <i class="bi bi-check2-all me-1"></i> Terima Barang
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-light rounded-pill fw-bold text-muted btn-sm" disabled>
                                                            <i class="bi bi-check-circle-fill text-success me-1"></i>Selesai
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="border-0">
                                                <div class="empty-state">
                                                    <i class="bi bi-box2-heart"></i>
                                                    <div class="empty-state-title">Tidak ada pengiriman tertunda</div>
                                                    <div class="empty-state-desc">Saat ini tidak ada barang dari cabang yang menunggu persetujuan penerimaan di HO Jakarta.</div>
                                                </div>
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
                
                // Tambahkan konfirmasi SweetAlert yang modern sebelum mengeksekusi
                const confirmResult = await Swal.fire({
                    title: 'Konfirmasi Penerimaan',
                    text: "Pastikan fisik barang sudah tiba dan sesuai.",
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#ff7a00',
                    cancelButtonColor: '#111111',
                    confirmButtonText: 'Ya, Terima Barang',
                    cancelButtonText: 'Batal'
                });

                if (confirmResult.isConfirmed) {
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
                            title: 'Berhasil Diterima!',
                            text: data.message,
                            confirmButtonColor: '#111111'
                        });
                        location.reload();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: data.message || 'Terjadi kesalahan',
                            confirmButtonColor: '#111111'
                        });
                    }
                }
            }
        });
    </script>
</body>

</html>