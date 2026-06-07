<?php

/**
 * Penomoran unik asset IT HexIndo.
 * Format: HXI-{KATEGORI}-{TAHUN}-{NOMOR}
 * Contoh: HXI-NB-2026-00001 (Notebook tahun 2026 urutan ke-1)
 */

if (!function_exists('asset_category_code')) {
    function asset_category_code(string $namaBarang): string
    {
        static $map = [
            'notebook'       => 'NB',
            'monitor'        => 'MON',
            'cpu'            => 'CPU',
            'hardisk'        => 'HDD',
            'ram'            => 'RAM',
            'keyboard'       => 'KBD',
            'mouse'          => 'MSE',
            'battery'        => 'BAT',
            'router'         => 'RTL',
            'kabel lan'      => 'LAN',
            'kabel power'    => 'PWR',
            'tang crimping'  => 'TCR',
        ];

        $key = strtolower(trim($namaBarang));
        if (isset($map[$key])) {
            return $map[$key];
        }

        $clean = preg_replace('/[^a-z0-9]/', '', $key);
        if (strlen($clean) >= 3) {
            return strtoupper(substr($clean, 0, 3));
        }

        return 'GEN';
    }
}

if (!function_exists('asset_code_prefix')) {
    function asset_code_prefix(mysqli $koneksi, int $idBarang, ?string $tanggalTerima = null): string
    {
        $namaBarang = '';
        $stmt = mysqli_prepare($koneksi, 'SELECT nama_barang FROM tb_barang WHERE id_barang = ? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $idBarang);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($result);
            $namaBarang = (string) ($row['nama_barang'] ?? '');
            mysqli_stmt_close($stmt);
        }

        $category = asset_category_code($namaBarang);
        $year = $tanggalTerima ? date('Y', strtotime($tanggalTerima)) : date('Y');

        return 'HXI-' . $category . '-' . $year . '-';
    }
}

if (!function_exists('generate_kode_aset')) {
    function generate_kode_aset(mysqli $koneksi, int $idBarang, ?string $tanggalTerima = null): string
    {
        $prefix = asset_code_prefix($koneksi, $idBarang, $tanggalTerima);
        $safePrefix = mysqli_real_escape_string($koneksi, $prefix);

        $nextNum = 1;
        $result = mysqli_query(
            $koneksi,
            "SELECT kode_aset FROM barang WHERE kode_aset LIKE '{$safePrefix}%' ORDER BY kode_aset DESC LIMIT 1"
        );

        if ($result && ($row = mysqli_fetch_assoc($result))) {
            $lastPart = substr((string) $row['kode_aset'], strlen($prefix));
            $nextNum = max(1, (int) $lastPart + 1);
        }

        return $prefix . str_pad((string) $nextNum, 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('backfill_kode_aset')) {
    function backfill_kode_aset(mysqli $koneksi): void
    {
        $result = mysqli_query(
            $koneksi,
            "SELECT id, id_barang, tanggal_terima FROM barang WHERE kode_aset IS NULL OR kode_aset = '' ORDER BY id ASC"
        );

        if (!$result) {
            return;
        }

        while ($row = mysqli_fetch_assoc($result)) {
            $id = (int) $row['id'];
            $idBarang = (int) $row['id_barang'];
            $tanggalTerima = (string) ($row['tanggal_terima'] ?? '');

            $attempts = 0;
            while ($attempts < 5) {
                $kode = generate_kode_aset($koneksi, $idBarang, $tanggalTerima !== '' ? $tanggalTerima : null);
                $safeKode = mysqli_real_escape_string($koneksi, $kode);

                if (mysqli_query($koneksi, "UPDATE barang SET kode_aset = '{$safeKode}' WHERE id = {$id} AND (kode_aset IS NULL OR kode_aset = '')")) {
                    break;
                }

                $attempts++;
            }
        }
    }
}
