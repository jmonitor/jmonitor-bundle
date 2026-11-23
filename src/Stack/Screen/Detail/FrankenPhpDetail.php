<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Fact;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauge;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauges;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Reading\FrankenPhpReading;
use Jmonitor\JmonitorBundle\Stack\Reading\FrankenPhpWorker;

final class FrankenPhpDetail implements DetailInterface
{
    public function sections(InstanceState $instance, array $findings, int $now, bool $reading = true): array
    {
        $snapshot = Sections::snapshot($instance);

        if ($snapshot === null) {
            return [];
        }

        return Sections::of($snapshot, 'frankenphp', Block::GAUGES, $now, $reading, static function () use ($snapshot, $findings): array {
            $frankenPhp = FrankenPhpReading::of($snapshot);
            $blocks = [
                Block::gauges(new Gauges('Threads', [
                    new Gauge('Busy', $frankenPhp->threadsPercent, Sections::percent($frankenPhp->threadsPercent), hint: $frankenPhp->threadsNote),
                ])),
                Block::facts(new Facts('Server', [
                    new Fact('Version', Sections::value($frankenPhp->version)),
                    new Fact('Mode', Sections::value($frankenPhp->mode === null ? null : ucfirst($frankenPhp->mode))),
                    new Fact('Threads', Sections::value(self::threads($frankenPhp))),
                    new Fact('Queue', Sections::value($frankenPhp->queue === null ? null : (string) $frankenPhp->queue), Sections::mark($findings, 'frankenphp.queue')),
                ])),
            ];

            if ($frankenPhp->mode === 'worker') {
                $blocks[] = Block::table(self::workers($frankenPhp->workers));
            }

            return $blocks;
        });
    }

    public static function threads(FrankenPhpReading $frankenPhp): ?string
    {
        [$busy, $total] = $frankenPhp->mode === 'worker'
            ? [$frankenPhp->busyRegularThreads, $frankenPhp->regularThreads]
            : [$frankenPhp->busyThreads, $frankenPhp->totalThreads];

        return $busy === null || $total === null ? null : Sections::fraction($busy, $total);
    }

    /**
     * @param list<FrankenPhpWorker> $workers
     */
    private static function workers(array $workers): Table
    {
        return new Table(
            'workers',
            'Workers',
            [
                new Column('worker', 'Worker', false),
                new Column('busy', 'Busy'),
                new Column('ready', 'Ready'),
                new Column('requests', 'Requests'),
                new Column('time', 'Avg time'),
                new Column('queue', 'Queue'),
                new Column('crashes', 'Crashes'),
                new Column('restarts', 'Restarts'),
            ],
            array_map(static fn(FrankenPhpWorker $worker): Row => new Row($worker->name, [
                'worker' => new Cell($worker->name),
                'busy' => new Cell(self::count($worker->busy)),
                'ready' => new Cell(self::count($worker->ready)),
                'requests' => new Cell(Sections::value(Sections::rate($worker->requestsPerSecond))),
                'time' => new Cell(Sections::value(Sections::milliseconds($worker->secondsPerRequest))),
                'queue' => new Cell(self::count($worker->queue)),
                'crashes' => new Cell(self::count($worker->crashes)),
                'restarts' => new Cell(self::count($worker->restarts)),
            ], ['data-jm-worker' => $worker->name]), $workers),
            noun: 'worker',
        );
    }

    private static function count(?int $count): string
    {
        return Sections::value($count === null ? null : (string) $count);
    }
}
