<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Metric\Rate;
use Jmonitor\JmonitorBundle\Stack\Metric\Ratios;
use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;

/**
 * One worker script. Its variations come from the previous sample only when that sample had the same script at
 * the same rank, so that a script added or removed in between never mixes the counters of two scripts.
 */
final readonly class FrankenPhpWorker
{
    /**
     * @param string $name the worker name of the Caddyfile, or the path of its script
     * @param string $file the file name of a worker named by its path, else its name
     */
    private function __construct(
        public string $name,
        public string $file,
        public ?int $total,
        public ?int $busy,
        public ?int $ready,
        public ?int $queue,
        public ?int $crashes,
        public ?int $crashesRecently,
        public ?int $restarts,
        public ?int $restartsRecently,
        public Rate $requestsPerSecond,
        public Rate $secondsPerRequest,
    ) {}

    /**
     * @param array<mixed> $worker
     * @param int          $rank   its rank among the workers of the sample
     */
    public static function of(array $worker, int $rank, ?Delta $delta): self
    {
        $name = Values::string($worker['name'] ?? null) ?? '';
        $key = 'workers.' . $rank . '.';
        $delta = $delta !== null && ($delta->toArray()['previous'][$key . 'name'] ?? null) === $name ? $delta : null;
        $requests = Values::int($worker['worker_request_count'] ?? null);

        return new self(
            $name,
            str_contains($name, '/') ? basename($name) : $name,
            Values::int($worker['total_workers'] ?? null),
            Values::int($worker['busy_workers'] ?? null),
            Values::int($worker['ready_workers'] ?? null),
            Values::int($worker['worker_queue_depth'] ?? null),
            Values::int($worker['worker_crashes'] ?? null),
            Values::int($delta?->getValue($key . 'worker_crashes')),
            Values::int($worker['worker_restarts'] ?? null),
            Values::int($delta?->getValue($key . 'worker_restarts')),
            Ratios::perSecond($delta, $key . 'worker_request_count', 'req/s', $requests),
            Ratios::average($delta, $key . 'worker_request_time', $key . 'worker_request_count', Values::float($worker['worker_request_time'] ?? null), $requests, 's'),
        );
    }
}
