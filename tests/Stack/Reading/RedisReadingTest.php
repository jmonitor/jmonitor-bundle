<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Collector\RedisInfoCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\RedisLink;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Metric\RateSource;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Reading\RedisReading;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeRedis;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\RedisPayloads;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use PHPUnit\Framework\TestCase;

final class RedisReadingTest extends TestCase
{
    private const LIMIT = 2_147_483_648;

    private const PREVIOUS = ['stats' => [
        'total_commands_processed' => 840_000,
        'keyspace_hits' => 8_950,
        'keyspace_misses' => 1_000,
        'evicted_keys' => 4,
        'rejected_connections' => 0,
    ]];

    public function testValuesOfACompleteSnapshot(): void
    {
        $data = self::data(['memory' => ['max_memory' => self::LIMIT]]);
        $reading = RedisReading::of(self::snapshot($data, Snapshots::delta(self::PREVIOUS, $data)), self::today());

        self::assertSame('Redis', $reading->product);
        self::assertSame('7.2.4', $reading->version);
        self::assertSame(SupportStatus::SecurityOnly, $reading->support);
        self::assertSame(360_000, $reading->uptime);
        self::assertSame('standalone', $reading->mode);
        self::assertSame('master', $reading->role);

        self::assertSame(1_500_000_000, $reading->usedMemory);
        self::assertSame(self::LIMIT, $reading->maxMemory);
        self::assertSame(1_500_000_000 / self::LIMIT * 100, $reading->memoryPercent);
        self::assertSame(1_600_000_000, $reading->peakMemory);
        self::assertSame('allkeys-lru', $reading->policy);
        self::assertTrue($reading->evicts);

        self::assertSame(12, $reading->clients);
        self::assertSame(1_000.0, $reading->commandsPerSecond->value, '60 000 commands in 60 seconds');
        self::assertSame('cmd/s', $reading->commandsPerSecond->unit);
        self::assertSame(95.24, $reading->hitRate->value, '1 000 hits out of 1 050 lookups');
        self::assertSame(RateSource::Delta, $reading->hitRate->source);
        self::assertSame(1_050, $reading->lookups);
        self::assertSame(3, $reading->evictedLately);
        self::assertSame(7, $reading->evictedKeys);
        self::assertSame(0, $reading->rejectedLately);
        self::assertSame(0, $reading->rejectedConnections);

        self::assertSame('ok', $reading->rdbStatus);
        self::assertFalse($reading->rdbInProgress);
        self::assertSame(1_760_000_000, $reading->lastSaveAt);
        self::assertSame(42, $reading->unsavedChanges);
        self::assertFalse($reading->aofEnabled);
        self::assertSame('ok', $reading->aofStatus);
        self::assertFalse($reading->aofInProgress);

        self::assertSame([
            ['name' => 'db0', 'keys' => 1_500, 'expires' => 1_200],
            ['name' => 'db1', 'keys' => 10, 'expires' => 0],
        ], $reading->databases);
    }

    public function testAnEmptySnapshotGivesNulls(): void
    {
        $failed = Snapshots::of(Component::Redis, [], ['redis' => ResultState::Failed]);

        foreach ([new ComponentSnapshot(Component::Redis, []), $failed] as $snapshot) {
            self::assertEmptyReading(RedisReading::of($snapshot, self::today()));
        }
    }

    public function testDataOfUnknownShapeGivesNullsWithoutWarning(): void
    {
        set_error_handler(static fn(int $level, string $message): never => throw new \ErrorException($message, 0, $level));

        try {
            $odd = RedisReading::of(Snapshots::of(Component::Redis, [
                'redis' => [
                    'server' => ['version' => ['7.2.4'], 'mode' => [], 'uptime' => 'long', 'name' => ['valkey'], 'valkey_version' => []],
                    'clients' => ['connected' => 'many'],
                    'memory' => ['used' => 'lots', 'used_peak' => [1], 'max_memory' => INF, 'max_memory_policy' => ['noeviction']],
                    'persistence' => ['rdb_bgsave_in_progress' => 'maybe', 'rdb_last_save_time' => [], 'rdb_changes_since_last_save' => 'some', 'rdb_last_bgsave_status' => [], 'aof_enabled' => 'perhaps', 'aof_last_bgrewrite_status' => 1.5],
                    'stats' => ['total_commands_processed' => 'many', 'keyspace_hits' => [1], 'keyspace_misses' => null, 'evicted_keys' => 'few', 'rejected_connections' => []],
                    'replication' => 'none',
                    'databases' => ['db0' => 'keys=1500', 'db1' => ['keys' => 'many', 'expires' => 1], 'db2' => ['expires' => 1], 'db3' => ['keys' => [1]]],
                ],
                'eol.redis' => ['cycles' => ['7.2' => 'ended']],
            ], deltas: ['redis' => Snapshots::delta(['stats' => 'x'], ['stats' => ['keyspace_hits' => ['y']]])]), self::today());
            $notAnArray = RedisReading::of(Snapshots::of(Component::Redis, [
                'redis' => ['server' => 'x', 'clients' => 'x', 'memory' => 'x', 'persistence' => 'x', 'stats' => 'x', 'replication' => 'x', 'databases' => 'x'],
            ]), self::today());

            $integers = RedisReading::of(self::snapshot(self::data()), self::today());
            $strings = RedisReading::of(self::snapshot(self::strings(self::data())), self::today());
        } finally {
            restore_error_handler();
        }

        self::assertEmptyReading($odd);
        self::assertEmptyReading($notAnArray);
        self::assertEquals($integers, $strings, 'the redis extension gives numbers, Predis gives strings');
        self::assertSame(1_500_000_000, $strings->usedMemory);
    }

