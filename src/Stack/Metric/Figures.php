<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Metric;

use Jmonitor\JmonitorBundle\Dashboard\Format;

/**
 * How the figures of the stack are written, in the sentences of the checks as on the screens.
 */
final class Figures
{
    public const NBSP = "\u{00A0}";

    private const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    /**
     * Rounded down to one decimal, so that a value never shows a threshold it did not reach.
     */
    public static function percent(float $percent): string
    {
        $shown = number_format(floor(round($percent * 10, 6)) / 10, 1, '.', '');

        return (str_ends_with($shown, '.0') ? substr($shown, 0, -2) : $shown) . self::NBSP . '%';
    }

    public static function bytes(int $bytes): string
    {
        $value = $bytes;
        $unit = 0;

        while (round($value) >= 1024 && $unit < count(self::BYTE_UNITS) - 1) {
            $value /= 1024;
            ++$unit;
        }

        $shown = $unit === 0 ? number_format($value) : number_format($value, $value < 10 ? 1 : 0);

        return (str_ends_with($shown, '.0') ? substr($shown, 0, -2) : $shown) . self::NBSP . self::BYTE_UNITS[$unit];
    }

    public static function rate(float $value, string $unit): string
    {
        $shown = number_format($value, match (true) {
            abs(round($value, 2)) < 1 => 2,
            abs(round($value, 1)) < 100 => 1,
            default => 0,
        });

        return $unit === '' ? $shown : $shown . self::NBSP . $unit;
    }

    /**
     * With the separator of thousands of the tables.
     */
    public static function count(int $count): string
    {
        return Format::number($count);
    }

    public static function times(int $count): string
    {
        return $count === 1 ? 'once' : $count . ' times';
    }

    /**
     * The span a rate covers, or when it comes.
     */
    public static function span(Rate $rate, ?int $elapsed): ?string
    {
        return match ($rate->source) {
            RateSource::Delta => $elapsed === null ? null : self::lastSeconds($elapsed),
            RateSource::SinceStart => $rate->since,
            RateSource::Pending => $rate->expected,
            RateSource::Unavailable => null,
        };
    }

    /**
     * Its two largest units, such as "5 min 42 s" or "3 d 4 h".
     */
    public static function duration(int $seconds): string
    {
        $units = [
            [intdiv($seconds, 86_400), 'd'],
            [intdiv($seconds % 86_400, 3_600), 'h'],
            [intdiv($seconds % 3_600, 60), 'min'],
            [$seconds % 60, 's'],
        ];

        foreach (array_slice($units, 0, 3) as $i => [$count, $unit]) {
            if ($count > 0) {
                [$nextCount, $nextUnit] = $units[$i + 1];

                return $count . self::NBSP . $unit . ($nextCount > 0 ? self::NBSP . $nextCount . self::NBSP . $nextUnit : '');
            }
        }

        return $seconds . self::NBSP . 's';
    }

    public static function lastSeconds(int $seconds): string
    {
        return 'over the last ' . $seconds . self::NBSP . 's';
    }
}
