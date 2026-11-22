<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Metric;

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

        while ($value >= 1024 && $unit < count(self::BYTE_UNITS) - 1) {
            $value /= 1024;
            ++$unit;
        }

        $shown = $unit === 0 ? number_format($value) : number_format($value, $value < 10 ? 1 : 0);

        return (str_ends_with($shown, '.0') ? substr($shown, 0, -2) : $shown) . self::NBSP . self::BYTE_UNITS[$unit];
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

    public static function lastSeconds(int $seconds): string
    {
        return 'over the last ' . $seconds . self::NBSP . 's';
    }
}
