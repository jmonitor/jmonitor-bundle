<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Dashboard\View\Gauges;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\NginxDetail;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\WebServerPayloads;
use PHPUnit\Framework\TestCase;

final class NginxDetailTest extends TestCase
{
    use DescribesSections;

    public function testNginxShowsItsConnectionsTrafficAndSettings(): void
    {
        $snapshot = Snapshots::of(Component::Nginx, [
            'nginx' => WebServerPayloads::nginxStatus(),
            'nginx.config' => WebServerPayloads::nginxConfig(),
            'eol.nginx' => WebServerPayloads::cycles(),
        ]);

        $blocks = (new NginxDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame([
            ['gauges', 'Usage', ['Connections' => self::nb('0.3 %')]],
            ['facts', 'Connections', ['Active' => '12', 'Reading' => '1', 'Writing' => '3', 'Waiting' => '8', 'Refused' => '0']],
            ['facts', 'Traffic', ['Requests per second' => '—', 'Requests per connection' => '2.2']],
            ['facts', 'Settings', [
                'Version' => '1.26.2 [End of life]',
                'worker_processes' => 'auto (4 CPUs)',
                'worker_connections' => '768',
                'keepalive_timeout' => self::nb('65 s'),
                'gzip' => 'on',
                'server_tokens' => 'off',
                'ssl_protocols' => 'TLSv1.2 TLSv1.3',
                'Configuration file' => '/etc/nginx/nginx.conf',
            ]],
        ], self::describe($blocks));

        $hints = self::hints($blocks);
        self::assertSame('since Nginx started', $hints['Connections / Refused']);
        self::assertSame('at the next refresh', $hints['Traffic / Requests per second']);
        self::assertSame('since Nginx started', $hints['Traffic / Requests per connection']);
    }

    public function testTheGaugeHintTellsTheShareOfTheLimit(): void
    {
        $snapshot = Snapshots::of(Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus(), 'nginx.config' => WebServerPayloads::nginxConfig()]);

        $blocks = (new NginxDetail())->sections(self::instance($snapshot), [], Snapshots::NOW);
        $gauge = $blocks[0]->content;

        self::assertInstanceOf(Gauges::class, $gauge);
        self::assertSame(self::nb('12 / 3072'), $gauge->gauges[0]->hint);
        self::assertEqualsWithDelta(0.39, $gauge->gauges[0]->percent, 0.01);
    }

