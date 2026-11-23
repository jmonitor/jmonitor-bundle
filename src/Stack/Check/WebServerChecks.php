<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Check;

use Jmonitor\JmonitorBundle\Stack\Metric\Figures;
use Jmonitor\JmonitorBundle\Stack\Reading\ApacheReading;
use Jmonitor\JmonitorBundle\Stack\Reading\FrankenPhpReading;
use Jmonitor\JmonitorBundle\Stack\Reading\FrankenPhpWorker;
use Jmonitor\JmonitorBundle\Stack\Reading\NginxReading;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final class WebServerChecks
{
    /**
     * @param \Closure(string, Severity, string, string, string...): Finding $raise
     *
     * @return list<Finding>
     */
    public static function apache(ApacheReading $apache, \Closure $raise): array
    {
        $findings = [];

        if ($apache->slotsPercent !== null && $apache->slotsPercent >= 90) {
            $findings[] = $raise(
                'apache.workers',
                $apache->slotsPercent >= 100 ? Severity::Critical : Severity::Warning,
                sprintf('Apache workers take %s of the slots (%d of %d)', Figures::percent($apache->slotsPercent), $apache->usedSlots, $apache->slots),
                'The slots are those of the scoreboard of mod_status, which may hold more than MaxRequestWorkers. Once every slot is taken, new connections wait for a worker to be free. Raise MaxRequestWorkers, and ServerLimit with it, if the memory allows it, or find the slow requests that hold the workers.',
                'apache',
            );
        }

        if ($apache->idleWorkers === 0 && $apache->busyWorkers !== null && $apache->busyWorkers >= 1) {
            $findings[] = $raise(
                'apache.idle_workers',
                Severity::Info,
                sprintf('Apache has no idle worker, %s', $apache->busyWorkers === 1 ? '1 is busy' : $apache->busyWorkers . ' are busy'),
                'New requests wait for a worker to be free, or for Apache to start more, up to MaxRequestWorkers. If it lasts, raise MaxRequestWorkers as far as the memory allows it, or find the slow requests that hold the workers.',
                'apache',
            );
        }

        return $findings;
    }

    /**
     * @param \Closure(string, Severity, string, string, string...): Finding $raise
     *
     * @return list<Finding>
     */
    public static function nginx(NginxReading $nginx, ComponentSnapshot $snapshot, \Closure $raise): array
    {
        $findings = [];

        if ($nginx->connectionsPercent !== null && $nginx->connectionsPercent >= 85) {
            $findings[] = $raise(
                'nginx.connections',
                $nginx->connectionsPercent >= 95 ? Severity::Critical : Severity::Warning,
                sprintf('Nginx holds %s of its connections (%d of %d)', Figures::percent($nginx->connectionsPercent), $nginx->active, $nginx->maxConnections),
                'Past worker_processes × worker_connections, Nginx refuses new connections. The connections to the upstream servers, a proxied backend or FastCGI, count too, and so do the idle keep-alive ones, which a shorter keepalive_timeout frees sooner. Raise worker_connections, and the open files limit with it.',
                'nginx',
                'nginx.config',
            );
        }

        $elapsed = $snapshot->delta('nginx')?->elapsed;

        if ($nginx->refusedRecently !== null && $nginx->refusedRecently >= 1 && $elapsed !== null) {
            $findings[] = $raise(
                'nginx.refused',
                Severity::Warning,
                sprintf('Nginx refused %s %s', self::connections($nginx->refusedRecently), Figures::lastSeconds($elapsed)),
                'Nginx accepted connections it could not handle, which happens when it reaches worker_connections or the open files limit. Raise them, as far as the memory allows it.',
                'nginx',
            );
        } elseif ($nginx->refused !== null && $nginx->refused >= 1) {
            $findings[] = $raise(
                'nginx.refused',
                Severity::Info,
                sprintf('Nginx refused %s since it started', self::connections($nginx->refused)),
                'At some point, Nginx accepted connections it could not handle, which happens when it reaches worker_connections or the open files limit. If it happens again, raise them.',
                'nginx',
            );
        }

        return $findings;
    }

    /**
     * @param \Closure(string, Severity, string, string, string...): Finding $raise
     *
     * @return list<Finding>
     */
    public static function frankenPhp(FrankenPhpReading $frankenPhp, ComponentSnapshot $snapshot, \Closure $raise): array
    {
        $findings = [];

        if ($frankenPhp->queue !== null && $frankenPhp->queue >= 1) {
            $findings[] = $raise(
                'frankenphp.queue',
                Severity::Warning,
                sprintf('%s for a FrankenPHP thread', self::waiting($frankenPhp->queue)),
                'Every PHP thread is busy, so requests wait before PHP even starts. Raise num_threads, or max_threads, in the Caddyfile if the memory allows it, or find the slow requests that hold the threads.',
                'frankenphp',
            );
        }

        $queued = array_filter($frankenPhp->workers, static fn(FrankenPhpWorker $worker): bool => $worker->queue !== null && $worker->queue >= 1);

        if ($queued !== []) {
            $findings[] = $raise(
                'frankenphp.worker_queue',
                Severity::Warning,
                sprintf('%s for a worker of %s', self::waiting(array_sum(array_map(static fn(FrankenPhpWorker $worker): int => (int) $worker->queue, $queued))), self::files($queued)),
                'Every worker of these scripts is busy, so requests wait for one to be free. Raise num in the worker block of the Caddyfile if the memory allows it, or find the slow requests that hold the workers.',
                'frankenphp',
            );
        }

        $elapsed = $snapshot->delta('frankenphp')?->elapsed;
        $crashedRecently = array_filter($frankenPhp->workers, static fn(FrankenPhpWorker $worker): bool => $worker->crashesRecently !== null && $worker->crashesRecently >= 1);
        $crashed = array_filter($frankenPhp->workers, static fn(FrankenPhpWorker $worker): bool => $worker->crashes !== null && $worker->crashes >= 1);

        if ($crashedRecently !== [] && $elapsed !== null) {
            $findings[] = $raise(
                'frankenphp.worker_crashes',
                Severity::Warning,
                sprintf(
                    '%s %s',
                    self::crashes($crashedRecently, array_sum(array_map(static fn(FrankenPhpWorker $worker): int => (int) $worker->crashesRecently, $crashedRecently))),
                    Figures::lastSeconds($elapsed),
                ),
                'A worker that crashes is started again, and the request it was handling fails. Look in the logs for the fatal error or the exit of the script.',
                'frankenphp',
            );
        } elseif ($crashed !== []) {
            $findings[] = $raise(
                'frankenphp.worker_crashes',
                Severity::Info,
                sprintf(
                    '%s since FrankenPHP started',
                    self::crashes($crashed, array_sum(array_map(static fn(FrankenPhpWorker $worker): int => (int) $worker->crashes, $crashed))),
                ),
                'At some point, a worker crashed and was started again. If it happens again, look in the logs for the fatal error or the exit of the script.',
                'frankenphp',
            );
        }

        return $findings;
    }

    private static function connections(int $count): string
    {
        return $count === 1 ? '1 connection' : $count . ' connections';
    }

    private static function waiting(int $count): string
    {
        return $count === 1 ? '1 request is waiting' : $count . ' requests are waiting';
    }

    /**
     * @param array<FrankenPhpWorker> $workers
     */
    private static function crashes(array $workers, int $count): string
    {
        return sprintf('%s of %s crashed %s', $count === 1 ? 'A worker' : 'Workers', self::files($workers), Figures::times($count));
    }

    /**
     * @param array<FrankenPhpWorker> $workers
     */
    private static function files(array $workers): string
    {
        return implode(', ', array_map(static fn(FrankenPhpWorker $worker): string => $worker->file, $workers));
    }
}
