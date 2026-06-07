<?php
/** Notification bell — include after auth + koneksi available */
if (!isset($koneksi) || !function_exists('is_logged_in') || !is_logged_in()) {
    return;
}

require_once __DIR__ . '/../config/warranty_helper.php';

$notifRole = (string) (current_role() ?? 'user');
$notifBranchId = (int) (current_user_branch_id() ?? 0);
$notifUnread = count_unread_notifications($koneksi, $notifRole, $notifBranchId > 0 ? $notifBranchId : null);
$notifApiUrl = htmlspecialchars(base_url('dashboard/notifications_api.php'), ENT_QUOTES, 'UTF-8');
$notifCenterUrl = htmlspecialchars(base_url('dashboard/notifications.php'), ENT_QUOTES, 'UTF-8');
?>
<style>
    .notif-bell-wrap { position: relative; }
    .notif-bell-btn {
        width: 40px; height: 40px; border-radius: 12px; border: 1px solid #e5e7eb;
        background: #fff; color: #374151; display: inline-flex; align-items: center; justify-content: center;
        cursor: pointer; position: relative; transition: .2s; box-shadow: 0 2px 8px rgba(0,0,0,.04);
    }
    .notif-bell-btn:hover { border-color: #E64312; color: #E64312; }
    .notif-badge-count {
        position: absolute; top: -4px; right: -4px; min-width: 18px; height: 18px; padding: 0 5px;
        border-radius: 999px; background: #ef4444; color: #fff; font-size: 10px; font-weight: 800;
        display: inline-flex; align-items: center; justify-content: center; border: 2px solid #fff;
    }
    .notif-dropdown {
        position: absolute; top: calc(100% + 10px); right: 0; width: min(380px, 92vw);
        background: #fff; border: 1px solid #e5e7eb; border-radius: 14px;
        box-shadow: 0 20px 50px rgba(0,0,0,.12); z-index: 2000; display: none; overflow: hidden;
    }
    .notif-dropdown.show { display: block; animation: notifDrop .18s ease; }
    @keyframes notifDrop { from { opacity:0; transform: translateY(-6px);} to { opacity:1; transform:none;} }
    .notif-dd-head { padding: .85rem 1rem; border-bottom: 1px solid #f3f4f6; display:flex; justify-content:space-between; align-items:center; background:#fafafa; }
    .notif-dd-head strong { font-size: .92rem; color: #231F20; }
    .notif-dd-body { max-height: 360px; overflow-y: auto; }
    .notif-dd-item { display:flex; gap:.75rem; padding:.85rem 1rem; border-bottom:1px solid #f3f4f6; text-decoration:none; color:inherit; transition:.15s; }
    .notif-dd-item:hover { background:#fff8f5; }
    .notif-dd-item.unread { background:#fffdfb; }
    .notif-dd-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .notif-dd-title { font-weight:700; font-size:.84rem; color:#231F20; margin-bottom:.15rem; }
    .notif-dd-msg { font-size:.78rem; color:#6b7280; line-height:1.35; }
    .notif-dd-time { font-size:.72rem; color:#9ca3af; margin-top:.2rem; }
    .notif-dd-foot { padding:.75rem 1rem; border-top:1px solid #f3f4f6; text-align:center; background:#fafafa; }
    .notif-dd-foot a { font-weight:700; font-size:.82rem; color:#E64312; text-decoration:none; }
    .notif-dd-empty { padding:1.5rem 1rem; text-align:center; color:#9ca3af; font-size:.85rem; }
    .page-notif-bar { display:flex; justify-content:flex-end; padding:12px 28px 0; }
    @media (max-width: 991.98px) { .page-notif-bar { padding:12px 16px 0; } }
</style>

<div class="page-notif-bar">
    <div class="notif-bell-wrap" id="globalNotifBell">
        <button type="button" class="notif-bell-btn" id="notifBellBtn" aria-label="Notifikasi">
            <i class="bi bi-bell"></i>
            <?php if ($notifUnread > 0): ?>
                <span class="notif-badge-count" id="notifBadgeCount"><?= $notifUnread > 99 ? '99+' : $notifUnread ?></span>
            <?php else: ?>
                <span class="notif-badge-count" id="notifBadgeCount" style="display:none;">0</span>
            <?php endif; ?>
        </button>
        <div class="notif-dropdown" id="notifDropdown">
            <div class="notif-dd-head">
                <strong><i class="bi bi-bell-fill me-1 text-warning"></i> Notifikasi</strong>
                <span class="small text-muted" id="notifDropdownSubtitle"><?= $notifUnread ?> belum dibaca</span>
            </div>
            <div class="notif-dd-body" id="notifDropdownBody">
                <div class="notif-dd-empty">Memuat...</div>
            </div>
            <div class="notif-dd-foot">
                <a href="<?= $notifCenterUrl ?>">Lihat semua notifikasi</a>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const apiUrl = <?= json_encode(base_url('dashboard/notifications_api.php')) ?>;
    const bellBtn = document.getElementById('notifBellBtn');
    const dropdown = document.getElementById('notifDropdown');
    const bodyEl = document.getElementById('notifDropdownBody');
    const badgeEl = document.getElementById('notifBadgeCount');
    const subtitleEl = document.getElementById('notifDropdownSubtitle');
    if (!bellBtn || !dropdown || !bodyEl) return;

    function renderItems(items) {
        if (!items.length) {
            bodyEl.innerHTML = '<div class="notif-dd-empty"><i class="bi bi-bell-slash d-block fs-4 mb-2"></i>Tidak ada notifikasi baru.</div>';
            return;
        }
        bodyEl.innerHTML = items.map(item => `
            <a href="${item.read_url}" class="notif-dd-item ${item.is_read ? '' : 'unread'}">
                <div class="notif-dd-icon" style="background:${item.bg};color:${item.color};"><i class="bi ${item.icon}"></i></div>
                <div>
                    <div class="notif-dd-title">${item.title}</div>
                    <div class="notif-dd-msg">${item.message}</div>
                    <div class="notif-dd-time">${item.time_ago}</div>
                </div>
            </a>
        `).join('');
    }

    function updateBadge(count) {
        if (!badgeEl) return;
        if (count > 0) {
            badgeEl.style.display = 'inline-flex';
            badgeEl.textContent = count > 99 ? '99+' : count;
        } else {
            badgeEl.style.display = 'none';
        }
        if (subtitleEl) subtitleEl.textContent = count + ' belum dibaca';
    }

    async function loadNotifications() {
        try {
            const res = await fetch(apiUrl + '?limit=6', { cache: 'no-store' });
            const data = await res.json();
            renderItems(data.items || []);
            updateBadge(data.unread_count || 0);
        } catch (e) {
            bodyEl.innerHTML = '<div class="notif-dd-empty">Gagal memuat notifikasi.</div>';
        }
    }

    bellBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        dropdown.classList.toggle('show');
        if (dropdown.classList.contains('show')) loadNotifications();
    });

    document.addEventListener('click', function (e) {
        if (!document.getElementById('globalNotifBell')?.contains(e.target)) {
            dropdown.classList.remove('show');
        }
    });

    loadNotifications();
    setInterval(loadNotifications, 60000);
})();
</script>
