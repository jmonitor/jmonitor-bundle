<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Collector\FrankenPhpBinary;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentRegistry;
use Jmonitor\JmonitorBundle\Stack\Detection\PhpDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\SymfonyDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\SystemDetector;
use Jmonitor\JmonitorBundle\Stack\Read\LocalStackReader;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheProbe;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeProcessRunner;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeStatusPages;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class StackScreenTest extends WebTestCase
{
    use PollsThePage;

    private const STACK = ['end_of_life' => false, 'symfony' => ['flex' => false]];
    private const SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];
    private const NGINX = ['SERVER_SOFTWARE' => 'nginx/1.26.2'];
    private const MYSQL_URL = 'JMONITOR_TEST_MYSQL_URL';
    private const PGSQL_URL = 'JMONITOR_TEST_PGSQL_URL';
    private const REDIS_URL = 'JMONITOR_TEST_REDIS_URL';

    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-stack-screen-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testTheStackScreenListsPhpAndSymfony(): void
    {
        $client = $this->client();

        $crawler = $client->request('GET', '/jmonitor/stack');

        static::assertResponseStatusCodeSame(200);
        static::assertCount(1, $crawler->filter('[data-jm-component="php"]'));
        static::assertCount(1, $crawler->filter('[data-jm-component="symfony"]'));
        static::assertSame('JMonitor — Stack', $crawler->filter('title')->text());
        $entries = $crawler->filter('.jm-nav__item');
        static::assertMatchesRegularExpression('/^Stack\b/', $entries->last()->text());
        static::assertSame($entries->last()->attr('href'), $crawler->filter('.jm-nav__item[aria-current="page"]')->attr('href'));
        static::assertSame('/jmonitor/stack', $crawler->filter('.jm-nav__item[aria-current="page"]')->attr('href'));
        static::assertSame('Overview', $crawler->filter('.jm-subnav__item[aria-current="page"]')->text());
        static::assertCount(1, $crawler->filter('form[action="/jmonitor/_action/stack"] button'));
    }

    public function testAnApplicationWithoutDatabaseArrivesOnTheStack(): void
    {
        $client = $this->client(['doctrine' => false]);

        $crawler = $client->request('GET', '/jmonitor/');

        static::assertResponseIsSuccessful();
        static::assertSame('JMonitor — Stack', $crawler->filter('title')->text());
        static::assertCount(1, $crawler->filter('[data-jm-component="php"]'));
    }

    public function testAnApplicationWithoutMysqlHasNoMysqlCardAndSaysWhy(): void
    {
        $crawler = $this->client()->request('GET', '/jmonitor/stack');

        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('[data-jm-component^="mysql"]'));
        static::assertStringContainsString('MySQL: No MySQL Doctrine connection found.', self::notDetected($crawler));
    }

    public function testAnApplicationWithoutDoctrineHasNoMysqlAndSaysWhy(): void
    {
        $crawler = $this->client(['doctrine' => false])->request('GET', '/jmonitor/stack');

        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('[data-jm-component^="mysql"]'));
        static::assertCount(0, $crawler->filter('[data-jm-notice="bad"]'));
        static::assertStringContainsString('MySQL: No Doctrine DBAL connection is configured.', self::notDetected($crawler));
    }

    public function testAnApplicationWithoutPostgresqlSaysWhy(): void
    {
        $crawler = $this->client()->request('GET', '/jmonitor/stack');

        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('[data-jm-component^="postgresql"]'));
        static::assertStringContainsString('PostgreSQL: No PostgreSQL Doctrine connection found.', self::notDetected($crawler));
    }

    public function testAnApplicationWithoutRedisSaysWhy(): void
    {
        $crawler = $this->client()->request('GET', '/jmonitor/stack');

        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('[data-jm-component^="redis"]'));
        static::assertStringContainsString('Redis: No Redis DSN found: set jmonitor.stack.redis.connections, use Redis for a cache pool or the sessions, or set the REDIS_URL environment variable.', self::notDetected($crawler));
    }

    public function testRecordingOffArrivesOnTheStack(): void
    {
        $client = $this->client(['jmonitor' => ['recording' => ['enabled' => false]]]);

        $crawler = $client->request('GET', '/jmonitor/');

        static::assertResponseIsSuccessful();
        static::assertSame('JMonitor — Stack', $crawler->filter('title')->text());
    }

    public function testATestThatLeavesTheStackOutReadsNothingOutsideTheWebServer(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['enabled' => false, 'buffer_dir' => $this->bufferDir]]]);

        $client->request('GET', '/jmonitor/');
        static::assertResponseIsSuccessful();
        $client->request('GET', '/jmonitor/_fragment');
        static::assertResponseIsSuccessful();

        $container = $client->getKernel()->getContainer();
        static::assertFalse($container->getParameter('jmonitor.stack.end_of_life'));
        static::assertFalse($container->getParameter('jmonitor.stack.symfony.flex'));
    }

    public function testAStackRequestIsNotRecorded(): void
    {
        $client = $this->client(['jmonitor' => ['recording' => ['flush_interval' => 1]]]);

        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/jmonitor/stack');
            static::assertResponseIsSuccessful();
        }
        $client->request('GET', '/jmonitor/_fragment/stack');
        static::assertResponseIsSuccessful();
        $client->request('GET', '/hello/world');
        $client->request('GET', '/cached');

        $requests = self::requestUntil($client, '/jmonitor/requests', 'tr[data-jm-route="GET app_hello"]');
        static::assertCount(1, $requests->filter('tr[data-jm-route="GET app_hello"]'));
        $routes = $requests->filter('tr[data-jm-route]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-route'));
        static::assertSame([], preg_grep('/jmonitor/', $routes));

        $cache = self::requestUntil($client, '/jmonitor/cache', 'tr[data-jm-pool="cache.app"]');
        static::assertCount(1, $cache->filter('tr[data-jm-pool="cache.app"]'));
        static::assertCount(0, $cache->filter('tr[data-jm-pool="jmonitor.stack.cache"]'));
        static::assertStringNotContainsString('jmonitor.stack', $cache->filter('.jm-screen')->text());
    }

    public function testRefreshIsAProtectedPost(): void
    {
        $client = $this->client();

        $client->request('POST', '/jmonitor/_action/stack', ['action' => 'refresh'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(303);
        static::assertResponseRedirects('/jmonitor/stack');

        $client->request('POST', '/jmonitor/_action/stack', ['action' => 'refresh'], server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        static::assertResponseStatusCodeSame(403);

        $client->request('GET', '/jmonitor/_action/stack');
        static::assertResponseStatusCodeSame(405);

        $client->request('POST', '/jmonitor/_action/stack', ['action' => 'forget'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(404);
    }

    public function testASecondRefreshWithinTenSecondsForgetsNothing(): void
    {
        $client = $this->client();
        $client->request('GET', '/jmonitor/stack');
        static::assertResponseIsSuccessful();

        $client->request('POST', '/jmonitor/_action/stack', ['action' => 'refresh'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(303);
        $readAt = $client->request('GET', '/jmonitor/stack')->filter('[data-jm-component="php"]')->attr('data-jm-read-at');
        static::assertNotNull($readAt);

        $start = time();
        while (time() === $start) {
            usleep(10_000);
        }

        $client->request('POST', '/jmonitor/_action/stack', ['action' => 'refresh'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(303);
        static::assertResponseRedirects('/jmonitor/stack');

        static::assertSame($readAt, $client->request('GET', '/jmonitor/stack')->filter('[data-jm-component="php"]')->attr('data-jm-read-at'));
    }

    public function testTheStackOffRemovesTheEntryAndTheScreen(): void
    {
        $client = $this->client(['jmonitor' => ['stack' => false]]);

        $crawler = $client->request('GET', '/jmonitor/requests');
        static::assertResponseIsSuccessful();
        static::assertNotContains('Stack', $crawler->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()));

        $client->request('GET', '/jmonitor/stack');
        static::assertResponseStatusCodeSame(404);

        $client->request('POST', '/jmonitor/_action/stack', ['action' => 'refresh'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(404);
    }

    public function testEachComponentHasItsDetail(): void
    {
        $client = $this->client();

        foreach (['php' => 'PHP', 'symfony' => 'Symfony'] as $id => $label) {
            $crawler = $client->request('GET', '/jmonitor/stack/' . $id);

            static::assertResponseStatusCodeSame(200);
            static::assertSame('JMonitor — ' . $label, $crawler->filter('title')->text());
            static::assertSame('/jmonitor/stack', $crawler->filter('.jm-nav__item[aria-current="page"]')->attr('href'));
            static::assertSame('/jmonitor/stack/' . $id, $crawler->filter('.jm-subnav__item[aria-current="page"]')->attr('href'));
            static::assertSame('/jmonitor/stack', $crawler->filter('.jm-subnav__item')->first()->attr('href'));
            static::assertCount(1, $crawler->filter('[data-jm-component="' . $id . '"]'));
            static::assertCount(0, $crawler->filter('[data-jm-component="' . $id . '"] a'));
            static::assertCount(1, $crawler->filter('form[action="/jmonitor/_action/stack/' . $id . '"] button'));
        }

        $php = $client->request('GET', '/jmonitor/stack/php');
        static::assertContains('Runtime', $php->filter('.jm-panel__title')->each(static fn(Crawler $title): string => $title->text()));

        $symfony = $client->request('GET', '/jmonitor/stack/symfony');
        static::assertContains('Bundles', $symfony->filter('.jm-panel__title')->each(static fn(Crawler $title): string => $title->text()));
        static::assertGreaterThan(0, $symfony->filter('th[scope="row"]:contains("JmonitorBundle")')->count());
    }

    public function testUnknownDetailsAreNotFound(): void
    {
        $client = $this->client();

        foreach (['/jmonitor/stack/memcached', '/jmonitor/stack/php/x', '/jmonitor/_fragment/stack/memcached'] as $url) {
            $client->request('GET', $url);
            static::assertResponseStatusCodeSame(404, $url);
        }

        $client->request('POST', '/jmonitor/_action/stack/memcached', ['action' => 'refresh'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(404);

        $client->request('POST', '/jmonitor/_action/stack/php', ['action' => 'forget'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(404);
    }

    public function testADetailRefreshesItselfAndComesBack(): void
    {
        $client = $this->client();

        $client->request('POST', '/jmonitor/_action/stack/php', ['action' => 'refresh'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(303);
        static::assertResponseRedirects('/jmonitor/stack/php');

        $client->request('POST', '/jmonitor/_action/stack/php', ['action' => 'refresh'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(303);
        static::assertResponseRedirects('/jmonitor/stack/php');

        $client->request('POST', '/jmonitor/_action/stack/php', ['action' => 'refresh'], server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        static::assertResponseStatusCodeSame(403);

        $client->request('GET', '/jmonitor/stack/php');
        static::assertResponseIsSuccessful();
    }

    public function testTheFragmentOfADetailIsItsContent(): void
    {
        $client = $this->client();

        $client->request('GET', '/jmonitor/_fragment/stack/php');
        $html = (string) $client->getResponse()->getContent();

        static::assertResponseIsSuccessful();
        static::assertStringNotContainsString('<html', $html);
        $fragment = new Crawler($html);
        static::assertCount(1, $fragment->filter('.jm-screen'));
        static::assertCount(1, $fragment->filter('[data-jm-component="php"]'));
        static::assertSame('/jmonitor/stack/php', $fragment->filter('.jm-subnav__item[aria-current="page"]')->attr('href'));
        static::assertContains('Runtime', $fragment->filter('.jm-panel__title')->each(static fn(Crawler $title): string => $title->text()));
    }

    public function testAnApplicationScreenBuildsNothingOfTheStackButItsPool(): void
    {
        $client = $this->client(['internals' => true]);
        $client->disableReboot();

        $client->request('GET', '/jmonitor/requests');
        static::assertResponseIsSuccessful();

        $container = $client->getKernel()->getContainer();
        foreach ([ComponentRegistry::class, SnapshotProvider::class, LocalStackReader::class, CacheProbe::class, SystemDetector::class, PhpDetector::class, SymfonyDetector::class, StackChecks::class] as $id) {
            static::assertFalse($container->initialized($id), $id);
        }
        static::assertTrue($container->initialized(StackSummary::class));
        static::assertTrue($container->initialized('jmonitor.stack.cache'));
    }

    public function testAnEndpointReadFromAMissingVariableLeavesTheWebServerOutNotTheApplication(): void
    {
        $client = $this->client(['jmonitor' => ['stack' => ['nginx' => ['endpoint' => '%env(NOPE)%']]], 'fakeWebServer' => 'nginx']);
        $client->disableReboot();

        $crawler = $client->request('GET', '/jmonitor/stack', server: self::NGINX);

        static::assertResponseStatusCodeSame(200);
        static::assertCount(1, $crawler->filter('[data-jm-component="php"]'));
        static::assertCount(0, $crawler->filter('[data-jm-component="nginx"]'));
        static::assertSame(0, self::statusPages($client)->calls);
    }

    public function testTheStackOffReadsNoStatusPage(): void
    {
        $client = $this->client(['jmonitor' => ['stack' => false], 'fakeWebServer' => 'nginx']);
        $client->disableReboot();

        $client->request('GET', '/jmonitor/stack', server: self::NGINX);
        static::assertResponseStatusCodeSame(404);
        $client->request('GET', '/jmonitor/requests', server: self::NGINX);
        static::assertResponseIsSuccessful();

        static::assertSame(0, self::statusPages($client)->calls);
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, string, array<string, string>, ?string, string}>
     */
    public static function webServers(): iterable
    {
        yield 'FrankenPHP' => ['frankenphp', 'FrankenPHP', [], 'http://localhost:2019/metrics', ['Threads' => '1 / 4', 'Queue' => '0'], 'Worker mode', 'Workers'];
        yield 'Apache' => ['apache', 'Apache', [], 'http://localhost/server-status?auto', ['Workers' => '3 / 50', 'Requests' => '—'], null, 'Scoreboard'];
        yield 'Nginx' => ['nginx', 'Nginx', self::NGINX, 'http://localhost/nginx_status', ['Connections' => '12 / 3072', 'Requests' => '—'], null, 'Settings'];
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $vitals
     */
    #[DataProvider('webServers')]
    public function testEachWebServerHasItsCardAndItsDetail(string $id, string $label, array $server, string $statusPage, array $vitals, ?string $note, string $section): void
    {
        $client = $this->client(['fakeWebServer' => $id]);
        $client->disableReboot();
        self::answerCommands($client);

        $client->request('GET', '/jmonitor/stack', server: $server);
        $crawler = $client->request('GET', '/jmonitor/stack?rest=1', server: $server);

        static::assertResponseStatusCodeSame(200);
        $card = $crawler->filter('[data-jm-component="' . $id . '"]');
        static::assertCount(1, $card);
        static::assertSame($vitals, self::vitals($card));
        static::assertSame($note, $card->filter('.jm-component__note')->count() === 0 ? null : $card->filter('.jm-component__note')->text());
        static::assertSame('/jmonitor/stack/' . $id, $card->filter('a')->attr('href'));
        static::assertSame([$statusPage], self::statusPages($client)->urls);
        static::assertStringNotContainsString('serving this page', self::notDetected($crawler));

        $crawler = $client->request('GET', '/jmonitor/stack/' . $id, server: $server);

        static::assertResponseStatusCodeSame(200);
        static::assertSame('JMonitor — ' . $label, $crawler->filter('title')->text());
        static::assertSame('/jmonitor/stack', $crawler->filter('.jm-nav__item[aria-current="page"]')->attr('href'));
        static::assertSame('/jmonitor/stack/' . $id, $crawler->filter('.jm-subnav__item[aria-current="page"]')->attr('href'));
        static::assertCount(1, $crawler->filter('[data-jm-component="' . $id . '"]'));
        static::assertContains($section, $crawler->filter('.jm-panel__title')->each(static fn(Crawler $title): string => $title->text()));
    }

    public function testWithoutAWebServerTheNotDetectedLineSaysSoOnce(): void
    {
        $crawler = $this->client()->request('GET', '/jmonitor/stack');

        static::assertStringContainsString('Web server: FrankenPHP, Apache and Nginx were not found serving this page', self::notDetected($crawler));
        static::assertStringNotContainsString('is not serving this page', self::notDetected($crawler));
    }

    public function testAWorkerScriptNameIsEscaped(): void
    {
        $client = $this->client(['fakeWebServer' => 'frankenphp']);
        $client->disableReboot();
        self::answerCommands($client);
        self::statusPages($client)->replace('worker="/app/public/index.php"', 'worker="<b>x"');

        $client->request('GET', '/jmonitor/stack/frankenphp');
        $crawler = $client->request('GET', '/jmonitor/stack/frankenphp');
        $html = (string) $client->getResponse()->getContent();

        static::assertResponseStatusCodeSame(200);
        static::assertCount(1, $crawler->filter('tr[data-jm-worker="<b>x"]'));
        static::assertStringContainsString('&lt;b&gt;x', $html);
        static::assertStringNotContainsString('<b>x', $html);
    }

    public function testAStatusPageAnsweredByTheApplicationGivesAnInformativeCard(): void
    {
        $client = $this->client(['fakeWebServer' => 'nginx']);
        $client->disableReboot();
        self::answerCommands($client);
        self::statusPages($client)->answerAsTheApplication();

        $client->request('GET', '/jmonitor/stack', server: self::NGINX);
        $crawler = $client->request('GET', '/jmonitor/stack?rest=1', server: self::NGINX);

        static::assertResponseStatusCodeSame(200);
        $card = $crawler->filter('[data-jm-component="nginx"]');
        static::assertCount(1, $card);
        static::assertSame('unknown', $card->attr('data-jm-verdict'));
        static::assertSame('Unknown', $card->filter('.jm-component__marks .jm-mark')->first()->text());
        static::assertStringContainsString('http://localhost/nginx_status answered HTTP 404: it is not a status page.', $card->filter('.jm-component__reason')->text());
        static::assertCount(0, $card->filter('.jm-vital'));
        static::assertCount(1, $crawler->filter('[data-jm-check="nginx.failed"][data-jm-severity="info"]'));

        for ($i = 0; $i < 5; ++$i) {
            $client->request('GET', '/jmonitor/_fragment/stack', server: self::NGINX);
            static::assertResponseIsSuccessful();
        }

        static::assertSame(1, self::statusPages($client)->calls);
    }

    public function testWithoutTheNginxCommandTheCardKeepsTheVersionOfTheServerSoftware(): void
    {
        $client = $this->client(['fakeWebServer' => 'nginx']);
        $client->disableReboot();
        $runner = $client->getContainer()->get(FakeProcessRunner::class);
        static::assertInstanceOf(FakeProcessRunner::class, $runner);
        $runner->answer(null, 'sh: nginx: not found', 127);

        $client->request('GET', '/jmonitor/stack', server: self::NGINX);
        $crawler = $client->request('GET', '/jmonitor/stack?rest=1', server: self::NGINX);

        static::assertResponseStatusCodeSame(200);
        static::assertSame('1.26.2', $crawler->filter('[data-jm-component="nginx"] .jm-component__version')->text());
        static::assertCount(0, $crawler->filter('[data-jm-check="nginx.config.failed"]'));
    }

    public function testADetailOfAWebServerThatDoesNotServeThePageSaysSo(): void
    {
        $client = $this->client(['fakeWebServer' => 'nginx']);
        $client->disableReboot();
        self::answerCommands($client);

        $crawler = $client->request('GET', '/jmonitor/stack/apache', server: self::NGINX);

        static::assertResponseStatusCodeSame(200);
        static::assertSame('JMonitor — Apache', $crawler->filter('title')->text());
        static::assertSame('Apache is not part of this stack', $crawler->filter('.jm-notice__title strong')->text());
        static::assertSame('Apache is not serving this page.', $crawler->filter('.jm-notice__text')->text());
        static::assertSame(0, self::statusPages($client)->calls);
    }

    public function testTheOverviewShowsOneMysqlCardForTheTwoConnections(): void
    {
        $client = $this->client(['connections' => self::twoConnections()]);

        $client->request('GET', '/jmonitor/stack');
        $crawler = self::requestUntil($client, '/jmonitor/stack', '[data-jm-component="mysql.main"][data-jm-read-at]');

        static::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-jm-component="mysql.main"]');
        static::assertCount(1, $card);
        static::assertCount(0, $crawler->filter('[data-jm-component="mysql.reports"]'));
        static::assertSame('MySQL · main', $card->filter('.jm-component__name')->text());
        static::assertSame('/jmonitor/stack/mysql?connection=main', $card->filter('.jm-component__name a')->attr('href'));
        static::assertMatchesRegularExpression('/^(MySQL|MariaDB) \d+\.\d+/', $card->filter('.jm-component__version')->text());
        static::assertStringEndsWith(' · 2 connections', $card->filter('.jm-component__note')->text());
        static::assertSame(['Connections', 'Queries', 'Buffer pool hit rate'], array_keys(self::vitals($card)));
        static::assertNull($crawler->filter('.jm-components__more')->getNode(0));
        static::assertSame('/jmonitor/stack/mysql', $crawler->filter('.jm-subnav__item')->last()->attr('href'));
    }

    public function testTheDetailOfTheSecondConnectionShowsItsDatabase(): void
    {
        $client = $this->client(['connections' => self::twoConnections()]);
        $database = trim((string) parse_url((string) getenv(self::MYSQL_URL), \PHP_URL_PATH), '/');

        $client->request('GET', '/jmonitor/stack/mysql?connection=reports');
        $crawler = self::requestUntil($client, '/jmonitor/stack/mysql?connection=reports&rest=1', '.jm-facts[aria-label="Database · ' . $database . '"]');

        static::assertResponseIsSuccessful();
        static::assertSame('JMonitor — MySQL', $crawler->filter('title')->text());
        static::assertCount(1, $crawler->filter('[data-jm-component="mysql.reports"]'));
        static::assertCount(1, $crawler->filter('.jm-facts[aria-label="Database · ' . $database . '"]'));
        static::assertSame(['Usage', 'Traffic', 'InnoDB', 'Temporary tables and locks', 'Settings', 'Database · ' . $database], $crawler->filter('.jm-gauges, .jm-facts')->each(static fn(Crawler $section): string => (string) $section->attr('aria-label')));
        static::assertCount(1, $crawler->filter('#jm-mysql-slow-queries-title, .jm-state__title:contains("No query of this database"), .jm-notice__title:contains("The slowest queries could not be read")'));

        $selector = $crawler->filter('.jm-subbar + [data-jm-instances]');
        static::assertSame(['Databases'], $selector->filter('nav')->each(static fn(Crawler $row): string => (string) $row->attr('aria-label')));
        static::assertSame(['/jmonitor/stack/mysql?connection=main', '/jmonitor/stack/mysql?connection=reports'], $selector->filter('a')->each(static fn(Crawler $link): string => (string) $link->attr('href')));
        static::assertSame('reports', $selector->filter('a[aria-current="true"] .jm-instances__name')->text());
        static::assertSame('/jmonitor/_fragment/stack/mysql?connection=reports', $crawler->filter('[data-jm-fragment-url]')->attr('data-jm-fragment-url'));
        static::assertSame('reports', $crawler->filter('form[action="/jmonitor/_action/stack/mysql"] input[name="connection"]')->attr('value'));

        $client->request('GET', '/jmonitor/_fragment/stack/mysql?connection=reports');
        static::assertResponseIsSuccessful();
        static::assertCount(1, (new Crawler((string) $client->getResponse()->getContent()))->filter('[data-jm-component="mysql.reports"]'));

        $client->request('POST', '/jmonitor/_action/stack/mysql', ['action' => 'refresh', 'connection' => 'reports'], server: self::SAME_ORIGIN);
        static::assertResponseRedirects('/jmonitor/stack/mysql?connection=reports');
    }

    public function testADeadMysqlIsCriticalAndTheNextDisplayDoesNotWait(): void
    {
        self::needsMysql();
        $client = $this->client([
            'internals' => true,
            'connections' => ['dead' => ['url' => 'mysql://jmonitor:jmonitor@127.0.0.1:1/jmonitor']],
            'jmonitor' => ['stack' => ['ignore_checks' => ['system.load']]],
        ]);

        $client->request('GET', '/jmonitor/stack');
        $client->request('GET', '/jmonitor/_fragment/stack');
        static::assertResponseIsSuccessful();
        static::assertTrue($client->getKernel()->getContainer()->initialized('doctrine.dbal.dead_connection'), 'the server was tried once');

        $crawler = $client->request('GET', '/jmonitor/stack');

        static::assertResponseIsSuccessful();
        static::assertFalse($client->getKernel()->getContainer()->initialized('doctrine.dbal.dead_connection'), 'the failure is kept: nothing is tried again');
        $card = $crawler->filter('[data-jm-component="mysql.dead"]');
        static::assertSame('bad', $card->attr('data-jm-verdict'));
        static::assertSame('Critical', $card->filter('.jm-component__marks .jm-mark')->first()->text());
        static::assertStringContainsString('Connection refused', $card->filter('.jm-component__reason')->text());
        static::assertSame('127.0.0.1:1', $card->filter('.jm-component__note')->text());
        $check = $crawler->filter('[data-jm-check="mysql.failed"]');
        static::assertSame('bad', $check->attr('data-jm-severity'));
        static::assertStringStartsWith('MySQL cannot be reached: ', $check->filter('.jm-check__sentence')->text());
        static::assertSame('/jmonitor/stack/mysql?connection=dead', $check->filter('.jm-check__where a')->attr('href'));
        static::assertSame('1', $crawler->filter('[data-jm-slot="nav-stack"] .jm-nav__badge')->getNode(0)?->firstChild?->textContent);

        $crawler = $client->request('GET', '/jmonitor/stack/mysql?connection=dead');

        static::assertResponseIsSuccessful();
        static::assertSame('bad', $crawler->filter('[data-jm-component="mysql.dead"]')->attr('data-jm-verdict'));
        static::assertFalse($client->getKernel()->getContainer()->initialized('doctrine.dbal.dead_connection'));
    }

    public function testTheDetailOfMysqlIsNotRecorded(): void
    {
        $client = $this->client(['connections' => self::twoConnections(), 'jmonitor' => ['recording' => ['flush_interval' => 1]]]);

        $client->request('GET', '/jmonitor/stack/mysql?connection=main');
        for ($i = 0; $i < 2; ++$i) {
            $client->request('GET', '/jmonitor/stack/mysql?connection=main&rest=1');
            static::assertResponseIsSuccessful();
        }
        $client->request('GET', '/jmonitor/_fragment/stack/mysql?connection=main');
        $client->request('GET', '/hello/world');

        $requests = self::requestUntil($client, '/jmonitor/requests', 'tr[data-jm-route="GET app_hello"]');
        static::assertCount(1, $requests->filter('tr[data-jm-route="GET app_hello"]'));
        static::assertSame([], preg_grep('/jmonitor/', $requests->filter('tr[data-jm-route]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-route'))));

        $queries = $client->request('GET', '/jmonitor/queries')->filter('.jm-screen')->text();
        foreach (['GLOBAL STATUS', 'GLOBAL VARIABLES', 'performance_schema', 'information_schema'] as $sql) {
            static::assertStringNotContainsStringIgnoringCase($sql, $queries);
        }
    }

    public function testThePostgresqlDetailIsServed(): void
    {
        if (!\is_string(getenv(self::PGSQL_URL))) {
            static::markTestSkipped(self::PGSQL_URL . ' is not set.');
        }
        $client = $this->client(['connections' => ['shop' => ['url' => '%env(' . self::PGSQL_URL . ')%', 'server_version' => '17']]]);
        $slowest = '#jm-postgresql-slow-queries-title, .jm-state__title:contains("No statement of this database"), .jm-state__title:contains("This role cannot see the statements"), .jm-notice__title:contains("The slowest queries could not be read")';

        $client->request('GET', '/jmonitor/stack/postgresql?connection=shop');
        self::requestUntil($client, '/jmonitor/stack/postgresql?connection=shop&rest=1', '.jm-facts[aria-label="Tables"]');
        $crawler = self::requestUntil($client, '/jmonitor/stack/postgresql?connection=shop&rest=1', $slowest);

        static::assertResponseStatusCodeSame(200);
        static::assertSame('JMonitor — PostgreSQL', $crawler->filter('title')->text());
        $card = $crawler->filter('[data-jm-component="postgresql.shop"]');
        static::assertCount(1, $card);
        static::assertMatchesRegularExpression('/^PostgreSQL \d+\.\d+$/', $card->filter('.jm-component__version')->text());
        static::assertSame(['Connections', 'Cache hit rate', 'Transactions'], array_keys(self::vitals($card)));
        $url = (string) getenv(self::PGSQL_URL);
        static::assertSame(parse_url($url, \PHP_URL_HOST) . ':' . (parse_url($url, \PHP_URL_PORT) ?? 5432), $card->filter('.jm-component__note')->text());
        static::assertSame(['Usage', 'Traffic', 'Cache', 'Sessions', 'Tables', 'Settings'], $crawler->filter('.jm-gauges, .jm-facts')->each(static fn(Crawler $section): string => (string) $section->attr('aria-label')));
        static::assertCount(1, $crawler->filter($slowest));
        static::assertCount(0, $crawler->filter('[data-jm-instances]'));
    }

    public function testTheRedisDetailIsServed(): void
    {
        if (!\is_string(getenv(self::REDIS_URL))) {
            static::markTestSkipped(self::REDIS_URL . ' is not set.');
        }
        $client = $this->client(['jmonitor' => ['stack' => ['redis' => ['connections' => ['cache' => '%env(' . self::REDIS_URL . ')%']]]]]);
        $keyspace = '#jm-redis-keyspace-title, .jm-state__title:contains("No key in this server")';

        $client->request('GET', '/jmonitor/stack/redis?connection=cache');
        $crawler = self::requestUntil($client, '/jmonitor/stack/redis?connection=cache&rest=1', '.jm-facts[aria-label="Server"]');

        static::assertResponseStatusCodeSame(200);
        static::assertSame('JMonitor — Redis', $crawler->filter('title')->text());
        $card = $crawler->filter('[data-jm-component="redis.cache"]');
        static::assertCount(1, $card);
        static::assertMatchesRegularExpression('/^(Redis|Valkey) \d+\.\d+\.\d+$/', $card->filter('.jm-component__version')->text());
        static::assertSame(['Memory', 'Hit rate', 'Commands'], array_keys(self::vitals($card)));
        $url = (string) getenv(self::REDIS_URL);
        static::assertSame(parse_url($url, \PHP_URL_HOST) . ':' . (parse_url($url, \PHP_URL_PORT) ?? 6379), $card->filter('.jm-component__note')->text());
        static::assertSame(['Usage', 'Traffic', 'Memory', 'Persistence', 'Server'], $crawler->filter('.jm-gauges, .jm-facts')->each(static fn(Crawler $section): string => (string) $section->attr('aria-label')));
        static::assertCount(1, $crawler->filter($keyspace));
        static::assertCount(0, $crawler->filter('[data-jm-instances]'));
    }

    public function testAMissingVariableOnlyBreaksItsRedisServer(): void
    {
        if (!\is_string(getenv(self::REDIS_URL))) {
            static::markTestSkipped(self::REDIS_URL . ' is not set.');
        }
        $client = $this->client(['jmonitor' => ['stack' => ['redis' => ['connections' => [
            'cache' => ['dsn' => '%env(' . self::REDIS_URL . ')%'],
            'absent' => '%env(JMONITOR_TEST_ABSENT_REDIS_URL)%',
        ]]]]]);

        $crawler = self::requestUntil($client, '/jmonitor/stack', '[data-jm-component="redis.cache"][data-jm-read-at]');

        static::assertResponseIsSuccessful();
        static::assertMatchesRegularExpression('/^(Redis|Valkey) \d+\.\d+\.\d+$/', $crawler->filter('[data-jm-component="redis.cache"] .jm-component__version')->text());
        static::assertSame(
            'The DSN of this connection cannot be read: an environment variable is missing ("JMONITOR_TEST_ABSENT_REDIS_URL").',
            $crawler->filter('[data-jm-component="redis.absent"] .jm-component__reason')->text(),
        );
    }

    public function testAPercentSignInARedisPasswordIsRead(): void
    {
        $url = getenv(self::REDIS_URL);
        if (!\is_string($url) || !\extension_loaded('redis')) {
            static::markTestSkipped(self::REDIS_URL . ' is not set, or the redis extension is not loaded.');
        }
        $user = 'jm_percent_' . bin2hex(random_bytes(4));
        $admin = RedisAdapter::createConnection($url);
        \assert($admin instanceof \Redis);
        $admin->rawCommand('ACL', 'SETUSER', $user, 'on', '>p@ss%40', '+@all', '~*');

        try {
            $dsn = \sprintf('redis://%s:p%%%%40ss%%%%2540@%s:%d', $user, parse_url($url, \PHP_URL_HOST), parse_url($url, \PHP_URL_PORT) ?? 6379);
            $client = $this->client(['jmonitor' => ['stack' => ['redis' => ['connections' => ['percent' => $dsn]]]]]);

            $crawler = self::requestUntil($client, '/jmonitor/stack', '[data-jm-component="redis.percent"][data-jm-read-at]');

            static::assertCount(0, $crawler->filter('[data-jm-component="redis.percent"] .jm-component__reason'));
            static::assertMatchesRegularExpression('/^(Redis|Valkey) \d+\.\d+\.\d+$/', $crawler->filter('[data-jm-component="redis.percent"] .jm-component__version')->text());
        } finally {
            $admin->rawCommand('ACL', 'DELUSER', $user);
            $admin->close();
        }
    }

    /**
     * @return array<string, string> label => value, with ordinary spaces
     */
    private static function vitals(Crawler $card): array
    {
        $vitals = [];

        foreach ($card->filter('.jm-vital') as $vital) {
            $vital = new Crawler($vital);
            $vitals[$vital->filter('.jm-vital__label')->text()] = str_replace("\u{00A0}", ' ', $vital->filter('.jm-vital__value')->text('', false));
        }

        return $vitals;
    }

    private static function notDetected(Crawler $crawler): string
    {
        $line = $crawler->filter('.jm-components__absent');

        return $line->count() === 0 ? '' : $line->text();
    }

    private static function answerCommands(KernelBrowser $client): void
    {
        $runner = $client->getContainer()->get(FakeProcessRunner::class);
        static::assertInstanceOf(FakeProcessRunner::class, $runner);
        $fixtures = \dirname(__DIR__) . '/Stack/Fixtures/Nginx/';

        $runner
            ->answer([FrankenPhpBinary::path(), 'version'], "FrankenPHP v1.13.0 PHP 8.4.26 Caddy v2.11.7\n")
            ->answer(['sh', '-c', 'nginx -V 2>&1'], (string) file_get_contents($fixtures . 'nginx-V.txt'))
            ->answer(['sh', '-c', 'nginx -T'], (string) file_get_contents($fixtures . 'nginx-T.txt'))
            ->answer(['sh', '-c', 'getconf _NPROCESSORS_ONLN'], "4\n");
    }

    private static function statusPages(KernelBrowser $client): FakeStatusPages
    {
        $pages = $client->getContainer()->get(FakeStatusPages::class);
        static::assertInstanceOf(FakeStatusPages::class, $pages);

        return $pages;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function twoConnections(): array
    {
        self::needsMysql();

        return ['main' => ['url' => '%env(' . self::MYSQL_URL . ')%'], 'reports' => ['url' => '%env(' . self::MYSQL_URL . ')%']];
    }

    private static function needsMysql(): void
    {
        if (!\is_string(getenv(self::MYSQL_URL))) {
            static::markTestSkipped(self::MYSQL_URL . ' is not set.');
        }
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, doctrine?: bool, internals?: bool, fakeWebServer?: string, connections?: array<string, array<string, mixed>>} $options
     */
    private function client(array $options = []): KernelBrowser
    {
        $options['jmonitor'] = array_replace_recursive(
            ['recording' => ['enabled' => true, 'buffer_dir' => $this->bufferDir], 'stack' => self::STACK],
            $options['jmonitor'] ?? [],
        );

        return static::createClient($options);
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, doctrine?: bool, internals?: bool, fakeWebServer?: string, connections?: array<string, array<string, mixed>>} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel(
            'test',
            true,
            $options['jmonitor'] ?? [],
            withDoctrine: $options['doctrine'] ?? true,
            withPublicInternals: $options['internals'] ?? false,
            fakeWebServer: $options['fakeWebServer'] ?? null,
            connections: $options['connections'] ?? [],
        );
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }
}
