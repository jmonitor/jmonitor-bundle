<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Snapshot;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use PHPUnit\Framework\TestCase;

final class ComponentSnapshotTest extends TestCase
{
    public function testAccessors(): void
    {
        $delta = new Delta(['requests' => 1], ['requests' => 11], 10);
        $snapshot = new ComponentSnapshot(Component::Php, [
            'php' => new CollectorResult('php', ['requests' => 11], ResultState::Fresh, collectedAt: 1_000, delta: $delta),
            'php.old' => new CollectorResult('php.old', ['version' => '8.3'], ResultState::Stale, collectedAt: 700),
            'php.failed' => new CollectorResult('php.failed', [], ResultState::Failed, 'opcache is not loaded'),
        ]);

        self::assertTrue($snapshot->has('php'));
        self::assertTrue($snapshot->has('php.old'), 'an outdated value still shows');
        self::assertFalse($snapshot->has('php.failed'));
        self::assertFalse($snapshot->has('php.missing'));
        self::assertSame(['requests' => 11], $snapshot->data('php'));
        self::assertSame([], $snapshot->data('php.missing'));
        self::assertSame($delta, $snapshot->delta('php'));
        self::assertNull($snapshot->delta('php.failed'));
        self::assertSame($snapshot->results['php.old'], $snapshot->result('php.old'));
        self::assertNull($snapshot->error);
    }

    public function testAResultIsOkWhenItHasAValueToShow(): void
    {
        $ok = static fn(ResultState $state): bool => (new CollectorResult('php', [], $state))->ok();

        self::assertTrue($ok(ResultState::Fresh));
        self::assertTrue($ok(ResultState::Stale));
        self::assertFalse($ok(ResultState::Pending));
        self::assertFalse($ok(ResultState::Busy));
        self::assertFalse($ok(ResultState::Failed));
    }

    public function testItIsReadingWhileAResultIsPendingOrBusy(): void
    {
        $snapshot = static function (ResultState ...$states): ComponentSnapshot {
            $results = [];

            foreach ($states as $state) {
                $results['php.' . $state->value] = new CollectorResult('php.' . $state->value, [], $state);
            }

            return new ComponentSnapshot(Component::Php, $results);
        };

        self::assertTrue($snapshot(ResultState::Fresh, ResultState::Pending)->isReading());
        self::assertTrue($snapshot(ResultState::Fresh, ResultState::Busy)->isReading());
        self::assertFalse($snapshot(ResultState::Fresh, ResultState::Stale, ResultState::Failed)->isReading());
        self::assertFalse((new ComponentSnapshot(Component::Php, []))->isReading());
    }

    public function testErrorsListFailuresAndOutdatedValuesThatCouldNotBeRenewed(): void
    {
        $snapshot = new ComponentSnapshot(Component::Php, [
            'php' => new CollectorResult('php', [], ResultState::Fresh, collectedAt: 1_000),
            'php.failed' => new CollectorResult('php.failed', [], ResultState::Failed, 'opcache is not loaded'),
            'php.renewing' => new CollectorResult('php.renewing', [], ResultState::Stale, collectedAt: 700),
            'php.unrenewed' => new CollectorResult('php.unrenewed', [], ResultState::Stale, 'Connection refused', 700),
            'php.pending' => new CollectorResult('php.pending', [], ResultState::Pending),
            'php.busy' => new CollectorResult('php.busy', [], ResultState::Busy),
        ]);

        self::assertSame(['php.failed: opcache is not loaded', 'php.unrenewed: Connection refused'], $snapshot->errors());
    }

    public function testFreshnessTakesTheOldestReadAndTheEarliestNextRead(): void
    {
        $snapshot = new ComponentSnapshot(Component::Php, [
            'php.a' => new CollectorResult('php.a', [], ResultState::Fresh, collectedAt: 1_000, expiresAt: 1_900),
            'php.b' => new CollectorResult('php.b', [], ResultState::Fresh, collectedAt: 1_100, expiresAt: 1_400),
            'php.failed' => new CollectorResult('php.failed', [], ResultState::Failed, 'boom', expiresAt: 910),
            'php.pending' => new CollectorResult('php.pending', [], ResultState::Pending),
        ]);

        $freshness = $snapshot->freshness(1_200, 'php.a', 'php.b', 'php.failed', 'php.pending', 'php.missing');

        self::assertNotNull($freshness);
        self::assertSame(200, $freshness->age());
        self::assertSame(200, $freshness->nextReadIn());
        self::assertSame(300, $freshness->lifetime);
        self::assertNull($snapshot->freshness(1_200, 'php.failed', 'php.pending'), 'no value tells nothing');
        self::assertSame(0, $snapshot->freshness(1_050, 'php.b')?->age(), 'read by a web server whose clock is ahead');
        self::assertSame(1_050, $snapshot->freshness(1_050, 'php.b')?->readAt);
    }

    public function testFreshnessCountsAnOutdatedValue(): void
    {
        $snapshot = new ComponentSnapshot(Component::Php, [
            'php' => new CollectorResult('php', [], ResultState::Stale, collectedAt: 1_000, expiresAt: 1_010),
        ]);

        self::assertSame(200, $snapshot->freshness(1_200, 'php')?->age());
        self::assertSame(0, $snapshot->freshness(1_200, 'php')?->nextReadIn());
    }
}
