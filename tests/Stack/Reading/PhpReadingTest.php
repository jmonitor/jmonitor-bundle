<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Metric\RateSource;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Reading\PhpReading;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpReadingTest extends TestCase
{
    public function testValuesOfACompleteSnapshotUnderFpm(): void
    {
        $delta = Snapshots::delta(
            ['opcache' => ['status' => ['opcache_statistics' => ['hits' => 1000, 'misses' => 100]]], 'fpm' => ['accepted-conn' => 4950, 'max-children-reached' => 1]],
            Snapshots::php(),
            10,
        );

        $reading = PhpReading::of(Snapshots::of(Component::Php, ['php' => Snapshots::php(), 'eol.php' => Snapshots::cycles()], deltas: ['php' => $delta]), self::today());

        self::assertSame('8.4.23', $reading->version);
        self::assertSame(SupportStatus::Active, $reading->support);
        self::assertSame('fpm-fcgi', $reading->sapi);
        self::assertSame(134_217_728, $reading->memoryLimit);
        self::assertFalse($reading->memoryUnlimited);
        self::assertSame(30, $reading->maxExecutionTime);
        self::assertFalse($reading->displayErrors);
        self::assertSame(4_194_304, $reading->realpathCacheSize);

        self::assertFalse($reading->opcacheAbsent);
        self::assertTrue($reading->opcacheRunning);
        self::assertTrue($reading->opcacheEnabled);
        self::assertSame(60_000_000, $reading->opcacheUsed);
        self::assertSame(10_000_000, $reading->opcacheWasted);
        self::assertSame(100_000_000, $reading->opcacheTotal);
        self::assertSame(70.0, $reading->opcacheMemoryPercent, 'wasted memory is not free');
        self::assertFalse($reading->opcacheFull);
        self::assertSame(90.83, $reading->opcacheHitRate->value, 'the 100 lookups between the two samples are too few: the counters tell');
        self::assertSame(RateSource::SinceStart, $reading->opcacheHitRate->source);
        self::assertSame(1_200, $reading->opcacheHitRate->total);

        self::assertTrue($reading->apcuEnabled);
        self::assertSame(25_165_824, $reading->apcuUsed);
        self::assertSame(33_554_432, $reading->apcuTotal);
        self::assertSame(75.0, $reading->apcuMemoryPercent);
        self::assertSame(90.0, $reading->apcuHitRate->value);
        self::assertSame(RateSource::SinceStart, $reading->apcuHitRate->source, 'no APCu counters in the delta');

        self::assertTrue($reading->fpm);
        self::assertSame(2, $reading->fpmActive);
        self::assertSame(6, $reading->fpmIdle);
        self::assertSame(8, $reading->fpmTotal);
        self::assertSame(0, $reading->fpmQueue);
        self::assertSame(3, $reading->fpmMaxChildrenReached);
        self::assertSame(2.0, $reading->fpmMaxChildrenReachedRecently);
        self::assertSame(5.0, $reading->fpmRequestsPerSecond->value, '50 connections in 10 seconds');
        self::assertSame(1, $reading->fpmSlowRequests);
        self::assertSame('www', $reading->fpmPool);
        self::assertSame('dynamic', $reading->fpmProcessManager);
    }

    /**
     * @return iterable<string, array{int, int, ?array{int, int}, ?float, ?RateSource}>
     */
    public static function hitRates(): iterable
    {
        yield 'no lookup between the samples' => [500_000, 500_000, [500_000, 500_000], 50.0, RateSource::SinceStart];
        yield 'too few lookups between the samples' => [500_000, 500_000, [499_800, 499_800], 50.0, RateSource::SinceStart];
        yield 'enough lookups between the samples' => [990_500, 10_500, [990_000, 10_000], 50.0, RateSource::Delta];
        yield 'a few lookups and no second sample' => [500, 499, null, 50.05, RateSource::SinceStart];
        yield 'no lookup at all' => [0, 0, null, null, RateSource::Unavailable];
    }

    /**
     * @param ?array{int, int} $previous hits and misses of the previous sample
     */
    #[DataProvider('hitRates')]
    public function testTheHitRateIsTheVariationWhenItHoldsEnoughLookupsElseTheCounters(int $hits, int $misses, ?array $previous, ?float $value, RateSource $source): void
    {
        $data = Snapshots::php();
        $data['opcache']['status']['opcache_statistics']['hits'] = $hits;
        $data['opcache']['status']['opcache_statistics']['misses'] = $misses;
        $deltas = $previous === null ? [] : ['php' => Snapshots::delta(
            ['opcache' => ['status' => ['opcache_statistics' => ['hits' => $previous[0], 'misses' => $previous[1]]]]],
            ['opcache' => ['status' => ['opcache_statistics' => ['hits' => $hits, 'misses' => $misses]]]],
        )];

        $rate = PhpReading::of(Snapshots::of(Component::Php, ['php' => $data], deltas: $deltas), self::today())->opcacheHitRate;

        self::assertSame($value, $rate->value);
        self::assertSame($source, $rate->source);
    }

    public function testWithoutASecondSampleTheRecentValuesAreUnknown(): void
    {
        $reading = PhpReading::of(Snapshots::of(Component::Php, ['php' => Snapshots::php()]), self::today());

        self::assertNull($reading->fpmMaxChildrenReachedRecently);
        self::assertTrue($reading->fpmRequestsPerSecond->isPending());
        self::assertSame(RateSource::SinceStart, $reading->opcacheHitRate->source);
        self::assertNull($reading->support, 'no release cycles read');
    }

    public function testCliWithoutOpcacheApcuOrFpm(): void
    {
        $reading = PhpReading::of(Snapshots::of(Component::Php, ['php' => [
            'version' => '8.4.23',
            'sapi_name' => 'cli',
            'memory_limit' => '-1',
            'display_errors' => 'STDOUT',
            'opcache' => [],
            'apcu' => [],
            'fpm' => [],
        ]]), self::today());

        self::assertTrue($reading->opcacheAbsent, 'no extension, or no status to read');
        self::assertNull($reading->opcacheRunning);
        self::assertFalse($reading->opcacheEnabled);
        self::assertFalse($reading->apcuEnabled);
        self::assertFalse($reading->fpm);
        self::assertNull($reading->memoryLimit);
        self::assertTrue($reading->memoryUnlimited);
        self::assertTrue($reading->displayErrors);
        self::assertTrue($reading->opcacheHitRate->isUnavailable());
        self::assertTrue($reading->fpmRequestsPerSecond->isUnavailable());
    }

    public function testOpcacheTurnedOffAtRuntimeIsNotEnabled(): void
    {
        $data = Snapshots::php();
        $data['opcache']['status']['opcache_enabled'] = false;

        self::assertFalse(PhpReading::of(Snapshots::of(Component::Php, ['php' => $data]), self::today())->opcacheEnabled);
    }

    /**
     * @return iterable<string, array{mixed, bool, ?bool}>
     */
    public static function opcacheStatuses(): iterable
    {
        yield 'running' => [['status' => ['opcache_enabled' => true]], false, true];
        yield 'turned off at runtime' => [['config' => ['directives' => ['opcache.enable' => true]], 'status' => ['opcache_enabled' => false]], false, false];
        yield 'turned off by its directive' => [['config' => ['directives' => ['opcache.enable' => '0']]], false, false];
        yield 'false' => [false, true, null];
        yield 'empty' => [[], true, null];
        yield 'null' => [null, false, null];
        yield 'a string' => ['on', false, null];
        yield 'status and config of unknown shape' => [['status' => 'x', 'config' => 5], false, null];
        yield 'a flag that cannot be read' => [['status' => ['opcache_enabled' => 'maybe']], false, null];
    }

    #[DataProvider('opcacheStatuses')]
    public function testOnlyAReadableStatusSaysWhetherOpcacheRuns(mixed $opcache, bool $absent, ?bool $running): void
    {
        $reading = PhpReading::of(Snapshots::of(Component::Php, ['php' => ['opcache' => $opcache]]), self::today());

        self::assertSame($absent, $reading->opcacheAbsent);
        self::assertSame($running, $reading->opcacheRunning);
        self::assertSame($running === true, $reading->opcacheEnabled);
    }

    public function testSumsBeyondTheIntegerRangeGiveNulls(): void
    {
        $reading = PhpReading::of(Snapshots::of(Component::Php, ['php' => [
            'opcache' => ['status' => ['opcache_enabled' => true, 'memory_usage' => ['used_memory' => PHP_INT_MAX, 'free_memory' => 10, 'wasted_memory' => 10]]],
            'apcu' => ['config' => ['apc.enabled' => true], 'sma_info' => ['num_seg' => 4, 'seg_size' => PHP_INT_MAX, 'avail_mem' => 0]],
            'fpm' => ['active-processes' => PHP_INT_MAX, 'idle-processes' => 1],
        ]]), self::today());

        self::assertNull($reading->opcacheTotal);
        self::assertNull($reading->opcacheMemoryPercent);
        self::assertNull($reading->apcuTotal);
        self::assertNull($reading->apcuUsed);
        self::assertNull($reading->apcuMemoryPercent);
        self::assertNull($reading->fpmTotal);
    }

    public function testAFullOpcacheIsTold(): void
    {
        $data = Snapshots::php();
        $data['opcache']['status']['cache_full'] = true;

        self::assertTrue(PhpReading::of(Snapshots::of(Component::Php, ['php' => $data]), self::today())->opcacheFull);
    }

    /**
     * @return iterable<string, array{mixed, ?bool}>
     */
    public static function displayErrors(): iterable
    {
        yield 'off' => ['0', false];
        yield 'on' => ['On', true];
        yield 'stderr' => ['stderr', true];
        yield 'stdout' => ['stdout', true];
        yield 'boolean' => [true, true];
        yield 'unknown' => ['sometimes', null];
        yield 'missing' => [null, null];
    }

    #[DataProvider('displayErrors')]
    public function testDisplayErrorsToAStreamCountsAsOn(mixed $value, ?bool $expected): void
    {
        $reading = PhpReading::of(Snapshots::of(Component::Php, ['php' => ['display_errors' => $value]]), self::today());

        self::assertSame($expected, $reading->displayErrors);
    }

    public function testAnEmptySnapshotGivesNulls(): void
    {
        foreach ([new ComponentSnapshot(Component::Php, []), Snapshots::of(Component::Php, [], ['php' => ResultState::Failed])] as $snapshot) {
            $reading = PhpReading::of($snapshot, self::today());

            self::assertNull($reading->version);
            self::assertNull($reading->support);
            self::assertNull($reading->sapi);
            self::assertNull($reading->displayErrors);
            self::assertNull($reading->realpathCacheSize);
            self::assertFalse($reading->opcacheAbsent);
            self::assertNull($reading->opcacheRunning);
            self::assertFalse($reading->opcacheEnabled);
            self::assertNull($reading->opcacheMemoryPercent);
            self::assertFalse($reading->opcacheFull);
            self::assertTrue($reading->opcacheHitRate->isUnavailable());
            self::assertFalse($reading->apcuEnabled);
            self::assertNull($reading->apcuMemoryPercent);
            self::assertTrue($reading->apcuHitRate->isUnavailable());
            self::assertFalse($reading->fpm);
            self::assertNull($reading->fpmActive);
            self::assertNull($reading->fpmTotal);
            self::assertNull($reading->fpmQueue);
            self::assertNull($reading->fpmMaxChildrenReached);
            self::assertNull($reading->fpmMaxChildrenReachedRecently);
            self::assertTrue($reading->fpmRequestsPerSecond->isUnavailable());
        }
    }

    public function testDataOfUnknownShapeGivesNullsWithoutWarning(): void
    {
        set_error_handler(static fn(int $level, string $message): never => throw new \ErrorException($message, 0, $level));

        try {
            $reading = PhpReading::of(Snapshots::of(Component::Php, [
                'php' => [
                    'version' => ['8.4'],
                    'sapi_name' => false,
                    'memory_limit' => 'lots',
                    'max_execution_time' => 'forever',
                    'display_errors' => ['On'],
                    'realpath_cache_size' => '4096Q',
                    'opcache' => false,
                    'apcu' => 'on',
                ],
                'eol.php' => ['cycles' => 'soon'],
            ], deltas: ['php' => Snapshots::delta(['fpm' => 'x'], ['fpm' => ['max-children-reached' => 'many']])]), self::today());
        } finally {
            restore_error_handler();
        }

        self::assertNull($reading->version);
        self::assertNull($reading->support);
        self::assertNull($reading->sapi);
        self::assertNull($reading->memoryLimit);
        self::assertFalse($reading->memoryUnlimited);
        self::assertNull($reading->maxExecutionTime);
        self::assertNull($reading->displayErrors);
        self::assertNull($reading->realpathCacheSize);
        self::assertFalse($reading->opcacheEnabled);
        self::assertNull($reading->opcacheMemoryPercent);
        self::assertFalse($reading->opcacheFull);
        self::assertTrue($reading->opcacheHitRate->isUnavailable());
        self::assertFalse($reading->apcuEnabled);
        self::assertNull($reading->apcuMemoryPercent);
        self::assertFalse($reading->fpm);
        self::assertNull($reading->fpmMaxChildrenReached);
        self::assertNull($reading->fpmMaxChildrenReachedRecently);
        self::assertTrue($reading->fpmRequestsPerSecond->isUnavailable());
    }

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . Snapshots::NOW);
    }
}
