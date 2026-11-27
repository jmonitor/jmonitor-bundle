<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\Embed;

use Jmonitor\JmonitorBundle\Dashboard\BlockScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\NavBadge;
use Jmonitor\JmonitorBundle\Dashboard\NavBadgeScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\View\Alert;
use Jmonitor\JmonitorBundle\Dashboard\View\Alerts;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCard;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCards;
use Jmonitor\JmonitorBundle\Dashboard\View\Fact;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauge;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauges;
use Jmonitor\JmonitorBundle\Dashboard\View\Guide;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\InstanceNav;
use Jmonitor\JmonitorBundle\Dashboard\View\InstanceNavItem;
use Jmonitor\JmonitorBundle\Dashboard\View\InstanceNavRow;
use Jmonitor\JmonitorBundle\Dashboard\View\Lead;
use Jmonitor\JmonitorBundle\Dashboard\View\Map;
use Jmonitor\JmonitorBundle\Dashboard\View\MapBox;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\Place;
use Jmonitor\JmonitorBundle\Dashboard\View\Places;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\Section;
use Jmonitor\JmonitorBundle\Dashboard\View\StackGlance;
use Jmonitor\JmonitorBundle\Dashboard\View\Status;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Dashboard\View\Vital;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Every block of the live stack in every state, the blocks that guide the reading of a screen, a status in each tone and
 * cards of checks worth a look, cards that lead to the sections below, a section in each of its states, then the map of an application and what to find where. Each request
 * moves a counter kept in a file: the badge shows it, and its tone and the web server named by the second navigation
 * alternate with its parity.
 */
final class GalleryScreen implements BlockScreenInterface, NavBadgeScreenInterface, ResetInterface
{
    private const HOST = 'redis-primary-0.redis-headless.monitoring.svc.cluster.local:6379';
    private const LONG = 'A sentence long enough to wrap on a narrow screen, so that the layout shows how it breaks: the cache of compiled scripts is nearly full and new scripts are compiled on every request.';

    private ?int $requests = null;

    public function __construct(private readonly string $counterFile) {}

    public function id(): string
    {
        return 'gallery';
    }

    public function label(): string
    {
        return 'Gallery';
    }

