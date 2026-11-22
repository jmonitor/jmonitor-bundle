<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Snapshot;

use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectBudget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CollectBudgetTest extends TestCase
{
    public function testAPageAllowsImmediateAndNetworkForOneSecondAndNeverSlow(): void
    {
        $clock = new MockClock('2026-10-12 10:00:00');
        $budget = CollectBudget::page($clock);

        self::assertTrue($budget->allows(CostClass::Immediate));
        self::assertTrue($budget->allows(CostClass::Network));
        self::assertFalse($budget->allows(CostClass::Slow));

        $clock->sleep(0.99);

        self::assertTrue($budget->allows(CostClass::Immediate));
        self::assertTrue($budget->allows(CostClass::Network));

        $clock->sleep(0.01);

        self::assertFalse($budget->allows(CostClass::Immediate));
        self::assertFalse($budget->allows(CostClass::Network));
        self::assertFalse($budget->allows(CostClass::Slow));
    }

    public function testAFragmentAllowsSlowWhileUnderTwoSecondsOfSlowTime(): void
    {
        $clock = new MockClock('2026-10-12 10:00:00');
        $budget = CollectBudget::fragment($clock);

        self::assertTrue($budget->allows(CostClass::Slow));

        $budget->ran(CostClass::Slow, 1.5);
        $budget->ran(CostClass::Network, 5.0);

        self::assertTrue($budget->allows(CostClass::Slow), 'only slow time counts');

        $budget->ran(CostClass::Slow, 0.5);

        self::assertFalse($budget->allows(CostClass::Slow));
        self::assertTrue($budget->allows(CostClass::Network), 'the clock, not ran(), measures the second');

        $clock->sleep(1.0);

        self::assertFalse($budget->allows(CostClass::Network));
    }
}
