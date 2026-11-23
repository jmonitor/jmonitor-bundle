<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Metric\RateSource;
use Jmonitor\JmonitorBundle\Stack\Reading\FrankenPhpReading;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\WebServerPayloads;
use PHPUnit\Framework\TestCase;

final class FrankenPhpReadingTest extends TestCase
{
    public function testValuesOfACompleteSnapshotInWorkerMode(): void
    {
        $reading = FrankenPhpReading::of(self::snapshot(WebServerPayloads::frankenphpWorker(), self::delta()));

        self::assertSame('1.13.0', $reading->version);
        self::assertSame('worker', $reading->mode);
        self::assertSame(13, $reading->busyThreads);
        self::assertSame(16, $reading->totalThreads);
        self::assertSame(0, $reading->queue);
        self::assertCount(2, $reading->workers);

        $index = $reading->workers[0];
        self::assertSame('/app/public/index.php', $index->name);
        self::assertSame('index.php', $index->file);
        self::assertSame(8, $index->total);
        self::assertSame(2, $index->busy);
        self::assertSame(8, $index->ready);
        self::assertSame(0, $index->queue);
        self::assertSame(1, $index->crashes);
        self::assertSame(0, $index->crashesRecently);
        self::assertSame(3, $index->restarts);
        self::assertSame(1, $index->restartsRecently, '2 then 3 restarts');
        self::assertSame(10.0, $index->requestsPerSecond->value, '100 requests in 10 seconds');
        self::assertSame(0.025, $index->secondsPerRequest->value, '2.5 seconds for 100 requests');
        self::assertSame(RateSource::Delta, $index->secondsPerRequest->source);

        $api = $reading->workers[1];
        self::assertSame('api', $api->name);
        self::assertSame('api', $api->file, 'a name given in the Caddyfile stays as it is');
        self::assertSame(2, $api->queue);
        self::assertNull($api->crashesRecently, 'no previous sample for this script');
        self::assertNull($api->restartsRecently);
        self::assertTrue($api->requestsPerSecond->isPending());
        self::assertSame(0.02, $api->secondsPerRequest->value, '40 seconds for 2000 requests since the start');
        self::assertSame(RateSource::SinceStart, $api->secondsPerRequest->source);
        self::assertSame('since FrankenPHP started', $api->secondsPerRequest->hint());
    }

    public function testInWorkerModeOnlyTheRegularThreadsAreGauged(): void
    {
        $reading = FrankenPhpReading::of(self::snapshot(WebServerPayloads::frankenphpWorker()));

        self::assertSame(1, $reading->busyRegularThreads, 'the 12 threads of the workers always count as busy');
        self::assertSame(4, $reading->regularThreads);
        self::assertSame(25.0, $reading->threadsPercent);
        self::assertSame('12 threads run the workers', $reading->threadsNote);
    }

    public function testASingleWorkerThreadIsTold(): void
    {
        $payload = WebServerPayloads::frankenphpWorker();
        $payload['workers'] = [[...$payload['workers'][0], 'total_workers' => 1, 'busy_workers' => 1, 'ready_workers' => 1]];

        $reading = FrankenPhpReading::of(self::snapshot($payload));

        self::assertSame('1 thread runs the workers', $reading->threadsNote);
    }

    public function testWorkersStillStartingLeaveNoRegularThreadBusy(): void
    {
        $payload = WebServerPayloads::frankenphpWorker();
        $payload['busy_threads'] = 10;

        $reading = FrankenPhpReading::of(self::snapshot($payload));

        self::assertSame(0, $reading->busyRegularThreads);
        self::assertSame(0.0, $reading->threadsPercent);
    }

    public function testWorkersTakingEveryThreadLeaveNoPercentage(): void
    {
        $payload = WebServerPayloads::frankenphpWorker();
        $payload['total_threads'] = 12;
        $payload['busy_threads'] = 12;

        $reading = FrankenPhpReading::of(self::snapshot($payload));

        self::assertSame(0, $reading->regularThreads);
        self::assertNull($reading->threadsPercent);
        self::assertSame('12 threads run the workers', $reading->threadsNote);
    }

    public function testClassicModeHasNoWorker(): void
    {
        $reading = FrankenPhpReading::of(self::snapshot(WebServerPayloads::frankenphpClassic()));

        self::assertSame('classic', $reading->mode);
        self::assertSame([], $reading->workers);
        self::assertSame(3, $reading->busyRegularThreads);
        self::assertSame(16, $reading->regularThreads);
        self::assertSame(18.75, $reading->threadsPercent);
        self::assertNull($reading->threadsNote);
    }

