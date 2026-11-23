<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\EndOfLifeCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\PhpRuntimeCollector;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\PhpDetector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeUrlFetcher;
use PHPUnit\Framework\TestCase;

final class PhpDetectorTest extends TestCase
{
    public function testPhpIsAlwaysFoundAndTracksDeltas(): void
    {
        $detector = new PhpDetector(endOfLife: false);
        $detection = $detector->detect();

        self::assertSame(Component::Php, $detector->component());
        self::assertTrue($detection->isFound());
        $collectors = $detection->instances[0]->collectors;
        self::assertNotNull($collectors);
        self::assertSame(['php'], $collectors->names());

        $spec = $collectors->get('php');
        self::assertNotNull($spec);
        self::assertInstanceOf(PhpRuntimeCollector::class, $spec->collector);
        self::assertSame(Lifetime::Dynamic, $spec->lifetime);
        self::assertSame(CostClass::Immediate, $spec->cost);
        self::assertTrue($spec->trackDelta);
        self::assertFalse($spec->shared);
        self::assertSame(\PHP_VERSION, (new CollectorRunner())->run($spec->collector)['version'], 'read from the running process, not from a URL');
    }

    public function testTheEndOfLifeIsReadOncePerDayForEveryServer(): void
    {
        $fetcher = new FakeUrlFetcher('[{"cycle": "8.5", "eol": "2029-12-31", "support": "2027-12-31"}]');
        $collectors = (new PhpDetector(endOfLife: true, fetcher: $fetcher))->detect()->instances[0]->collectors;

        self::assertNotNull($collectors);
        self::assertSame(['php', 'eol.php'], $collectors->names());

        $spec = $collectors->get('eol.php');
        self::assertNotNull($spec);
        self::assertInstanceOf(EndOfLifeCollector::class, $spec->collector);
        self::assertSame(Lifetime::Day, $spec->lifetime);
        self::assertSame(CostClass::Slow, $spec->cost);
        self::assertFalse($spec->trackDelta);
        self::assertTrue($spec->shared);

        $spec->collector->collect();
        self::assertSame('https://endoflife.date/api/php.json', $fetcher->url);
    }
}
