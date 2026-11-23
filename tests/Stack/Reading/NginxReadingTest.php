<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Metric\RateSource;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Reading\NginxReading;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\WebServerPayloads;
use PHPUnit\Framework\TestCase;

final class NginxReadingTest extends TestCase
{
    public function testValuesOfACompleteSnapshot(): void
    {
        $reading = NginxReading::of(self::snapshot(eol: WebServerPayloads::cycles()), self::today());

        self::assertSame('1.26.2', $reading->version);
        self::assertSame(SupportStatus::Ended, $reading->support);
        self::assertSame([12, 1, 3, 8], [$reading->active, $reading->reading, $reading->writing, $reading->waiting]);
        self::assertSame(3_072, $reading->maxConnections, '768 connections for each of the 4 workers');
        self::assertSame(0.390625, $reading->connectionsPercent);
        self::assertTrue($reading->requestsPerSecond->isPending());
        self::assertSame(2.233062, $reading->requestsPerConnection->value, '41200 requests over 18450 connections');
        self::assertSame(RateSource::SinceStart, $reading->requestsPerConnection->source);
        self::assertSame(0, $reading->refused);
        self::assertNull($reading->refusedRecently, 'no second sample');
        self::assertSame('auto (4 CPUs)', $reading->workerProcesses);
        self::assertSame(768, $reading->workerConnections);
        self::assertSame(65, $reading->keepaliveTimeout);
        self::assertSame('on', $reading->gzip);
        self::assertSame('off', $reading->serverTokens);
        self::assertSame('TLSv1.2 TLSv1.3', $reading->sslProtocols);
        self::assertSame('/etc/nginx/nginx.conf', $reading->configPath);
    }

    public function testTheTrafficAndTheRefusalsComeFromTheVariation(): void
    {
        $delta = Snapshots::delta(WebServerPayloads::nginxStatus(18_400, 18_390, 41_000), WebServerPayloads::nginxStatus(18_450, 18_437), 10);

        $reading = NginxReading::of(self::snapshot(WebServerPayloads::nginxStatus(18_450, 18_437), delta: $delta), self::today());

        self::assertSame(20.0, $reading->requestsPerSecond->value, '200 requests in 10 seconds');
        self::assertSame(4.255319, $reading->requestsPerConnection->value, '200 requests over 47 connections');
        self::assertSame(RateSource::Delta, $reading->requestsPerConnection->source);
        self::assertSame(13, $reading->refused, 'since Nginx started');
        self::assertSame(3, $reading->refusedRecently, '50 accepted, 47 handled between the two samples');
    }

    public function testNoRefusalBetweenTheSamplesIsZero(): void
    {
        $delta = Snapshots::delta(WebServerPayloads::nginxStatus(18_400, 18_387), WebServerPayloads::nginxStatus(18_450, 18_437), 10);

        $reading = NginxReading::of(self::snapshot(WebServerPayloads::nginxStatus(18_450, 18_437), delta: $delta), self::today());

        self::assertSame(13, $reading->refused);
        self::assertSame(0, $reading->refusedRecently);
    }

    public function testWithoutSettingsTheConnectionLimitIsUnknown(): void
    {
        $reading = NginxReading::of(self::snapshot(config: WebServerPayloads::nginxConfigWithoutSettings()), self::today());

        self::assertSame('1.26.2', $reading->version, 'from another command');
        self::assertNull($reading->workerProcesses);
        self::assertNull($reading->workerConnections);
        self::assertNull($reading->maxConnections);
        self::assertNull($reading->connectionsPercent);
        self::assertNull($reading->configPath);
        self::assertSame(12, $reading->active, 'the connections are still counted');
    }

    public function testAutoWorkerProcessesCountTheCpus(): void
    {
        $withoutCpus = WebServerPayloads::nginxConfig();
        $withoutCpus['cpu_count'] = null;
        $fixed = WebServerPayloads::nginxConfig();
        $fixed['config']['worker_processes'] = '2';

        $auto = NginxReading::of(self::snapshot(), self::today());
        $unknown = NginxReading::of(self::snapshot(config: $withoutCpus), self::today());
        $two = NginxReading::of(self::snapshot(config: $fixed), self::today());

        self::assertSame(3_072, $auto->maxConnections);
        self::assertSame('auto', $unknown->workerProcesses);
        self::assertNull($unknown->maxConnections);
        self::assertSame('2', $two->workerProcesses);
        self::assertSame(1_536, $two->maxConnections);
    }