    public function testAWorkerThatChangedRankHasNoRate(): void
    {
        $previous = WebServerPayloads::frankenphpWorker();
        $previous['workers'] = array_reverse($previous['workers']);
        $delta = new Delta(Delta::flatten($previous), Delta::flatten(WebServerPayloads::frankenphpWorker()), 10);

        $reading = FrankenPhpReading::of(self::snapshot(WebServerPayloads::frankenphpWorker(), $delta));

        foreach ($reading->workers as $worker) {
            self::assertTrue($worker->requestsPerSecond->isUnavailable(), $worker->name);
            self::assertSame(RateSource::SinceStart, $worker->secondsPerRequest->source);
            self::assertNull($worker->crashesRecently);
            self::assertNull($worker->restartsRecently);
        }
    }

    public function testMetricsNotYetExportedAreUnknown(): void
    {
        $reading = FrankenPhpReading::of(self::snapshot(['workers' => [['name' => '/srv/probe/worker.php', 'total_workers' => 2, 'busy_workers' => 0, 'ready_workers' => 2]]] + WebServerPayloads::frankenphpWorker()));

        $worker = $reading->workers[0];
        self::assertSame('worker.php', $worker->file);
        self::assertNull($worker->crashes);
        self::assertNull($worker->restarts);
        self::assertNull($worker->queue);
        self::assertTrue($worker->requestsPerSecond->isUnavailable());
        self::assertFalse($worker->requestsPerSecond->isPending(), 'no counter to compare later');
    }

    public function testAnEmptySnapshotGivesNulls(): void
    {
        foreach ([new ComponentSnapshot(Component::FrankenPhp, []), Snapshots::of(Component::FrankenPhp, [], ['frankenphp' => ResultState::Failed, 'frankenphp.version' => ResultState::Failed])] as $snapshot) {
            $reading = FrankenPhpReading::of($snapshot);

            self::assertNull($reading->version);
            self::assertNull($reading->mode);
            self::assertNull($reading->busyThreads);
            self::assertNull($reading->totalThreads);
            self::assertNull($reading->busyRegularThreads);
            self::assertNull($reading->regularThreads);
            self::assertNull($reading->threadsPercent);
            self::assertNull($reading->threadsNote);
            self::assertNull($reading->queue);
            self::assertSame([], $reading->workers);
        }
    }

    public function testDataOfUnknownShapeGivesNullsWithoutWarning(): void
    {
        set_error_handler(static fn(int $level, string $message): never => throw new \ErrorException($message, 0, $level));

        try {
            $reading = FrankenPhpReading::of(Snapshots::of(Component::FrankenPhp, [
                'frankenphp' => [
                    'mode' => 'turbo',
                    'busy_threads' => 'all',
                    'total_threads' => [16],
                    'queue_depth' => NAN,
                    'workers' => ['index', ['name' => ['a'], 'total_workers' => 'eight', 'worker_crashes' => 'none', 'worker_request_count' => []]],
                ],
                'frankenphp.version' => ['version' => ['1.13.0']],
            ], deltas: ['frankenphp' => Snapshots::delta(['workers' => 'x'], ['workers' => [['worker_crashes' => 'y']]])]));
            $notAList = FrankenPhpReading::of(Snapshots::of(Component::FrankenPhp, ['frankenphp' => ['mode' => 'worker', 'workers' => 'index.php']]));
        } finally {
            restore_error_handler();
        }

        self::assertNull($reading->version);
        self::assertNull($reading->mode);
        self::assertNull($reading->busyThreads);
        self::assertNull($reading->totalThreads);
        self::assertNull($reading->threadsPercent);
        self::assertNull($reading->queue);
        self::assertCount(1, $reading->workers, 'only an array is a worker');
        self::assertSame('', $reading->workers[0]->name);
        self::assertNull($reading->workers[0]->total);
        self::assertNull($reading->workers[0]->crashes);
        self::assertNull($reading->workers[0]->crashesRecently);
        self::assertTrue($reading->workers[0]->requestsPerSecond->isUnavailable());
        self::assertSame([], $notAList->workers);
    }

    private static function delta(): Delta
    {
        $previous = WebServerPayloads::frankenphpWorker();
        $previous['workers'][0]['worker_request_count'] = 9_900;
        $previous['workers'][0]['worker_request_time'] = 123.0;
        $previous['workers'][0]['worker_restarts'] = 2;
        unset($previous['workers'][1]);

        return new Delta(Delta::flatten($previous), Delta::flatten(WebServerPayloads::frankenphpWorker()), 10);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function snapshot(array $data, ?Delta $delta = null): ComponentSnapshot
    {
        return Snapshots::of(
            Component::FrankenPhp,
            ['frankenphp' => $data, 'frankenphp.version' => ['version' => '1.13.0']],
            deltas: $delta === null ? [] : ['frankenphp' => $delta],
        );
    }
}
