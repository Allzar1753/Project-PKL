<?php
require_once __DIR__ . '/../config/auth.php';

if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('isMenuActive')) {
    function isMenuActive($keyword)
    {
        $path = $_SERVER['PHP_SELF'] ?? '';
        return strpos($path, $keyword) !== false;
    }
}

$user = current_user();
$role = current_role();
$currentPath = $_SERVER['PHP_SELF'] ?? '';
?>

<style>
    .sidebar-shell {
        min-height: 100vh;
        background: linear-gradient(180deg, #1f2328 0%, #2b3137 100%);
        color: #fff;
        box-shadow: 4px 0 20px rgba(0, 0, 0, 0.06);
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .sidebar-brand {
        padding: 1.1rem 1rem 0.9rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }

    .sidebar-brand-title {
        font-weight: 800;
        font-size: 1rem;
        color: #fff;
        display: flex;
        align-items: center;
        gap: .5rem;
        margin-bottom: .15rem;
    }

    .sidebar-brand-subtitle {
        font-size: 0.8rem;
        color: rgba(255, 255, 255, 0.62);
    }

    .sidebar-user {
        margin: .9rem .85rem 0;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 16px;
        padding: .95rem;
        overflow: hidden;
    }

    .sidebar-user-avatar {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: rgba(255, 193, 7, 0.16);
        color: #ffc107;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.08rem;
        flex-shrink: 0;
    }

    .sidebar-user-name {
        font-weight: 700;
        font-size: .95rem;
        color: #fff;
        margin-bottom: 0.1rem;
        line-height: 1.25;
        word-break: break-word;
    }

    .sidebar-user-email {
        color: rgba(255, 255, 255, 0.56);
        font-size: .78rem;
        line-height: 1.35;
        word-break: break-word;
    }

    .sidebar-user-role {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        font-size: .75rem;
        color: #ffe082;
        background: rgba(255, 193, 7, 0.10);
        border: 1px solid rgba(255, 193, 7, 0.18);
        border-radius: 999px;
        padding: .34rem .62rem;
        margin-top: .5rem;
        max-width: 100%;
        white-space: normal;
        line-height: 1.2;
    }

    .sidebar-nav-wrap {
        padding: .95rem .85rem 1rem;
    }

    .sidebar-section-label {
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: rgba(255, 255, 255, 0.42);
        font-weight: 700;
        margin: 1rem 0 .65rem;
        padding: 0 .25rem;
    }

    .sidebar-menu {
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .sidebar-menu li {
        margin-bottom: .38rem;
    }

    .sidebar-link {
        display: flex;
        align-items: center;
        gap: .82rem;
        text-decoration: none;
        color: rgba(255, 255, 255, 0.84);
        padding: .82rem .9rem;
        border-radius: 14px;
        transition: all .18s ease;
        font-weight: 600;
        font-size: .96rem;
    }

    .sidebar-link:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.07);
    }

    .sidebar-link.active {
        background: #ffc107;
        color: #212529;
        box-shadow: 0 8px 18px rgba(255, 193, 7, 0.18);
    }

    .sidebar-link.active .sidebar-icon {
        background: rgba(33, 37, 41, 0.08);
        color: #212529;
    }

    .sidebar-icon {
        width: 38px;
        height: 38px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, 0.06);
        color: #ffc107;
        font-size: 1rem;
        flex-shrink: 0;
    }

    .sidebar-link:hover .sidebar-icon {
        background: rgba(255, 255, 255, 0.12);
        color: #fff3cd;
    }

    .sidebar-footer {
        margin-top: auto;
        padding: .85rem;
    }

    .sidebar-logout {
        display: flex;
        align-items: center;
        gap: .8rem;
        text-decoration: none;
        color: #ffb3b3;
        padding: .82rem .9rem;
        border-radius: 14px;
        font-weight: 700;
        font-size: .95rem;
        background: rgba(220, 53, 69, 0.08);
        border: 1px solid rgba(220, 53, 69, 0.14);
        transition: all .18s ease;
    }

    .sidebar-logout:hover {
        color: #fff;
        background: rgba(220, 53, 69, 0.16);
    }

    .sidebar-logout .sidebar-icon {
        background: rgba(220, 53, 69, 0.12);
        color: #ffb3b3;
    }

    @media (max-width: 767.98px) {
        .sidebar-shell {
            min-height: auto;
            position: relative;
        }
    }
