<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Reading;

/**
 * Collector payloads mix ints, numeric strings, "ON"/"OFF" flags and ini quantities.
 */
final class Values
{
    public static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        $float = self::float($value);

        return $float !== null && abs($float) < PHP_INT_MAX ? (int) $float : null;
    }

    public static function float(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return is_finite($float) ? $float : null;
    }

    public static function string(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    public static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return match (strtolower($value)) {
                '1', 'on', 'true', 'yes' => true,
                '', '0', 'off', 'false', 'no' => false,
                default => null,
            };
        }

        return null;
    }

    public static function percent(int|float|null $part, int|float|null $total): ?float
    {
        if ($part === null || $total === null || $total <= 0) {
            return null;
        }

        return $part / $total * 100;
    }

    /**
     * @return int|false|null false when the directive means "no limit" (-1)
     */
    public static function iniBytes(mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value < 0 ? false : $value;
        }

        if (!is_string($value)) {
            return null;
        }

        // ini_parse_quantity() warns about what it cannot read, and reads it as some number anyway.
        $readable = true;
        set_error_handler(static function () use (&$readable): bool {
            $readable = false;

            return true;
        });

        try {
            $bytes = ini_parse_quantity($value);
        } finally {
            restore_error_handler();
        }

        if (!$readable) {
            return null;
        }

        return $bytes < 0 ? false : $bytes;
    }
}
