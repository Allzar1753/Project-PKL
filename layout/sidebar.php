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
$branchName = '-';
if (!empty($user['id_branch'])) {
    $branchId = (int) $user['id_branch'];
    if ($branchId > 0 && isset($koneksi)) {
        $stmtBranch = mysqli_prepare($koneksi, "SELECT nama_branch FROM tb_branch WHERE id_branch = ? LIMIT 1");
        if ($stmtBranch) {
            mysqli_stmt_bind_param($stmtBranch, 'i', $branchId);
            mysqli_stmt_execute($stmtBranch);
            $branchResult = mysqli_stmt_get_result($stmtBranch);
            $branchRow = mysqli_fetch_assoc($branchResult);
            mysqli_stmt_close($stmtBranch);
            if (!empty($branchRow['nama_branch'])) {
                $branchName = $branchRow['nama_branch'];
            }
        }
    }
}

$mainMenuActive = isMenuActive('/dashboard/')
    || isMenuActive('/Barang/')
    || isMenuActive('/Riwayat/')
    || isMenuActive('/laporan/');

$currentPath = $_SERVER['PHP_SELF'] ?? '';

$isUsersPage =
    strpos($currentPath, '/users/index.php') !== false
    || strpos($currentPath, '/users/create.php') !== false
    || strpos($currentPath, '/users/edit.php') !== false;

$isAccessPage =
    strpos($currentPath, '/users/role_permissions.php') !== false
    || strpos($currentPath, '/users/user_permissions.php') !== false;

$adminMenuActive = $isUsersPage || $isAccessPage;

$pendingResetCount = 0;

if (isset($_SESSION['user']) && strtolower($_SESSION['user']['role']) === 'admin') {
    // Pastikan variabel $koneksi tersedia, jika error tambahkan include '../config/koneksi.php'; di sini
    $stmtBadge = mysqli_prepare($koneksi, "SELECT COUNT(id) as total FROM password_reset_requests WHERE status = 'pending'");
    if ($stmtBadge) {
        mysqli_stmt_execute($stmtBadge);
        $resBadge = mysqli_stmt_get_result($stmtBadge);
        $rowBadge = mysqli_fetch_assoc($resBadge);
        $pendingResetCount = (int) ($rowBadge['total'] ?? 0);
        mysqli_stmt_close($stmtBadge);
    }
}

?>

