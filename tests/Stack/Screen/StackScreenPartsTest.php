<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen;

use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\ComponentState;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Screen\StackScreenParts;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use PHPUnit\Framework\TestCase;

final class StackScreenPartsTest extends TestCase
{
    private const NOW = 1_780_000_000;

    public function testAFailureThatIsDueAsksForAnotherRead(): void
    {
        foreach ([ResultState::Stale, ResultState::Failed] as $shown) {
            self::assertTrue(StackScreenParts::followUp(self::state($shown, self::NOW - 1)), $shown->value);
            self::assertTrue(StackScreenParts::followUp(self::state($shown, self::NOW)), $shown->value);
        }
    }

    public function testAFailureStillKeptAsksForNothing(): void
    {
        foreach ([ResultState::Stale, ResultState::Failed] as $shown) {
            self::assertFalse(StackScreenParts::followUp(self::state($shown, self::NOW + 1)), $shown->value);
        }
    }

    public function testWithoutAWorkingCacheADueFailureAsksForNothing(): void
    {
        foreach ([CacheState::Cold, CacheState::OutOfOrder] as $cache) {
            self::assertFalse(StackScreenParts::followUp(self::state(ResultState::Stale, self::NOW - 1, $cache)), $cache->value);
        }
    }

    private static function state(ResultState $shown, int $expiresAt, CacheState $cache = CacheState::Working): StackState
    {
        $data = $shown === ResultState::Stale ? ['Uptime' => 5] : [];
        $result = new CollectorResult('mysql', $data, $shown, 'Access denied', $data === [] ? null : self::NOW - 30, null, $expiresAt, FailureKind::Unreachable);
        $instance = new InstanceState('a', 'shop', null, new ComponentSnapshot(Component::Mysql, ['mysql' => $result]));

        return new StackState(null, self::NOW, $cache, [new ComponentState(Component::Mysql, [$instance], 0)], []);
    }
}
