<?php

/** @var mysqli $koneksi */ //
include '../config/koneksi.php';
require_once '../config/auth.php';

require_admin();
refresh_permissions($koneksi);

$currentRole = 'user';

if (!function_exists('redirect_role_permissions')) {
    function redirect_role_permissions(): void
    {
        redirect_to(base_url('users/role_permissions.php'));
    }
}

if (!function_exists('role_color_class')) {
    function role_color_class(string $role): string
    {
        return $role === 'admin' ? 'role-admin' : 'role-user';
    }
}

if (!function_exists('role_button_class')) {
    function role_button_class(string $role, string $currentRole): string
    {
        if ($role === $currentRole) {
            return $role === 'admin'
                ? 'role-switch-btn is-active admin'
                : 'role-switch-btn is-active user';
        }

        return $role === 'admin'
            ? 'role-switch-btn admin'
            : 'role-switch-btn user';
    }
}

function get_assigned_permission_ids(mysqli $koneksi, string $role): array
{
    $assignedIds = [];

    $stmt = mysqli_prepare($koneksi, "SELECT permission_id FROM rbac_role_permissions WHERE role = ?");
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, 's', $role);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $assignedIds[] = (int) $row['permission_id'];
    }

    mysqli_stmt_close($stmt);

    return $assignedIds;
}

function get_all_permission_ids(mysqli $koneksi): array
{
    $ids = [];

    $result = mysqli_query($koneksi, "SELECT id FROM rbac_permissions");
    if (!$result) {
        return [];
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $ids[] = (int) $row['id'];
    }

    return $ids;
}

function get_grouped_permissions(mysqli $koneksi): array
{
    $groupedPermissions = [];

    $query = mysqli_query(
        $koneksi,
        "SELECT id, permission_key, permission_name, menu_group
         FROM rbac_permissions
         ORDER BY menu_group, permission_name"
    );

    if (!$query) {
        return [];
    }

    while ($permission = mysqli_fetch_assoc($query)) {
        $group = $permission['menu_group'] ?: 'Lainnya';
        $groupedPermissions[$group][] = $permission;
    }

    return $groupedPermissions;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = 'user';

    $selectedPermissions = array_map('intval', $_POST['permissions'] ?? []);
    $selectedPermissions = array_values(array_unique(array_filter($selectedPermissions, function ($id) {
        return $id > 0;
    })));

    $validPermissionIds = get_all_permission_ids($koneksi);
    $validPermissionMap = array_fill_keys($validPermissionIds, true);

    $selectedPermissions = array_values(array_filter($selectedPermissions, function ($id) use ($validPermissionMap) {
        return isset($validPermissionMap[$id]);
    }));

    mysqli_begin_transaction($koneksi);

    try {
        $deleteStmt = mysqli_prepare($koneksi, "DELETE FROM rbac_role_permissions WHERE role = ?");
        if (!$deleteStmt) {
            throw new Exception('Gagal menyiapkan query hapus permission.');
        }

        mysqli_stmt_bind_param($deleteStmt, 's', $role);

        if (!mysqli_stmt_execute($deleteStmt)) {
            throw new Exception(mysqli_stmt_error($deleteStmt));
        }

        mysqli_stmt_close($deleteStmt);

        if (!empty($selectedPermissions)) {
            $insertStmt = mysqli_prepare(
                $koneksi,
                "INSERT INTO rbac_role_permissions (role, permission_id) VALUES (?, ?)"
            );

            if (!$insertStmt) {
                throw new Exception('Gagal menyiapkan query simpan permission.');
            }

            foreach ($selectedPermissions as $permissionId) {
                mysqli_stmt_bind_param($insertStmt, 'si', $role, $permissionId);

                if (!mysqli_stmt_execute($insertStmt)) {
                    throw new Exception(mysqli_stmt_error($insertStmt));
                }
            }

            mysqli_stmt_close($insertStmt);
        }

        mysqli_commit($koneksi);
        set_flash('success', 'Hak akses role User berhasil disimpan.');
    } catch (Throwable $e) {
        mysqli_rollback($koneksi);
        set_flash('error', 'Gagal menyimpan hak akses role: ' . $e->getMessage());
    }

    redirect_role_permissions();
}

$assignedIds = get_assigned_permission_ids($koneksi, $currentRole);
$groupedPermissions = get_grouped_permissions($koneksi);

$allPermissionsCount = 0;
foreach ($groupedPermissions as $items) {
    $allPermissionsCount += count($items);
}

