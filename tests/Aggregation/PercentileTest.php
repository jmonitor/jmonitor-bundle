<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Aggregation;

use Jmonitor\JmonitorBundle\Aggregation\Percentile;
use PHPUnit\Framework\TestCase;

class PercentileTest extends TestCase
{
    public function testInterpolatesInsideTheBucket(): void
    {
        static::assertEqualsWithDelta(175.0, Percentile::estimate(['100' => 90, '250' => 10], 0.95, 240.0), 0.001);
    }

    public function testNeverExceedsMax(): void
    {
        static::assertSame(12000.0, Percentile::estimate(['inf' => 10], 0.95, 12000.0));
        static::assertLessThanOrEqual(40.0, Percentile::estimate(['100' => 10], 0.95, 40.0));
    }

    public function testEmptyGivesZero(): void
    {
        static::assertSame(0.0, Percentile::estimate([], 0.95, 0.0));
    }
}
