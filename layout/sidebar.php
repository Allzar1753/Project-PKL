<?php
if (!isset($koneksi)) {
    include_once __DIR__ . '/../config/koneksi.php';
}
include_once __DIR__ . '/../config/auth.php';

if (is_logged_in()) {
    refresh_permissions($koneksi);
}
?>

<div class="col-md-2 bg-dark text-white min-vh-100 p-3">
    <h3 class="text-warning fw-bold mb-4">IT Asset</h3>

    <?php if (can('dashboard.view')): ?>
        <a href="<?= e(base_url('dashboard/index.php')) ?>" class="d-block text-white text-decoration-none mb-3">Dashboard</a>
    <?php endif; ?>

    <?php if (can('barang.view')): ?>
        <a href="<?= e(base_url('Barang/index.php')) ?>" class="d-block text-white text-decoration-none mb-3">Barang</a>
    <?php endif; ?>

    <?php if (can('riwayat.view')): ?>
        <a href="<?= e(base_url('Riwayat/index.php')) ?>" class="d-block text-white text-decoration-none mb-3">Riwayat</a>
    <?php endif; ?>

    <?php if (can('laporan.view')): ?>
        <a href="<?= e(base_url('laporan/laporan_harian.php')) ?>" class="d-block text-white text-decoration-none mb-3">Laporan</a>
    <?php endif; ?>

    <?php if (is_super_admin()): ?>
        <a href="<?= e(base_url('users/index.php')) ?>" class="d-block text-white text-decoration-none mb-3">Kelola User</a>
        <a href="<?= e(base_url('users/role_permissions.php')) ?>" class="d-block text-white text-decoration-none mb-3">Hak Akses</a>
    <?php endif; ?>

    <hr class="border-secondary">
    <div class="small text-light mb-2">
        Login sebagai: <strong><?= e(current_user()['username'] ?? '-') ?></strong><br>
        Role: <strong><?= e(current_role()) ?></strong>
    </div>

    <a href="<?= e(base_url('auth/logout.php')) ?>" class="btn btn-outline-light btn-sm">Logout</a>
</div>
