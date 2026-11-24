<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\Fact;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauge;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauges;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Check\Severity;
use Jmonitor\JmonitorBundle\Stack\Metric\Figures;
use Jmonitor\JmonitorBundle\Stack\Metric\Rate;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Reading\PhpReading;
use Jmonitor\JmonitorBundle\Stack\Screen\Marks;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final class PhpDetail implements DetailInterface
{
    public function sections(InstanceState $instance, array $findings, int $now, bool $reading = true): array
    {
        $snapshot = Sections::snapshot($instance);

        if ($snapshot === null) {
            return [];
        }

        return Sections::of($snapshot, 'php', Block::FACTS, $now, $reading, static function () use ($snapshot, $findings, $now): array {
            $php = PhpReading::of($snapshot, new \DateTimeImmutable('@' . $now));
            $blocks = [
                Block::facts(new Facts('Runtime', self::runtime($php, $findings))),
                self::opcache($php, $findings, $snapshot),
            ];

            if ($php->apcuEnabled) {
                $blocks[] = Block::gauges(new Gauges('APCu', [
                    new Gauge('Memory', $php->apcuMemoryPercent, Sections::percent($php->apcuMemoryPercent), Sections::mark($findings, 'php.apcu_memory'), Sections::share($php->apcuUsed, $php->apcuTotal)),
                    self::hitRate($php->apcuHitRate, null, $snapshot),
                ]));
            }

            if ($php->fpm) {
                $blocks[] = Block::facts(new Facts('PHP-FPM', self::fpm($php, $findings, $snapshot)));
            }

            return $blocks;
        });
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Fact>
     */
    private static function runtime(PhpReading $php, array $findings): array
    {
        return [
            new Fact('Version', Sections::value($php->version), $php->support === null ? null : Marks::support($php->support)),
            new Fact('SAPI', Sections::value($php->sapi)),
            new Fact('memory_limit', match (true) {
                $php->memoryUnlimited => 'unlimited',
                $php->memoryLimit === null => Sections::MISSING,
                default => Figures::bytes($php->memoryLimit),
            }),
            new Fact('max_execution_time', match ($php->maxExecutionTime) {
                null => Sections::MISSING,
                0 => 'unlimited',
                default => Sections::unbroken($php->maxExecutionTime . ' s'),
            }),
            new Fact('display_errors', self::onOff($php->displayErrors), Sections::mark($findings, 'php.display_errors')),
            new Fact(
                'realpath_cache_size',
                $php->realpathCacheSize === null ? Sections::MISSING : Figures::bytes($php->realpathCacheSize),
                Sections::mark($findings, 'php.realpath_cache_size'),
            ),
        ];
    }

    /**
     * @param list<Finding> $findings
     */
    private static function opcache(PhpReading $php, array $findings, ComponentSnapshot $snapshot): Block
    {
        if (!$php->opcacheEnabled) {
            return Block::facts(new Facts('OPcache', [new Fact('Status', match (true) {
                $php->opcacheAbsent => 'not available',
                $php->opcacheRunning === false => 'off',
                default => Sections::MISSING,
            }, Sections::mark($findings, 'php.opcache_disabled'))]));
        }

        $wasted = $php->opcacheUsed === null || $php->opcacheWasted === null || $php->opcacheTotal === null
            ? null
            : sprintf('%s used and %s wasted, of %s', Figures::bytes($php->opcacheUsed), Figures::bytes($php->opcacheWasted), Figures::bytes($php->opcacheTotal));

        return Block::gauges(new Gauges('OPcache', [
            new Gauge('Memory', $php->opcacheMemoryPercent, Sections::percent($php->opcacheMemoryPercent), Sections::mark($findings, 'php.opcache_memory'), $wasted),
            self::hitRate($php->opcacheHitRate, Sections::mark($findings, 'php.opcache_hit_rate'), $snapshot),
        ]));
    }

    private static function hitRate(Rate $rate, ?Mark $mark, ComponentSnapshot $snapshot): Gauge
    {
        return new Gauge('Hit rate', $rate->value, Sections::percent($rate->value), $mark, Sections::span($rate, $snapshot, 'php'));
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Fact>
     */
    private static function fpm(PhpReading $php, array $findings, ComponentSnapshot $snapshot): array
    {
        $requests = $php->fpmRequestsPerSecond;

        return [
            new Fact('Busy workers', $php->fpmActive === null || $php->fpmTotal === null ? Sections::MISSING : Sections::fraction($php->fpmActive, $php->fpmTotal)),
            new Fact('Pool', Sections::value($php->fpmPool)),
            new Fact('Process manager', Sections::value($php->fpmProcessManager)),
            new Fact(
                'Requests per second',
                $requests->value === null ? Sections::MISSING : Sections::unbroken(Sections::decimal($requests->value, 1) . ' ' . $requests->unit),
                hint: Sections::span($requests, $snapshot, 'php'),
            ),
            new Fact('Queue', Sections::value($php->fpmQueue === null ? null : (string) $php->fpmQueue), Sections::mark($findings, 'php.fpm_queue')),
            self::maxChildren($php, $findings, $snapshot->delta('php')?->elapsed),
            new Fact(
                'Slow requests',
                Sections::value($php->fpmSlowRequests === null ? null : (string) $php->fpmSlowRequests),
                hint: $php->fpmSlowRequests === null ? null : PhpReading::SINCE_FPM_START,
            ),
        ];
    }

    /**
     * @param list<Finding> $findings
     */
    private static function maxChildren(PhpReading $php, array $findings, ?int $elapsed): Fact
    {
        $finding = Sections::finding($findings, 'php.fpm_max_children');
        $recent = $finding?->severity === Severity::Warning && $elapsed !== null && $php->fpmMaxChildrenReachedRecently !== null;
        $count = $recent ? (int) $php->fpmMaxChildrenReachedRecently : $php->fpmMaxChildrenReached;

        return new Fact(
            'pm.max_children reached',
            match ($count) {
                null => Sections::MISSING,
                0 => 'never',
                default => Sections::unbroken(Figures::times($count)),
            },
            $finding === null ? null : Marks::severity($finding->severity),
            match (true) {
                $count === null => null,
                $recent => Figures::lastSeconds((int) $elapsed),
                default => PhpReading::SINCE_FPM_START,
            },
        );
    }

    private static function onOff(?bool $value): string
    {
        return match ($value) {
            true => 'on',
            false => 'off',
            null => Sections::MISSING,
        };
    }
}
