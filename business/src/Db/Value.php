<?php

declare(strict_types=1);

namespace App\Db;

/**
 * Narrows scalar values read from the database (DBAL returns mixed) without silent casts.
 */
final class Value
{
    public static function int(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('Expected an integer value from the database');
    }

    public static function string(mixed $value): string
    {
        if (\is_string($value) || \is_int($value)) {
            return (string) $value;
        }

        throw new \UnexpectedValueException('Expected a string value from the database');
    }
}
