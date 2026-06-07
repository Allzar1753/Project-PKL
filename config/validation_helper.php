<?php

if (!function_exists('validate_password_strength')) {
    /**
     * Validasi kombinasi password: min 8 char, huruf besar, kecil, angka, simbol.
     * Return null jika valid, atau pesan error.
     */
    function validate_password_strength(string $password): ?string
    {
        if (strlen($password) < 8) {
            return 'Password minimal 8 karakter.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return 'Password wajib mengandung huruf besar (A-Z).';
        }
        if (!preg_match('/[a-z]/', $password)) {
            return 'Password wajib mengandung huruf kecil (a-z).';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return 'Password wajib mengandung angka (0-9).';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'Password wajib mengandung simbol (contoh: !@#$%).';
        }

        return null;
    }
}

if (!function_exists('validate_person_name')) {
    /**
     * Nama orang: hanya huruf dan spasi.
     */
    function validate_person_name(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return 'Nama wajib diisi.';
        }
        if (!preg_match('/^[a-zA-Z\s]+$/', $name)) {
            return 'Nama hanya boleh huruf dan spasi (tanpa angka/simbol).';
        }

        return null;
    }
}

if (!function_exists('date_min_back_tolerance')) {
    function date_min_back_tolerance(int $daysBack = 3): string
    {
        return date('Y-m-d', strtotime('-' . max(0, $daysBack) . ' days'));
    }
}

if (!function_exists('is_date_within_back_tolerance')) {
    /**
     * Tanggal boleh dari (hari ini - N hari) ke depan (tanpa batas atas).
     */
    function is_date_within_back_tolerance(string $date, int $daysBack = 3): bool
    {
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        return $date >= date_min_back_tolerance($daysBack);
    }
}
