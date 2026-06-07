<?php

if (!function_exists('generate_strong_password')) {
    function generate_strong_password(int $length = 12): string
    {
        $length = max(8, $length);
        $uppers = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowers = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $symbols = '!@#$%^&*';
        $all = $uppers . $lowers . $numbers . $symbols;

        $password = $uppers[random_int(0, strlen($uppers) - 1)]
            . $lowers[random_int(0, strlen($lowers) - 1)]
            . $numbers[random_int(0, strlen($numbers) - 1)]
            . $symbols[random_int(0, strlen($symbols) - 1)];

        while (strlen($password) < $length) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        $chars = str_split($password);
        shuffle($chars);

        return implode('', $chars);
    }
}

if (!function_exists('stash_user_credentials_flash')) {
    /**
     * @param array<int, array{username:string,email:string,password:string}> $items
     */
    function stash_user_credentials_flash(array $items): void
    {
        if ($items === []) {
            return;
        }

        set_flash('user_credentials_batch', json_encode(array_values($items), JSON_UNESCAPED_UNICODE));
    }
}