<style>
    .sidebar-shell {
        min-height: 100vh;
        background:
            radial-gradient(circle at top left, rgba(255, 193, 7, 0.08), transparent 26%),
            linear-gradient(180deg, #343940 0%, #3d434b 45%, #464d56 100%);
        color: #fff;
        box-shadow: 8px 0 28px rgba(0, 0, 0, 0.08);
        position: sticky;
        top: 0;
        z-index: 20;
        border-right: 1px solid rgba(255, 255, 255, 0.06);
        transition: all .28s ease;
        overflow: hidden;
    }

    .sidebar-shell.sidebar-hidden {
        flex: 0 0 0 !important;
        max-width: 0 !important;
        width: 0 !important;
        min-width: 0 !important;
        padding: 0 !important;
        margin: 0 !important;
        border: 0 !important;
        box-shadow: none !important;
        opacity: 0;
        pointer-events: none;
    }

    .content-with-sidebar {
        transition: all .28s ease;
    }

    .content-with-sidebar.content-expanded {
        flex: 0 0 100% !important;
        max-width: 100% !important;
        width: 100% !important;
    }

    .sidebar-expand-btn {
        position: fixed;
        top: 18px;
        left: 18px;
        z-index: 1055;
        width: 46px;
        height: 46px;
        border: none;
        border-radius: 14px;
        background: linear-gradient(135deg, #111111, #ff8f00);
        color: #fff;
        box-shadow: 0 14px 28px rgba(255, 143, 0, 0.18);
        display: none;
        align-items: center;
        justify-content: center;
        font-size: 1.05rem;
    }

    .sidebar-expand-btn.show {
        display: inline-flex;
    }

    .sidebar-brand {
        padding: 1.15rem 1rem 1rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        background: rgba(255, 255, 255, 0.02);
        backdrop-filter: blur(6px);
    }

    .sidebar-brand-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: .75rem;
    }

    .sidebar-brand-title {
        font-weight: 800;
        font-size: 1.02rem;
        color: #fff;
        display: flex;
        align-items: center;
        gap: .55rem;
        margin-bottom: .15rem;
        letter-spacing: -.01em;
    }

    .sidebar-brand-title i {
        color: #ffc107;
        font-size: 1.05rem;
    }

    .sidebar-brand-subtitle {
        font-size: 0.8rem;
        color: rgba(255, 255, 255, 0.64);
    }

    .sidebar-toggle-btn {
        width: 40px;
        height: 40px;
        border: none;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.08);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all .2s ease;
    }

    .sidebar-toggle-btn:hover {
        background: rgba(255, 255, 255, 0.14);
    }

    .sidebar-user {
        margin: 1rem .85rem 0;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 18px;
        padding: 1rem;
        overflow: hidden;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
    }

    .sidebar-user-avatar {
        width: 46px;
        height: 46px;
        border-radius: 14px;
        background: linear-gradient(135deg, rgba(255, 193, 7, 0.22), rgba(255, 193, 7, 0.10));
        color: #ffd54f;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
        border: 1px solid rgba(255, 193, 7, 0.18);
    }

    .sidebar-user-name {
        font-weight: 700;
        font-size: .95rem;
        color: #fff;
        margin-bottom: 0.12rem;
        line-height: 1.25;
        word-break: break-word;
    }

    .sidebar-user-email {
        color: rgba(255, 255, 255, 0.58);
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
        margin-top: .55rem;
        max-width: 100%;
        white-space: normal;
        line-height: 1.2;
    }

    .sidebar-nav-wrap {
        padding: 1rem .85rem 1rem;
    }

    .sidebar-group {
        margin-bottom: .85rem;
    }

    .sidebar-group-button {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .8rem;
        border: none;
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.05);
        color: #fff;
        padding: .88rem .95rem;
        font-size: .9rem;
        font-weight: 800;
        transition: all .2s ease;
        border: 1px solid rgba(255, 255, 255, 0.06);
    }

    .sidebar-group-button:hover {
        background: rgba(255, 255, 255, 0.09);
    }

    .sidebar-group-button.is-open {
        background: linear-gradient(135deg, rgba(255, 193, 7, 0.16), rgba(255, 193, 7, 0.08));
        color: #fff6d1;
        border-color: rgba(255, 193, 7, 0.16);
    }

    .sidebar-group-button-left {
        display: flex;
        align-items: center;
        gap: .75rem;
        text-align: left;
    }

    .sidebar-group-icon {
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

    .sidebar-group-arrow {
        transition: transform .22s ease;
        color: rgba(255, 255, 255, 0.72);
    }

    .sidebar-group-button.is-open .sidebar-group-arrow {
        transform: rotate(180deg);
        color: #fff3cd;
    }

    .sidebar-submenu {
        display: none;
        padding: .55rem 0 0;
    }

    .sidebar-submenu.show {
        display: block;
    }

    .sidebar-menu {
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .sidebar-menu li {
        margin-bottom: .42rem;
    }

    .sidebar-link {
        display: flex;
        align-items: center;
        gap: .82rem;
        text-decoration: none;
        color: rgba(255, 255, 255, 0.86);
        padding: .82rem .9rem;
        border-radius: 14px;
        transition: all .2s ease;
        font-weight: 600;
        font-size: .94rem;
        position: relative;
    }

    .sidebar-link:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.07);
        transform: translateX(2px);
    }

    .sidebar-link.active {
        background: linear-gradient(135deg, #ffc107 0%, #ffb300 100%);
        color: #212529;
        box-shadow: 0 12px 24px rgba(255, 193, 7, 0.18);
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
        transition: all .2s ease;
        border: 1px solid rgba(255, 255, 255, 0.04);
    }

    .sidebar-link:hover .sidebar-icon {
        background: rgba(255, 255, 255, 0.12);
        color: #fff3cd;
    }

    .sidebar-footer {
        margin-top: auto;
        padding: .9rem .85rem 1rem;
        border-top: 1px solid rgba(255, 255, 255, 0.06);
        background: rgba(0, 0, 0, 0.04);
    }

    .sidebar-logout {
        display: flex;
        align-items: center;
        gap: .8rem;
        text-decoration: none;
        color: #ffd1d1;
        padding: .84rem .9rem;
        border-radius: 16px;
        font-weight: 700;
        font-size: .94rem;
        background: rgba(220, 53, 69, 0.10);
        border: 1px solid rgba(220, 53, 69, 0.16);
        transition: all .2s ease;
    }

    .sidebar-logout:hover {
        color: #fff;
        background: rgba(220, 53, 69, 0.18);
        transform: translateX(2px);
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

        .sidebar-expand-btn {
            top: 14px;
            left: 14px;
        }
    }
</style>

<button type="button" id="sidebarExpandBtn" class="sidebar-expand-btn" title="Buka Sidebar">
    <i class="bi bi-list"></i>
</button>

<div id="appSidebar" class="col-md-3 col-lg-2 p-0 d-flex flex-column sidebar-shell">
    <div class="sidebar-brand">
        <div class="sidebar-brand-row">
            <div>
                <div class="sidebar-brand-title">
                    <i class="bi bi-hdd-network"></i>
                    <span>IT Asset</span>
                </div>
                <div class="sidebar-brand-subtitle">Management System</div>
            </div>

            <button type="button" id="sidebarToggleBtn" class="sidebar-toggle-btn" title="Tutup Sidebar">
                <i class="bi bi-layout-sidebar-inset"></i>
            </button>
        </div>
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
                <div class="sidebar-user-email">
                    <i class="bi bi-geo-alt me-1"></i><?= h($branchName) ?>
                </div>
                <div class="sidebar-user-role">
                    <i class="bi bi-shield-lock"></i>
                    <span><?= h($role ?? '-') ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="sidebar-nav-wrap flex-grow-1">

        <div class="sidebar-group">
            <button type="button"
                class="sidebar-group-button <?= $mainMenuActive ? 'is-open' : '' ?>"
                data-menu-target="mainMenu">
                <span class="sidebar-group-button-left">
                    <span class="sidebar-group-icon"><i class="bi bi-grid-1x2-fill"></i></span>
                    <span>Main Menu</span>
                </span>
                <i class="bi bi-chevron-down sidebar-group-arrow"></i>
            </button>

            <div id="mainMenu" class="sidebar-submenu <?= $mainMenuActive ? 'show' : '' ?>">
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
            </div>
        </div>

        <?php if (can('users.view') || can('role_permissions.manage')): ?>
            <div class="sidebar-group">
                <button type="button"
                    class="sidebar-group-button <?= $adminMenuActive ? 'is-open' : '' ?>"
                    data-menu-target="adminMenu">
                    <span class="sidebar-group-button-left">
                        <span class="sidebar-group-icon"><i class="bi bi-shield-check"></i></span>
                        <span>Administrator</span>
                    </span>
                    <i class="bi bi-chevron-down sidebar-group-arrow"></i>
                </button>

                <div id="adminMenu" class="sidebar-submenu <?= $adminMenuActive ? 'show' : '' ?>">
                    <ul class="sidebar-menu">
                        <?php if (can('users.view')): ?>
                            <li>
                                <a href="<?= h(base_url('users/index.php')) ?>" class="sidebar-link <?= $isUsersPage ? 'active' : '' ?>">
                                    <span class="sidebar-icon"><i class="bi bi-people"></i></span>
                                    <span>Kelola User</span>

                                    <?php if ($pendingResetCount > 0): ?>
                                        <span class="badge bg-danger rounded-pill" style="font-size: 0.75rem;">
                                            <?= $pendingResetCount ?>
                                        </span>
                                    <?php endif; ?>

                                </a>
                            </li>
                        <?php endif; ?>

                        <?php if (can('role_permissions.manage')): ?>
                            <li>
                                <a href="<?= h(base_url('users/role_permissions.php')) ?>" class="sidebar-link <?= $isAccessPage ? 'active' : '' ?>">
                                    <span class="sidebar-icon"><i class="bi bi-key"></i></span>
                                    <span>Hak Akses</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
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
        const sidebar = document.getElementById('appSidebar');
        const toggleBtn = document.getElementById('sidebarToggleBtn');
        const expandBtn = document.getElementById('sidebarExpandBtn');
        const logoutButton = document.getElementById('btnLogoutConfirm');
        const storageSidebar = 'itasset-sidebar-hidden';
        const storageMain = 'itasset-menu-main';
        const storageAdmin = 'itasset-menu-admin';

        const content = sidebar ? sidebar.nextElementSibling : null;
        if (content) {
            content.classList.add('content-with-sidebar');
        }

        function hideSidebar() {
            if (!sidebar) return;
            sidebar.classList.add('sidebar-hidden');
            if (content) {
                content.classList.add('content-expanded');
            }
            if (expandBtn) {
                expandBtn.classList.add('show');
            }
            localStorage.setItem(storageSidebar, 'hidden');
        }

        function showSidebar() {
            if (!sidebar) return;
            sidebar.classList.remove('sidebar-hidden');
            if (content) {
                content.classList.remove('content-expanded');
            }
            if (expandBtn) {
                expandBtn.classList.remove('show');
            }
            localStorage.setItem(storageSidebar, 'shown');
        }

        if (localStorage.getItem(storageSidebar) === 'hidden') {
            hideSidebar();
        }

        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                hideSidebar();
            });
        }

        if (expandBtn) {
            expandBtn.addEventListener('click', function() {
                showSidebar();
            });
        }

        document.querySelectorAll('.sidebar-group-button').forEach(function(button) {
            const targetId = button.getAttribute('data-menu-target');
            const submenu = document.getElementById(targetId);
            if (!submenu) return;

            const storageKey = targetId === 'mainMenu' ? storageMain : storageAdmin;
            const savedState = localStorage.getItem(storageKey);

            if (savedState === 'open') {
                submenu.classList.add('show');
                button.classList.add('is-open');
            } else if (savedState === 'closed') {
                submenu.classList.remove('show');
                button.classList.remove('is-open');
            }

            button.addEventListener('click', function() {
                const isOpen = submenu.classList.contains('show');

                submenu.classList.toggle('show');
                button.classList.toggle('is-open');

                localStorage.setItem(storageKey, isOpen ? 'closed' : 'open');
            });
        });

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