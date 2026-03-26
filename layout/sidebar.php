<?php
require_once __DIR__ . '/../config/auth.php';

if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$user = current_user();
$role = current_role();

$currentPath = $_SERVER['PHP_SELF'] ?? '';

function isMenuActive($keyword)
{
    $path = $_SERVER['PHP_SELF'] ?? '';
    return strpos($path, $keyword) !== false;
}
?>

<style>
    .sidebar-shell {
        min-height: 100vh;
        background: linear-gradient(180deg, #1f2328 0%, #2c3136 100%);
        color: #fff;
        box-shadow: 6px 0 24px rgba(0, 0, 0, 0.08);
        position: sticky;
        top: 0;
    }

    .sidebar-brand {
        padding: 1.4rem 1.2rem 1rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }

    .sidebar-brand-title {
        font-weight: 800;
        font-size: 1.1rem;
        margin-bottom: 0.15rem;
        color: #fff;
    }

    .sidebar-brand-subtitle {
        font-size: 0.85rem;
        color: rgba(255, 255, 255, 0.65);
    }

    .sidebar-user {
        margin: 1rem 1rem 0;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 16px;
        padding: 1rem;
    }

    .sidebar-user-avatar {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        background: rgba(255, 193, 7, 0.18);
        color: #ffc107;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        flex-shrink: 0;
    }

    .sidebar-user-name {
        font-weight: 700;
        font-size: 0.95rem;
        color: #fff;
        margin-bottom: 0.1rem;
    }

    .sidebar-user-role {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        font-size: 0.8rem;
        color: #ffe082;
        background: rgba(255, 193, 7, 0.12);
        border: 1px solid rgba(255, 193, 7, 0.2);
        border-radius: 999px;
        padding: .35rem .65rem;
        margin-top: .45rem;
    }

    .sidebar-nav-wrap {
        padding: 1rem;
    }

    .sidebar-section-label {
        font-size: 0.76rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: rgba(255, 255, 255, 0.45);
        font-weight: 700;
        margin: 1rem 0 0.75rem;
        padding: 0 .35rem;
    }

    .sidebar-menu {
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .sidebar-menu li {
        margin-bottom: .35rem;
    }

    .sidebar-link {
        display: flex;
        align-items: center;
        gap: .85rem;
        text-decoration: none;
        color: rgba(255, 255, 255, 0.82);
        padding: .82rem .95rem;
        border-radius: 14px;
        transition: all .18s ease;
        font-weight: 600;
    }

    .sidebar-link:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.07);
    }

    .sidebar-link.active {
        background: #ffc107;
        color: #212529;
        box-shadow: 0 8px 20px rgba(255, 193, 7, 0.22);
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
        background: rgba(255, 255, 255, 0.07);
        color: #ffc107;
        font-size: 1rem;
        flex-shrink: 0;
    }

    .sidebar-link:hover .sidebar-icon {
        color: #fff3cd;
        background: rgba(255, 255, 255, 0.12);
    }

    .sidebar-footer {
        margin-top: auto;
        padding: 1rem;
    }

    .sidebar-logout {
        display: flex;
        align-items: center;
        gap: .85rem;
        text-decoration: none;
        color: #ffb3b3;
        padding: .82rem .95rem;
        border-radius: 14px;
        font-weight: 700;
        background: rgba(220, 53, 69, 0.08);
        border: 1px solid rgba(220, 53, 69, 0.16);
        transition: all .18s ease;
    }

    .sidebar-logout:hover {
        color: #fff;
        background: rgba(220, 53, 69, 0.18);
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

<div class="col-md-2 p-0 d-flex flex-column sidebar-shell">
    <div class="sidebar-brand">
        <div class="sidebar-brand-title">
            <i class="bi bi-hdd-network me-2 text-warning"></i>IT Asset
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
                <div class="text-white-50 small">
                    <?= h($user['email'] ?? '-') ?>
                </div>
                <div class="sidebar-user-role">
                    <i class="bi bi-shield-lock"></i>
                    <?= h($role ?? '-') ?>
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

        <?php if (is_super_admin()): ?>
            <div class="sidebar-section-label">Administration</div>
            <ul class="sidebar-menu">
                <li>
                    <a href="<?= h(base_url('users/index.php')) ?>" class="sidebar-link <?= isMenuActive('/users/') ? 'active' : '' ?>">
                        <span class="sidebar-icon"><i class="bi bi-people"></i></span>
                        <span>Kelola User</span>
                    </a>
                </li>

                <li>
                    <a href="<?= h(base_url('users/role_permissions.php')) ?>" class="sidebar-link <?= strpos($currentPath, 'role_permissions.php') !== false ? 'active' : '' ?>">
                        <span class="sidebar-icon"><i class="bi bi-shield-check"></i></span>
                        <span>Hak Akses</span>
                    </a>
                </li>
            </ul>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer">
        <a href="<?= h(base_url('auth/logout.php')) ?>" class="sidebar-logout">
            <span class="sidebar-icon"><i class="bi bi-box-arrow-right"></i></span>
            <span>Logout</span>
        </a>
    </div>
</div>