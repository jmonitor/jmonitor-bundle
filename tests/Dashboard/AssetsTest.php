<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Dashboard\Assets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AssetsTest extends TestCase
{
    public function testAssetsCarryAHashOfTheirContent(): void
    {
        $assets = new Assets();
        $request = Request::create('/jmonitor/');

        $css = $assets->stylesheets($request);

        static::assertCount(1, $css);
        static::assertMatchesRegularExpression('#^/bundles/jmonitor/jmonitor\.css\?v=[0-9a-f]{8}$#', $css[0]);
        static::assertSame($css, $assets->stylesheets($request));
        static::assertSame($css, (new Assets())->stylesheets($request));
        static::assertStringEndsWith('?v=' . substr(hash_file('xxh3', __DIR__ . '/../../public/jmonitor.css') ?: '', 0, 8), $css[0]);

        $js = $assets->scripts($request);

        static::assertCount(1, $js);
        static::assertMatchesRegularExpression('#^/bundles/jmonitor/jmonitor\.js\?v=[0-9a-f]{8}$#', $js[0]);
        static::assertStringEndsWith('?v=' . substr(hash_file('xxh3', __DIR__ . '/../../public/jmonitor.js') ?: '', 0, 8), $js[0]);
    }

    public function testAssetsCarryTheBasePath(): void
    {
        $request = Request::create('/sub/index.php/jmonitor/', server: ['SCRIPT_NAME' => '/sub/index.php', 'SCRIPT_FILENAME' => '/srv/sub/index.php']);

        static::assertStringStartsWith('/sub/bundles/jmonitor/jmonitor.css?v=', (new Assets())->stylesheets($request)[0]);
        static::assertStringStartsWith('/sub/bundles/jmonitor/jmonitor.js?v=', (new Assets())->scripts($request)[0]);
    }
}