    public function testValkeyIsToldByItsNameAndTakesItsOwnVersion(): void
    {
        $valkey = RedisReading::of(self::snapshot(self::data(['server' => ['name' => 'valkey', 'valkey_version' => '8.0.1']])), self::today());
        $byVersionOnly = RedisReading::of(self::snapshot(self::data(['server' => ['valkey_version' => '8.0.1']])), self::today());
        $byNameOnly = RedisReading::of(self::snapshot(self::data(['server' => ['name' => 'valkey']])), self::today());

        self::assertSame('Valkey', $valkey->product);
        self::assertSame('8.0.1', $valkey->version, 'not the Redis version it tells for compatibility');
        self::assertSame(SupportStatus::Active, $valkey->support, 'the cycles of Valkey');
        self::assertSame('Valkey', $byVersionOnly->product);
        self::assertSame('8.0.1', $byVersionOnly->version);
        self::assertSame('Valkey', $byNameOnly->product);
        self::assertNull($byNameOnly->version, 'its own version is unknown');
        self::assertNull($byNameOnly->support);
    }

    public function testRedisTakesTheCyclesOfRedis(): void
    {
        $version = static fn(string $version): RedisReading => RedisReading::of(self::snapshot(self::data(['server' => ['version' => $version]])), self::today());

        self::assertSame(SupportStatus::Active, $version('7.4.2')->support);
        self::assertSame(SupportStatus::SecurityOnly, $version('7.2.4')->support);
        self::assertSame(SupportStatus::Ended, $version('6.2.14')->support);
        self::assertNull($version('8.0.1')->support, 'not a cycle of Redis in the fixture, though one of Valkey');
    }

    public function testWithoutALimitThereIsNoPercentage(): void
    {
        $unlimited = RedisReading::of(self::snapshot(self::data()), self::today());
        $noeviction = RedisReading::of(self::snapshot(self::data(['memory' => ['max_memory' => self::LIMIT, 'max_memory_policy' => 'noeviction']])), self::today());

        self::assertNull($unlimited->maxMemory, 'maxmemory 0 sets no limit');
        self::assertNull($unlimited->memoryPercent);
        self::assertSame(1_500_000_000, $unlimited->usedMemory);
        self::assertFalse($unlimited->evicts, 'nothing is evicted without a limit');
        self::assertSame(self::LIMIT, $noeviction->maxMemory);
        self::assertFalse($noeviction->evicts);
    }

    public function testWithoutASecondReadingTheRatesWait(): void
    {
        $reading = RedisReading::of(self::snapshot(self::data()), self::today());

        self::assertTrue($reading->commandsPerSecond->isPending());
        self::assertNull($reading->lookups);
        self::assertNull($reading->evictedLately);
        self::assertNull($reading->rejectedLately);
        self::assertSame(7, $reading->evictedKeys);
        self::assertSame(90.45, $reading->hitRate->value, '9 950 hits out of 11 000 lookups');
        self::assertSame(RateSource::SinceStart, $reading->hitRate->source);
        self::assertSame('since the server started', $reading->hitRate->hint());
    }

