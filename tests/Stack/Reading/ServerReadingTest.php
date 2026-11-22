<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Reading\ServerReading;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use PHPUnit\Framework\TestCase;

final class ServerReadingTest extends TestCase
{
    public function testValuesOfACompleteSnapshot(): void
    {
        $reading = ServerReading::of(Snapshots::of(Component::System, ['system' => Snapshots::system()]));

        self::assertSame(75.0, $reading->memoryPercent);
        self::assertSame(6_000_000_000, $reading->memoryUsed);
        self::assertSame(8_000_000_000, $reading->memoryTotal);
        self::assertSame(75.0, $reading->diskPercent);
        self::assertSame(75_000_000_000, $reading->diskUsed);
        self::assertSame(100_000_000_000, $reading->diskTotal);
        self::assertSame(62.0, $reading->loadPercent);
        self::assertSame(2.48, $reading->load1);
        self::assertSame(1.1, $reading->load5);
        self::assertSame(0.9, $reading->load15);
        self::assertSame(4, $reading->cores);
        self::assertFalse($reading->container);
        self::assertNull($reading->containerMemoryLimit);
        self::assertNull($reading->containerCpuQuota);
        self::assertSame('Debian GNU/Linux 12 (bookworm)', $reading->os);
        self::assertSame('web-1', $reading->hostname);
        self::assertSame(86_400, $reading->uptime);
        self::assertSame('Europe/Paris', $reading->timezone);
    }

    public function testACgroupLimitMeansAContainer(): void
    {
        $memory = Snapshots::system();
        $memory['cgroup'] = ['memory_limit' => 2_147_483_648, 'cpu_quota' => null];
        $cpu = Snapshots::system();
        $cpu['cgroup'] = ['memory_limit' => null, 'cpu_quota' => 1.5];

        $byMemory = ServerReading::of(Snapshots::of(Component::System, ['system' => $memory]));
        $byCpu = ServerReading::of(Snapshots::of(Component::System, ['system' => $cpu]));

        self::assertTrue($byMemory->container);
        self::assertSame(2_147_483_648, $byMemory->containerMemoryLimit);
        self::assertTrue($byCpu->container);
        self::assertSame(1.5, $byCpu->containerCpuQuota);
    }

    public function testAMissingAvailableAmountGivesNoUsedAmount(): void
    {
        $reading = ServerReading::of(Snapshots::of(Component::System, ['system' => [
            'ram' => ['total' => 8_000_000_000],
            'disk' => ['total' => 100_000_000_000],
        ]]));

        self::assertNull($reading->memoryUsed);
        self::assertNull($reading->memoryPercent);
        self::assertNull($reading->diskUsed);
        self::assertNull($reading->diskPercent);
    }

    public function testDifferencesBeyondTheIntegerRangeGiveNulls(): void
    {
        $reading = ServerReading::of(Snapshots::of(Component::System, ['system' => [
            'ram' => ['total' => PHP_INT_MAX, 'available' => -10],
            'disk' => ['total' => PHP_INT_MAX, 'free' => -1],
        ]]));

        self::assertNull($reading->memoryUsed);
        self::assertNull($reading->memoryPercent);
        self::assertNull($reading->diskUsed);
        self::assertNull($reading->diskPercent);
    }

    public function testAnEmptySnapshotGivesNulls(): void
    {
        foreach ([new ComponentSnapshot(Component::System, []), Snapshots::of(Component::System, [], ['system' => ResultState::Failed])] as $snapshot) {
            $reading = ServerReading::of($snapshot);

            self::assertNull($reading->memoryPercent);
            self::assertNull($reading->memoryUsed);
            self::assertNull($reading->memoryTotal);
            self::assertNull($reading->diskPercent);
            self::assertNull($reading->loadPercent);
            self::assertNull($reading->load1);
            self::assertNull($reading->cores);
            self::assertFalse($reading->container);
            self::assertNull($reading->os);
            self::assertNull($reading->hostname);
            self::assertNull($reading->uptime);
        }
    }

    public function testDataOfUnknownShapeGivesNullsWithoutWarning(): void
    {
        set_error_handler(static fn(int $level, string $message): never => throw new \ErrorException($message, 0, $level));

        try {
            $reading = ServerReading::of(Snapshots::of(Component::System, ['system' => [
                'disk' => 'full',
                'cpu' => ['cores' => 'four', 'load' => [62], 'load1' => INF, 'load5' => NAN, 'load15' => null],
                'ram' => ['total' => 'lots', 'available' => []],
                'os' => 42,
                'hostname' => ['web-1'],
                'cgroup' => 'none',
            ]]));
        } finally {
            restore_error_handler();
        }

        self::assertNull($reading->memoryPercent);
        self::assertNull($reading->memoryTotal);
        self::assertNull($reading->diskPercent);
        self::assertNull($reading->diskTotal);
        self::assertNull($reading->loadPercent);
        self::assertNull($reading->load1);
        self::assertNull($reading->load5);
        self::assertNull($reading->cores);
        self::assertFalse($reading->container);
        self::assertNull($reading->os);
        self::assertNull($reading->hostname);
        self::assertNull($reading->uptime);
    }
}
