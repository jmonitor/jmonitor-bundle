<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final readonly class FrankenPhpReading
{
    /**
     * A running worker always holds its thread: in worker mode, the regular threads are those left to the other
     * requests, and $threadsPercent covers them only.
     *
     * @param ?string                $mode    "worker" or "classic"
     * @param ?int                   $queue   requests waiting for a regular thread
     * @param list<FrankenPhpWorker> $workers
     */
    private function __construct(
        public ?string $version,
        public ?string $mode,
        public ?int $busyThreads,
        public ?int $totalThreads,
        public ?int $busyRegularThreads,
        public ?int $regularThreads,
        public ?float $threadsPercent,
        public ?string $threadsNote,
        public ?int $queue,
        public array $workers,
    ) {}

    public static function of(ComponentSnapshot $snapshot): self
    {
        $data = $snapshot->data('frankenphp');
        $delta = $snapshot->delta('frankenphp');
        $mode = Values::string($data['mode'] ?? null);
        $mode = in_array($mode, ['worker', 'classic'], true) ? $mode : null;
        $busy = Values::int($data['busy_threads'] ?? null);
        $total = Values::int($data['total_threads'] ?? null);

        $workers = [];

        foreach (is_array($data['workers'] ?? null) ? array_values($data['workers']) : [] as $rank => $worker) {
            if (is_array($worker)) {
                $workers[] = FrankenPhpWorker::of($worker, $rank, $delta);
            }
        }

        $workerThreads = $mode === 'worker' ? array_sum(array_map(static fn(FrankenPhpWorker $worker): int => max(0, $worker->total ?? 0), $workers)) : 0;
        $regular = $total === null ? null : Values::int(max(0, $total - $workerThreads));
        $busyRegular = $busy === null ? null : Values::int(max(0, $busy - $workerThreads));

        return new self(
            Values::string($snapshot->data('frankenphp.version')['version'] ?? null),
            $mode,
            $busy,
            $total,
            $busyRegular,
            $regular,
            Values::percent($busyRegular, $regular),
            $workerThreads > 0 ? sprintf('%d threads run the workers', $workerThreads) : null,
            Values::int($data['queue_depth'] ?? null),
            $workers,
        );
    }
}
