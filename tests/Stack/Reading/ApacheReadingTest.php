<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Metric\RateSource;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Reading\ApacheReading;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\WebServerPayloads;
use PHPUnit\Framework\TestCase;

final class ApacheReadingTest extends TestCase
{
    public function testValuesOfACompleteSnapshot(): void
    {
        $reading = ApacheReading::of(Snapshots::of(Component::Apache, [
            'apache' => WebServerPayloads::apache(),
            'eol.apache-http-server' => WebServerPayloads::cycles(),
        ]), self::today());

        self::assertSame('2.4.62', $reading->version);
        self::assertSame(SupportStatus::Active, $reading->support);
        self::assertSame('event', $reading->mpm);
        self::assertSame(1_040_619, $reading->uptime);
        self::assertSame(3, $reading->busyWorkers);
        self::assertSame(47, $reading->idleWorkers);
        self::assertSame(3, $reading->usedSlots, 'neither waiting for a connection nor open');
        self::assertSame(400, $reading->slots);
        self::assertSame(0.75, $reading->slotsPercent);
        self::assertSame([
            'Waiting for connection' => 47,
            'Reading request' => 1,
            'Sending reply' => 1,
            'Keepalive' => 1,
            'Open slot' => 350,
        ], $reading->scoreboard, 'in the order of the mod_status legend, the empty states left out');

        self::assertTrue($reading->requestsPerSecond->isPending());
        self::assertTrue($reading->bytesPerSecond->isPending());
        self::assertSame(25_600.0, $reading->bytesPerRequest->value);
        self::assertSame(RateSource::SinceStart, $reading->bytesPerRequest->source);
        self::assertSame(0.04, $reading->secondsPerRequest->value, '2080 seconds over 52000 requests');
        self::assertSame(RateSource::SinceStart, $reading->secondsPerRequest->source);
        self::assertSame('since Apache started', $reading->bytesPerRequest->hint());
        self::assertSame('since Apache started', $reading->secondsPerRequest->hint());
    }

    public function testTheTrafficComesFromTheVariation(): void
    {
        $delta = Snapshots::delta(WebServerPayloads::apache(51_900, 1_330_176_000, 2_077.5), WebServerPayloads::apache(), 10);

        $reading = ApacheReading::of(Snapshots::of(Component::Apache, ['apache' => WebServerPayloads::apache()], deltas: ['apache' => $delta]), self::today());

        self::assertSame(10.0, $reading->requestsPerSecond->value, '100 requests in 10 seconds');
        self::assertSame(102_400.0, $reading->bytesPerSecond->value, '1000 KB in 10 seconds');
        self::assertSame(10_240.0, $reading->bytesPerRequest->value);
        self::assertSame(RateSource::Delta, $reading->bytesPerRequest->source);
        self::assertSame(0.025, $reading->secondsPerRequest->value, '2.5 seconds over 100 requests');
        self::assertSame(RateSource::Delta, $reading->secondsPerRequest->source);
    }

    public function testWithoutTotalDurationTheMeanSinceStartIsUsed(): void
    {
        $reading = ApacheReading::of(Snapshots::of(Component::Apache, ['apache' => ['total_duration' => null, 'duration_per_request' => 0.734] + WebServerPayloads::apache()]), self::today());

        self::assertSame(0.000734, $reading->secondsPerRequest->value, 'the page gives it in milliseconds');
        self::assertSame(RateSource::SinceStart, $reading->secondsPerRequest->source);
        self::assertSame('since Apache started', $reading->secondsPerRequest->hint());
    }

    public function testAHiddenVersionHasNoSupportStatus(): void
    {
        $reading = ApacheReading::of(Snapshots::of(Component::Apache, [
            'apache' => ['server_version' => 'Apache'] + WebServerPayloads::apache(),
            'eol.apache-http-server' => WebServerPayloads::cycles(),
        ]), self::today());

        self::assertSame('Apache', $reading->version, 'ServerTokens Prod leaves the server name only');
        self::assertNull($reading->support);
    }

    public function testTheVersionIsReadAmongTheModules(): void
    {
        $reading = ApacheReading::of(Snapshots::of(Component::Apache, ['apache' => ['server_version' => 'Apache/2.4.71 (Debian) PHP/8.4.28'] + WebServerPayloads::apache()]), self::today());

        self::assertSame('2.4.71', $reading->version);
    }

    public function testAnEmptySnapshotGivesNulls(): void
    {
        foreach ([new ComponentSnapshot(Component::Apache, []), Snapshots::of(Component::Apache, [], ['apache' => ResultState::Failed])] as $snapshot) {
            $reading = ApacheReading::of($snapshot, self::today());

            self::assertNull($reading->version);
            self::assertNull($reading->support);
            self::assertNull($reading->mpm);
            self::assertNull($reading->uptime);
            self::assertNull($reading->busyWorkers);
            self::assertNull($reading->idleWorkers);
            self::assertNull($reading->usedSlots);
            self::assertNull($reading->slots);
            self::assertNull($reading->slotsPercent);
            self::assertSame([], $reading->scoreboard);
            self::assertTrue($reading->requestsPerSecond->isUnavailable());
            self::assertFalse($reading->requestsPerSecond->isPending(), 'no later sample would bring it');
            self::assertTrue($reading->bytesPerSecond->isUnavailable());
            self::assertTrue($reading->bytesPerRequest->isUnavailable());
            self::assertTrue($reading->secondsPerRequest->isUnavailable());
        }
    }

    public function testDataOfUnknownShapeGivesNullsWithoutWarning(): void
    {
        set_error_handler(static fn(int $level, string $message): never => throw new \ErrorException($message, 0, $level));

        try {
            $reading = ApacheReading::of(Snapshots::of(Component::Apache, [
                'apache' => [
                    'server_version' => ['Apache/2.4.62'],
                    'server_mpm' => false,
                    'uptime' => 'long',
                    'workers' => 'all',
                    'scoreboard' => ['_' => 'many', 'W' => [1], 3 => -2],
                    'total_accesses' => [],
                    'total_bytes' => 'lots',
                    'total_duration' => INF,
                    'duration_per_request' => 'slow',
                ],
                'eol.apache-http-server' => ['cycles' => 'soon'],
            ], deltas: ['apache' => Snapshots::delta(['total_accesses' => 'x'], ['total_accesses' => ['y']])]), self::today());
        } finally {
            restore_error_handler();
        }

        self::assertNull($reading->version);
        self::assertNull($reading->support);
        self::assertNull($reading->mpm);
        self::assertNull($reading->uptime);
        self::assertNull($reading->busyWorkers);
        self::assertNull($reading->usedSlots);
        self::assertNull($reading->slots);
        self::assertNull($reading->slotsPercent);
        self::assertSame([], $reading->scoreboard);
        self::assertTrue($reading->requestsPerSecond->isUnavailable());
        self::assertTrue($reading->bytesPerRequest->isUnavailable());
        self::assertTrue($reading->secondsPerRequest->isUnavailable());
    }

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . Snapshots::NOW);
    }
}
