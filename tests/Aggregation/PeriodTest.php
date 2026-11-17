<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Aggregation;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use PHPUnit\Framework\TestCase;

class PeriodTest extends TestCase
{
    public function testBucketBoundaries(): void
    {
        static::assertSame(60, Period::OneHour->bucketSeconds());
        static::assertSame(10080, Period::SevenDays->bucketSeconds());
        static::assertSame(1700000040, Period::OneHour->bucketFor(1700000099.9));
        static::assertSame(Period::OneDay, Period::fromLabel('24h'));
        static::assertNull(Period::fromLabel('abc'));
    }

    public function testLabels(): void
    {
        static::assertSame(['1h', '6h', '24h', '7d'], array_map(static fn(Period $p): string => $p->label(), Period::cases()));
    }
}
