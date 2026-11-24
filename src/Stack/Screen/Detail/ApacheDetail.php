<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\Fact;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauge;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauges;
use Jmonitor\JmonitorBundle\Stack\Metric\Figures;
use Jmonitor\JmonitorBundle\Stack\Metric\Rate;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Reading\ApacheReading;
use Jmonitor\JmonitorBundle\Stack\Reading\Values;
use Jmonitor\JmonitorBundle\Stack\Screen\Marks;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final class ApacheDetail implements DetailInterface
{
    public function sections(InstanceState $instance, array $findings, int $now, bool $reading = true): array
    {
        $snapshot = Sections::snapshot($instance);

        if ($snapshot === null) {
            return [];
        }

        return Sections::of($snapshot, 'apache', Block::GAUGES, $now, $reading, static function () use ($snapshot, $findings, $now): array {
            $apache = ApacheReading::of($snapshot, new \DateTimeImmutable('@' . $now));
            $busyPercent = $apache->busyWorkers === null || $apache->idleWorkers === null ? null : Values::percent($apache->busyWorkers, $apache->busyWorkers + $apache->idleWorkers);

            $blocks = [
                Block::gauges(new Gauges('Workers', [
                    new Gauge('Busy workers', $busyPercent, Sections::percent($busyPercent), Sections::mark($findings, 'apache.idle_workers'), self::workers($apache)),
                    new Gauge(
                        'Used slots',
                        $apache->slotsPercent,
                        Sections::percent($apache->slotsPercent),
                        Sections::mark($findings, 'apache.workers'),
                        $apache->usedSlots === null || $apache->slots === null ? null : Sections::fraction($apache->usedSlots, $apache->slots),
                    ),
                ])),
                Block::facts(new Facts('Traffic', [
                    self::traffic('Requests per second', Sections::rate($apache->requestsPerSecond), $apache->requestsPerSecond, $snapshot),
                    self::traffic('Bytes per second', self::bytes($apache->bytesPerSecond, '/s'), $apache->bytesPerSecond, $snapshot),
                    self::traffic('Bytes per request', self::bytes($apache->bytesPerRequest), $apache->bytesPerRequest, $snapshot),
                    self::traffic('Time per request', Sections::milliseconds($apache->secondsPerRequest), $apache->secondsPerRequest, $snapshot),
                ])),
                Block::facts(new Facts('Server', [
                    new Fact('Version', Sections::value($apache->version), $apache->support === null ? null : Marks::support($apache->support)),
                    new Fact('MPM', Sections::value($apache->mpm)),
                    new Fact('Uptime', $apache->uptime === null || $apache->uptime < 0 ? Sections::MISSING : Sections::duration($apache->uptime)),
                ])),
            ];

            if ($apache->scoreboard !== []) {
                $blocks[] = Block::facts(new Facts('Scoreboard', array_map(
                    static fn(string $state, int $slots): Fact => new Fact($state, (string) $slots),
                    array_keys($apache->scoreboard),
                    array_values($apache->scoreboard),
                )));
            }

            return $blocks;
        });
    }

    public static function workers(ApacheReading $apache): ?string
    {
        return $apache->busyWorkers === null || $apache->idleWorkers === null ? null : Sections::fraction($apache->busyWorkers, $apache->busyWorkers + $apache->idleWorkers);
    }

    private static function traffic(string $label, ?string $value, Rate $rate, ComponentSnapshot $snapshot): Fact
    {
        return new Fact($label, Sections::value($value), hint: Sections::span($rate, $snapshot, 'apache'));
    }

    private static function bytes(Rate $rate, string $suffix = ''): ?string
    {
        return $rate->value === null ? null : Figures::bytes((int) round($rate->value)) . $suffix;
    }
}