    public function testTheHitRateIsTheVariationWhenItCountsEnough(): void
    {
        $window = static function (int $hits, int $misses): RedisReading {
            $data = self::data(['stats' => ['keyspace_hits' => 9_950 + $hits, 'keyspace_misses' => 1_050 + $misses]]);

            return RedisReading::of(self::snapshot($data, Snapshots::delta(self::data(), $data)), self::today());
        };

        $three = $window(0, 3);
        $tooFew = $window(500, 499);
        $enough = $window(0, 1_000);

        self::assertSame(RateSource::SinceStart, $three->hitRate->source, 'three lookups tell nothing');
        self::assertSame(90.43, $three->hitRate->value, '9 950 hits out of 11 003 lookups');
        self::assertSame('since the server started', $three->hitRate->hint());
        self::assertSame(3, $three->lookups);

        self::assertSame(RateSource::SinceStart, $tooFew->hitRate->source);
        self::assertSame(999, $tooFew->lookups);

        self::assertSame(RateSource::Delta, $enough->hitRate->source);
        self::assertSame(0.0, $enough->hitRate->value);
        self::assertSame(1_000, $enough->lookups);
    }

    public function testTheModeOfValkey8IsRead(): void
    {
        $kept = (new RedisInfoCollector(new RedisLink(static fn(): FakeRedis => new FakeRedis(RedisPayloads::valkey8()))))->collect();

        $reading = RedisReading::of(self::snapshot($kept), self::today());

        self::assertSame('Valkey', $reading->product);
        self::assertSame('8.1.1', $reading->version);
        self::assertSame('standalone', $reading->mode, 'Valkey 8 tells server_mode, no longer redis_mode');
    }

    public function testAnIdleWindowFallsBackOnTheCounters(): void
    {
        $data = self::data();
        $reading = RedisReading::of(self::snapshot($data, Snapshots::delta($data, $data)), self::today());

        self::assertSame(RateSource::SinceStart, $reading->hitRate->source, 'no lookup over the window');
        self::assertSame(0, $reading->lookups);
        self::assertSame(0, $reading->evictedLately);
    }

    public function testASnapshotOrARewriteInProgressIsTold(): void
    {
        $reading = RedisReading::of(self::snapshot(self::data(['persistence' => [
            'rdb_bgsave_in_progress' => 1,
            'rdb_last_bgsave_status' => 'err',
            'aof_enabled' => 1,
            'aof_rewrite_in_progress' => 1,
            'aof_last_bgrewrite_status' => 'err',
        ]])), self::today());

        self::assertTrue($reading->rdbInProgress);
        self::assertSame('err', $reading->rdbStatus);
        self::assertTrue($reading->aofEnabled);
        self::assertTrue($reading->aofInProgress);
        self::assertSame('err', $reading->aofStatus);
    }

    private static function assertEmptyReading(RedisReading $reading): void
    {
        self::assertNull($reading->product);
        self::assertNull($reading->version);
        self::assertNull($reading->support);
        self::assertNull($reading->uptime);
        self::assertNull($reading->mode);
        self::assertNull($reading->role);
        self::assertNull($reading->usedMemory);
        self::assertNull($reading->maxMemory);
        self::assertNull($reading->memoryPercent);
        self::assertNull($reading->peakMemory);
        self::assertNull($reading->policy);
        self::assertFalse($reading->evicts);
        self::assertNull($reading->clients);
        self::assertTrue($reading->commandsPerSecond->isUnavailable());
        self::assertFalse($reading->commandsPerSecond->isPending(), 'no later sample would bring it');
        self::assertTrue($reading->hitRate->isUnavailable());
        self::assertNull($reading->lookups);
        self::assertNull($reading->evictedLately);
        self::assertNull($reading->evictedKeys);
        self::assertNull($reading->rejectedLately);
        self::assertNull($reading->rejectedConnections);
        self::assertNull($reading->rdbStatus);
        self::assertFalse($reading->rdbInProgress);
        self::assertNull($reading->lastSaveAt);
        self::assertNull($reading->unsavedChanges);
        self::assertFalse($reading->aofEnabled);
        self::assertNull($reading->aofStatus);
        self::assertFalse($reading->aofInProgress);
        self::assertSame([], $reading->databases);
    }

    /**
     * @param array<string, mixed> $replaced replacing what the stack keeps of a Redis 7.2, recursively
     *
     * @return array<string, mixed>
     */
    private static function data(array $replaced = []): array
    {
        return array_replace_recursive(RedisPayloads::kept(), $replaced);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function strings(array $data): array
    {
        return array_map(static fn(mixed $value): mixed => match (true) {
            \is_array($value) => self::strings($value),
            \is_int($value), \is_float($value) => (string) $value,
            default => $value,
        }, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function snapshot(array $data, ?Delta $delta = null): ComponentSnapshot
    {
        return Snapshots::of(
            Component::Redis,
            ['redis' => $data, 'eol.redis' => RedisPayloads::redisCycles(), 'eol.valkey' => RedisPayloads::valkeyCycles()],
            deltas: $delta === null ? [] : ['redis' => $delta],
        );
    }

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . Snapshots::NOW);
    }
}
