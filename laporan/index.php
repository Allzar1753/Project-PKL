<?php
include '../config/koneksi.php';
require_once '../config/auth.php';

require_permission($koneksi, 'laporan.view');

redirect_to(base_url('laporan/laporan_bulanan.php'));