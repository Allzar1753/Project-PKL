<?php
/** @var mysqli $koneksi */ 
require_once __DIR__ . '/../config/auth.php';

if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('isMenuActive')) {
    function isMenuActive($keyword) {
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

$mainMenuActive = isMenuActive('/dashboard/') || isMenuActive('/Barang/') || isMenuActive('/Riwayat/') || isMenuActive('/laporan/');
$isMonitoringPage = isMenuActive('/monitoring/');
$monitoringMenuActive = $isMonitoringPage;
$isUsersPage = strpos($currentPath, '/users/index.php') !== false || strpos($currentPath, '/users/create.php') !== false || strpos($currentPath, '/users/edit.php') !== false;
$isAccessPage = strpos($currentPath, '/users/role_permissions.php') !== false || strpos($currentPath, '/users/user_permissions.php') !== false;
$adminMenuActive = $isUsersPage || $isAccessPage;

$pendingResetCount = 0;
$pendingHoShippingCount = 0;

if (isset($_SESSION['user']) && strtolower($_SESSION['user']['role']) === 'admin') {
    $stmtBadge = mysqli_prepare($koneksi, "SELECT COUNT(id) as total FROM password_reset_requests WHERE status = 'pending'");
    if ($stmtBadge) {
        mysqli_stmt_execute($stmtBadge);
        $resBadge = mysqli_stmt_get_result($stmtBadge);
        $rowBadge = mysqli_fetch_assoc($resBadge);
        $pendingResetCount = (int) ($rowBadge['total'] ?? 0);
        mysqli_stmt_close($stmtBadge);
    }

    $stmtShip = mysqli_prepare($koneksi, "SELECT COUNT(id_pengiriman_ho) as total FROM pengiriman_cabang_ho WHERE COALESCE(status_pengiriman,'') = 'Menunggu persetujuan admin'");
    if ($stmtShip) {
        mysqli_stmt_execute($stmtShip);
        $resShip = mysqli_stmt_get_result($stmtShip);
        $rowShip = mysqli_fetch_assoc($resShip);
        $pendingHoShippingCount = (int) ($rowShip['total'] ?? 0);
        mysqli_stmt_close($stmtShip);
    }
}
?>

<style>
    :root {
        /* Warna yang disamakan dengan tema Dashboard Anda */
        --orange-1: #E64312;
        --orange-2: #F25C05;
        --dark-1: #231F20;
        --text-main: #2d3136;
        --text-soft: #6b7280;
        --surface-bg: #F4F6F9;
        --border-soft: #e5e7eb;
        
        --sb-width: 280px;
        --sb-transition: 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
    }

    /* SIDEBAR BASE - Tema Terang tanpa Scroll */
    .sidebar-shell {
        width: var(--sb-width);
        min-width: var(--sb-width);
        min-height: 100vh;
        background: #ffffff; /* Background Putih Bersih */
        color: var(--text-main);
        box-shadow: 4px 0 24px rgba(0, 0, 0, 0.03);
        position: sticky;
        top: 0;
        z-index: 1040;
        border-right: 1px solid var(--border-soft);
        transition: margin-left var(--sb-transition), transform var(--sb-transition);
        
        /* SCROLL DIMATIKAN SESUAI PERMINTAAN */
        overflow-y: hidden;
        overflow-x: hidden;
    }

    /* DESKTOP HIDE */
    .sidebar-shell.sidebar-hidden {
        margin-left: calc(var(--sb-width) * -1);
    }

    /* CONTENT WRAPPER */
    .content-with-sidebar {
        transition: margin-left var(--sb-transition);
        flex: 1;
        min-width: 0;
    }

    /* MOBILE ADJUSTMENTS */
    @media (max-width: 991.98px) {
        .sidebar-shell {
            position: fixed;
            left: 0;
            top: 0;
            margin-left: 0 !important;
            transform: translateX(-100%);
        }
        .sidebar-shell.show-mobile { transform: translateX(0); }
        .sidebar-shell.sidebar-hidden { transform: translateX(-100%); }
        .content-with-sidebar { margin-left: 0 !important; }
    }

    /* BACKDROP */
    .sidebar-backdrop {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(35, 31, 32, 0.4);
        backdrop-filter: blur(2px);
        z-index: 1035;
        display: none;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    .sidebar-backdrop.active { display: block; opacity: 1; }

    /* FLOATING EXPAND BUTTON - Tema Dark/Orange */
    .sidebar-expand-btn {
        position: fixed;
        top: 18px;
        left: 18px;
        z-index: 1030;
        width: 46px;
        height: 46px;
        border: none;
        border-radius: 14px;
        background: var(--dark-1);
        color: #fff;
        box-shadow: 0 8px 20px rgba(35, 31, 32, 0.2);
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 1.15rem;
        opacity: 0; visibility: hidden;
        transform: scale(0.8);
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        cursor: pointer;
    }
    .sidebar-expand-btn:hover { background: var(--orange-1); }
    .sidebar-expand-btn.show { opacity: 1; visibility: visible; transform: scale(1); }

    /* BRAND SECTION */
    .sidebar-brand { 
        padding: 1.15rem 1rem 1rem; 
        border-bottom: 1px solid var(--border-soft); 
        background: #ffffff; 
        position: sticky; top: 0; z-index: 10; 
    }
    .sidebar-brand-row { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
    .sidebar-brand-title { font-weight: 800; font-size: 1.15rem; color: var(--dark-1); display: flex; align-items: center; gap: .6rem; }
    .sidebar-brand-title i { color: var(--orange-1); font-size: 1.25rem; background: rgba(230, 67, 18, 0.1); padding: 6px; border-radius: 8px; }
    .sidebar-brand-subtitle { font-size: 0.78rem; color: var(--text-soft); }
    .sidebar-toggle-btn { width: 38px; height: 38px; border: none; border-radius: 12px; background: var(--surface-bg); color: var(--text-main); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s;}
    .sidebar-toggle-btn:hover { background: var(--border-soft); }

    /* USER SECTION */
    .sidebar-user { 
        margin: 1rem .85rem 0; 
        background: #ffffff; 
        border: 1px solid var(--border-soft); 
        border-radius: 16px; 
        padding: 1rem; 
        box-shadow: 0 2px 10px rgba(0,0,0,0.02);
    }
    .sidebar-user-avatar { width: 46px; height: 46px; border-radius: 14px; background: rgba(230, 67, 18, 0.1); color: var(--orange-1); display: inline-flex; align-items: center; justify-content: center; font-size: 1.2rem; }
    .sidebar-user-name { font-weight: 800; font-size: .95rem; color: var(--dark-1); line-height: 1.25; }
    .sidebar-user-email { color: var(--text-soft); font-size: .78rem; font-weight: 500;}
    .sidebar-user-role { display: inline-flex; align-items: center; gap: .35rem; font-size: .75rem; font-weight: 700; color: var(--dark-1); background: var(--surface-bg); border: 1px solid var(--border-soft); border-radius: 999px; padding: .34rem .7rem; margin-top: .6rem; }

    /* NAV & MENU */
    .sidebar-nav-wrap { padding: 1rem .85rem 1rem; }
    .sidebar-group { margin-bottom: .85rem; }
    .sidebar-group-button { 
        width: 100%; display: flex; align-items: center; justify-content: space-between; 
        border: none; border-radius: 14px; 
        background: transparent; color: var(--text-main); 
        padding: .8rem .95rem; cursor: pointer; 
        font-weight: 600; transition: all 0.2s;
    }
    .sidebar-group-button:hover { background: var(--surface-bg); }
    .sidebar-group-button.is-open { background: var(--surface-bg); color: var(--orange-1); }
    
    .sidebar-group-icon { width: 36px; height: 36px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: var(--surface-bg); color: var(--text-soft); transition: 0.2s;}
    .sidebar-group-button:hover .sidebar-group-icon { color: var(--dark-1); }
    .sidebar-group-button.is-open .sidebar-group-icon { background: #ffffff; color: var(--orange-1); box-shadow: 0 2px 6px rgba(0,0,0,0.05);}
    
    .sidebar-group-arrow { transition: transform .3s ease; font-size: 0.85rem;}
    .sidebar-group-button.is-open .sidebar-group-arrow { transform: rotate(180deg); }
    
    .sidebar-submenu { display: grid; grid-template-rows: 0fr; transition: grid-template-rows 0.3s ease; }
    .sidebar-submenu.show { grid-template-rows: 1fr; }
    .sidebar-submenu-inner { overflow: hidden; padding-top: 0.5rem; }
    .sidebar-menu { list-style: none; padding: 0; margin: 0; }
    
    .sidebar-link { 
        display: flex; align-items: center; gap: .82rem; text-decoration: none; 
        color: var(--text-soft); padding: .75rem .9rem; border-radius: 12px; 
        font-weight: 600; transition: 0.2s; 
    }
    .sidebar-icon { width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; color: inherit; font-size: 1.1rem; transition: 0.2s;}
    
    .sidebar-link:hover { background: var(--surface-bg); transform: translateX(4px); color: var(--orange-1); }
    .sidebar-link.active { background: rgba(230, 67, 18, 0.08); color: var(--orange-1); }
    .sidebar-link.active .sidebar-icon { background: #ffffff; box-shadow: 0 2px 6px rgba(230, 67, 18, 0.15); }

    /* FOOTER */
    .sidebar-footer { margin-top: auto; padding: .9rem .85rem 1rem; border-top: 1px solid var(--border-soft); background: #ffffff; position: sticky; bottom: 0; }
    .sidebar-logout { 
        display: flex; align-items: center; gap: .8rem; text-decoration: none; 
        color: #ef4444; padding: .8rem .9rem; border-radius: 14px; 
        font-weight: 700; background: rgba(239, 68, 68, 0.1); transition: 0.2s; 
    }
    .sidebar-logout .sidebar-icon { background: transparent; }
    .sidebar-logout:hover { background: #ef4444; transform: translateY(-2px); color: #fff; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);}
</style>

<!-- Tombol Expand (Muncul saat sidebar sembunyi) -->
<button type="button" id="sidebarExpandBtn" class="sidebar-expand-btn" title="Buka Sidebar">
    <i class="bi bi-list"></i>
</button>

<!-- Backdrop untuk Mobile -->
<div id="sidebarBackdrop" class="sidebar-backdrop"></div>

<div id="appSidebar" class="d-flex flex-column sidebar-shell">
    <div class="sidebar-brand">
        <div class="sidebar-brand-row">
            <div>
                <div class="sidebar-brand-title">
                    <i class="bi bi-layers-fill"></i>
                    <span>IT Asset</span>
                </div>
                <div class="sidebar-brand-subtitle">Management System</div>
            </div>
            <button type="button" id="sidebarToggleBtn" class="sidebar-toggle-btn" title="Tutup Sidebar">
                <i class="bi bi-list-nested"></i>
            </button>
        </div>
    </div>

    <div class="sidebar-user">
        <div class="d-flex align-items-start gap-3">
            <div class="sidebar-user-avatar">
                <i class="bi bi-person-bounding-box"></i>
            </div>
            <div class="flex-grow-1">
                <div class="sidebar-user-name"><?= h($user['username'] ?? 'User') ?></div>
                <div class="sidebar-user-email"><?= h($user['email'] ?? '-') ?></div>
                <div class="sidebar-user-email mt-1">
                    <i class="bi bi-geo-alt-fill text-danger me-1"></i><?= h($branchName) ?>
                </div>
                <div class="sidebar-user-role">
                    <i class="bi bi-shield-check text-primary"></i>
                    <span><?= h($role ?? '-') ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="sidebar-nav-wrap flex-grow-1">
        <!-- Main Menu Group -->
        <div class="sidebar-group">
            <button type="button" class="sidebar-group-button <?= $mainMenuActive ? 'is-open' : '' ?>" data-menu-target="mainMenu">
                <span class="sidebar-group-button-left">
                    <span class="sidebar-group-icon"><i class="bi bi-grid-fill"></i></span>
                    <span class="ms-2">Main Menu</span>
                </span>
                <i class="bi bi-chevron-down sidebar-group-arrow"></i>
            </button>
            <div id="mainMenu" class="sidebar-submenu <?= $mainMenuActive ? 'show' : '' ?>">
                <div class="sidebar-submenu-inner">
                    <ul class="sidebar-menu">
                        <?php if (can('dashboard.view')): ?>
                        <li><a href="<?= h(base_url('dashboard/index.php')) ?>" class="sidebar-link <?= isMenuActive('/dashboard/') ? 'active' : '' ?>">
                            <span class="sidebar-icon"><i class="bi bi-house-door"></i></span><span>Dashboard</span></a>
                        </li>
                        <?php endif; ?>
                        <?php if (can('barang.view')): ?>
                        <li><a href="<?= h(base_url('Barang/index.php')) ?>" class="sidebar-link <?= isMenuActive('/Barang/') ? 'active' : '' ?>">
                            <span class="sidebar-icon"><i class="bi bi-box-seam"></i></span><span>Barang</span></a>
                        </li>
                        <?php endif; ?>
                        <?php if (can('riwayat.view')): ?>
                        <li><a href="<?= h(base_url('Riwayat/index.php')) ?>" class="sidebar-link <?= isMenuActive('/Riwayat/') ? 'active' : '' ?>">
                            <span class="sidebar-icon"><i class="bi bi-clock-history"></i></span><span>Riwayat</span></a>
                        </li>
                        <?php endif; ?>
                        <?php if (can('laporan.view')): ?>
                        <li><a href="<?= h(base_url('laporan/index.php')) ?>" class="sidebar-link <?= isMenuActive('/laporan/') ? 'active' : '' ?>">
                            <span class="sidebar-icon"><i class="bi bi-file-earmark-bar-graph"></i></span><span>Laporan</span></a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- MENU KHUSUS MONITORING ASSET HO -->
        <!-- ============================================== -->
        <?php if (is_admin()): ?>
        <div class="sidebar-group mt-3">
            <button type="button" class="sidebar-group-button <?= $monitoringMenuActive ? 'is-open' : '' ?>" data-menu-target="monitoringMenu">
                <span class="sidebar-group-button-left">
                    <span class="sidebar-group-icon"><i class="bi bi-display"></i></span>
                    <span class="ms-2">Monitoring HO</span>
                </span>
                <i class="bi bi-chevron-down sidebar-group-arrow"></i>
            </button>
            <div id="monitoringMenu" class="sidebar-submenu <?= $monitoringMenuActive ? 'show' : '' ?>">
                <div class="sidebar-submenu-inner">
                    <ul class="sidebar-menu">
                        <li>
                            <a href="<?= h(base_url('monitoring/index.php')) ?>" class="sidebar-link <?= isMenuActive('/monitoring/') ? 'active' : '' ?>">
                                <span class="sidebar-icon"><i class="bi bi-pc-display-horizontal"></i></span>
                                <span>Data Asset</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <!-- ============================================== -->

        <!-- Administrator Group -->
        <?php if (can('users.view') || can('role_permissions.manage')): ?>
        <div class="sidebar-group mt-3">
            <button type="button" class="sidebar-group-button <?= $adminMenuActive ? 'is-open' : '' ?>" data-menu-target="adminMenu">
                <span class="sidebar-group-button-left">
                    <span class="sidebar-group-icon"><i class="bi bi-shield-check"></i></span>
                    <span class="ms-2">Administrator</span>
                </span>
                <i class="bi bi-chevron-down sidebar-group-arrow"></i>
            </button>
            <div id="adminMenu" class="sidebar-submenu <?= $adminMenuActive ? 'show' : '' ?>">
                <div class="sidebar-submenu-inner">
                    <ul class="sidebar-menu">
                        <?php if (can('users.view')): ?>
                        <li><a href="<?= h(base_url('users/index.php')) ?>" class="sidebar-link <?= $isUsersPage ? 'active' : '' ?>">
                            <span class="sidebar-icon"><i class="bi bi-people"></i></span><span>Kelola User</span>
                            <?php if ($pendingResetCount > 0): ?><span class="badge bg-danger rounded-pill ms-auto"><?= $pendingResetCount ?></span><?php endif; ?>
                        </a></li>
                        <?php endif; ?>
                        <?php if (is_admin() && can('barang.kirim')): ?>
                        <li><a href="<?= h(base_url('Barang/pengiriman_approval.php')) ?>" class="sidebar-link <?= isMenuActive('/Barang/pengiriman_approval.php') ? 'active' : '' ?>">
                            <span class="sidebar-icon"><i class="bi bi-inboxes"></i></span><span>Approval Pengiriman</span>
                            <?php if ($pendingHoShippingCount > 0): ?><span class="badge bg-danger rounded-pill ms-auto"><?= $pendingHoShippingCount ?></span><?php endif; ?>
                        </a></li>
                        <?php endif; ?>
                        <?php if (can('users.view')): ?>
                        <li><a href="<?= h(base_url('users/activity_log.php')) ?>" class="sidebar-link <?= isMenuActive('/users/activity_log.php') ? 'active' : '' ?>">
                            <span class="sidebar-icon"><i class="bi bi-activity"></i></span><span>Activity Log</span></a>
                        </li>
                        <?php endif; ?>
                        <?php if (can('role_permissions.manage')): ?>
                        <li><a href="<?= h(base_url('users/role_permissions.php')) ?>" class="sidebar-link <?= $isAccessPage ? 'active' : '' ?>">
                            <span class="sidebar-icon"><i class="bi bi-key"></i></span><span>Hak Akses</span></a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer">
        <a href="#" id="btnLogoutConfirm" class="sidebar-logout">
            <span class="sidebar-icon"><i class="bi bi-power"></i></span>
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
        const backdrop = document.getElementById('sidebarBackdrop');
        const content = document.getElementById('mainContent');

        const storageSidebar = 'itasset-sidebar-hidden';

        if (content) content.classList.add('content-with-sidebar');

        function isMobile() { return window.innerWidth <= 991.98; }

        function hideSidebar() {
            if (isMobile()) {
                sidebar.classList.remove('show-mobile');
                backdrop.classList.remove('active');
            } else {
                sidebar.classList.add('sidebar-hidden');
                localStorage.setItem(storageSidebar, 'hidden');
            }
            expandBtn.classList.add('show');
        }

        function showSidebar() {
            if (isMobile()) {
                sidebar.classList.add('show-mobile');
                backdrop.classList.add('active');
            } else {
                sidebar.classList.remove('sidebar-hidden');
                localStorage.setItem(storageSidebar, 'shown');
            }
            expandBtn.classList.remove('show');
        }

        // Init State
        if (!isMobile() && localStorage.getItem(storageSidebar) === 'hidden') {
            hideSidebar();
        } else if (isMobile()) {
            expandBtn.classList.add('show');
        }

        toggleBtn.addEventListener('click', hideSidebar);
        expandBtn.addEventListener('click', showSidebar);
        backdrop.addEventListener('click', hideSidebar);

        // Submenu Logic (Tidak lagi menyimpan state ke localStorage)
        document.querySelectorAll('.sidebar-group-button').forEach(btn => {
            const targetId = btn.getAttribute('data-menu-target');
            const submenu = document.getElementById(targetId);

            btn.addEventListener('click', () => {
                submenu.classList.toggle('show');
                btn.classList.toggle('is-open');
            });
        });

        // Logout Logic
        const logoutButton = document.getElementById('btnLogoutConfirm');
        if (logoutButton) {
            logoutButton.addEventListener('click', function(e) {
                e.preventDefault();
                Swal.fire({
                    title: 'Keluar Sistem?',
                    text: 'Sesi Anda akan diakhiri.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Logout',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#dc3545',
                    reverseButtons: true,
                    customClass: { popup: 'rounded-4' }
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = '<?= h(base_url('auth/logout.php')) ?>';
                    }
                });
            });
        }
    });
</script>