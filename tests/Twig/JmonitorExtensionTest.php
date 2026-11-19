<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Twig;

use Jmonitor\JmonitorBundle\Twig\JmonitorExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

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

    public function testAssetsCarryAHashOfTheirContent(): void
    {
        $css = $this->extension->asset('jmonitor.css');

        static::assertMatchesRegularExpression('#^/bundles/jmonitor/jmonitor\.css\?v=[0-9a-f]{8}$#', $css);
        static::assertSame($css, $this->extension->asset('jmonitor.css'));
        static::assertSame($css, (new JmonitorExtension())->asset('jmonitor.css'));
        static::assertStringEndsWith('?v=' . substr(hash_file('xxh3', __DIR__ . '/../../public/jmonitor.css') ?: '', 0, 8), $css);
        static::assertMatchesRegularExpression('#^/bundles/jmonitor/jmonitor\.js\?v=[0-9a-f]{8}$#', $this->extension->asset('jmonitor.js'));
    }

    public function testAssetsCarryTheBasePath(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/sub/index.php/jmonitor/', server: ['SCRIPT_NAME' => '/sub/index.php', 'SCRIPT_FILENAME' => '/srv/sub/index.php']));

        static::assertStringStartsWith('/sub/bundles/jmonitor/jmonitor.css?v=', (new JmonitorExtension($requests))->asset('jmonitor.css'));
    }

    public function testFiltersAndFunctionsAreRegistered(): void
    {
        $filters = array_map(static fn($filter): string => $filter->getName(), $this->extension->getFilters());
        $functions = array_map(static fn($function): string => $function->getName(), $this->extension->getFunctions());

        static::assertSame(['jmonitor_duration', 'jmonitor_bytes', 'jmonitor_number', 'jmonitor_percent', 'jmonitor_period'], $filters);
        static::assertSame(['jmonitor_sparkline', 'jmonitor_asset'], $functions);
    }
}
