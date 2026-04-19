<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_admin();
set_flash('error', 'Hak akses per-user dinonaktifkan. Gunakan role default.');
redirect_to(base_url('users/index.php'));