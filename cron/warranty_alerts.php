<?php

/** @var mysqli $koneksi */
require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/warranty_helper.php';

sync_warranty_notifications($koneksi);

echo date('Y-m-d H:i:s') . " - Warranty notifications synced.\n";
