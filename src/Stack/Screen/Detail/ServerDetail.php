<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\Fact;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauge;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauges;
use Jmonitor\JmonitorBundle\Stack\Metric\Figures;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Reading\ServerReading;
use Jmonitor\JmonitorBundle\Stack\Screen\CardBuilder;

final class ServerDetail implements DetailInterface
{
    public function sections(InstanceState $instance, array $findings, int $now, bool $reading = true): array
    {
        $snapshot = Sections::snapshot($instance);

        if ($snapshot === null) {
            return [];
        }

        return Sections::of($snapshot, 'system', Block::GAUGES, $now, $reading, static function () use ($snapshot, $findings): array {
            $server = ServerReading::of($snapshot);

            return [
                Block::gauges(new Gauges('Usage', [
                    new Gauge('Load', $server->loadPercent, Sections::percent($server->loadPercent), Sections::mark($findings, 'system.load'), $server->container ? CardBuilder::HOST_LOAD : null),
                    new Gauge('Memory', $server->memoryPercent, Sections::percent($server->memoryPercent), Sections::mark($findings, 'system.memory'), Sections::share($server->memoryUsed, $server->memoryTotal)),
                    new Gauge('Disk', $server->diskPercent, Sections::percent($server->diskPercent), Sections::mark($findings, 'system.disk'), Sections::share($server->diskUsed, $server->diskTotal)),
                ])),
                Block::facts(new Facts('Load', self::load($server))),
                Block::facts(new Facts('System', self::system($server))),
            ];
        });
    }

    /**
     * @return list<Fact>
     */
    private static function load(ServerReading $server): array
    {
        $facts = [];

        foreach (['1' => $server->load1, '5' => $server->load5, '15' => $server->load15] as $minutes => $load) {
            $facts[] = new Fact(Sections::unbroken($minutes . ' min'), $load === null ? Sections::MISSING : number_format($load, 2, '.', ''));
        }

        $facts[] = new Fact('CPU cores', Sections::value($server->cores === null ? null : (string) $server->cores));

        if ($server->containerCpuQuota !== null) {
            $facts[] = new Fact('CPU quota', Sections::unbroken(Sections::decimal($server->containerCpuQuota, 2) . ($server->containerCpuQuota === 1.0 ? ' CPU' : ' CPUs')));
        }

        return $facts;
    }

    /**
     * @return list<Fact>
     */
    private static function system(ServerReading $server): array
    {
        $facts = [
            new Fact('OS', Sections::value($server->os)),
            new Fact('Uptime', $server->uptime === null || $server->uptime < 0 ? Sections::MISSING : Sections::duration($server->uptime)),
            new Fact('Hostname', Sections::value($server->hostname)),
        ];

        if ($server->containerMemoryLimit !== null) {
            $facts[] = new Fact('Memory limit', Figures::bytes($server->containerMemoryLimit));
        }

        return $facts;
    }
}
