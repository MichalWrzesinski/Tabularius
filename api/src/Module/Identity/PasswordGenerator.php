<?php

declare(strict_types=1);

namespace App\Module\Identity;

/**
 * Random passwords for new accounts and resets (ACC-LIFE): 16 characters from an unambiguous alphabet,
 * CSPRNG only. The value is used once, to hash it and to put it in the e-mail; it is never stored or logged.
 */
final class PasswordGenerator
{
    private const string ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function generate(int $length = 16): string
    {
        $max = \strlen(self::ALPHABET) - 1;
        $password = '';
        for ($i = 0; $i < $length; ++$i) {
            $password .= self::ALPHABET[random_int(0, $max)];
        }

        return $password;
    }
}
