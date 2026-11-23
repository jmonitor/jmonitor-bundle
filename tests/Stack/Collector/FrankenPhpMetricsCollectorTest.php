<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\FrankenPhpMetricsCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\CommandTrap;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeUrlFetcher;
use PHPUnit\Framework\TestCase;

final class FrankenPhpMetricsCollectorTest extends TestCase
{
    private const ENDPOINT = 'http://localhost:2019/metrics';
    private const MESSAGE = 'Cannot read the FrankenPHP metrics at http://localhost:2019/metrics. Enable them with the "metrics" global option of the Caddyfile, and check "jmonitor.stack.frankenphp.endpoint".';

    public function testItReadsTheWorkerMode(): void
    {
        $fetcher = new FakeUrlFetcher(self::export('worker'));
        $collector = new FrankenPhpMetricsCollector(self::ENDPOINT, $fetcher);

        $payload = $collector->collect();

        self::assertSame(self::ENDPOINT, $fetcher->url);
        self::assertSame('worker', $payload['mode']);
        self::assertSame(13, $payload['busy_threads']);
        self::assertSame(16, $payload['total_threads']);
        self::assertSame(0, $payload['queue_depth']);
        self::assertSame(['/app/public/index.php', 'api'], array_column($payload['workers'], 'name'));
        self::assertSame([
            'name' => '/app/public/index.php',
            'total_workers' => 8,
            'busy_workers' => 2,
            'worker_request_time' => 125.5,
            'worker_request_count' => 10000,
            'ready_workers' => 8,
            'worker_crashes' => 1,
            'worker_restarts' => 3,
            'worker_queue_depth' => 0,
        ], $payload['workers'][0]);
        self::assertSame('frankenphp', $collector->getName());
        self::assertSame(1, $collector->getVersion());
    }

    public function testItReadsTheClassicMode(): void
    {
        $payload = (new FrankenPhpMetricsCollector(self::ENDPOINT, new FakeUrlFetcher(self::export('classic'))))->collect();

        self::assertSame(['mode' => 'classic', 'busy_threads' => 3, 'total_threads' => 16, 'queue_depth' => 0, 'workers' => []], $payload);
    }

    public function testItGivesNoVersion(): void
    {
        $payload = (new FrankenPhpMetricsCollector(self::ENDPOINT, new FakeUrlFetcher(self::export('worker'))))->collect();

        self::assertArrayNotHasKey('version', $payload);
    }

    public function testCaddyMetricsWithoutFrankenPhpAreAMissingPrerequisite(): void
    {
        $e = Failures::thrownBy(static fn() => (new FrankenPhpMetricsCollector(self::ENDPOINT, new FakeUrlFetcher(self::export('caddy-only'))))->collect());

        self::assertContains(MissingPrerequisiteException::class, Failures::causes($e));
        self::assertSame(self::MESSAGE, $e->getMessage());
    }

    public function testAnUnreachableEndpointStaysUnreachable(): void
    {
        $reader = new UnreachableServerException('Nothing answered at http://localhost:2019/metrics: Connection refused');

        $e = Failures::thrownBy(static fn() => (new FrankenPhpMetricsCollector(self::ENDPOINT, new FakeUrlFetcher(null, $reader)))->collect());

        self::assertContains(UnreachableServerException::class, Failures::causes($e));
        self::assertNotContains(MissingPrerequisiteException::class, Failures::causes($e));
        self::assertSame(self::MESSAGE, $e->getMessage());
        self::assertSame($reader, $e->getPrevious());
    }

    public function testAMissingPrerequisiteOfTheReaderStaysOne(): void
    {
        $reader = new MissingPrerequisiteException('http://localhost:2019/metrics answered HTTP 404: it is not a status page.');

        $e = Failures::thrownBy(static fn() => (new FrankenPhpMetricsCollector(self::ENDPOINT, new FakeUrlFetcher(null, $reader)))->collect());

        self::assertContains(MissingPrerequisiteException::class, Failures::causes($e));
        self::assertSame($reader, $e->getPrevious());
    }

    public function testItRunsNoCommand(): void
    {
        $trap = new CommandTrap('frankenphp');

        try {
            (new FrankenPhpMetricsCollector(self::ENDPOINT, new FakeUrlFetcher(self::export('classic'))))->collect();
            $started = $trap->started();
        } finally {
            $trap->release();
        }

        self::assertFalse($started);
    }

    public function testTheModeIsReadAgainAtEachCollect(): void
    {
        $fetcher = new FakeUrlFetcher(self::export('caddy-only'));
        $collector = new FrankenPhpMetricsCollector(self::ENDPOINT, $fetcher);
        Failures::thrownBy(static fn() => $collector->collect());

        $fetcher->content = self::export('worker');

        self::assertSame('worker', $collector->collect()['mode']);
    }

    private static function export(string $name): string
    {
        return (string) file_get_contents(\dirname(__DIR__) . '/Fixtures/FrankenPhp/metrics-' . $name . '.prom');
    }
}
