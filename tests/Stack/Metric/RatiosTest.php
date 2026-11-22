<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Metric;

use Jmonitor\JmonitorBundle\Stack\Metric\Rate;
use Jmonitor\JmonitorBundle\Stack\Metric\RateSource;
use Jmonitor\JmonitorBundle\Stack\Metric\Ratios;
use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;
use PHPUnit\Framework\TestCase;

final class RatiosTest extends TestCase
{
    public function testShareUsesTheDeltaWhenItHasTraffic(): void
    {
        $delta = new Delta(['hits' => 1000, 'misses' => 100], ['hits' => 1090, 'misses' => 110], 10);

        $rate = Ratios::share($delta, 'hits', 'misses', 1090, 110);

        self::assertSame(90.0, $rate->value);
        self::assertSame(RateSource::Delta, $rate->source);
        self::assertSame('%', $rate->unit);
    }

    public function testShareFallsBackToTheCountersWithoutDeltaOrTraffic(): void
    {
        $sinceStart = Ratios::share(null, 'hits', 'misses', 900, 100);
        self::assertSame(90.0, $sinceStart->value);
        self::assertTrue($sinceStart->isSinceStart());

        $quiet = new Delta(['hits' => 900, 'misses' => 100], ['hits' => 900, 'misses' => 100], 10);
        self::assertTrue(Ratios::share($quiet, 'hits', 'misses', 900, 100)->isSinceStart());
    }

    public function testShareWithoutAnyCounterIsUnavailable(): void
    {
        $rate = Ratios::share(null, 'hits', 'misses', null, null);

        self::assertTrue($rate->isUnavailable());
        self::assertNull($rate->value);
        self::assertSame('%', $rate->unit);
    }

    public function testTheCumulativeFallbackNamesWhereItsCountersStart(): void
    {
        $delta = new Delta(['hits' => 1000, 'misses' => 100], ['hits' => 1090, 'misses' => 110], 10);

        self::assertSame('since server start', Ratios::share(null, 'hits', 'misses', 900, 100)->hint());
        self::assertSame('since stats reset', Ratios::share(null, 'hits', 'misses', 900, 100, 'since stats reset')->hint());
        self::assertSame('since stats reset', Ratios::percent(null, 'disk', 'all', 15, 150, 'since stats reset')->hint());
        self::assertSame('since stats reset', Ratios::complement(null, 'reads', 'requests', 7, 1100, 'since stats reset')->hint());
        self::assertNull(Ratios::share($delta, 'hits', 'misses', 1090, 110, 'since stats reset')->hint());
        self::assertNull(Ratios::share(null, 'hits', 'misses', null, null, 'since stats reset')->hint());
    }

    public function testPercentAndComplement(): void
    {
        $delta = new Delta(['disk' => 10, 'all' => 100, 'reads' => 5, 'requests' => 1000], ['disk' => 15, 'all' => 150, 'reads' => 7, 'requests' => 1100], 10);

        self::assertSame(10.0, Ratios::percent($delta, 'disk', 'all', 15, 150)->value);
        self::assertSame(98.0, Ratios::complement($delta, 'reads', 'requests', 7, 1100)->value);
        self::assertSame(99.36, Ratios::complement(null, 'reads', 'requests', 7, 1100)->value);
        self::assertTrue(Ratios::percent(null, 'disk', 'all', 15, 0)->isUnavailable());
    }

    public function testCounterResetIsNotNegative(): void
    {
        $delta = new Delta(['hits' => 1000, 'misses' => 100], ['hits' => 5, 'misses' => 1], 10);

        $rate = Ratios::share($delta, 'hits', 'misses', 5, 1);

        self::assertTrue($rate->isSinceStart(), 'a reset counter has no delta, the cumulative value is shown instead');
        self::assertSame(83.33, $rate->value);
    }

    public function testPerSecond(): void
    {
        $delta = new Delta(['Questions' => 100], ['Questions' => 250], 10);

        self::assertSame(15.0, Ratios::perSecond($delta, 'Questions', 'q/s')->value);
        self::assertSame('q/s', Ratios::perSecond($delta, 'Questions', 'q/s')->unit);
        self::assertTrue(Ratios::perSecond(null, 'Questions')->isUnavailable());
        self::assertTrue(Ratios::perSecond($delta, 'Unknown')->isUnavailable());
        self::assertFalse(Ratios::perSecond(null, 'Questions')->isPending(), 'counter not collected: nothing to wait for');
    }

    public function testPerSecondIsPendingUntilASecondSampleOfACollectedCounter(): void
    {
        $delta = new Delta(['Questions' => 100], ['Questions' => 250], 10);
        $pending = Ratios::perSecond(null, 'Questions', 'q/s', 250);

        self::assertTrue($pending->isPending());
        self::assertTrue($pending->isUnavailable());
        self::assertSame('q/s', $pending->unit);
        self::assertSame(Rate::AT_NEXT_REFRESH, $pending->expected);
        self::assertSame(Rate::AFTER_A_MINUTE, Ratios::perSecond(null, 'Questions', 'q/s', 250, Rate::AFTER_A_MINUTE)->expected);
        self::assertFalse(Ratios::perSecond($delta, 'Unknown', '/s', 250)->isPending(), 'a counter missing from the delta never comes');
    }

    public function testAverageUsesTheDeltaWhenItHasTraffic(): void
    {
        $delta = new Delta(['time' => 10.0, 'count' => 100], ['time' => 12.5, 'count' => 110], 10);

        $rate = Ratios::average($delta, 'time', 'count', 12.5, 110, 's');

        self::assertSame(0.25, $rate->value);
        self::assertSame(RateSource::Delta, $rate->source);
        self::assertSame('s', $rate->unit);
        self::assertSame(2.5, $rate->part);
        self::assertSame(10, $rate->total);
    }

    public function testAverageFallsBackToTheCountersWithoutDeltaOrTraffic(): void
    {
        $sinceStart = Ratios::average(null, 'time', 'count', 12.5, 110, 's');
        self::assertSame(round(12.5 / 110, 6), $sinceStart->value);
        self::assertTrue($sinceStart->isSinceStart());
        self::assertSame('since server start', $sinceStart->hint());

        $quiet = new Delta(['time' => 12.5, 'count' => 110], ['time' => 12.5, 'count' => 110], 10);
        self::assertTrue(Ratios::average($quiet, 'time', 'count', 12.5, 110, 's')->isSinceStart());
        self::assertSame('since stats reset', Ratios::average($quiet, 'time', 'count', 12.5, 110, 's', 'since stats reset')->hint());
    }

    public function testAverageWithoutAnyCounterIsUnavailable(): void
    {
        $rate = Ratios::average(null, 'time', 'count', null, null, 's');

        self::assertTrue($rate->isUnavailable());
        self::assertSame('s', $rate->unit);
        self::assertTrue(Ratios::average(null, 'time', 'count', 12.5, 0, 's')->isUnavailable(), 'no request yet');
    }
}
