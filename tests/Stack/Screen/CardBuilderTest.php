<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCard;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Vital;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\Server;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Screen\CardBuilder;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\RedisPayloads;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\WebServerPayloads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CardBuilderTest extends TestCase
{
    private const NBSP = "\u{00A0}";

    public function testTheServerCardShowsItsSystemLoadMemoryAndDisk(): void
    {
        $card = $this->card(Snapshots::of(Component::System, ['system' => Snapshots::system()]), 'stack/system');

        self::assertSame('system', $card->id);
        self::assertSame('Server', $card->name);
        self::assertSame('Debian GNU/Linux 12 (bookworm)', $card->version);
        self::assertEquals(new Mark('ok', 'Healthy'), $card->mark);
        self::assertSame(['Load' => '62 %', 'Memory' => '75 %', 'Disk' => '75 %'], self::vitals($card));
        self::assertSame('stack/system', $card->path);
        self::assertSame(Snapshots::NOW, $card->readAt);
        self::assertFalse($card->reading);
        self::assertNull($card->reason);
        self::assertNull($card->note);
        self::assertSame([], $card->badges);

        $system = Snapshots::system();
        $system['cgroup']['memory_limit'] = 2_000_000_000;

        self::assertSame('Load is measured on the host node', $this->card(Snapshots::of(Component::System, ['system' => $system]))->note);
    }

    public function testThePhpCardShowsOpcacheAndFpmWorkersWithItsEndOfLife(): void
    {
        $php = ['version' => '8.2.29'] + Snapshots::php();
        $card = $this->card(Snapshots::of(Component::Php, ['php' => $php, 'eol.php' => Snapshots::cycles()]), 'stack/php');

        self::assertSame('php', $card->id);
        self::assertSame('PHP', $card->name);
        self::assertSame('8.2.29', $card->version);
        self::assertEquals(new Mark('warn', 'Warning'), $card->mark);
        self::assertEquals([new Mark('warn', 'Security fixes only')], $card->badges);
        self::assertEquals([
            new Vital('OPcache memory', '70 %'),
            new Vital('OPcache hit rate', '90.8 %', 'since server start'),
            new Vital('FPM workers', '2 / 8'),
        ], array_map(self::readable(...), $card->vitals));

        $delta = Snapshots::delta(
            ['opcache' => ['status' => ['opcache_statistics' => ['hits' => 190, 'misses' => 10]]]],
            ['opcache' => ['status' => ['opcache_statistics' => ['hits' => 1090, 'misses' => 110]]]],
        );
        $card = $this->card(Snapshots::of(Component::Php, ['php' => Snapshots::php()], deltas: ['php' => $delta]));

        self::assertSame(['OPcache memory' => '70 %', 'OPcache hit rate' => '90 %', 'FPM workers' => '2 / 8'], self::vitals($card));
        self::assertSame('over the last 60' . self::NBSP . 's', $card->vitals[1]->hint);

        $ended = $this->card(Snapshots::of(Component::Php, ['php' => ['version' => '8.1.31'] + Snapshots::php(), 'eol.php' => Snapshots::cycles()]));
        self::assertEquals([new Mark('bad', 'End of life')], $ended->badges);
        self::assertSame([], $this->card(Snapshots::of(Component::Php, ['php' => Snapshots::php(), 'eol.php' => Snapshots::cycles()]))->badges);
    }

    public function testAVitalNeverShowsAThresholdItDidNotReach(): void
    {
        $php = Snapshots::php();
        $php['opcache']['status']['memory_usage'] = ['used_memory' => 84_960, 'free_memory' => 15_040, 'wasted_memory' => 0];

        self::assertSame('84.9' . self::NBSP . '%', $this->card(Snapshots::of(Component::Php, ['php' => $php]))->vitals[0]->value);
    }

    public function testTheSymfonyCardShowsItsEnvironmentDebugAndFlexRecipes(): void
    {
        $outdated = ['up_to_date' => false, 'outdated_recipes' => ['doctrine/doctrine-bundle', 'symfony/framework-bundle']];
        $card = $this->card(Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony(), 'symfony.flex' => $outdated]), 'stack/symfony');

        self::assertSame('symfony', $card->id);
        self::assertSame('Symfony', $card->name);
        self::assertSame('7.4.2', $card->version);
        self::assertEquals(new Mark('ok', 'Healthy'), $card->mark);
        self::assertSame(['Environment' => 'prod', 'Debug' => 'off', 'Flex recipes' => '2 outdated'], self::vitals($card));

        $upToDate = ['up_to_date' => true, 'outdated_recipes' => []];
        $card = $this->card(Snapshots::of(Component::Symfony, ['symfony' => ['debug' => true, 'env' => 'dev'] + Snapshots::symfony(), 'symfony.flex' => $upToDate, 'eol.symfony' => Snapshots::cycles()]));

        self::assertSame(['Environment' => 'dev', 'Debug' => 'on', 'Flex recipes' => 'up to date'], self::vitals($card));
        self::assertSame([], $card->badges);

        $card = $this->card(Snapshots::of(Component::Symfony, ['symfony' => ['version' => '6.4.20'] + Snapshots::symfony(), 'eol.symfony' => Snapshots::cycles()]));
        self::assertEquals([new Mark('warn', 'Security fixes only')], $card->badges);
    }

    public function testAMissingValueIsADash(): void
    {
        $card = $this->card(Snapshots::of(Component::System, ['system' => ['os' => []]]));

        self::assertNull($card->version);
        self::assertSame(['Load' => '—', 'Memory' => '—', 'Disk' => '—'], self::vitals($card));

        $card = $this->card(Snapshots::of(Component::Php, ['php' => ['opcache' => false, 'fpm' => ['pool' => 'www']]]));

        self::assertSame(['OPcache memory' => '—', 'OPcache hit rate' => '—', 'FPM workers' => '—'], self::vitals($card));
        self::assertNull($card->vitals[1]->hint);
    }

    public function testAVitalSaysReadingWhileItsCollectorWasNeverRead(): void
    {
        foreach ([ResultState::Pending, ResultState::Busy] as $state) {
            $card = $this->card(Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => $state]));

            self::assertSame(['Environment' => 'prod', 'Debug' => 'off', 'Flex recipes' => 'Reading…'], self::vitals($card), $state->value);
            self::assertFalse($card->reading, $state->value);
        }

        $card = $this->card(Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Failed]));
        self::assertSame('—', self::vitals($card)['Flex recipes']);
    }

    public function testWhatWillNotBeReadForNowShowsAsMissing(): void
    {
        $instance = new InstanceState(null, null, null, Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Pending]));
        $card = $this->cardOf(Component::Symfony, $instance, reading: false);

        self::assertSame(['Environment' => 'prod', 'Debug' => 'off', 'Flex recipes' => '—'], self::vitals($card));
        self::assertNull($card->vitals[2]->hint);
        self::assertFalse($card->reading);

        foreach ([ResultState::Pending, ResultState::Busy] as $state) {
            $card = $this->cardOf(Component::Php, new InstanceState(null, null, null, Snapshots::of(Component::Php, [], ['php' => $state])), reading: false);

            self::assertFalse($card->reading, $state->value);
            self::assertSame(['OPcache memory' => '—', 'OPcache hit rate' => '—'], self::vitals($card), $state->value);
            self::assertSame([null, null], array_map(static fn(Vital $vital): ?string => $vital->hint, $card->vitals), $state->value);
        }
    }

    /**
     * @return iterable<string, array{ResultState, bool}>
     */
    public static function mainStates(): iterable
    {
        yield 'pending' => [ResultState::Pending, true];
        yield 'busy' => [ResultState::Busy, true];
        yield 'fresh' => [ResultState::Fresh, false];
        yield 'stale' => [ResultState::Stale, false];
        yield 'failed' => [ResultState::Failed, false];
    }

    #[DataProvider('mainStates')]
    public function testACardIsReadingUntilItsMainCollectorWasRead(ResultState $state, bool $reading): void
    {
        $card = $this->card(Snapshots::of(Component::Php, ['php' => Snapshots::php()], ['php' => $state]));

        self::assertSame($reading, $card->reading);
        self::assertSame($state === ResultState::Fresh ? Snapshots::NOW : ($state === ResultState::Stale ? Snapshots::NOW - Snapshots::STALE_AGE : null), $card->readAt);

        if ($reading) {
            self::assertSame(['OPcache memory' => 'Reading…', 'OPcache hit rate' => 'Reading…'], self::vitals($card), 'FPM is not known yet');
        }
    }

    public function testAFailedMainCollectorGivesItsReason(): void
    {
        $card = $this->card(Snapshots::of(Component::Php, [], ['php' => ResultState::Failed]));

        self::assertSame(Snapshots::FAILURE, $card->reason);
        self::assertEquals(new Mark('unknown', 'Unknown'), $card->mark);
        self::assertFalse($card->reading);

        $unavailable = new InstanceState(null, null, 'The FPM status page did not answer.', new ComponentSnapshot(Component::Php, [], 'The FPM status page did not answer.'));
        self::assertSame('The FPM status page did not answer.', $this->cardOf(Component::Php, $unavailable)->reason);

        $failedSnapshot = new InstanceState(null, null, null, new ComponentSnapshot(Component::Php, [], 'No collector could start.'));
        self::assertSame('No collector could start.', $this->cardOf(Component::Php, $failedSnapshot)->reason);

        $stale = Snapshots::of(Component::Php, ['php' => Snapshots::php()], ['php' => ResultState::Stale]);
        self::assertNull($this->card($stale)->reason);
    }

    public function testAStaleCardSaysHowOldItsFiguresAre(): void
    {
        $stale = static fn(Component $component, array $data, ?string $error = null): ComponentSnapshot => new ComponentSnapshot($component, [
            $component->value => new CollectorResult($component->value, $data, ResultState::Stale, $error, Snapshots::NOW - 180, expiresAt: Snapshots::NOW - 120),
        ]);
        $container = Snapshots::system();
        $container['cgroup']['memory_limit'] = 2_000_000_000;

        self::assertNull($this->card(Snapshots::of(Component::Php, ['php' => Snapshots::php()]))->note);
        self::assertSame('Read 3' . self::NBSP . 'min ago', $this->card($stale(Component::Php, Snapshots::php()))->note);
        self::assertSame('Read 3' . self::NBSP . 'min ago, could not be read again', $this->card($stale(Component::Php, Snapshots::php(), 'Timed out'))->note);
        self::assertSame('Load is measured on the host node · Read 3' . self::NBSP . 'min ago, could not be read again', $this->card($stale(Component::System, $container, 'Timed out'))->note);
    }

    public function testFlexRecipesTellTheOutdatedOnesFirst(): void
    {
        $contradicting = ['up_to_date' => true, 'outdated_recipes' => ['symfony/twig-bundle']];
        $card = $this->card(Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony(), 'symfony.flex' => $contradicting]));

        self::assertSame('1 outdated', self::vitals($card)['Flex recipes']);
    }

    public function testFpmWorkersOnlyShowUnderFpm(): void
    {
        $php = Snapshots::php();
        unset($php['fpm']);
        $php['sapi_name'] = 'apache2handler';

        self::assertSame(['OPcache memory', 'OPcache hit rate'], array_keys(self::vitals($this->card(Snapshots::of(Component::Php, ['php' => $php])))));
    }

    public function testFlexRecipesAreLeftOutWhenTurnedOff(): void
    {
        self::assertSame(['Environment', 'Debug'], array_keys(self::vitals($this->card(Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()])))));
    }

    public function testTheFrankenPhpCardShowsItsThreadsAndQueue(): void
    {
        $card = $this->card(Snapshots::of(Component::FrankenPhp, [
            'frankenphp' => WebServerPayloads::frankenphpClassic(),
            'frankenphp.version' => ['version' => '1.13.0'],
        ]), 'stack/frankenphp');

        self::assertSame('frankenphp', $card->id);
        self::assertSame('FrankenPHP', $card->name);
        self::assertSame('1.13.0', $card->version);
        self::assertEquals(new Mark('ok', 'Healthy'), $card->mark);
        self::assertSame(['Threads' => '3 / 16', 'Queue' => '0'], self::vitals($card));
        self::assertSame('Classic mode', $card->note);
        self::assertSame([], $card->badges);
        self::assertSame('stack/frankenphp', $card->path);
        self::assertNull($card->reason);

        $queued = ['queue_depth' => 3] + WebServerPayloads::frankenphpClassic();
        $card = $this->card(Snapshots::of(Component::FrankenPhp, ['frankenphp' => $queued], ['frankenphp.version' => ResultState::Pending]));

        self::assertNull($card->version);
        self::assertSame(['Threads' => '3 / 16', 'Queue' => '3'], self::vitals($card));
        self::assertEquals(new Mark('warn', 'Warning'), $card->mark);
    }

    public function testInWorkerModeTheCardCountsTheRegularThreads(): void
    {
        $card = $this->card(Snapshots::of(Component::FrankenPhp, ['frankenphp' => WebServerPayloads::frankenphpWorker()]));

        self::assertSame(['Threads' => '1 / 4', 'Queue' => '0'], self::vitals($card));
        self::assertSame('Worker mode', $card->note);
    }

    public function testTheApacheCardShowsItsWorkersAndRequests(): void
    {
        $card = $this->card(Snapshots::of(Component::Apache, ['apache' => WebServerPayloads::apache(), 'eol.apache-http-server' => WebServerPayloads::cycles()]), 'stack/apache');

        self::assertSame('apache', $card->id);
        self::assertSame('Apache', $card->name);
        self::assertSame('2.4.62', $card->version);
        self::assertEquals(new Mark('ok', 'Healthy'), $card->mark);
        self::assertEquals([new Vital('Workers', '3 / 50'), new Vital('Requests', '—', 'at the next refresh')], array_map(self::readable(...), $card->vitals));
        self::assertSame([], $card->badges);
        self::assertNull($card->note);

        $delta = Snapshots::delta(['total_accesses' => 51_880], ['total_accesses' => 52_000]);
        $card = $this->card(Snapshots::of(Component::Apache, ['apache' => WebServerPayloads::apache()], deltas: ['apache' => $delta]));

        self::assertEquals([new Vital('Workers', '3 / 50'), new Vital('Requests', '2.0 req/s', 'over the last 60' . self::NBSP . 's')], array_map(self::readable(...), $card->vitals));
    }

    public function testAnApacheWithoutStatusPageStillShowsItsVersionAndItsSupport(): void
    {
        $card = $this->card(Snapshots::of(Component::Apache, [
            'apache.software' => ['version' => '1.26.2'],
            'eol.apache-http-server' => WebServerPayloads::cycles(),
        ], ['apache' => ResultState::Failed]), 'stack/apache');

        self::assertSame('1.26.2', $card->version);
        self::assertSame('Apache', $card->name);
        self::assertSame(Snapshots::FAILURE, $card->reason);
        self::assertEquals([new Mark('bad', 'End of life')], $card->badges);
        self::assertSame([], $card->vitals);
    }

    public function testTheNginxCardShowsItsConnectionsAndRequestsWithItsEndOfLife(): void
    {
        $card = $this->card(Snapshots::of(Component::Nginx, [
            'nginx' => WebServerPayloads::nginxStatus(),
            'nginx.config' => WebServerPayloads::nginxConfig(),
            'eol.nginx' => WebServerPayloads::cycles(),
        ]), 'stack/nginx');

        self::assertSame('nginx', $card->id);
        self::assertSame('Nginx', $card->name);
        self::assertSame('1.26.2', $card->version);
        self::assertEquals(new Mark('bad', 'Critical'), $card->mark);
        self::assertEquals([new Mark('bad', 'End of life')], $card->badges);
        self::assertEquals([new Vital('Connections', '12 / 3072'), new Vital('Requests', '—', 'at the next refresh')], array_map(self::readable(...), $card->vitals));

        $delta = Snapshots::delta(WebServerPayloads::nginxStatus(requests: 41_080), WebServerPayloads::nginxStatus());
        $card = $this->card(Snapshots::of(Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus(), 'nginx.config' => WebServerPayloads::nginxConfig()], deltas: ['nginx' => $delta]));

        self::assertEquals([new Vital('Connections', '12 / 3072'), new Vital('Requests', '2.0 req/s', 'over the last 60' . self::NBSP . 's')], array_map(self::readable(...), $card->vitals));
        self::assertSame([], $card->badges);
    }

    public function testWithoutAKnownLimitTheCardShowsTheConnectionsAlone(): void
    {
        $card = $this->card(Snapshots::of(Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus(), 'nginx.config' => WebServerPayloads::nginxConfigWithoutSettings()]));

        self::assertSame(['Connections' => '12', 'Requests' => '—'], self::vitals($card));

        $card = $this->card(Snapshots::of(Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus()], ['nginx.config' => ResultState::Failed]));

        self::assertSame(['Connections' => '12', 'Requests' => '—'], self::vitals($card));
        self::assertNull($card->version);
    }

    /**
     * @return iterable<string, array{Component, array<string, array<string, mixed>>}>
     */
    public static function webServers(): iterable
    {
        yield 'FrankenPHP' => [Component::FrankenPhp, ['frankenphp.version' => ['version' => '1.13.0']]];
        yield 'Apache' => [Component::Apache, []];
        yield 'Nginx' => [Component::Nginx, ['nginx.config' => WebServerPayloads::nginxConfig()]];
    }

    /**
     * @param array<string, array<string, mixed>> $others
     */
    #[DataProvider('webServers')]
    public function testACardOfAnUnreadableStatusPageGivesItsReason(Component $component, array $others): void
    {
        $card = $this->card(Snapshots::of($component, $others, [$component->value => ResultState::Failed]), 'stack/' . $component->value);

        self::assertSame(Snapshots::FAILURE, $card->reason);
        self::assertEquals(new Mark('unknown', 'Unknown'), $card->mark);
        self::assertSame([], $card->vitals);
        self::assertNull($card->version);
        self::assertFalse($card->reading);
        self::assertSame('stack/' . $component->value, $card->path);
    }

    #[DataProvider('webServers')]
    public function testAWebServerCardSaysReadingUntilItsStatusPageWasRead(Component $component, array $others): void
    {
        $card = $this->card(Snapshots::of($component, $others, [$component->value => ResultState::Pending]));

        self::assertTrue($card->reading);
        self::assertSame(['Reading…', 'Reading…'], array_values(self::vitals($card)));
        self::assertSame([null, null], array_map(static fn(Vital $vital): ?string => $vital->hint, $card->vitals));
    }

    public function testAnInstanceWithAnIdIsNamedAfterIt(): void
    {
        $card = $this->cardOf(Component::Php, new InstanceState('www', null, null, Snapshots::of(Component::Php, ['php' => Snapshots::php()])));

        self::assertSame('php.www', $card->id);
        self::assertSame('PHP · www', $card->name);
    }

    public function testTheMysqlCardNamesItsProductAndItsFigures(): void
    {
        $variables = ['version' => '11.4.10-MariaDB-ubu2404', 'version_comment' => 'mariadb.org binary distribution'] + DatabasePayloads::mysqlVariables();
        $previous = ['Questions' => '550', 'Innodb_buffer_pool_read_requests' => '100000', 'Innodb_buffer_pool_reads' => '1000'];
        $snapshot = Snapshots::of(Component::Mysql, [
            'mysql' => DatabasePayloads::mysqlStatus(),
            'mysql.variables' => $variables,
            'eol.mariadb' => ['cycles' => ['11.4' => ['eol' => '2029-05-29', 'support' => '2026-01-01']]],
        ], deltas: ['mysql' => Snapshots::delta($previous, DatabasePayloads::mysqlStatus())]);

        $card = $this->cardOf(Component::Mysql, new InstanceState('main', 'shop', null, $snapshot, new Server('db1:3306', 'db1:3306'), 1), 'stack/mysql');

        self::assertSame('mysql.main', $card->id);
        self::assertSame('MySQL · main', $card->name);
        self::assertSame('MariaDB 11.4.10', $card->version);
        self::assertEquals([new Mark('warn', 'Security fixes only')], $card->badges);
        self::assertEquals(new Mark('warn', 'Warning'), $card->mark);
        self::assertEquals([
            new Vital('Connections', '30 / 100'),
            new Vital('Queries', '10.0 q/s', 'over the last 60' . self::NBSP . 's'),
            new Vital('Buffer pool hit rate', '99 %', 'over the last 60' . self::NBSP . 's'),
        ], array_map(self::readable(...), $card->vitals));
        self::assertSame('db1:3306 · 2 connections', $card->note);
        self::assertSame(['connection' => 'main'], $card->query);

        $alone = $this->cardOf(Component::Mysql, new InstanceState('main', 'shop', null, Snapshots::of(Component::Mysql, ['mysql' => DatabasePayloads::mysqlStatus()]), new Server('db1:3306', 'db1:3306')));

        self::assertSame('db1:3306', $alone->note);
        self::assertNull($alone->version);
        self::assertSame(['Connections' => '30', 'Queries' => '—', 'Buffer pool hit rate' => '99 %'], self::vitals($alone));
        self::assertSame('since the server started', $alone->vitals[2]->hint);
    }

    public function testAnUnreachableMysqlCardGivesTheReasonAndIsCritical(): void
    {
        $snapshot = new ComponentSnapshot(Component::Mysql, [
            'mysql' => new CollectorResult('mysql', [], ResultState::Failed, 'SQLSTATE[HY000] [2002] Connection refused', expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
            'mysql.variables' => new CollectorResult('mysql.variables', DatabasePayloads::mysqlVariables(), ResultState::Stale, 'SQLSTATE[HY000] [2002] Connection refused', Snapshots::NOW - 600, expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
        ]);

        $card = $this->cardOf(Component::Mysql, new InstanceState('main', 'shop', null, $snapshot, new Server('db1:3306', 'db1:3306')), 'stack/mysql');

        self::assertSame('SQLSTATE[HY000] [2002] Connection refused', $card->reason);
        self::assertEquals(new Mark('bad', 'Critical'), $card->mark);
        self::assertFalse($card->reading);
        self::assertSame('MySQL 8.4.0', $card->version);
        self::assertSame('db1:3306', $card->note);
        self::assertNull($card->readAt);
    }

    public function testAMysqlCardWaitsForItsFirstReading(): void
    {
        $snapshot = Snapshots::of(Component::Mysql, [], ['mysql' => ResultState::Pending, 'mysql.variables' => ResultState::Pending]);

        $card = $this->cardOf(Component::Mysql, new InstanceState('main', 'shop', null, $snapshot, new Server('db1:3306', 'db1:3306')), 'stack/mysql');

        self::assertTrue($card->reading);
        self::assertNull($card->reason);
        self::assertSame(['Connections' => 'Reading…', 'Queries' => 'Reading…', 'Buffer pool hit rate' => 'Reading…'], self::vitals($card));
        self::assertEquals(new Mark('unknown', 'Unknown'), $card->mark);
    }

    public function testThePostgresqlCardShowsItsVersionItsThreeFiguresAndItsAddress(): void
    {
        $previous = ['database_stats' => ['xact_commit' => 124_400, 'xact_rollback' => 0, 'blks_hit' => 336_000, 'blks_read' => 3_880]];
        $snapshot = Snapshots::of(Component::Postgresql, [
            'postgresql' => DatabasePayloads::postgresqlActivity(),
            'postgresql.settings' => ['server_version' => '14.15 (Debian 14.15-1.pgdg120+1)'] + DatabasePayloads::postgresqlSettings(),
            'eol.postgresql' => ['cycles' => ['14' => ['eol' => '2026-11-12', 'support' => '2025-11-12']]],
        ], deltas: ['postgresql' => Snapshots::delta($previous, DatabasePayloads::postgresqlActivity())]);

        $card = $this->cardOf(Component::Postgresql, new InstanceState('main', 'shop', null, $snapshot, new Server('db1:5432', 'db1:5432')), 'stack/postgresql');

        self::assertSame('postgresql.main', $card->id);
        self::assertSame('PostgreSQL · main', $card->name);
        self::assertSame('PostgreSQL 14.15', $card->version);
        self::assertEquals([new Mark('warn', 'Security fixes only')], $card->badges);
        self::assertEquals([
            new Vital('Connections', '37', 'of 100'),
            new Vital('Cache hit rate', '99.8 %', 'over the last 60' . self::NBSP . 's'),
            new Vital('Transactions', '12.0/s', 'over the last 60' . self::NBSP . 's'),
        ], array_map(self::readable(...), $card->vitals));
        self::assertSame('db1:5432', $card->note);
        self::assertSame(['connection' => 'main'], $card->query);

        $alone = $this->cardOf(Component::Postgresql, new InstanceState('main', 'shop', null, Snapshots::of(Component::Postgresql, ['postgresql' => DatabasePayloads::postgresqlActivity()]), new Server('db1:5432', 'db1:5432')));

        self::assertNull($alone->version);
        self::assertSame([], $alone->badges);
        self::assertSame(['Connections' => '37', 'Cache hit rate' => '99 %', 'Transactions' => '—'], self::vitals($alone));
        self::assertNull($alone->vitals[0]->hint, 'no limit read');
        self::assertSame('since the statistics were reset', $alone->vitals[1]->hint);
        self::assertSame('at the next refresh', $alone->vitals[2]->hint);
    }

    public function testAnUnreachablePostgresqlKeepsItsVersionAndSaysWhy(): void
    {
        $reason = 'Nothing answers on db1:5432.';
        $snapshot = new ComponentSnapshot(Component::Postgresql, [
            'postgresql' => new CollectorResult('postgresql', [], ResultState::Failed, $reason, expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
            'postgresql.settings' => new CollectorResult('postgresql.settings', ['server_version' => '13.18'] + DatabasePayloads::postgresqlSettings(), ResultState::Stale, $reason, Snapshots::NOW - 600, expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
            'eol.postgresql' => new CollectorResult('eol.postgresql', ['cycles' => ['13' => ['eol' => '2025-11-13', 'support' => '2025-11-13']]], ResultState::Fresh, collectedAt: Snapshots::NOW, expiresAt: Snapshots::NOW + 86_400),
        ]);

        $card = $this->cardOf(Component::Postgresql, new InstanceState('main', 'shop', null, $snapshot, new Server('db1:5432', 'db1:5432')), 'stack/postgresql');

        self::assertSame($reason, $card->reason);
        self::assertEquals(new Mark('bad', 'Critical'), $card->mark);
        self::assertFalse($card->reading);
        self::assertSame('PostgreSQL 13.18', $card->version);
        self::assertEquals([new Mark('bad', 'End of life')], $card->badges);
        self::assertSame([], $card->vitals);
        self::assertSame('db1:5432', $card->note);
        self::assertNull($card->readAt);

        $never = new ComponentSnapshot(Component::Postgresql, ['postgresql' => $snapshot->results['postgresql']]);
        $card = $this->cardOf(Component::Postgresql, new InstanceState('main', 'shop', null, $never, new Server('db1:5432', 'db1:5432')));

        self::assertSame($reason, $card->reason);
        self::assertNull($card->version);
    }

    public function testAPostgresqlCardWaitsForItsFirstReading(): void
    {
        $snapshot = Snapshots::of(Component::Postgresql, [], ['postgresql' => ResultState::Pending, 'postgresql.settings' => ResultState::Pending]);

        $card = $this->cardOf(Component::Postgresql, new InstanceState('main', 'shop', null, $snapshot, new Server('db1:5432', 'db1:5432')), 'stack/postgresql');

        self::assertTrue($card->reading);
        self::assertSame(['Connections' => 'Reading…', 'Cache hit rate' => 'Reading…', 'Transactions' => 'Reading…'], self::vitals($card));
    }

    public function testTheRedisCardShowsItsVersionItsThreeFiguresAndItsAddress(): void
    {
        $kept = RedisPayloads::kept();
        $kept['memory']['max_memory'] = 2_147_483_648;
        $previous = ['stats' => ['total_commands_processed' => 840_000, 'keyspace_hits' => 8_950, 'keyspace_misses' => 1_000]];
        $snapshot = Snapshots::of(Component::Redis, ['redis' => $kept, 'eol.redis' => RedisPayloads::redisCycles()], deltas: ['redis' => Snapshots::delta($previous, $kept)]);

        $card = $this->cardOf(Component::Redis, new InstanceState('main', null, null, $snapshot, new Server('cache:6379', 'cache:6379')), 'stack/redis');

        self::assertSame('redis.main', $card->id);
        self::assertSame('Redis · main', $card->name);
        self::assertSame('Redis 7.2.4', $card->version);
        self::assertEquals([new Mark('warn', 'Security fixes only')], $card->badges);
        self::assertEquals([
            new Vital('Memory', '1.4 GB', 'of 2' . self::NBSP . 'GB'),
            new Vital('Hit rate', '95.2 %', 'over the last 60' . self::NBSP . 's'),
            new Vital('Commands', '1,000/s', 'over the last 60' . self::NBSP . 's'),
        ], array_map(self::readable(...), $card->vitals));
        self::assertSame('cache:6379', $card->note);
        self::assertSame(['connection' => 'main'], $card->query);

        $alone = $this->cardOf(Component::Redis, new InstanceState('main', null, null, Snapshots::of(Component::Redis, ['redis' => $kept]), new Server('cache:6379', 'cache:6379')));

        self::assertSame(['Memory' => '1.4 GB', 'Hit rate' => '90.4 %', 'Commands' => '—'], self::vitals($alone));
        self::assertSame([], $alone->badges);
        self::assertSame('since the server started', $alone->vitals[1]->hint);
        self::assertSame('at the next refresh', $alone->vitals[2]->hint);
    }

    public function testARedisWithoutLimitShowsItsMemoryAlone(): void
    {
        $card = $this->cardOf(Component::Redis, new InstanceState('main', null, null, Snapshots::of(Component::Redis, ['redis' => RedisPayloads::kept()]), new Server('cache:6379', 'cache:6379')));

        self::assertSame('1.4 GB', self::vitals($card)['Memory']);
        self::assertNull($card->vitals[0]->hint);
    }

    /**
     * A column of a card writes about nine figures on one line: no value of these cards is longer than its widest case.
     */
    public function testTheFiguresOfADatabaseOrRedisCardHoldOnOneLine(): void
    {
        $activity = DatabasePayloads::postgresqlActivity();
        $activity['server_connections'] = 1_024;
        $previous = ['database_stats' => ['xact_commit' => $activity['database_stats']['xact_commit'] - 74_040, 'xact_rollback' => $activity['database_stats']['xact_rollback'], 'blks_hit' => 0, 'blks_read' => $activity['database_stats']['blks_read']]];
        $postgresql = Snapshots::of(Component::Postgresql, [
            'postgresql' => $activity,
            'postgresql.settings' => ['max_connections' => '1024'] + DatabasePayloads::postgresqlSettings(),
        ], deltas: ['postgresql' => Snapshots::delta($previous, $activity)]);

        $kept = RedisPayloads::kept();
        $kept['memory'] = ['used' => 1_072_693_248, 'max_memory' => 1_072_693_248] + $kept['memory'];
        $kept['stats'] = ['total_commands_processed' => 8_000_000, 'keyspace_hits' => 10_000, 'keyspace_misses' => 0] + $kept['stats'];
        $redis = Snapshots::of(Component::Redis, ['redis' => $kept], deltas: ['redis' => Snapshots::delta(['stats' => ['total_commands_processed' => 592_640, 'keyspace_hits' => 0, 'keyspace_misses' => 0]], $kept)]);

        $postgresqlCard = $this->cardOf(Component::Postgresql, new InstanceState('main', 'shop', null, $postgresql, new Server('db1:5432', 'db1:5432')));
        $redisCard = $this->cardOf(Component::Redis, new InstanceState('main', null, null, $redis, new Server('cache:6379', 'cache:6379')));

        self::assertEquals([
            new Vital('Connections', '1,024', 'of 1,024'),
            new Vital('Cache hit rate', '100 %', 'over the last 60' . self::NBSP . 's'),
            new Vital('Transactions', '1,234/s', 'over the last 60' . self::NBSP . 's'),
        ], array_map(self::readable(...), $postgresqlCard->vitals));
        self::assertEquals([
            new Vital('Memory', '1,023 MB', 'of 1,023' . self::NBSP . 'MB'),
            new Vital('Hit rate', '100 %', 'over the last 60' . self::NBSP . 's'),
            new Vital('Commands', '123,456/s', 'over the last 60' . self::NBSP . 's'),
        ], array_map(self::readable(...), $redisCard->vitals));
    }

    public function testAFoundRedisDoesNotRepeatItsAddress(): void
    {
        $card = $this->cardOf(Component::Redis, new InstanceState('cache:6379', null, null, Snapshots::of(Component::Redis, ['redis' => RedisPayloads::kept()]), new Server('cache:6379', 'cache:6379')), 'stack/redis');

        self::assertSame('Redis · cache:6379', $card->name, 'a server found takes its address as its id');
        self::assertNull($card->note);
        self::assertSame(['connection' => 'cache:6379'], $card->query);
    }

    public function testAValkeyCardTellsItsOwnVersion(): void
    {
        $kept = RedisPayloads::kept();
        $kept['server']['name'] = 'valkey';
        $kept['server']['valkey_version'] = '7.2.5';
        $snapshot = Snapshots::of(Component::Redis, ['redis' => $kept, 'eol.redis' => RedisPayloads::redisCycles(), 'eol.valkey' => RedisPayloads::valkeyCycles()]);

        $card = $this->cardOf(Component::Redis, new InstanceState('main', null, null, $snapshot, new Server('cache:6379', 'cache:6379')));

        self::assertSame('Valkey 7.2.5', $card->version);
        self::assertEquals([new Mark('bad', 'End of life')], $card->badges);
    }

    public function testAnUnreachableRedisSaysWhy(): void
    {
        $reason = 'The connection is refused, cannot be opened, or gets no answer in time.';
        $snapshot = new ComponentSnapshot(Component::Redis, [
            'redis' => new CollectorResult('redis', [], ResultState::Failed, $reason, expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
        ]);

        $card = $this->cardOf(Component::Redis, new InstanceState('main', null, null, $snapshot, new Server('cache:6379', 'cache:6379')), 'stack/redis');

        self::assertSame($reason, $card->reason);
        self::assertEquals(new Mark('bad', 'Critical'), $card->mark);
        self::assertNull($card->version);
        self::assertSame([], $card->vitals);
        self::assertSame('cache:6379', $card->note);
    }

    public function testARedisCardWaitsForItsFirstReading(): void
    {
        $snapshot = Snapshots::of(Component::Redis, [], ['redis' => ResultState::Pending]);

        $card = $this->cardOf(Component::Redis, new InstanceState('main', null, null, $snapshot, new Server('cache:6379', 'cache:6379')), 'stack/redis');

        self::assertTrue($card->reading);
        self::assertSame(['Memory' => 'Reading…', 'Hit rate' => 'Reading…', 'Commands' => 'Reading…'], self::vitals($card));
    }

    private function card(ComponentSnapshot $snapshot, ?string $path = null): ComponentCard
    {
        return $this->cardOf($snapshot->component, new InstanceState(null, null, null, $snapshot), $path);
    }

    private function cardOf(Component $component, InstanceState $instance, ?string $path = null, bool $reading = true): ComponentCard
    {
        $clock = new MockClock('@' . Snapshots::NOW);
        $health = (new StackChecks([], false, $clock))->health(Snapshots::state([$component->value => [$instance]]));

        return (new CardBuilder($clock))->card($component, $instance, $health, $path, $reading);
    }

    /**
     * @return array<string, string> the values with ordinary spaces
     */
    private static function vitals(ComponentCard $card): array
    {
        $vitals = [];

        foreach ($card->vitals as $vital) {
            $vitals[$vital->label] = self::readable($vital)->value;
        }

        return $vitals;
    }

    private static function readable(Vital $vital): Vital
    {
        self::assertStringNotContainsString(' ', $vital->value, $vital->label . ': a short value never breaks');

        return new Vital($vital->label, str_replace(self::NBSP, ' ', $vital->value), $vital->hint);
    }
}
