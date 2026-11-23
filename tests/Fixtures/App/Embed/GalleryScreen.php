<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\Embed;

use Jmonitor\JmonitorBundle\Dashboard\BlockScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\NavBadge;
use Jmonitor\JmonitorBundle\Dashboard\NavBadgeScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCard;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCards;
use Jmonitor\JmonitorBundle\Dashboard\View\Fact;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauge;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauges;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Vital;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Every block of the live stack in every state. Each request moves a counter kept in a file: the
 * badge shows it, and its tone and the web server named by the second navigation alternate with its parity.
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

    public function navBadge(): NavBadge
    {
        $requests = $this->requests();

        return new NavBadge((string) $requests, $requests % 2 === 1 ? 'warn' : 'bad', 'Requests seen by the gallery');
    }

    public function blocks(ScreenRequest $request): array
    {
        $healthy = new Mark('ok', 'Healthy');
        $warning = new Mark('warn', 'Warning');
        $critical = new Mark('bad', 'Critical');
        $unknown = new Mark('unknown', 'Unknown');
        $info = new Mark('info', 'Info');

        return [
            Block::subnav(new SubNav([
                new SubNavItem('Overview', 'gallery', true),
                new SubNavItem('Server', 'gallery', false, $healthy),
                new SubNavItem('PHP', 'gallery', false, $warning),
                new SubNavItem('Symfony', 'gallery', false, $critical),
                new SubNavItem('MySQL', 'gallery', false, $unknown),
                new SubNavItem('Redis', 'gallery', false, $info),
            ], $this->requests() % 2 === 1 ? 'web-1' : 'web-2')),
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
            ], 'Messages waiting in each transport, counted 12 s ago')),
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
