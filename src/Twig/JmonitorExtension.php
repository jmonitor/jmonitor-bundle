<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class JmonitorExtension extends AbstractExtension
{
    private const SPARKLINE_WIDTH = 120;
    private const SPARKLINE_HEIGHT = 32;
    private const SPARKLINE_PADDING = 2;

    public function getFilters(): array
    {
        return [
            new TwigFilter('jmonitor_duration', $this->duration(...)),
            new TwigFilter('jmonitor_bytes', $this->bytes(...)),
            new TwigFilter('jmonitor_number', $this->number(...)),
            new TwigFilter('jmonitor_percent', $this->percent(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('jmonitor_sparkline', $this->sparkline(...), ['is_safe' => ['html']]),
        ];
    }

    public function duration(int|float $milliseconds): string
    {
        if ($milliseconds < 1) {
            return '<1 ms';
        }

        if (round($milliseconds) < 1000) {
            return round($milliseconds) . ' ms';
        }

        $seconds = $milliseconds / 1000;

        return $seconds < 10 ? number_format($seconds, 1) . ' s' : number_format($seconds) . ' s';
    }

    public function bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;

        while ($bytes >= 1024 && $unit < \count($units) - 1) {
            $bytes /= 1024;
            ++$unit;
        }

        if ($unit === 0) {
            return number_format($bytes) . ' B';
        }

        return self::trimZero(number_format($bytes, $bytes < 10 ? 1 : 0)) . ' ' . $units[$unit];
    }

    public function number(int|float $value): string
    {
        return number_format($value);
    }

    public function percent(int|float $ratio): string
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

    /**
     * @param array<int|float> $values
     */
    public function sparkline(array $values): string
    {
        $values = array_values(array_map(static fn(int|float $value): int => max(0, (int) $value), $values));
        $peak = $values === [] ? 0 : max($values);
        $points = [];

        if ($peak > 0) {
            $step = \count($values) > 1 ? self::SPARKLINE_WIDTH / (\count($values) - 1) : 0;
            $range = self::SPARKLINE_HEIGHT - 2 * self::SPARKLINE_PADDING;

            foreach ($values as $index => $value) {
                $x = round($index * $step, 2);
                $y = round(self::SPARKLINE_HEIGHT - self::SPARKLINE_PADDING - $value / $peak * $range, 2);
                $points[] = $x . ',' . $y;
            }
        }

        return sprintf(
            '<svg class="jm-sparkline" viewBox="0 0 %d %d" preserveAspectRatio="none" role="img" aria-label="Requests over the period, peak %d per interval">'
            . '<polyline points="%s" fill="none" vector-effect="non-scaling-stroke"/></svg>',
            self::SPARKLINE_WIDTH,
            self::SPARKLINE_HEIGHT,
            $peak,
            implode(' ', $points),
        );
    }

    private static function trimZero(string $number): string
    {
        return str_ends_with($number, '.0') ? substr($number, 0, -2) : $number;
    }
}
