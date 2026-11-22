<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Metric;

final class EndOfLife
{
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * "16.4 (Debian 16.4-1)" is looked up as cycle "16.4", then "16".
     *
     * @param array<mixed> $cycles the data of an EndOfLifeCollector
     */
    public static function status(array $cycles, ?string $version, \DateTimeImmutable $today): ?SupportStatus
    {
        if ($version === null || preg_match('/^v?(\d+)(?:\.(\d+))?/', $version, $matches) !== 1) {
            return null;
        }

        $cycles = $cycles['cycles'] ?? null;

        if (!is_array($cycles) || !self::areCycles($cycles)) {
            return null;
        }

        $cycle = null;

        foreach (isset($matches[2]) ? [$matches[1] . '.' . $matches[2], $matches[1]] : [$matches[1]] as $candidate) {
            if (isset($cycles[$candidate])) {
                $cycle = $cycles[$candidate];
                break;
            }
        }

        if ($cycle === null) {
            return null;
        }

        // The dates are the last day of support.
        $day = $today->format('Y-m-d');
        $ended = is_bool($cycle['eol']) ? $cycle['eol'] : $cycle['eol'] < $day;
        $supported = match (true) {
            $cycle['support'] === null => !$ended,
            is_bool($cycle['support']) => $cycle['support'],
            default => $cycle['support'] >= $day,
        };

        return match (true) {
            $ended => SupportStatus::Ended,
            $supported => SupportStatus::Active,
            default => SupportStatus::SecurityOnly,
        };
    }

    /**
     * A date, or a boolean in place of one.
     *
     * @phpstan-assert-if-true bool|string $value
     */
    public static function isDate(mixed $value): bool
    {
        return is_bool($value) || (is_string($value) && preg_match(self::DATE, $value) === 1);
    }

    /**
     * @param array<mixed> $cycles
     *
     * @phpstan-assert-if-true array<array{eol: bool|string, support: bool|string|null}> $cycles
     */
    private static function areCycles(array $cycles): bool
    {
        foreach ($cycles as $cycle) {
            if (!is_array($cycle) || !self::isDate($cycle['eol'] ?? null) || !array_key_exists('support', $cycle) || ($cycle['support'] !== null && !self::isDate($cycle['support']))) {
                return false;
            }
        }

        return true;
    }
}
