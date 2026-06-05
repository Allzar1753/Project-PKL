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

        // --- LOGIKA OTOMATISASI PINDAH INVENTARIS ---
        $stmtGetSN = mysqli_prepare($koneksi, "SELECT serial_number, catatan_user FROM pengiriman_cabang_ho WHERE id_pengiriman_ho = ?");
        mysqli_stmt_bind_param($stmtGetSN, 'i', $idPengiriman);
        mysqli_stmt_execute($stmtGetSN);
        $resSN = mysqli_stmt_get_result($stmtGetSN);
        $dataSN = mysqli_fetch_assoc($resSN);

        if ($dataSN) {
            $sn = $dataSN['serial_number'];
            $catatanUser = trim((string) ($dataSN['catatan_user'] ?? ''));

            $stmtPemilik = mysqli_prepare($koneksi, "SELECT pemilik_barang FROM pengiriman_cabang_ho WHERE id_pengiriman_ho = ? LIMIT 1");
            mysqli_stmt_bind_param($stmtPemilik, 'i', $idPengiriman);
            mysqli_stmt_execute($stmtPemilik);
            $resPemilik = mysqli_stmt_get_result($stmtPemilik);
            $dataPemilik = mysqli_fetch_assoc($resPemilik);
            mysqli_stmt_close($stmtPemilik);

            $pemilikBarang = trim((string) ($dataPemilik['pemilik_barang'] ?? ''));

            $queryUpdateBarang = "UPDATE barang SET 
                id_branch = ?, 
                status = 'Diterima', 
                bermasalah = 'Iya', 
                keterangan_masalah = ?,
                id_status = 5,
                `user` = CASE WHEN ? != '' AND ? != '0' THEN ? ELSE `user` END
                WHERE serial_number = ?";

            $stmtUpdate = mysqli_prepare($koneksi, $queryUpdateBarang);
            if ($stmtUpdate) {
                mysqli_stmt_bind_param($stmtUpdate, 'isssss', $jakartaBranchId, $catatanUser, $pemilikBarang, $pemilikBarang, $pemilikBarang, $sn);
                mysqli_stmt_execute($stmtUpdate);
                mysqli_stmt_close($stmtUpdate);
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
        p.catatan_user,
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
            min-height: 100vh;
        }

        .page-shell {
            padding: 24px 32px;
        }

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

        .page-title {
            color: #fff;
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .page-desc {
            color: #9ca3af;
            font-size: 0.95rem;
            margin-bottom: 0;
            max-width: 800px;
        }

        /* Table Card & UI */
        .ui-card {
            background: #ffffff;
            border: 1px solid var(--border-soft);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-soft);
            overflow: hidden;
        }

        .table-custom {
            margin-bottom: 0;
        }

        .table-custom thead th {
            background-color: #f9fafb;
            color: var(--text-soft);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid var(--border-soft);
        }

        .table-custom tbody td {
            padding: 1.2rem 1.5rem;
            vertical-align: middle;
            border-bottom: 1px solid var(--border-soft);
        }

        /* Teks & Meta Data */
        .meta-line {
            display: block;
            font-size: 0.85rem;
            margin-bottom: 4px;
            color: var(--text-main);
        }

        .meta-strong {
            color: var(--dark-1);
            font-weight: 600;
        }

        /* Soft Badge Hexindo Style */
        .badge-custom {
            padding: 0.5em 1em;
            font-weight: 600;
            font-size: 0.8rem;
            border-radius: 999px;
            background-color: rgba(245, 158, 11, 0.15); /* Soft Warning */
            color: #d97706;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Button Action Approval */
        .btn-modern {
            background-color: var(--orange-1);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 0.6rem 1.2rem;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.2s ease;
        }

        .btn-modern:hover {
            background-color: var(--orange-2);
            color: white;
        }

        /* Empty State */
        .empty-state {
            padding: 4rem 2rem;
            text-align: center;
            background-color: #f9fafb;
        }

        .empty-state i {
            font-size: 3.5rem;
            color: #d1d5db;
            margin-bottom: 1rem;
            display: block;
        }

        .empty-state-title {
            font-weight: 700;
            color: var(--dark-1);
            font-size: 1.1rem;
            margin-bottom: 0.2rem;
        }

        .empty-state-desc {
            color: var(--text-soft);
            font-size: 0.9rem;
        }
    </style>
</head>

<body>

    <div class="container-fluid p-0">
        <div class="d-flex flex-nowrap w-100 overflow-hidden">

            <?php include '../layout/sidebar.php'; ?>

            <div id="mainContent" class="flex-grow-1" style="transition: all 0.28s ease; min-width: 0;">

                <div class="page-shell">

                    <!-- Header Hero Hexindo Style -->
                    <div class="page-hero">
                        <div class="hero-content">
                            <h1 class="page-title"><i class="bi bi-inboxes-fill me-2" style="color: var(--orange-1);"></i> Approval Pengiriman ke HO</h1>
                            <p class="page-desc">Konfirmasi penerimaan barang rusak dari cabang yang sudah tiba secara fisik di Head Office Jakarta.</p>
                        </div>
                    </div>

                    <!-- Container Table -->
                    <div class="ui-card">
                        <div class="table-responsive">
                            <table class="table table-custom">
                                <thead>
                                    <tr>
                                        <th width="60">No</th>
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
                                                <td class="text-muted fw-semibold"><?= $no++ ?></td>
                                                <td>
                                                    <div class="fw-bold fs-6 text-dark mb-2"><?= h($row['nama_barang'] ?? '-') ?></div>
                                                    <span class="meta-line"><i class="bi bi-upc-scan me-2 text-muted"></i> SN: <span class="meta-strong"><?= h($row['serial_number'] ?? 'Belum ada SN') ?></span></span>
                                                    <span class="meta-line"><i class="bi bi-person me-2 text-muted"></i> User: <span class="meta-strong"><?= h($row['pemilik_barang'] ?? 'Belum ada User') ?></span></span>
                                                    <?php if (!empty($row['catatan_user'])): ?>
                                                        <span class="meta-line text-danger mt-1"><i class="bi bi-exclamation-triangle me-1"></i> <?= h($row['catatan_user']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark mb-2">
                                                        <?= h($row['nama_branch_asal'] ?? '-') ?> <i class="bi bi-arrow-right mx-1 text-muted"></i> <?= h($row['nama_branch_tujuan'] ?? '-') ?>
                                                    </div>
                                                    <span class="meta-line"><i class="bi bi-calendar3 me-2 text-muted"></i> Tgl kirim: <span class="meta-strong"><?= h($row['tanggal_keluar'] ?? '-') ?></span></span>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark mb-2"><i class="bi bi-receipt me-1 text-muted"></i> <?= h($row['nomor_resi_keluar'] ?? '-') ?></div>
                                                    <span class="meta-line"><i class="bi bi-truck me-2 text-muted"></i> <?= h($row['jasa_pengiriman'] ?? '-') ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge-custom">
                                                        <i class="bi bi-hourglass-split"></i> <?= h($status !== '' ? $status : '-') ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($canApprove): ?>
                                                        <button class="btn btn-modern btnApprove" data-id="<?= (int) $row['id_pengiriman'] ?>" title="Konfirmasi Terima Barang">
                                                            <i class="bi bi-check2-all me-1"></i> Terima Barang
                                                        </button>
                                                    <?php else: ?>
                                                        <!-- Status Selesai (Meskipun query men-filter ini, dibiarkan sbg fallback) -->
                                                        <button class="btn btn-light border rounded-2 fw-bold text-muted btn-sm" disabled>
                                                            <i class="bi bi-check-circle-fill text-success me-1"></i> Selesai
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="border-0 p-0">
                                                <div class="empty-state">
                                                    <i class="bi bi-inbox"></i>
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

    <!-- SCRIPT TIDAK DIUBAH SAMA SEKALI (HANYA WARNA SWEETALERT DISESUAIKAN) -->
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
                    confirmButtonColor: '#E64312', /* Warna tombol YA Hexindo */
                    cancelButtonColor: '#231F20',  /* Warna tombol BATAL Dark */
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
                            confirmButtonColor: '#E64312'
                        });
                        location.reload();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: data.message || 'Terjadi kesalahan',
                            confirmButtonColor: '#231F20'
                        });
                    }
                }
            }
        });
    </script>
</body>

</html>