<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\FrankenPhpMetricsCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\FrankenPhpVersionCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\FrankenPhpDetector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeProcessRunner;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeStatusPages;
use PHPUnit\Framework\TestCase;

final class FrankenPhpDetectorTest extends TestCase
{
    public function testFrankenPhpIsDetectedFromTheSapiWithoutReadingAnything(): void
    {
        $pages = new FakeStatusPages();
        $runner = new FakeProcessRunner();
        $detector = new FrankenPhpDetector('http://localhost:2019/metrics', $runner, 'frankenphp', $pages);

        $detection = $detector->detect();

        self::assertSame(Component::FrankenPhp, $detector->component());
        self::assertTrue($detection->isFound());
        self::assertSame(Component::FrankenPhp, $detection->instances[0]->component);
        self::assertNull($detection->instances[0]->id);
        $collectors = $detection->instances[0]->collectors;
        self::assertNotNull($collectors);
        self::assertSame(['frankenphp', 'frankenphp.version'], $collectors->names());

        $metrics = $collectors->get('frankenphp');
        self::assertNotNull($metrics);
        self::assertInstanceOf(FrankenPhpMetricsCollector::class, $metrics->collector);
        self::assertSame(Lifetime::Dynamic, $metrics->lifetime);
        self::assertSame(CostClass::Network, $metrics->cost);
        self::assertTrue($metrics->trackDelta);
        self::assertFalse($metrics->shared);

        $version = $collectors->get('frankenphp.version');
        self::assertNotNull($version);
        self::assertInstanceOf(FrankenPhpVersionCollector::class, $version->collector);
        self::assertSame(Lifetime::Static, $version->lifetime);
        self::assertSame(CostClass::Slow, $version->cost);
        self::assertFalse($version->trackDelta);
        self::assertFalse($version->shared);

        self::assertSame(0, $pages->calls);
        self::assertSame([], $runner->calls);
    }

    public function testAnotherSapiIsUnavailable(): void
    {
        $detection = (new FrankenPhpDetector('http://localhost:2019/metrics', new FakeProcessRunner(), 'fpm-fcgi', new FakeStatusPages()))->detect();

        self::assertFalse($detection->isFound());
        self::assertSame('FrankenPHP is not serving this page.', $detection->error);
    }

    public function testTheCollectorsReadTheConfiguredEndpointAndTheBinaryOfThePath(): void
    {
        $pages = new FakeStatusPages();
        $runner = (new FakeProcessRunner())->answer(['frankenphp', 'version'], "FrankenPHP v1.13.0 PHP 8.4.26 Caddy v2.11.7\n");
        $collectors = (new FrankenPhpDetector('http://caddy:2019/metrics', $runner, 'frankenphp', $pages))->detect()->instances[0]->collectors;
        self::assertNotNull($collectors);

        $metrics = $collectors->get('frankenphp')?->collector->collect();
        $version = $collectors->get('frankenphp.version')?->collector->collect();

        self::assertSame('worker', $metrics['mode'] ?? null);
        self::assertSame(['http://caddy:2019/metrics'], $pages->urls);
        self::assertSame(['version' => '1.13.0'], $version);
    }
}