    public function testAFailedStatusKeepsTheVersionAndTheSettings(): void
    {
        $reading = NginxReading::of(Snapshots::of(Component::Nginx, ['nginx.config' => WebServerPayloads::nginxConfig()], ['nginx' => ResultState::Failed]), self::today());

        self::assertSame('1.26.2', $reading->version);
        self::assertSame(3_072, $reading->maxConnections);
        self::assertNull($reading->active);
        self::assertNull($reading->connectionsPercent);
        self::assertNull($reading->refused);
    }

    public function testAnEmptySnapshotGivesNulls(): void
    {
        foreach ([new ComponentSnapshot(Component::Nginx, []), Snapshots::of(Component::Nginx, [], ['nginx' => ResultState::Failed, 'nginx.config' => ResultState::Failed])] as $snapshot) {
            $reading = NginxReading::of($snapshot, self::today());

            self::assertNull($reading->version);
            self::assertNull($reading->support);
            self::assertNull($reading->active);
            self::assertNull($reading->reading);
            self::assertNull($reading->writing);
            self::assertNull($reading->waiting);
            self::assertNull($reading->maxConnections);
            self::assertNull($reading->connectionsPercent);
            self::assertTrue($reading->requestsPerSecond->isUnavailable());
            self::assertFalse($reading->requestsPerSecond->isPending(), 'no later sample would bring it');
            self::assertTrue($reading->requestsPerConnection->isUnavailable());
            self::assertNull($reading->refused);
            self::assertNull($reading->refusedRecently);
            self::assertNull($reading->workerProcesses);
            self::assertNull($reading->workerConnections);
            self::assertNull($reading->keepaliveTimeout);
            self::assertNull($reading->gzip);
            self::assertNull($reading->serverTokens);
            self::assertNull($reading->sslProtocols);
            self::assertNull($reading->configPath);
        }
    }

    public function testDataOfUnknownShapeGivesNullsWithoutWarning(): void
    {
        set_error_handler(static fn(int $level, string $message): never => throw new \ErrorException($message, 0, $level));

        try {
            $reading = NginxReading::of(Snapshots::of(Component::Nginx, [
                'nginx' => ['status' => ['active' => 'many', 'accepts' => [], 'handled' => 'all', 'requests' => INF]],
                'nginx.config' => [
                    'version' => ['1.26'],
                    'cpu_count' => 'four',
                    'config' => ['worker_processes' => ['auto'], 'worker_connections' => 'lots', 'keepalive_timeout' => '65s', 'gzip' => [], 'config_path' => false],
                ],
                'eol.nginx' => ['cycles' => ['1.26' => 'ended']],
            ], deltas: ['nginx' => Snapshots::delta(['status' => 'x'], ['status' => ['accepts' => 'y']])]), self::today());
            $notAnArray = NginxReading::of(Snapshots::of(Component::Nginx, ['nginx' => ['status' => 'up'], 'nginx.config' => ['config' => 'none']]), self::today());
        } finally {
            restore_error_handler();
        }

        foreach ([$reading, $notAnArray] as $read) {
            self::assertNull($read->version);
            self::assertNull($read->support);
            self::assertNull($read->active);
            self::assertNull($read->maxConnections);
            self::assertNull($read->connectionsPercent);
            self::assertTrue($read->requestsPerSecond->isUnavailable());
            self::assertTrue($read->requestsPerConnection->isUnavailable());
            self::assertNull($read->refused);
            self::assertNull($read->refusedRecently);
            self::assertNull($read->workerProcesses);
            self::assertNull($read->workerConnections);
            self::assertNull($read->keepaliveTimeout);
            self::assertNull($read->gzip);
            self::assertNull($read->configPath);
        }
    }

    /**
     * @param ?array<string, mixed> $status
     * @param ?array<string, mixed> $config
     * @param ?array<string, mixed> $eol
     */
    private static function snapshot(?array $status = null, ?array $config = null, ?array $eol = null, ?Delta $delta = null): ComponentSnapshot
    {
        return Snapshots::of(
            Component::Nginx,
            ['nginx' => $status ?? WebServerPayloads::nginxStatus(), 'nginx.config' => $config ?? WebServerPayloads::nginxConfig()] + ($eol === null ? [] : ['eol.nginx' => $eol]),
            deltas: $delta === null ? [] : ['nginx' => $delta],
        );
    }

    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . Snapshots::NOW);
    }
}
