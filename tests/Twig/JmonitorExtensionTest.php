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

    public function testDuration(): void
    {
        static::assertSame('84 ms', $this->extension->duration(84.4));
        static::assertSame('2.8 s', $this->extension->duration(2840));
        static::assertSame('<1 ms', $this->extension->duration(0.4));
    }

    public function testBytes(): void
    {
        static::assertSame('38 MB', $this->extension->bytes(39845888));
        static::assertSame('512 B', $this->extension->bytes(512));
    }

    public function testNumber(): void
    {
        static::assertSame('48,210', $this->extension->number(48210));
    }

    public function testPercent(): void
    {
        static::assertSame('0.4 %', $this->extension->percent(0.004));
        static::assertSame('0 %', $this->extension->percent(0));
    }

    public function testSparkline(): void
    {
        $sparkline = $this->extension->sparkline([0, 2, 5, 1]);

        static::assertStringStartsWith('<svg', $sparkline);
        static::assertStringContainsString('role="img"', $sparkline);
        static::assertMatchesRegularExpression('/<polyline points="[0-9., ]+"/', $sparkline);
        static::assertStringContainsString('<polyline points=""', $this->extension->sparkline([0, 0, 0]));
        static::assertStringContainsString('<polyline points=""', $this->extension->sparkline([]));
    }

    public function testFiltersAndFunctionAreRegistered(): void
    {
        $filters = array_map(static fn($filter): string => $filter->getName(), $this->extension->getFilters());
        $functions = array_map(static fn($function): string => $function->getName(), $this->extension->getFunctions());

        static::assertSame(['jmonitor_duration', 'jmonitor_bytes', 'jmonitor_number', 'jmonitor_percent'], $filters);
        static::assertSame(['jmonitor_sparkline'], $functions);
    }
}
