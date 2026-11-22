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

        self::assertTrue($reading->opcacheReported);
        self::assertTrue($reading->opcacheEnabled);
        self::assertSame(60_000_000, $reading->opcacheUsed);
        self::assertSame(100_000_000, $reading->opcacheTotal);
        self::assertSame(60.0, $reading->opcacheMemoryPercent);
        self::assertFalse($reading->opcacheFull);
        self::assertSame(90.0, $reading->opcacheHitRate->value, '90 hits for 10 misses between the two samples');
        self::assertSame(RateSource::Delta, $reading->opcacheHitRate->source);

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

        self::assertTrue($reading->opcacheReported, 'an empty array says the extension is not there');
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
            self::assertFalse($reading->opcacheReported);
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
