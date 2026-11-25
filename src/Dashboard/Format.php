<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;

final class Format
{
    private const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    public static function duration(int|float $ms): string
    {
        if ($ms < 1) {
            return '<1 ms';
        }

        if (round($ms) < 1000) {
            return round($ms) . ' ms';
        }

        $seconds = $ms / 1000;

        return $seconds < 10 ? number_format($seconds, 1) . ' s' : number_format($seconds) . ' s';
    }

    public static function bytes(int|float $bytes): string
    {
        $unit = 0;

        while ($bytes >= 1024 && $unit < \count(self::BYTE_UNITS) - 1) {
            $bytes /= 1024;
            ++$unit;
        }

        if ($unit === 0) {
            return number_format($bytes) . ' B';
        }

        return self::trimZero(number_format($bytes, $bytes < 10 ? 1 : 0)) . ' ' . self::BYTE_UNITS[$unit];
    }

    public static function number(int|float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals);
    }

    public static function percent(int|float $ratio): string
    {
        $percent = $ratio * 100;

        if ($percent <= 0) {
            return '0 %';
        }

        if ($percent < 0.1) {
            return '<0.1 %';
        }

        return self::trimZero(number_format($percent, $percent < 10 ? 1 : 0)) . ' %';
    }

    public static function sql(float $msPerUnit, float $queriesPerUnit): string
    {
        return self::duration($msPerUnit) . ' · ' . ($queriesPerUnit < 10 ? number_format($queriesPerUnit, 1) : number_format($queriesPerUnit)) . ' q';
    }

    public static function ago(int $timestamp, int $now): string
    {
        $seconds = $now - $timestamp;

        return match (true) {
            $seconds < 5 => 'just now',
            $seconds < 60 => $seconds . ' s ago',
            $seconds < 3600 => intdiv($seconds, 60) . ' min ago',
            $seconds < 86400 => intdiv($seconds, 3600) . ' h ago',
            default => intdiv($seconds, 86400) . ' d ago',
        };
    }

    public static function in(int $timestamp, int $now): string
    {
        $seconds = $timestamp - $now;

        return match (true) {
            $seconds < 5 => 'now',
            $seconds < 60 => 'in ' . $seconds . ' s',
            $seconds < 3600 => 'in ' . intdiv($seconds, 60) . ' min',
            $seconds < 86400 => 'in ' . intdiv($seconds, 3600) . ' h',
            default => 'in ' . intdiv($seconds, 86400) . ' d',
        };
    }

    public static function period(Period $period): string
    {
        return match ($period) {
            Period::OneHour => 'Last hour',
            Period::SixHours => 'Last 6 hours',
            Period::OneDay => 'Last 24 hours',
            Period::SevenDays => 'Last 7 days',
        };
    }

    /** where a curve of the period starts, read from now */
    public static function periodStart(Period $period): string
    {
        return match ($period) {
            Period::OneHour => '1 h ago',
            Period::SixHours => '6 h ago',
            Period::OneDay => '24 h ago',
            Period::SevenDays => '7 d ago',
        };
    }

    private static function trimZero(string $number): string
    {
        return str_ends_with($number, '.0') ? substr($number, 0, -2) : $number;
    }
}