</style>

<div class="col-md-3 col-lg-2 p-0 d-flex flex-column sidebar-shell">
    <div class="sidebar-brand">
        <div class="sidebar-brand-title">
            <i class="bi bi-hdd-network text-warning"></i>
            <span>IT Asset</span>
        </div>
        <div class="sidebar-brand-subtitle">Management System</div>
    </div>

    <div class="sidebar-user">
        <div class="d-flex align-items-start gap-3">
            <div class="sidebar-user-avatar">
                <i class="bi bi-person-circle"></i>
            </div>
            <div class="flex-grow-1">
                <div class="sidebar-user-name">
                    <?= h($user['username'] ?? 'User') ?>
                </div>
                <div class="sidebar-user-email">
                    <?= h($user['email'] ?? '-') ?>
                </div>
                <div class="sidebar-user-role">
                    <i class="bi bi-shield-lock"></i>
                    <span><?= h($role ?? '-') ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="sidebar-nav-wrap flex-grow-1">
        <div class="sidebar-section-label">Main Menu</div>
        <ul class="sidebar-menu">
            <?php if (can('dashboard.view')): ?>
                <li>
                    <a href="<?= h(base_url('dashboard/index.php')) ?>" class="sidebar-link <?= isMenuActive('/dashboard/') ? 'active' : '' ?>">
                        <span class="sidebar-icon"><i class="bi bi-speedometer2"></i></span>
                        <span>Dashboard</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if (can('barang.view')): ?>
                <li>
                    <a href="<?= h(base_url('Barang/index.php')) ?>" class="sidebar-link <?= isMenuActive('/Barang/') ? 'active' : '' ?>">
                        <span class="sidebar-icon"><i class="bi bi-box-seam"></i></span>
                        <span>Barang</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if (can('riwayat.view')): ?>
                <li>
                    <a href="<?= h(base_url('Riwayat/index.php')) ?>" class="sidebar-link <?= isMenuActive('/Riwayat/') ? 'active' : '' ?>">
                        <span class="sidebar-icon"><i class="bi bi-clock-history"></i></span>
                        <span>Riwayat</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if (can('laporan.view')): ?>
                <li>
                    <a href="<?= h(base_url('laporan/index.php')) ?>" class="sidebar-link <?= isMenuActive('/laporan/') ? 'active' : '' ?>">
                        <span class="sidebar-icon"><i class="bi bi-file-earmark-text"></i></span>
                        <span>Laporan</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>

        <?php if (can('users.view') || can('role_permissions.manage')): ?>
            <div class="sidebar-section-label">Administration</div>
            <ul class="sidebar-menu">
                <?php if (can('users.view')): ?>
                    <li>
                        <a href="<?= h(base_url('users/index.php')) ?>" class="sidebar-link <?= isMenuActive('/users/') ? 'active' : '' ?>">
                            <span class="sidebar-icon"><i class="bi bi-people"></i></span>
                            <span>Kelola User</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (can('role_permissions.manage')): ?>
                    <li>
                        <a href="<?= h(base_url('users/role_permissions.php')) ?>" class="sidebar-link <?= strpos($currentPath, 'role_permissions.php') !== false ? 'active' : '' ?>">
                            <span class="sidebar-icon"><i class="bi bi-shield-check"></i></span>
                            <span>Hak Akses</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer">
        <a href="#" id="btnLogoutConfirm" class="sidebar-logout">
            <span class="sidebar-icon"><i class="bi bi-box-arrow-right"></i></span>
            <span>Logout</span>
        </a>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const logoutButton = document.getElementById('btnLogoutConfirm');

        if (!logoutButton) {
            return;
        }

        logoutButton.addEventListener('click', function(e) {
            e.preventDefault();

            if (typeof Swal === 'undefined') {
                const confirmed = confirm('Apakah Anda ingin logout?');
                if (confirmed) {
                    window.location.href = '<?= h(base_url('auth/logout.php')) ?>';
                }
                return;
            }

            Swal.fire({
                title: 'Logout?',
                text: 'Apakah Anda ingin logout dari sistem?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, logout',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '<?= h(base_url('auth/logout.php')) ?>';
                }
            });
        });
    });
</script>