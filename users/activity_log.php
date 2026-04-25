<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'users.view');

if (!is_admin()) {
    http_response_code(403);
    exit('Halaman ini khusus administrator.');
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$onlineThresholdMinutes = 5;
$cutoff = date('Y-m-d H:i:s', time() - ($onlineThresholdMinutes * 60));

$qUsers = mysqli_query($koneksi, "
    SELECT
        u.id,
        u.username,
        u.email,
        u.role,
        b.nama_branch,
        p.last_seen_at
    FROM users u
    LEFT JOIN tb_branch b ON b.id_branch = u.id_branch
    LEFT JOIN user_presence p ON p.user_id = u.id
    WHERE u.role = 'user'
    ORDER BY b.nama_branch ASC, u.username ASC
");

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring User Online - IT Asset Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php require_once '../layout/sidebar.php'; ?>
        <div class="col-md-10 ms-auto">
            <div class="p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div>
                        <h4 class="fw-bold mb-1"><i class="bi bi-activity me-2"></i>Monitoring User Cabang</h4>
                        <div class="text-muted">Status online user cabang (online jika aktif dalam <?= (int) $onlineThresholdMinutes ?> menit terakhir).</div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-dark text-white">
                        <div class="fw-bold"><i class="bi bi-people me-2"></i>Status Online / Offline User Cabang</div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>No</th>
                                    <th>Cabang</th>
                                    <th>User</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Terakhir Aktif</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($qUsers && mysqli_num_rows($qUsers) > 0): ?>
                                    <?php $no = 1; ?>
                                    <?php while ($u = mysqli_fetch_assoc($qUsers)): ?>
                                        <?php
                                            $lastSeen = (string) ($u['last_seen_at'] ?? '');
                                            $isOnline = $lastSeen !== '' && strtotime($lastSeen) >= strtotime($cutoff);
                                            $lastSeenFormatted = '-';
                                            if ($lastSeen !== '') {
                                                $timestamp = strtotime($lastSeen);
                                                if ($timestamp !== false) {
                                                    $lastSeenFormatted = date('d-m-Y H:i:s', $timestamp) . ' WIB';
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td><?= $no++ ?></td>
                                            <td><?= h($u['nama_branch'] ?? '-') ?></td>
                                            <td class="fw-semibold"><?= h($u['username'] ?? '-') ?></td>
                                            <td class="text-muted"><?= h($u['email'] ?? '-') ?></td>
                                            <td>
                                                <?php if ($isOnline): ?>
                                                    <span class="badge bg-success rounded-pill">Online</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary rounded-pill">Offline</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-nowrap text-muted"><?= h($lastSeenFormatted) ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted p-3">Belum ada data user.</td>
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
</body>
</html>