    public function heading(): string
    {
        return 'Every block of the live stack in every state';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function space(): Space
    {
        return Space::Application;
    }

    public function navBadge(): ?NavBadge
    {
        $requests = $this->requests();

        return $requests % 2 === 1 ? NavBadge::of(0, $requests, 'seen by the gallery') : NavBadge::of($requests, 0, 'seen by the gallery');
    }

    public function blocks(ScreenRequest $request): array
    {
        $healthy = new Mark('ok', 'Healthy');
        $warning = new Mark('warn', 'Warning');
        $critical = new Mark('bad', 'Critical');
        $unknown = new Mark('unknown', 'Unknown');
        $info = new Mark('info', 'Info');

        return [
            Block::subnav(new SubNav('Screens', [
                new SubNavItem('Requests', 'gallery', true, badge: NavBadge::of(2, 5, 'in the last 6 hours'), icon: 'requests'),
                new SubNavItem('Queries', 'gallery', false, badge: NavBadge::of(0, 1, 'in the last 6 hours'), icon: 'queries'),
                new SubNavItem('Cache & HTTP', 'gallery', icon: 'cache-http'),
                new SubNavItem('Exceptions', 'gallery', false, badge: NavBadge::of(3, 0, 'in the last 6 hours'), icon: 'exceptions'),
                new SubNavItem('Users', 'gallery', icon: 'users'),
                new SubNavItem('Background', 'gallery', icon: 'background'),
            ])),
            Block::subnav(new SubNav('Stack', [
                new SubNavItem('All components', 'gallery', icon: 'stack'),
                new SubNavItem('Server', 'gallery', icon: 'system'),
                new SubNavItem('PHP', 'gallery', true, $warning, icon: 'php'),
                new SubNavItem('Symfony', 'gallery', false, $critical, icon: 'symfony'),
                new SubNavItem('FrankenPHP', 'gallery', icon: 'frankenphp'),
                new SubNavItem('Apache', 'gallery', false, $unknown, icon: 'apache'),
                new SubNavItem('Nginx', 'gallery', icon: 'nginx'),
                new SubNavItem('MySQL', 'gallery', false, $critical, icon: 'mysql'),
                new SubNavItem('PostgreSQL', 'gallery', false, $warning, icon: 'postgresql'),
                new SubNavItem('Redis', 'gallery', icon: 'redis'),
                new SubNavItem('Memcached', 'gallery', icon: 'memcached'),
            ], $this->requests() % 2 === 1 ? 'web-1' : 'web-2')),
            Block::instances(new InstanceNav([
                new InstanceNavRow('Servers', [
                    new InstanceNavItem('default', 'gallery', ['connection' => 'default'], true, 'db-primary.internal:3306'),
                    new InstanceNavItem('analytics', 'gallery', ['connection' => 'analytics'], false, 'a-very-long-host-name-that-does-not-fit.internal.example:3306'),
                    new InstanceNavItem('legacy', 'gallery', ['connection' => 'legacy'], false, null, new Mark('warn', 'Unavailable')),
                ]),
                new InstanceNavRow('Databases', [
                    new InstanceNavItem('default', 'gallery', ['connection' => 'default'], true, 'shop', $healthy),
                    new InstanceNavItem('reporting_read_replica_with_a_long_name', 'gallery', ['connection' => 'reporting_read_replica_with_a_long_name'], false, 'reporting_warehouse_2026'),
                ]),
            ])),
            Block::notice(new Notice('bad', 'The stack could not be read', 'Cannot reach redis://cache:6379: Connection refused')),
            Block::notice(new Notice('warn', 'The live stack needs a working cache', 'Only what PHP can read in its own process is shown. Check the cache.app pool of the application.')),
            Block::notice(new Notice('info', 'Some figures are still being read from ' . self::HOST)),
            Block::checks(new CheckList([
                new CheckLine('php.opcache_memory', $critical, 'OPcache memory is full', self::LONG, 'PHP', 'gallery', '12 s ago'),
                new CheckLine('symfony.debug', $warning, 'Debug mode is on in production', 'The debug mode slows every request down and shows internals to visitors. Set APP_DEBUG=0.', 'Symfony', 'gallery'),
                new CheckLine('server.load', new Mark('info', 'Info'), self::LONG, 'Inside a container, the load is the one of the host node.', 'Server · a-very-long-host-name-that-does-not-fit.internal.example'),
            ])),
            Block::checks(new CheckList([], 'Nothing to check', 'No check was raised at the last reading.')),
            Block::components(new ComponentCards([
                new ComponentCard('server', 'Server', $healthy, [new Vital('Load', '0.42'), new Vital('Memory', '61 %'), new Vital('Disk', '48 %')], 'Ubuntu 24.04 LTS', 'gallery', readAt: 1800000000, note: 'Load is the one of the host node'),
                new ComponentCard('php', 'PHP', $warning, [new Vital('OPcache memory', '92 %'), new Vital('OPcache hit rate', '—', 'available in a few seconds'), new Vital('FPM workers', '3 / 8')], '8.2.29', 'gallery', badges: [new Mark('warn', 'Security fixes only')], readAt: 1800000000),
                new ComponentCard('symfony', 'Symfony', $unknown, [new Vital('Environment', 'prod'), new Vital('Debug', 'off'), new Vital('Flex recipes', '3 outdated')], '7.4.0', 'gallery', reading: true),
                new ComponentCard('mysql', 'MySQL', $critical, [new Vital('Connections', '98 %')], '8.0.36', reason: 'SQLSTATE[HY000] [2002] Connection refused while reading the status of the server at a-very-long-host-name.internal.example:3306'),
                new ComponentCard('redis', 'Redis', $info, [new Vital('Memory', '12 %'), new Vital('Host', self::HOST)], badges: [new Mark('bad', 'End of life')]),
            ], ['Nginx: SERVER_SOFTWARE does not name it', 'PostgreSQL: no Doctrine connection uses it', 'Memcached: ' . self::HOST . ' did not answer'], 'web-1', '2 more connections'))->withReading(),
            Block::gauges(new Gauges('Memory', [
                new Gauge('OPcache memory', 92.4, '92 % of 128 MB', $critical, 'Wasted memory counts as used'),
                new Gauge('Interned strings', 71.0, '71 % of 8 MB', $warning),
                new Gauge('APCu', 12.5, '12 % of 32 MB', $healthy),
                new Gauge('Shared memory', 40.0, '40 % of ' . self::HOST),
                new Gauge('Connections beyond the maximum', 130.0, '130 / 100', $critical),
                new Gauge('Swap', null, ''),
                new Gauge('Realpath cache, a label long enough to wrap on a narrow screen', null, 'unlimited'),
            ])),
            Block::facts(new Facts('Runtime', [
                new Fact('Version', '8.2.29', new Mark('warn', 'Security fixes only'), 'Security support ends on 31 Dec 2026'),
                new Fact('SAPI', 'fpm-fcgi'),
                new Fact('Session handler', self::HOST, hint: self::HOST),
                new Fact('memory_limit', '512M'),
                new Fact('Loaded extensions', 'Core, date, libxml, openssl, pcre, sqlite3, zlib, ctype, curl, dom, fileinfo, filter, hash, iconv, json, mbstring, SPL, PDO, pdo_mysql, session, posix, Reflection, standard, SimpleXML, Phar, tokenizer, xml, xmlreader, xmlwriter, mysqlnd, apcu, intl, opcache'),
                new Fact('OPcache', 'off', $critical),
            ])),
            Block::facts(new Facts('Queues', [
                new Fact('async', '1,234'),
                new Fact('failed', 'Uncountable', hint: 'An exception occurred in the driver: SQLSTATE[HY000] [2002] Connection refused while counting the messages waiting in doctrine://default?queue_name=failed'),
            ], "Messages waiting in each transport, counted 12\u{00A0}s ago")),
            Block::status(new Status('Overview', new Mark('ok', 'Answers normally'))),
            Block::status(new Status('Overview', new Mark('bad', '12 server errors'))),
            Block::status(new Status('Overview', new Mark('warn', '1 slow request'))),
            Block::status(new Status('Overview', new Mark('unknown', 'No data yet'))),
            Block::status(new Status('Overview')),
            Block::alerts(new Alerts([
                new Alert('requests.slow', $warning, '1 slow request', 'Requests', 'gallery', ['period' => '1h']),
            ])),
            Block::alerts(new Alerts([
                new Alert('requests.server_errors', $critical, '12 server errors', 'Requests', 'gallery', ['period' => '1h']),
                new Alert('messages.failed', $critical, '3 messages failed', 'Background', 'missing', ['period' => '1h']),
                new Alert('stack.redis.memory', $critical, self::LONG, 'Redis', 'gallery', ['instance' => 'cache']),
                new Alert('queries.repeated', $warning, 'Repeated queries (N+1)', 'Queries', 'gallery', ['period' => '1h']),
            ])),
            Block::alerts(new Alerts([
                new Alert('requests.server_errors', $critical, '1,234 server errors', 'Requests', 'gallery', ['period' => '1h']),
                new Alert('messages.failed', $critical, '1 message failed', 'Background', 'gallery', ['period' => '1h']),
                new Alert('tasks.failed', $critical, '2 scheduled runs failed', 'Background', 'gallery', ['period' => '1h']),
                new Alert('commands.failed', $critical, '1 command run failed', 'Background', 'gallery', ['period' => '1h']),
                new Alert('http.failures', $critical, '4 calls without an answer', 'Cache & HTTP', 'gallery', ['period' => '1h']),
                new Alert('requests.slow', $warning, '38 slow requests', 'Requests', 'gallery', ['period' => '1h']),
                new Alert('queries.slow', $warning, '1 slow query', 'Queries', 'gallery', ['period' => '1h']),
                new Alert('queries.repeated', $warning, 'Repeated queries (N+1)', 'Queries', 'gallery', ['period' => '1h']),
                new Alert('cache.low_hit_rate', $warning, 'Low cache hit rate', 'Cache & HTTP', 'gallery', ['period' => '1h']),
                new Alert('http.errors', $warning, '7 error responses', 'Cache & HTTP', 'gallery', ['period' => '1h']),
            ])),
            Block::lead(new Lead('1,284 requests in the last hour, 3 of them ended in a server error', 'The busiest minute served 64 requests.', [12, 18, 15, 22, 40, 64, 31, 20, 18, 25, 19, 16], 'requests per minute', '1 hour ago')),
            Block::lead(new Lead('Nothing ran slower than 100 ms', self::LONG)),
            Block::checks(new CheckList([
                new CheckLine('queries.slow', $critical, 'One query took 1.4 s on average', 'A slow query holds its request for as long as it runs, and every request that runs it waits as long.', 'GET app_checkout', 'gallery', title: 'Slow query', action: 'Look at its plan with EXPLAIN, then add the index it misses or read fewer rows.', code: 'SELECT o.id, o.total, c.email FROM orders o INNER JOIN customers c ON c.id = o.customer_id WHERE o.created_at > ? AND c.country = ? ORDER BY o.created_at DESC'),
                new CheckLine('queries.repeated', $warning, 'One query ran 48 times in a single request', self::LONG, '', title: 'Repeated query', action: 'Load the related rows in one query, with a join or a WHERE … IN.'),
            ], title: 'Worth a look')),
            Block::checks(new CheckList([], 'Nothing to flag', 'No problem was seen in the last hour.', 'Worth a look')),
            Block::table(new Table('gallery-routes', 'Routes', [
                new Column('route', 'Route', false),
                new Column('total', 'Total time'),
                new Column('split', 'Time split', false),
                new Column('problems', 'Problems', false),
            ], [
                new Row('checkout', ['route' => Cell::route('GET app_checkout'), 'total' => Cell::bar('1.8 s', 1.0), 'split' => Cell::split(['php' => 77.0, 'sql' => 10.0, 'http' => 57.0], '77 · 10 · 57 ms'), 'problems' => Cell::tags([new Tag('N+1', 'warn'), new Tag('3 × 5xx', 'bad')])]),
                new Row('login', ['route' => Cell::route('POST app_login'), 'total' => Cell::bar('640 ms', 0.36), 'split' => Cell::split(['php' => 120.0, 'sql' => 0.0, 'http' => 0.0], 'PHP only'), 'problems' => Cell::tags([])]),
                new Row('search', ['route' => Cell::route('GET app_search_with_a_route_name_long_enough_to_wrap'), 'total' => Cell::bar('12 ms', 0.007), 'split' => Cell::split(['php' => 3.0, 'sql' => 40.0, 'http' => 0.0], '3 · 40 ms, a text long enough to be cut'), 'problems' => Cell::tags([new Tag('slow', 'warn')])]),
                new Row('other', ['route' => new Cell('Other routes'), 'total' => Cell::bar('90 ms', 0.05), 'split' => Cell::split(['php' => 0.0, 'sql' => 0.0, 'http' => 0.0], '—'), 'problems' => Cell::tags([])], muted: true),
            ], 12, 'route', partsLegend: true, footnote: 'Averages per request. The PHP part is what is left once SQL and outgoing HTTP calls are taken away.')),
            Block::table(new Table('gallery-nested', 'Exceptions', [
                new Column('exception', 'Exception', false),
                new Column('count', 'Count'),
                new Column('last', 'Last seen'),
            ], [
                new Row('runtime', ['exception' => new Cell('RuntimeException', kind: 'code'), 'count' => new Cell('12'), 'last' => new Cell('2 min ago')]),
                new Row('runtime-checkout', ['exception' => Cell::route('GET app_checkout'), 'count' => new Cell('9'), 'last' => new Cell('2 min ago')], nested: true),
                new Row('runtime-import', ['exception' => new Cell('app:import', kind: 'code'), 'count' => new Cell('3'), 'last' => new Cell('1 h ago')], nested: true),
                new Row('logic', ['exception' => new Cell('LogicException', kind: 'code'), 'count' => new Cell('1'), 'last' => new Cell('40 min ago')]),
            ], noun: 'exception')),
            Block::guide(new Guide([
                new GuideItem('Total time', 'adds up the time of every request of the route: a route called often can weigh more than a slow one.'),
                new GuideItem('Time split', 'shows where an average request spends its time: your PHP code, SQL queries, outgoing HTTP calls.', ['See the gallery' => 'gallery', 'See a missing screen' => 'missing']),
                new GuideItem('Problems', self::LONG),
            ])),
            Block::cards([
                new Card('A section with figures', '1,284', 'runs, 3 failed', 'bad', anchor: 'full'),
                new Card('A section without activity', '0', 'nothing ran', anchor: 'quiet'),
                new Card('Not a link', '12', 'A card without anchor'),
            ]),
            Block::section(new Section('full', 'A section with figures', 'Cards and a table, sorted within the section.', [
                Block::cards([new Card('Runs', '1,284', 'in the last hour', series: [3, 5, 4, 8, 6]), new Card('Failed', '3', tone: 'bad'), new Card('Slowest', '4.2 s'), new Card('Commands', '12')]),
                Block::table(new Table('gallery-full', 'Commands', [
                    new Column('command', 'Command', false, 'command'),
                    new Column('runs', 'Runs', sort: 'runs'),
                    new Column('failed', 'Failed', sort: 'failed'),
                ], [
                    new Row('app:import', ['command' => new Cell('app:import', kind: 'code'), 'runs' => new Cell('1,280'), 'failed' => new Cell('3', 'bad')]),
                    new Row('app:report', ['command' => new Cell('app:report', kind: 'code'), 'runs' => new Cell('4'), 'failed' => new Cell('0', 'zero')]),
                ], noun: 'command')),
            ], 'runs', 'desc', ['runs' => 'desc', 'command' => 'asc', 'failed' => 'desc'])),
            Block::section(new Section('quiet', 'A section without activity', 'One line stands where its figures would be.', [], 'runs', 'desc', ['runs' => 'desc'], ['title' => 'Nothing ran in the last hour.', 'text' => self::LONG])),
            Block::section(new Section('counting', 'A section with a block still being read', 'Its own block fills in on a quick refresh.', [
                Block::unavailable(Block::FACTS, BlockState::Reading, 'Counting…', 'The figures of this block are being read.'),
            ], 'runs', 'desc', ['runs' => 'desc'], ['title' => 'Nothing ran in the last hour.', 'text' => ''])),
            Block::checks(new CheckList([
                new CheckLine('queries.repeated', $warning, 'One query ran 48 times in a single request', 'Each run waits for the database.', 'GET app_checkout', 'gallery', title: 'Repeated query', action: 'Load the related rows in one query.'),
                new CheckLine('php.opcache_memory', $info, 'OPcache memory is nearly full', self::LONG, 'Stack · PHP', 'gallery'),
            ], title: 'Worth a look')),
            Block::map(new Map(
                [
                    new MapBox('Requests', '1,284', [new Tag('12 × 5xx', 'bad'), new Tag('38 slow', 'warn')], 'gallery', 'full', 'bad', [12, 18, 15, 22, 40, 64, 31, 20, 18, 25, 19, 16]),
                    new MapBox('Messages', '42', [new Tag('3 failed', 'bad')], 'gallery', 'quiet', 'bad'),
                    new MapBox('Scheduled tasks', '0', path: 'gallery'),
                    new MapBox('Commands', '12', path: 'missing'),
                ],
                [
                    new MapBox('Exceptions', '1,234,567', path: 'gallery'),
                    new MapBox('User requests', '140'),
                ],
                new StackGlance([
                    new ComponentCard('server', 'Server', $healthy, [new Vital('Load', '0.42'), new Vital('Memory', '61 %'), new Vital('Disk', '48 %')], 'Ubuntu 24.04 LTS', 'gallery'),
                    new ComponentCard('php', 'PHP', $warning, [new Vital('OPcache memory', '92 %'), new Vital('OPcache hit rate', '99.8 %'), new Vital('FPM workers', '3 / 8')], '8.2.29', 'gallery'),
                    new ComponentCard('postgresql.reporting_read_replica_with_a_long_name', 'PostgreSQL · reporting_read_replica_with_a_long_name', $critical, [new Vital('Connections', '98 %')], '16.4', 'gallery', reason: 'SQLSTATE[08006] could not connect to server: Connection refused while reading ' . self::HOST),
                    new ComponentCard('redis', 'Redis', $unknown, [new Vital('Memory', '12 %')], reading: true),
                ], more: '1 more connection'),
                [
                    new MapBox('SQL queries', '3,402', [new Tag('12 slow', 'warn'), new Tag('N+1', 'warn')], 'gallery', 'full', 'warn'),
                    new MapBox('Cache', '0', path: 'gallery'),
                    new MapBox('Other sites and APIs', '84', [new Tag('2 no answer', 'bad'), new Tag('3 × 4xx', 'warn')], 'gallery', null, 'bad'),
                ],
            )),
            Block::map(new Map(
                [new MapBox('Requests', '0', path: 'gallery'), new MapBox('Messages', '0', path: 'gallery'), new MapBox('Scheduled tasks', '0', path: 'gallery'), new MapBox('Commands', '0', path: 'gallery')],
                [new MapBox('Exceptions', '0', path: 'gallery'), new MapBox('User requests', '0', path: 'gallery')],
                null,
                [new MapBox('SQL queries', '0', path: 'gallery'), new MapBox('Cache', '0', path: 'gallery'), new MapBox('Other sites and APIs', '0', path: 'gallery')],
            )),
            Block::map(new Map(runsOn: new StackGlance([
                new ComponentCard('php', 'PHP', $healthy, [new Vital('OPcache memory', '42 %')], '8.4.1', 'gallery'),
                new ComponentCard('symfony', 'Symfony', $info, [new Vital('Environment', 'prod'), new Vital('Debug', 'off')], '7.4.0', 'gallery'),
            ]))),
            Block::map(new Map(runsOn: new StackGlance([
                new ComponentCard('php', 'PHP', $healthy, [new Vital('OPcache memory', '42 %')], '8.4.1', 'gallery'),
            ], notice: new Notice('warn', 'The live stack needs a working cache', 'Only what PHP can read in its own process is shown. Check the cache.app pool of the application.')))),
            Block::map(new Map(runsOn: new StackGlance(notice: new Notice('bad', 'The stack could not be read', 'Cannot reach redis://cache:6379: Connection refused')))),
            Block::places(new Places([
                new Place('Overview', '', 'This page. What needs a look comes first.', 'Is everything fine?'),
                new Place('Application', 'gallery', 'Requests, SQL queries, cache, outgoing HTTP, exceptions, users.', 'What is slow, and why?'),
                new Place('Background', 'missing', 'Messenger messages, scheduled tasks and console commands.', 'Did the work behind the scenes run?'),
                new Place('Stack', 'gallery', 'The server, PHP, Symfony, the web server, the databases and Redis.', 'Are the servers healthy right now?'),
            ])),
        ];
    }

    public function reset(): void
    {
        $this->requests = null;
    }

    private function requests(): int
    {
        if ($this->requests === null) {
            $this->requests = (is_file($this->counterFile) ? (int) file_get_contents($this->counterFile) : 0) + 1;
            file_put_contents($this->counterFile, (string) $this->requests);
        }

        return $this->requests;
    }
}
