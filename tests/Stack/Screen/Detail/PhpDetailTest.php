<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\PhpDetail;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use PHPUnit\Framework\TestCase;

final class PhpDetailTest extends TestCase
{
    use DescribesSections;

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function testPhpShowsItsRuntimeOpcacheApcuAndFpm(): void
    {
        $snapshot = Snapshots::of(Component::Php, ['php' => ['version' => '8.2.29'] + Snapshots::php(), 'eol.php' => Snapshots::cycles()]);

        $blocks = (new PhpDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame([
            ['facts', 'Runtime', [
                'Version' => '8.2.29 [Security fixes only]',
                'SAPI' => 'fpm-fcgi',
                'memory_limit' => self::nb('128 MB'),
                'max_execution_time' => self::nb('30 s'),
                'display_errors' => 'off',
                'realpath_cache_size' => self::nb('4 MB'),
            ]],
            ['gauges', 'OPcache', ['Memory' => self::nb('70 %'), 'Hit rate' => self::nb('91 %')]],
            ['gauges', 'APCu', ['Memory' => self::nb('75 %'), 'Hit rate' => self::nb('90 %')]],
            ['facts', 'PHP-FPM', [
                'Busy workers' => self::nb('2 / 8'),
                'Pool' => 'www',
                'Process manager' => 'dynamic',
                'Requests per second' => '—',
                'Queue' => '0',
                'pm.max_children reached' => self::nb('3 times') . ' [Info]',
                'Slow requests' => '1',
            ]],
        ], self::describe($blocks));

        $hints = array_map(static fn(?string $hint): ?string => $hint === null ? null : str_replace(self::NBSP, ' ', $hint), self::hints($blocks));
        self::assertSame('57 MB used and 9.5 MB wasted, of 95 MB', $hints['OPcache / Memory']);
        self::assertSame('since server start', $hints['OPcache / Hit rate']);
        self::assertSame('24 MB of 32 MB', $hints['APCu / Memory']);
        self::assertSame('since server start', $hints['APCu / Hit rate']);
        self::assertSame('at the next refresh', $hints['PHP-FPM / Requests per second']);
        self::assertSame('since the pool started', $hints['PHP-FPM / pm.max_children reached']);
        self::assertSame('since the pool started', $hints['PHP-FPM / Slow requests']);
        self::assertNull($hints['Runtime / Version']);
    }

    public function testRatesOverTheLastSamplesSayTheirSpan(): void
    {
        $delta = Snapshots::delta(
            ['opcache' => ['status' => ['opcache_statistics' => ['hits' => 1000, 'misses' => 100]]], 'fpm' => ['accepted-conn' => 4880]],
            ['opcache' => ['status' => ['opcache_statistics' => ['hits' => 1090, 'misses' => 110]]], 'fpm' => ['accepted-conn' => 5000]],
        );
        $snapshot = Snapshots::of(Component::Php, ['php' => Snapshots::php()], deltas: ['php' => $delta]);

        $blocks = (new PhpDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame(self::nb('90 %'), self::describe($blocks)[1][2]['Hit rate']);
        self::assertSame(self::nb('2 req/s'), self::describe($blocks)[3][2]['Requests per second']);
        self::assertSame('over the last ' . self::nb('60 s'), self::hints($blocks)['OPcache / Hit rate']);
        self::assertSame('over the last ' . self::nb('60 s'), self::hints($blocks)['PHP-FPM / Requests per second']);
    }

    public function testARaisedCheckMarksItsValue(): void
    {
        $php = Snapshots::php();
        $php['display_errors'] = '1';
        $php['realpath_cache_size'] = '16K';
        $php['opcache']['status']['memory_usage'] = ['used_memory' => 92_000_000, 'free_memory' => 3_000_000, 'wasted_memory' => 5_000_000];
        $php['opcache']['status']['opcache_statistics']['hits'] = 500;
        $php['opcache']['status']['opcache_statistics']['misses'] = 600;
        $php['apcu']['sma_info']['avail_mem'] = 1_000_000;
        $php['fpm']['listen-queue'] = 2;
        $snapshot = Snapshots::of(Component::Php, ['php' => $php, 'eol.php' => Snapshots::cycles()]);

        $described = self::describe((new PhpDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW));

        self::assertSame('8.4.23', $described[0][2]['Version']);
        self::assertSame('on [Warning]', $described[0][2]['display_errors']);
        self::assertSame(self::nb('16 KB') . ' [Info]', $described[0][2]['realpath_cache_size']);
        self::assertSame(['Memory' => self::nb('97 %') . ' [Warning]', 'Hit rate' => self::nb('45 %') . ' [Warning]'], $described[1][2]);
        self::assertSame(self::nb('97 %') . ' [Warning]', $described[2][2]['Memory']);
        self::assertSame('2 [Warning]', $described[3][2]['Queue']);

        $ended = Snapshots::of(Component::Php, ['php' => ['version' => '8.1.31'] + Snapshots::php(), 'eol.php' => Snapshots::cycles()]);

        self::assertSame('8.1.31 [End of life]', self::describe((new PhpDetail())->sections(self::instance($ended), self::findings($ended), Snapshots::NOW))[0][2]['Version']);
    }

    public function testOpcacheThatDoesNotRunIsAFact(): void
    {
        $php = Snapshots::php();
        $php['opcache'] = false;
        $absent = Snapshots::of(Component::Php, ['php' => $php]);
        $php['opcache'] = ['status' => ['opcache_enabled' => false]];
        $off = Snapshots::of(Component::Php, ['php' => $php]);

        self::assertSame(['facts', 'OPcache', ['Status' => 'not available [Warning]']], self::describe((new PhpDetail())->sections(self::instance($absent), self::findings($absent), Snapshots::NOW))[1]);
        self::assertSame(['facts', 'OPcache', ['Status' => 'off [Warning]']], self::describe((new PhpDetail())->sections(self::instance($off), self::findings($off), Snapshots::NOW))[1]);
    }

    public function testTheEndOfLifeHasNoWaitingState(): void
    {
        $snapshot = Snapshots::of(Component::Php, ['php' => ['version' => '8.2.29'] + Snapshots::php()], ['eol.php' => ResultState::Pending]);

        $blocks = (new PhpDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW);

        self::assertSame(['Runtime', 'OPcache', 'APCu', 'PHP-FPM'], self::titles($blocks));
        self::assertSame('8.2.29', self::describe($blocks)[0][2]['Version']);
    }

    public function testNoFpmSectionOutsideFpm(): void
    {
        $php = Snapshots::php();
        unset($php['fpm']);
        $php['sapi_name'] = 'apache2handler';
        $snapshot = Snapshots::of(Component::Php, ['php' => $php]);

        self::assertSame(['Runtime', 'OPcache', 'APCu'], self::titles((new PhpDetail())->sections(self::instance($snapshot), [], Snapshots::NOW)));
    }

    public function testNoApcuSectionWhenApcuIsOff(): void
    {
        $php = Snapshots::php();
        $php['apcu']['config']['apc.enabled'] = false;
        $off = Snapshots::of(Component::Php, ['php' => $php]);
        unset($php['apcu']);
        $absent = Snapshots::of(Component::Php, ['php' => $php]);

        self::assertSame(['Runtime', 'OPcache', 'PHP-FPM'], self::titles((new PhpDetail())->sections(self::instance($off), [], Snapshots::NOW)));
        self::assertSame(['Runtime', 'OPcache', 'PHP-FPM'], self::titles((new PhpDetail())->sections(self::instance($absent), [], Snapshots::NOW)));
    }

    public function testTheRequestsOfTheFpmWorkersAreNowhere(): void
    {
        $php = Snapshots::php();
        $php['fpm']['procs'] = [
            ['pid' => 12, 'state' => 'Running', 'request method' => 'POST', 'request uri' => '/checkout?token=s3cr3t', 'script' => '/srv/app/public/index.php', 'user' => 'alice'],
        ];
        $snapshot = Snapshots::of(Component::Php, ['php' => $php]);

        $shown = serialize((new PhpDetail())->sections(self::instance($snapshot), self::findings($snapshot), Snapshots::NOW));

        foreach (['s3cr3t', '/checkout', 'alice', 'index.php', 'Running'] as $secret) {
            self::assertStringNotContainsString($secret, $shown);
        }
    }

    public function testASectionSaysReadingWhileItsCollectorWasNeverRead(): void
    {
        $snapshot = Snapshots::of(Component::Php, [], ['php' => ResultState::Pending, 'eol.php' => ResultState::Pending]);

        self::assertSame([['reading', 'facts']], self::describe((new PhpDetail())->sections(self::instance($snapshot), [], Snapshots::NOW)));
        self::assertSame([], (new PhpDetail())->sections(self::instance($snapshot), [], Snapshots::NOW, false));
    }

    public function testAnOutdatedValueSaysItsAge(): void
    {
        $snapshot = Snapshots::of(Component::Php, ['php' => Snapshots::php()], ['php' => ResultState::Stale]);

        $blocks = (new PhpDetail())->sections(self::instance($snapshot), [], Snapshots::NOW);

        self::assertSame(['notice', 'info', 'Read ' . self::nb('1 h') . ' ago', ''], self::describe($blocks)[0]);
        self::assertSame(['Runtime', 'OPcache', 'APCu', 'PHP-FPM'], array_slice(self::titles($blocks), 1));
    }

    public function testAFailedRenewalSaysWhyAndWhenItIsRetried(): void
    {
        $snapshot = new ComponentSnapshot(Component::Php, [
            'php' => new CollectorResult('php', Snapshots::php(), ResultState::Stale, 'The status page of PHP-FPM did not answer', Snapshots::NOW - 120, expiresAt: Snapshots::NOW + 30),
        ]);

        $blocks = (new PhpDetail())->sections(self::instance($snapshot), [], Snapshots::NOW);

        self::assertSame(['notice', 'warn', 'Could not be read again: The status page of PHP-FPM did not answer', 'next try in ' . self::nb('30 s')], self::describe($blocks)[0]);

        $due = new ComponentSnapshot(Component::Php, [
            'php' => new CollectorResult('php', Snapshots::php(), ResultState::Stale, 'Timeout', Snapshots::NOW - 120, expiresAt: Snapshots::NOW - 10),
        ]);

        self::assertSame(['notice', 'warn', 'Could not be read again: Timeout', 'next try now'], self::describe((new PhpDetail())->sections(self::instance($due), [], Snapshots::NOW))[0]);
    }

    public function testAnEmptySnapshotRendersWithoutWarning(): void
    {
        self::failOnWarnings();

        $empty = (new PhpDetail())->sections(self::instance(Snapshots::of(Component::Php, ['php' => []])), [], Snapshots::NOW);
        $odd = (new PhpDetail())->sections(self::instance(Snapshots::of(Component::Php, ['php' => [
            'version' => ['8.4'],
            'memory_limit' => '-1',
            'max_execution_time' => 0,
            'display_errors' => 'stderr',
            'realpath_cache_size' => 'lots',
            'opcache' => ['status' => 'on', 'config' => 7],
            'apcu' => ['config' => ['apc.enabled' => '1'], 'sma_info' => 'x', 'cache_info' => null],
            'fpm' => ['pool' => ['www'], 'active-processes' => 'many', 'procs' => 'none'],
        ], 'eol.php' => ['cycles' => 'soon']])), [], Snapshots::NOW);

        self::assertSame([
            ['facts', 'Runtime', ['Version' => '—', 'SAPI' => '—', 'memory_limit' => '—', 'max_execution_time' => '—', 'display_errors' => '—', 'realpath_cache_size' => '—']],
            ['facts', 'OPcache', ['Status' => '—']],
        ], self::describe($empty));
        self::assertSame([
            ['facts', 'Runtime', ['Version' => '—', 'SAPI' => '—', 'memory_limit' => 'unlimited', 'max_execution_time' => 'unlimited', 'display_errors' => 'on', 'realpath_cache_size' => '—']],
            ['facts', 'OPcache', ['Status' => '—']],
            ['gauges', 'APCu', ['Memory' => '', 'Hit rate' => '']],
            ['facts', 'PHP-FPM', [
                'Busy workers' => '—',
                'Pool' => '—',
                'Process manager' => '—',
                'Requests per second' => '—',
                'Queue' => '—',
                'pm.max_children reached' => '—',
                'Slow requests' => '—',
            ]],
        ], self::describe($odd));
    }
}
