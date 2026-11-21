<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Twig;

use Jmonitor\JmonitorBundle\Twig\JmonitorExtension;
use PHPUnit\Framework\TestCase;

final class JmonitorExtensionTest extends TestCase
{
    private JmonitorExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new JmonitorExtension();
    }

    public function testSparkline(): void
    {
        $sparkline = $this->extension->sparkline([0, 2, 5, 1]);

        static::assertStringStartsWith('<svg', $sparkline);
        static::assertStringContainsString('role="img"', $sparkline);
        static::assertMatchesRegularExpression('/<polyline points="[0-9., ]+"/', $sparkline);
        static::assertStringContainsString('<polyline points=""', $this->extension->sparkline([0, 0, 0]));
        static::assertStringContainsString('<polyline points=""', $this->extension->sparkline([]));
        static::assertStringContainsString('aria-label="Requests over the period, peak 5 per interval"', $sparkline);
        static::assertStringContainsString('aria-label="Queries &lt;b&gt; over the period', $this->extension->sparkline([1], 'Queries <b>'));
    }

    public function testFiltersAndFunctionsAreRegistered(): void
    {
        $filters = array_map(static fn($filter): string => $filter->getName(), $this->extension->getFilters());
        $functions = array_map(static fn($function): string => $function->getName(), $this->extension->getFunctions());

        static::assertSame(['jmonitor_duration', 'jmonitor_bytes', 'jmonitor_number', 'jmonitor_percent', 'jmonitor_period'], $filters);
        static::assertSame(['jmonitor_sparkline'], $functions);
    }
}
