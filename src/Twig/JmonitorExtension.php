<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Twig;

use Jmonitor\JmonitorBundle\Dashboard\Format;
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
            new TwigFilter('jmonitor_duration', Format::duration(...)),
            new TwigFilter('jmonitor_bytes', Format::bytes(...)),
            new TwigFilter('jmonitor_number', Format::number(...)),
            new TwigFilter('jmonitor_percent', Format::percent(...)),
            new TwigFilter('jmonitor_period', Format::period(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('jmonitor_sparkline', $this->sparkline(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param array<int|float> $values
     */
    public function sparkline(array $values, string $label = 'Requests'): string
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
            '<svg class="jm-sparkline" viewBox="0 0 %d %d" preserveAspectRatio="none" role="img" aria-label="%s over the period, peak %d per interval">'
            . '<polyline points="%s" fill="none" vector-effect="non-scaling-stroke"/></svg>',
            self::SPARKLINE_WIDTH,
            self::SPARKLINE_HEIGHT,
            htmlspecialchars($label, \ENT_QUOTES),
            $peak,
            implode(' ', $points),
        );
    }
}