    public function testRefusalsBetweenTheLastSamplesAreWhatItsCheckJudges(): void
    {
        $status = WebServerPayloads::nginxStatus(18_460, 18_455, 41_260);
        $delta = Snapshots::delta(WebServerPayloads::nginxStatus(), $status);
        $snapshot = Snapshots::of(Component::Nginx, ['nginx' => $status, 'nginx.config' => WebServerPayloads::nginxConfig()], deltas: ['nginx' => $delta]);
        $findings = self::findings($snapshot);

        $blocks = (new NginxDetail())->sections(self::instance($snapshot), $findings, Snapshots::NOW);

        self::assertSame('5 [Warning]', self::describe($blocks)[1][2]['Refused']);
        self::assertSame('over the last ' . self::nb('60 s'), self::hints($blocks)['Connections / Refused']);
        self::assertSame(
            'Nginx refused 5 connections over the last ' . self::nb('60 s'),
            array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->id === 'nginx.refused'))[0]->sentence,
        );
        self::assertSame(['Requests per second' => self::nb('1.0 req/s'), 'Requests per connection' => '12.0'], self::describe($blocks)[2][2]);
        self::assertSame('over the last ' . self::nb('60 s'), self::hints($blocks)['Traffic / Requests per connection']);

        $earlier = Snapshots::of(Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus(18_460, 18_455)]);

        self::assertSame('5 [Info]', self::describe((new NginxDetail())->sections(self::instance($earlier), self::findings($earlier), Snapshots::NOW))[1][2]['Refused']);
    }

    public function testAGaugeAndItsCheckShowTheSameFigure(): void
    {
        $snapshot = Snapshots::of(Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus(active: 2_766), 'nginx.config' => WebServerPayloads::nginxConfig()]);
        $findings = self::findings($snapshot);

        self::assertSame(['Connections' => self::nb('90 %') . ' [Warning]'], self::describe((new NginxDetail())->sections(self::instance($snapshot), $findings, Snapshots::NOW))[0][2]);
        self::assertSame(
            'Nginx holds ' . self::nb('90 %') . ' of its connections (2766 of 3072)',
            array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->id === 'nginx.connections'))[0]->sentence,
        );
    }

    public function testWithoutConfigurationTheSettingsSayWhy(): void
    {
        $snapshot = Snapshots::of(Component::Nginx, [
            'nginx' => WebServerPayloads::nginxStatus(),
            'nginx.config' => WebServerPayloads::nginxConfigWithoutSettings(),
            'eol.nginx' => WebServerPayloads::cycles(),
        ]);

        $blocks = (new NginxDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame(['gauges', 'Usage', ['Connections' => '12']], self::describe($blocks)[0]);
        self::assertInstanceOf(Gauges::class, $blocks[0]->content);
        self::assertSame('connection limit unknown', $blocks[0]->content->gauges[0]->hint);
        self::assertNull($blocks[0]->content->gauges[0]->percent);
        self::assertSame([
            ['facts', 'Settings', ['Version' => '1.26.2 [End of life]']],
            ['notice', 'info', 'The Nginx settings could not be read', 'The "nginx" command gave no configuration: it is not installed where PHP runs, or "nginx -T" needs more rights than PHP has.'],
        ], array_slice(self::describe($blocks), 3));
    }

    public function testSettingsThatCouldNotBeReadSayWhy(): void
    {
        $snapshot = Snapshots::of(Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus()], ['nginx.config' => ResultState::Failed]);

        $blocks = (new NginxDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame(['gauges', 'Usage', ['Connections' => '12']], self::describe($blocks)[0]);
        self::assertSame(['Usage', 'Connections', 'Traffic', 'notice: The Nginx settings could not be read'], self::titles($blocks));
        self::assertSame(['notice', 'warn', 'The Nginx settings could not be read', Snapshots::FAILURE], self::describe($blocks)[3]);
    }

    public function testAStatusPageThatCouldNotBeReadLeavesTheSettings(): void
    {
        $snapshot = Snapshots::of(Component::Nginx, ['nginx.config' => WebServerPayloads::nginxConfig()], ['nginx' => ResultState::Failed]);

        self::assertSame(['Settings'], self::titles((new NginxDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW)));
    }

    public function testASectionSaysReadingWhileItsCollectorWasNeverRead(): void
    {
        $snapshot = Snapshots::of(Component::Nginx, [], ['nginx' => ResultState::Pending, 'nginx.config' => ResultState::Pending, 'eol.nginx' => ResultState::Pending]);

        self::assertSame([['reading', 'gauges'], ['reading', 'facts']], self::describe((new NginxDetail())->sections(self::instance($snapshot), [], Snapshots::NOW)));
        self::assertSame([], (new NginxDetail())->sections(self::instance($snapshot), [], Snapshots::NOW, false));
    }

    public function testAnOutdatedValueSaysItsAge(): void
    {
        $snapshot = Snapshots::of(Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus(), 'nginx.config' => WebServerPayloads::nginxConfig()], ['nginx' => ResultState::Stale]);

        $blocks = (new NginxDetail())->sections(self::instance($snapshot), [], Snapshots::NOW);

        self::assertSame(['notice', 'info', 'Read ' . self::nb('1 h') . ' ago', ''], self::describe($blocks)[0]);
        self::assertSame(['Usage', 'Connections', 'Traffic', 'Settings'], array_slice(self::titles($blocks), 1));
    }

    public function testAnEmptySnapshotRendersWithoutWarning(): void
    {
        [$empty, $odd] = self::withoutWarnings(static fn(): array => [
            (new NginxDetail())->sections(self::instance(Snapshots::of(Component::Nginx, ['nginx' => [], 'nginx.config' => []])), [], Snapshots::NOW),
            (new NginxDetail())->sections(self::instance(Snapshots::of(Component::Nginx, [
                'nginx' => ['status' => ['active' => 'many', 'accepts' => [1], 'reading' => null]],
                'nginx.config' => ['version' => ['1.26'], 'cpu_count' => 'four', 'config' => ['worker_processes' => 'auto', 'worker_connections' => 'lots', 'config_path' => '/etc/nginx/nginx.conf', 'gzip' => ['on']]],
                'eol.nginx' => ['cycles' => 'soon'],
            ])), [], Snapshots::NOW),
        ]);

        self::assertSame([
            ['gauges', 'Usage', ['Connections' => '—']],
            ['facts', 'Connections', ['Active' => '—', 'Reading' => '—', 'Writing' => '—', 'Waiting' => '—', 'Refused' => '—']],
            ['facts', 'Traffic', ['Requests per second' => '—', 'Requests per connection' => '—']],
            ['facts', 'Settings', ['Version' => '—']],
            ['notice', 'info', 'The Nginx settings could not be read', 'The "nginx" command gave no configuration: it is not installed where PHP runs, or "nginx -T" needs more rights than PHP has.'],
        ], self::describe($empty));
        self::assertSame([
            ['gauges', 'Usage', ['Connections' => '—']],
            ['facts', 'Connections', ['Active' => '—', 'Reading' => '—', 'Writing' => '—', 'Waiting' => '—', 'Refused' => '—']],
            ['facts', 'Traffic', ['Requests per second' => '—', 'Requests per connection' => '—']],
            ['facts', 'Settings', [
                'Version' => '—',
                'worker_processes' => 'auto',
                'worker_connections' => '—',
                'keepalive_timeout' => '—',
                'gzip' => '—',
                'server_tokens' => '—',
                'ssl_protocols' => '—',
                'Configuration file' => '/etc/nginx/nginx.conf',
            ]],
        ], self::describe($odd));
    }
}
