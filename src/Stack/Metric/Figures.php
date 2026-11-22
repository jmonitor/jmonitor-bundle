<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Metric;

/**
 * How the figures of the stack are written, in the sentences of the checks as on the screens.
 */
final class Figures
{
    public const NBSP = "\u{00A0}";

    /**
     * Rounded down to one decimal, so that a value never shows a threshold it did not reach.
     */
    public static function percent(float $percent): string
    {
        $shown = number_format(floor(round($percent * 10, 6)) / 10, 1, '.', '');

        return (str_ends_with($shown, '.0') ? substr($shown, 0, -2) : $shown) . self::NBSP . '%';
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
