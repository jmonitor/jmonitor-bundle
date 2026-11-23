<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\Fact;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauge;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauges;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Check\Severity;
use Jmonitor\JmonitorBundle\Stack\Metric\Figures;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Reading\NginxReading;
use Jmonitor\JmonitorBundle\Stack\Screen\Marks;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final class NginxDetail implements DetailInterface
{
    private const SETTINGS_UNREAD = 'The Nginx settings could not be read';

    public function sections(InstanceState $instance, array $findings, int $now, bool $reading = true): array
    {
        $snapshot = Sections::snapshot($instance);

        if ($snapshot === null) {
            return [];
        }

        $nginx = NginxReading::of($snapshot, new \DateTimeImmutable('@' . $now));

        return [
            ...Sections::of($snapshot, 'nginx', Block::GAUGES, $now, $reading, static fn(): array => self::status($nginx, $snapshot, $findings)),
            ...Sections::of($snapshot, 'nginx.config', Block::FACTS, $now, $reading, static fn(): array => self::settings($nginx), self::SETTINGS_UNREAD),
        ];
    }

    public static function connections(NginxReading $nginx): ?string
    {
        if ($nginx->active === null) {
            return null;
        }

        return $nginx->maxConnections === null ? (string) $nginx->active : Sections::fraction($nginx->active, $nginx->maxConnections);
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Block>
     */
    private static function status(NginxReading $nginx, ComponentSnapshot $snapshot, array $findings): array
    {
        $elapsed = $snapshot->delta('nginx')?->elapsed;
        $limited = $nginx->maxConnections !== null;

        return [
            Block::gauges(new Gauges('Connections', [new Gauge(
                'Active',
                $nginx->connectionsPercent,
                $limited ? Sections::percent($nginx->connectionsPercent) : Sections::value(self::count($nginx->active)),
                Sections::mark($findings, 'nginx.connections'),
                $limited ? self::connections($nginx) : 'connection limit unknown',
            )])),
            Block::facts(new Facts('Connections', [
                new Fact('Active', Sections::value(self::count($nginx->active))),
                new Fact('Reading', Sections::value(self::count($nginx->reading))),
                new Fact('Writing', Sections::value(self::count($nginx->writing))),
                new Fact('Waiting', Sections::value(self::count($nginx->waiting))),
                self::refused($nginx, $findings, $elapsed),
            ])),
            Block::facts(new Facts('Traffic', [
                new Fact('Requests per second', Sections::value(Sections::rate($nginx->requestsPerSecond)), hint: Figures::span($nginx->requestsPerSecond, $elapsed)),
                new Fact('Requests per connection', Sections::value(Sections::rate($nginx->requestsPerConnection)), hint: Figures::span($nginx->requestsPerConnection, $elapsed)),
            ])),
        ];
    }

    /**
     * @param list<Finding> $findings
     */
    private static function refused(NginxReading $nginx, array $findings, ?int $elapsed): Fact
    {
        $finding = Sections::finding($findings, 'nginx.refused');
        $mark = $finding === null ? null : Marks::severity($finding->severity);

        if ($finding?->severity === Severity::Warning && $elapsed !== null) {
            return new Fact('Refused', Sections::value(self::count($nginx->refusedRecently)), $mark, Figures::lastSeconds($elapsed));
        }

        return new Fact('Refused', Sections::value(self::count($nginx->refused)), $mark, $nginx->refused === null ? null : 'since Nginx started');
    }

    /**
     * @return list<Block>
     */
    private static function settings(NginxReading $nginx): array
    {
        $version = new Fact('Version', Sections::value($nginx->version), $nginx->support === null ? null : Marks::support($nginx->support));

        if ($nginx->configPath === null) {
            return [
                Block::facts(new Facts('Settings', [$version])),
                Block::notice(new Notice('info', self::SETTINGS_UNREAD, '"nginx -T" gave no configuration: it usually needs more rights than PHP has.')),
            ];
        }

        return [Block::facts(new Facts('Settings', [
            $version,
            new Fact('worker_processes', Sections::value($nginx->workerProcesses)),
            new Fact('worker_connections', Sections::value(self::count($nginx->workerConnections))),
            new Fact('keepalive_timeout', $nginx->keepaliveTimeout === null ? Sections::MISSING : Sections::unbroken($nginx->keepaliveTimeout . ' s')),
            new Fact('gzip', Sections::value($nginx->gzip)),
            new Fact('server_tokens', Sections::value($nginx->serverTokens)),
            new Fact('ssl_protocols', Sections::value($nginx->sslProtocols)),
            new Fact('Configuration file', $nginx->configPath),
        ]))];
    }

    private static function count(?int $count): ?string
    {
        return $count === null ? null : (string) $count;
    }
}