$error = get_flash('error');
$success = get_flash('success');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atur Hak Akses Role - IT Asset</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- CSS SINKRONISASI TEMA HEXINDO -->
    <style>
        :root {
            /* TEMA HEXINDO */
            --orange-1: #E64312; 
            --orange-2: #F25C05;
            --dark-1: #231F20;
            --text-main: #333333;
            --text-soft: #666666;
            --surface-bg: #F4F6F9;
            --border-soft: #E0E4E8;
            --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.04);
            --shadow-hover: 0 10px 25px rgba(0,0,0,0.08);
            --radius-box: 8px; /* Industrial Sharp Edges */
        }

        * { box-sizing: border-box; }

        body {
            background-color: var(--surface-bg);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
        }

        .page-shell { padding: 24px 32px; }
        @media (max-width: 991.98px) { .page-shell { padding: 18px; } }

        /* Hero Banner Hexindo */
        .hero-card {
            position: relative;
            background: var(--dark-1);
            border-top: 4px solid var(--orange-1);
            border-radius: var(--radius-box);
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-soft);
        }

        .hero-content { position: relative; z-index: 2; }

        .page-title {
            color: #fff; font-size: 1.6rem; font-weight: 700; margin-bottom: 0.25rem;
        }

        .page-subtitle {
            color: #9ca3af; margin-bottom: 0; font-size: 0.95rem; max-width: 760px;
        }

        /* Tombol Aksi Header */
        .hero-action {
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.1);
            color: #fff; font-weight: 600;
            border-radius: var(--radius-box);
            padding: 0.6rem 1.2rem;
            text-decoration: none;
            display: inline-flex; align-items: center;
            transition: all 0.2s; font-size: 0.9rem;
        }
        .hero-action:hover { background: rgba(255, 255, 255, 0.2); color: #fff; }

        /* Alert Bawaan */
        .alert { border: none; border-radius: var(--radius-box); padding: 14px 16px; box-shadow: var(--shadow-soft); }

        /* General Card Base */
        .summary-card, .info-card, .permission-card {
            background: #fff; border: 1px solid var(--border-soft); border-radius: var(--radius-box); box-shadow: var(--shadow-soft); overflow: hidden;
        }

        /* Summary Cards Hexindo */
        .summary-card {
            position: relative; height: 100%; padding: 1.25rem 1.5rem; transition: all 0.2s ease;
            border-left: 4px solid var(--orange-1);
        }
        .summary-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-hover); }
        .summary-label { font-size: 0.85rem; color: var(--text-soft); font-weight: 600; margin-bottom: 0.35rem; }
        .summary-value { font-size: 1.8rem; font-weight: 800; color: var(--dark-1); margin-bottom: 0.2rem; }
        .summary-note { font-size: 0.8rem; color: #9ca3af; }
        .summary-icon {
            width: 45px; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem; color: var(--orange-1); background: rgba(230, 67, 18, 0.1);
        }

        .role-admin { color: #2563eb !important; }
        .role-user { color: #16a34a !important; }

        /* Info Card */
        .info-card {
            padding: 1.5rem; margin-bottom: 1.5rem; background: #fff;
        }
        .info-title { font-size: 1.05rem; font-weight: 700; color: var(--dark-1); margin-bottom: 0.35rem; }
        .info-text { color: var(--text-soft); line-height: 1.6; margin-bottom: 0; font-size: 0.9rem; }

        /* Role Switch Buttons */
        .role-switch { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .role-switch-btn {
            border-radius: var(--radius-box); font-weight: 600; padding: 0.6rem 1.2rem; text-decoration: none; border: 1px solid var(--border-soft); transition: all 0.2s ease; display: inline-flex; align-items: center; justify-content: center; min-width: 130px; background: #fff; font-size: 0.9rem;
        }

        .role-switch-btn.admin { color: #2563eb; }
        .role-switch-btn.user { color: #16a34a; }
        .role-switch-btn:hover { background-color: var(--surface-bg); }

        .role-switch-btn.is-active.admin { background: #2563eb; color: #fff; border-color: transparent; }
        .role-switch-btn.is-active.user { background: #16a34a; color: #fff; border-color: transparent; }

        /* Permission Card Form */
        .permission-card-header {
            padding: 1.2rem 1.5rem; background: #fff; color: var(--dark-1); border-bottom: 1px solid var(--border-soft);
        }
        .permission-card-title { font-size: 1.05rem; font-weight: 700; margin-bottom: 0.2rem; }
        .section-subinfo { font-size: 0.85rem; color: var(--text-soft); }

        .permission-card-body { padding: 1.5rem; background: #fff; }

        /* Permission Groups Box */
        .permission-group {
            border: 1px solid var(--border-soft); border-radius: var(--radius-box); padding: 1.2rem; background: #F9FAFB; height: 100%;
        }
        .permission-group-title {
            font-weight: 700; color: var(--dark-1); margin-bottom: 1rem; font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .permission-group-title i { color: var(--orange-1); }

        /* Individual Permission Item */
        .permission-item {
            padding: 0.8rem 1rem; border: 1px solid var(--border-soft); border-radius: var(--radius-box); background: #fff; height: 100%; transition: all 0.2s ease;
        }
        .permission-item:hover { border-color: var(--orange-1); box-shadow: 0 4px 12px rgba(230, 67, 18, 0.05); }

        .permission-item .form-check { margin: 0; display: flex; align-items: flex-start; gap: 0.7rem; }
        .permission-item .form-check-input {
            margin-top: 0.2rem; width: 18px; height: 18px; border-radius: 4px; cursor: pointer; border-color: #d1d5db;
        }
        .permission-item .form-check-input:checked { background-color: var(--orange-1); border-color: var(--orange-1); }
        
        /* Outline fokus checkbox Hexindo */
        .permission-item .form-check-input:focus { box-shadow: 0 0 0 0.25rem rgba(230, 67, 18, 0.25); }

        .permission-item .form-check-label { cursor: pointer; line-height: 1.4; }
        .permission-name { font-size: 0.9rem; font-weight: 600; color: var(--dark-1); display: block; margin-bottom: 0.15rem; }
        .permission-key { color: var(--text-soft); font-size: 0.8rem; word-break: break-word; font-family: monospace;}

        /* Action Buttons Area */
        .sticky-action {
            position: sticky; bottom: 0; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(5px); padding: 1rem 0 0 0; margin-top: 1.5rem; border-top: 1px solid var(--border-soft);
        }

        .action-btn { border-radius: var(--radius-box); font-weight: 600; padding: 0.6rem 1.2rem; min-width: 140px; transition: all 0.2s ease; font-size: 0.9rem; }
        .btn-soft { background: #fff; border: 1px solid var(--border-soft); color: var(--text-main); }
        .btn-soft:hover { background: var(--surface-bg); color: var(--dark-1); }

        .btn-save { border: none; color: #fff; background-color: var(--orange-1); }
        .btn-save:hover { background-color: var(--orange-2); color: #fff; }

        @media (max-width: 991.98px) {
            .hero-card { padding: 1.3rem 1.15rem; }
            .page-title { font-size: 1.4rem; }
            .summary-value { font-size: 1.7rem; }
            .permission-card-body, .permission-card-header, .info-card { padding: 1rem; }
            .sticky-action { position: static; background: transparent; }
        }

        @media (max-width: 575.98px) {
            .page-title { font-size: 1.2rem; }
            .page-subtitle { font-size: .9rem; }
            .hero-action, .role-switch-btn, .action-btn { width: 100%; }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="d-flex flex-nowrap w-100 overflow-hidden">

            <?php include '../layout/sidebar.php'; ?>

            <div id="mainContent" class="flex-grow-1" style="transition: all 0.28s ease; min-width: 0;">

                <div class="page-shell">

                    <div class="hero-card">
                        <div class="hero-content d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div>
                                <h1 class="page-title">Atur Hak Akses Role Sistem</h1>
                                <p class="page-subtitle">
                                    Kelola batasan fitur dan menu (permissions) untuk role Admin dan User secara terpusat untuk menjaga keamanan sistem.
                                </p>
                            </div>

                            <a href="<?= e(base_url('users/index.php')) ?>" class="hero-action">
                                <i class="bi bi-people me-2"></i>Kembali ke Kelola User
                            </a>
                        </div>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($error) ?></div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i><?= e($success) ?></div>
                    <?php endif; ?>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Role Yang Dipilih</div>
                                        <div class="summary-value <?= role_color_class($currentRole) ?>">
                                            <?= strtoupper(e($currentRole)) ?>
                                        </div>
                                        <div class="summary-note">Target konfigurasi saat ini</div>
                                    </div>
                                    <div class="summary-icon">
                                        <i class="bi bi-person-badge"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="summary-card" style="border-left-color: #10b981;">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Akses Diizinkan</div>
                                        <div class="summary-value text-success"><?= count($assignedIds) ?></div>
                                        <div class="summary-note">Fitur yang sedang dicentang</div>
                                    </div>
                                    <div class="summary-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                                        <i class="bi bi-check2-square"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="summary-card" style="border-left-color: #6b7280;">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="summary-label">Total Modul Sistem</div>
                                        <div class="summary-value text-muted"><?= $allPermissionsCount ?></div>
                                        <div class="summary-note">Seluruh hak akses yang tersedia</div>
                                    </div>
                                    <div class="summary-icon" style="background: rgba(107, 114, 128, 0.1); color: #6b7280;">
                                        <i class="bi bi-diagram-3"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="row g-3 align-items-center">
                            <div class="col-lg-8">
                                <div class="info-title"><i class="bi bi-info-circle-fill me-2 text-primary"></i>Informasi Konfigurasi</div>
                                <p class="info-text">
                                    Administrator (Admin HO) secara default memiliki akses <b>Penuh/Bypass</b> ke seluruh sistem. Pengaturan kotak centang di bawah ini saat ini ditujukan untuk mengatur pembatasan hak akses operasional untuk role
                                    <b>User Cabang</b>.
                                </p>
                            </div>

                            <div class="col-lg-4">
                                <div class="role-switch justify-content-lg-end">
                                    <!-- Menambahkan tulisan '(User Cabang)' untuk kejelasan -->
                                    <a href="<?= e(base_url('users/role_permissions.php')) ?>"
                                        class="<?= role_button_class('user', $currentRole) ?>">
                                        Konfigurasi Role: USER CABANG
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <form method="POST" id="formRolePermissions" class="permission-card">
                        <input type="hidden" name="role" value="<?= e($currentRole) ?>">

                        <div class="permission-card-header">
                            <div class="permission-card-title">
                                Hak Akses Untuk: <?= strtoupper(e($currentRole)) ?>
                            </div>
                            <div class="section-subinfo">
                                Centang fitur yang Anda izinkan untuk dibuka/dilakukan oleh role ini.
                            </div>
                        </div>

                        <div class="permission-card-body">
                            <div class="row g-3">
                                <?php foreach ($groupedPermissions as $group => $items): ?>
                                    <div class="col-xl-4 col-md-6"> <!-- Ubah ukuran kolom agar muat 3 grid di layar lebar -->
                                        <div class="permission-group">
                                            <div class="permission-group-title">
                                                <i class="bi bi-folder2-open"></i> MODUL <?= e($group) ?>
                                            </div>

                                            <div class="row g-2">
                                                <?php foreach ($items as $item): ?>
                                                    <div class="col-12">
                                                        <div class="permission-item">
                                                            <div class="form-check">
                                                                <input class="form-check-input"
                                                                    type="checkbox"
                                                                    name="permissions[]"
                                                                    value="<?= (int) $item['id'] ?>"
                                                                    id="permission<?= (int) $item['id'] ?>"
                                                                    <?= in_array((int) $item['id'], $assignedIds, true) ? 'checked' : '' ?>>

                                                                <label class="form-check-label" for="permission<?= (int) $item['id'] ?>">
                                                                    <span class="permission-name"><?= e($item['permission_name']) ?></span>
                                                                    <span class="permission-key"><?= e($item['permission_key']) ?></span>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="sticky-action">
                                <div class="d-flex justify-content-end gap-2 flex-wrap">
                                    <button type="button" id="btnCheckAll" class="btn action-btn btn-soft">
                                        <i class="bi bi-check-all me-1"></i> Centang Semua
                                    </button>

                                    <button type="button" id="btnUncheckAll" class="btn action-btn btn-soft">
                                        <i class="bi bi-square me-1"></i> Kosongkan Semua
                                    </button>

                                    <button type="submit" class="btn action-btn btn-save">
                                        <i class="bi bi-save me-1"></i> Simpan Hak Akses
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPT TIDAK ADA YANG DIUBAH SAMA SEKALI -->
    <script>
        document.getElementById('btnCheckAll').addEventListener('click', function() {
            document.querySelectorAll('#formRolePermissions input[type="checkbox"]').forEach(function(checkbox) {
                checkbox.checked = true;
            });
        });

        document.getElementById('btnUncheckAll').addEventListener('click', function() {
            document.querySelectorAll('#formRolePermissions input[type="checkbox"]').forEach(function(checkbox) {
                checkbox.checked = false;
            });
        });

        document.getElementById('formRolePermissions').addEventListener('submit', function(e) {
            e.preventDefault();

            const role = document.querySelector('input[name="role"]').value;
            const checkedCount = document.querySelectorAll('#formRolePermissions input[type="checkbox"]:checked').length;

            Swal.fire({
                title: 'Simpan hak akses?',
                text: 'Permission untuk role "' + role + '" akan diperbarui. Total modul diizinkan: ' + checkedCount + '.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Simpan',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#E64312', /* Warna tombol YA Hexindo */
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    e.target.submit();
                }
            });
        });
    </script>
</body>

</html>