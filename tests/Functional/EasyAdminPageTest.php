<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

#[Group('easyadmin')]
final class EasyAdminPageTest extends WebTestCase
{
    use PollsThePage;

    private const ADMIN = ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'test'];
    private const USER = ['PHP_AUTH_USER' => 'user', 'PHP_AUTH_PW' => 'test'];
    private const SUPER = ['PHP_AUTH_USER' => 'super', 'PHP_AUTH_PW' => 'test'];
    private const SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];
    private const STACK = ['end_of_life' => false, 'symfony' => ['flex' => false]];

    private const VARIABLES = ['JMONITOR_TEST_PERMISSION', 'JMONITOR_TEST_ABSENT'];

    private string $bufferDir;

    /** @var array<string, array{mixed, mixed, string|false}> */
    private static array $savedVariables = [];

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            self::$savedVariables[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            self::unsetVariable($name);
        }
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-easyadmin-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (self::$savedVariables as $name => [$env, $server, $process]) {
            self::unsetVariable($name);
            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
            if ($process !== false) {
                putenv($name . '=' . $process);
            }
        }
        self::$savedVariables = [];
        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testTheNineScreensShowInsideTheBackOffice(): void
    {
        $client = $this->client(['messenger' => true, 'scheduler' => true]);

        foreach (['requests', 'queries', 'commands', 'messages', 'scheduler', 'http-client', 'cache', 'exceptions', 'users'] as $id) {
            $crawler = $client->request('GET', '/admin/jmonitor/' . $id);

            static::assertResponseIsSuccessful($id);
            static::assertMatchesRegularExpression('#^/admin/jmonitor/' . $id . '(\?|$)#', (string) $crawler->filter('.jm .jm-nav__item[aria-current="page"]')->attr('href'), $id);

            $urls = self::urlsOf($crawler->filter('.jm'));
            static::assertNotEmpty($urls, $id);
            foreach ($urls as $url) {
                static::assertStringStartsWith('/admin/jmonitor', $url, $id);
            }
        }
    }

    public function testTheEmptyPathIsTheDefaultScreen(): void
    {
        $client = $this->client();

        $crawler = $client->request('GET', '/admin/jmonitor');

        static::assertResponseIsSuccessful();
        static::assertSame('Requests', $crawler->filter('.jm .jm-nav__item[aria-current="page"]')->text());
    }

    public function testThePageSitsInTheFrameOfEasyAdmin(): void
    {
        $client = $this->client();

        $crawler = $client->request('GET', '/admin/jmonitor/requests');
        $response = $client->getResponse();

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('body.ea'));
        $jmonitor = $crawler->filter('.jm');
        static::assertCount(1, $jmonitor);
        static::assertSame('div', $jmonitor->nodeName());
        static::assertSame('auto', $jmonitor->attr('data-jm-theme'));
        static::assertCount(0, $crawler->filter('.jm-brand'));
        static::assertCount(1, $crawler->filter('h1'));
        static::assertStringContainsString('JMonitor — Requests', $crawler->filter('title')->text());

        $stylesheets = $crawler->filter('head link[rel="stylesheet"]')->each(static fn(Crawler $node): string => (string) $node->attr('href'));
        static::assertGreaterThan(1, \count($stylesheets));
        static::assertStringStartsWith('/bundles/jmonitor/jmonitor.css?v=', end($stylesheets));
        static::assertStringContainsString('/bundles/easyadmin/', $stylesheets[0]);

        $scripts = $crawler->filter('head script[src]');
        static::assertGreaterThan(1, $scripts->count());
        static::assertStringContainsString('/bundles/easyadmin/', (string) $scripts->first()->attr('src'));
        static::assertStringStartsWith('/bundles/jmonitor/jmonitor.js?v=', (string) $scripts->last()->attr('src'));
        static::assertNotNull($scripts->last()->attr('defer'));

        static::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        static::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    public function testAMenuLinkWrittenForJmonitorEasyadminBundleStillOpensThePage(): void
    {
        $client = $this->client();

        $crawler = $client->request('GET', '/admin');

        static::assertResponseIsSuccessful();
        $link = $crawler->filter('.sidebar-wrapper a[href="http://localhost/admin/jmonitor"]');
        static::assertCount(1, $link);

        $client->click($link->link());
        static::assertResponseIsSuccessful();
        static::assertCount(1, $client->getCrawler()->filter('.jm .jm-screen'));
    }

    public function testEveryDashboardHasItsOwnPage(): void
    {
        $client = $this->client();
        $client->setServerParameters(self::USER);
        $actions = 0;

        foreach (['requests', 'probe'] as $id) {
            $crawler = $client->request('GET', '/staff/jmonitor/' . $id);
            static::assertResponseIsSuccessful($id);

            $jmonitor = $crawler->filter('.jm');
            $urls = self::urlsOf($jmonitor);
            $forms = $jmonitor->filter('[action]')->each(static fn(Crawler $node): string => (string) $node->attr('action'));
            $actions += \count($forms);
            static::assertNotEmpty($urls, $id);
            foreach ([...$urls, ...$forms] as $url) {
                static::assertStringStartsWith('/staff/jmonitor', $url, $id);
            }
        }
        static::assertGreaterThan(0, $actions);
    }

    public function testAnEmbeddedScreenShowsTheSameDataAsTheStandaloneOne(): void
    {
        $client = $this->client();
        $row = 'tr[data-jm-route="GET app_hello"][data-jm-requests="3"]';

        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/hello/world');
        }
        $embedded = self::requestUntil($client, '/admin/jmonitor/requests', $row);
        static::assertCount(1, $embedded->filter($row));

        $standalone = $client->request('GET', '/jmonitor/requests');
        static::assertCount(1, $standalone->filter($row));
    }

    public function testTheFragmentIsRelayedAsIs(): void
    {
        $client = $this->client();

        $client->request('GET', '/admin/jmonitor/_fragment/requests');
        $response = $client->getResponse();
        $html = (string) $response->getContent();

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('jm-screen', $html);
        static::assertStringNotContainsString('<html', $html);
        static::assertCount(0, (new Crawler($html))->filter('body.ea'));
        static::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        static::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    public function testSortAndPeriodLinksStayOnThePage(): void
    {
        $client = $this->client();

        $client->request('GET', '/hello/world');
        $crawler = self::requestUntil($client, '/admin/jmonitor/requests?period=6h&sort=sql&dir=asc', 'tr[data-jm-route="GET app_hello"]');

        static::assertSame('/admin/jmonitor/requests?period=6h&sort=sql&dir=desc', $crawler->filter('th.is-sorted a.jm-sort')->attr('href'));
    }

    public function testTheActionOfTheProbeScreenRunsThroughEasyAdmin(): void
    {
        $client = $this->client();

        $client->request('POST', '/admin/jmonitor/_action/probe', ['action' => 'ping'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(303);
        static::assertResponseRedirects('/admin/jmonitor/probe');

        $crawler = $client->request('GET', '/admin/jmonitor/probe');
        static::assertCount(1, $crawler->filter('tr[data-jm-probe-runs="1"]'));

        $client->setServerParameters(self::USER);
        $client->request('POST', '/staff/jmonitor/_action/probe', ['action' => 'ping'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(303);
        static::assertResponseRedirects('/staff/jmonitor/probe');
    }

    public function testTheStackShowsUnderEasyAdmin(): void
    {
        $client = $this->client(['jmonitor' => ['stack' => self::STACK]]);

        $crawler = $client->request('GET', '/admin/jmonitor/stack');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('body.ea'));
        static::assertCount(1, $crawler->filter('.jm [data-jm-component="php"]'));
        static::assertCount(1, $crawler->filter('.jm [data-jm-component="symfony"]'));
        static::assertSame('/admin/jmonitor/stack', $crawler->filter('.jm .jm-nav__item[aria-current="page"]')->attr('href'));
        static::assertSame('/admin/jmonitor/stack/php', $crawler->filter('.jm [data-jm-component="php"] a')->attr('href'));
        foreach (self::urlsOf($crawler->filter('.jm')) as $url) {
            static::assertStringStartsWith('/admin/jmonitor', $url);
        }
    }

    public function testTheQueuesShowUnderEasyAdmin(): void
    {
        $client = $this->client([
            'jmonitor' => ['stack' => ['end_of_life' => false, 'symfony' => ['flex' => false, 'messenger' => true]]],
            'messenger' => true,
            'queuesOutput' => '{"transports":["async"]}' . "\n" . '{"name":"async","count":12}' . "\n",
        ]);

        $crawler = $client->request('GET', '/admin/jmonitor/messages');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('body.ea'));
        static::assertCount(1, $crawler->filter('.jm .jm-screen > .jm-state[data-jm-reading]'));
        $rest = (string) $crawler->filter('.jm a[data-jm-rest]')->attr('href');
        static::assertStringStartsWith('/admin/jmonitor/messages?', $rest);
        static::assertStringContainsString('period=1h', $rest);

        $client->request('GET', '/admin/jmonitor/_fragment/messages');
        static::assertResponseIsSuccessful();
        $crawler = $client->request('GET', '/admin/jmonitor/messages');

        static::assertSame('Queues', $crawler->filter('.jm .jm-screen > .jm-facts .jm-panel__title')->text());
        static::assertSame('12', $crawler->filter('.jm .jm-facts .jm-fact__value')->text());
        foreach (self::urlsOf($crawler->filter('.jm')) as $url) {
            static::assertStringStartsWith('/admin/jmonitor', $url);
        }
    }

    public function testADetailShowsUnderEasyAdmin(): void
    {
        $client = $this->client(['jmonitor' => ['stack' => self::STACK]]);

        $crawler = $client->request('GET', '/admin/jmonitor/stack/php');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('body.ea'));
        static::assertCount(1, $crawler->filter('.jm [data-jm-component="php"]'));
        static::assertSame('/admin/jmonitor/stack', $crawler->filter('.jm .jm-nav__item[aria-current="page"]')->attr('href'));
        static::assertSame('/admin/jmonitor/stack/php', $crawler->filter('.jm .jm-subnav__item[aria-current="page"]')->attr('href'));
        static::assertCount(1, $crawler->filter('.jm form[action="/admin/jmonitor/_action/stack/php"] button'));
        foreach (self::urlsOf($crawler->filter('.jm')) as $url) {
            static::assertStringStartsWith('/admin/jmonitor', $url);
        }

        $client->request('POST', '/admin/jmonitor/_action/stack/php', ['action' => 'refresh'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(303);
        static::assertResponseRedirects('/admin/jmonitor/stack/php');
    }

    public function testAWebServerShowsUnderEasyAdmin(): void
    {
        $client = $this->client(['jmonitor' => ['stack' => self::STACK], 'fakeWebServer' => 'nginx']);
        $client->setServerParameters(self::ADMIN + ['SERVER_SOFTWARE' => 'nginx/1.26.2']);

        $client->request('GET', '/admin/jmonitor/stack');
        $crawler = $client->request('GET', '/admin/jmonitor/stack');

        static::assertResponseIsSuccessful();
        static::assertSame('/admin/jmonitor/stack/nginx', $crawler->filter('.jm [data-jm-component="nginx"] a')->attr('href'));

        $crawler = $client->request('GET', '/admin/jmonitor/stack/nginx');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('body.ea'));
        static::assertCount(1, $crawler->filter('.jm [data-jm-component="nginx"]'));
        static::assertSame('/admin/jmonitor/stack', $crawler->filter('.jm .jm-nav__item[aria-current="page"]')->attr('href'));
        static::assertSame('/admin/jmonitor/stack/nginx', $crawler->filter('.jm .jm-subnav__item[aria-current="page"]')->attr('href'));
        static::assertContains('Connections', $crawler->filter('.jm .jm-panel__title')->each(static fn(Crawler $title): string => $title->text()));
        foreach (self::urlsOf($crawler->filter('.jm')) as $url) {
            static::assertStringStartsWith('/admin/jmonitor', $url);
        }
    }

    public function testTheMysqlDetailShowsUnderEasyAdminWithItsConnection(): void
    {
        $url = 'mysql://jmonitor:jmonitor@127.0.0.1:1/';
        $client = $this->client(['jmonitor' => ['stack' => self::STACK], 'connections' => ['shop' => ['url' => $url . 'shop'], 'a/b é' => ['url' => $url . 'reports']]]);

        $crawler = $client->request('GET', '/admin/jmonitor/stack/mysql?connection=a/b%20%C3%A9');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('body.ea'));
        static::assertCount(1, $crawler->filter('.jm [data-jm-component="mysql.a/b é"]'));
        static::assertSame('/admin/jmonitor/stack/mysql', $crawler->filter('.jm .jm-subnav__item[aria-current="page"]')->attr('href'));
        static::assertSame(
            ['/admin/jmonitor/stack/mysql?connection=shop', '/admin/jmonitor/stack/mysql?connection=a/b%20%C3%A9'],
            $crawler->filter('.jm [data-jm-instances] a')->each(static fn(Crawler $link): string => (string) $link->attr('href')),
        );
        static::assertSame('/admin/jmonitor/_fragment/stack/mysql?connection=a/b%20%C3%A9', $crawler->filter('.jm [data-jm-fragment-url]')->attr('data-jm-fragment-url'));
        static::assertSame('a/b é', $crawler->filter('.jm form[action="/admin/jmonitor/_action/stack/mysql"] input[name="connection"]')->attr('value'));
        foreach (self::urlsOf($crawler->filter('.jm')) as $url) {
            static::assertStringStartsWith('/admin/jmonitor', $url);
        }

        $client->request('POST', '/admin/jmonitor/_action/stack/mysql', ['action' => 'refresh', 'connection' => 'a/b é'], server: self::SAME_ORIGIN);
        static::assertResponseRedirects('/admin/jmonitor/stack/mysql?connection=a/b%20%C3%A9');
    }

    public function testThePostgresqlDetailShowsUnderEasyAdmin(): void
    {
        if (!\is_string(getenv('JMONITOR_TEST_PGSQL_URL'))) {
            static::markTestSkipped('JMONITOR_TEST_PGSQL_URL is not set.');
        }
        $client = $this->client(['jmonitor' => ['stack' => self::STACK], 'connections' => ['shop' => ['url' => '%env(JMONITOR_TEST_PGSQL_URL)%', 'server_version' => '17']]]);

        $client->request('GET', '/admin/jmonitor/stack/postgresql?connection=shop');
        $crawler = self::requestUntil($client, '/admin/jmonitor/stack/postgresql?connection=shop&rest=1', '.jm .jm-facts[aria-label="Tables"]');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('body.ea'));
        static::assertCount(1, $crawler->filter('.jm [data-jm-component="postgresql.shop"]'));
        static::assertMatchesRegularExpression('/^PostgreSQL \d+\.\d+$/', $crawler->filter('.jm [data-jm-component="postgresql.shop"] .jm-component__version')->text());
        static::assertCount(1, $crawler->filter('.jm .jm-facts[aria-label="Tables"]'));
        static::assertSame('/admin/jmonitor/stack/postgresql', $crawler->filter('.jm .jm-subnav__item[aria-current="page"]')->attr('href'));
        static::assertSame('shop', $crawler->filter('.jm form[action="/admin/jmonitor/_action/stack/postgresql"] input[name="connection"]')->attr('value'));
        foreach (self::urlsOf($crawler->filter('.jm')) as $url) {
            static::assertStringStartsWith('/admin/jmonitor', $url);
        }
    }

    public function testRefreshWorksThroughEasyAdmin(): void
    {
        $client = $this->client(['jmonitor' => ['stack' => self::STACK]]);

        $crawler = $client->request('GET', '/admin/jmonitor/stack');
        static::assertCount(1, $crawler->filter('.jm form[action="/admin/jmonitor/_action/stack"] button'));

        $client->request('POST', '/admin/jmonitor/_action/stack', ['action' => 'refresh'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(303);
        static::assertResponseRedirects('/admin/jmonitor/stack');

        $client->request('POST', '/admin/jmonitor/_action/stack', ['action' => 'refresh'], server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        static::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin/jmonitor/stack');
        static::assertResponseIsSuccessful();
    }

    public function testWithCsrfTheActionNeedsTheTokenOfThePage(): void
    {
        $client = $this->client(['csrf' => true]);

        $crawler = $client->request('GET', '/admin/jmonitor/probe');
        static::assertResponseIsSuccessful();
        $token = $crawler->filter('.jm form[action="/admin/jmonitor/_action/probe"] input[name="token"]');
        static::assertCount(1, $token);

        $client->request('POST', '/admin/jmonitor/_action/probe', ['action' => 'ping'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/jmonitor/_action/probe', ['action' => 'ping', 'token' => (string) $token->attr('value')], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(303);
    }

    public function testWithoutPermissionEveryUserOfTheBackOfficeSeesThePage(): void
    {
        $client = $this->client();

        $client->request('GET', '/staff/jmonitor', server: self::USER);

        static::assertResponseStatusCodeSame(200);
    }

    public function testWithoutAPathRuleThePermissionIsTheOnlyGuard(): void
    {
        $client = $this->client(['jmonitor' => ['easyadmin' => ['permission' => 'ROLE_ADMIN']], 'open' => true]);
        $client->setServerParameters([]);

        foreach ([[], self::USER] as $user) {
            $status = $user === [] ? 401 : 403;
            $client->request('GET', '/admin/jmonitor', server: $user);
            static::assertResponseStatusCodeSame($status);
            $client->request('GET', '/admin/jmonitor/_fragment/probe', server: $user);
            static::assertResponseStatusCodeSame($status);
            $client->request('POST', '/admin/jmonitor/_action/probe', ['action' => 'ping'], server: $user + self::SAME_ORIGIN);
            static::assertResponseStatusCodeSame($status);
        }

        $client->request('GET', '/admin/jmonitor', server: self::ADMIN);
        static::assertResponseStatusCodeSame(200);

        self::ensureKernelShutdown();
        $client = $this->client(['open' => true]);
        $client->setServerParameters([]);

        $client->request('GET', '/admin/jmonitor');
        static::assertResponseStatusCodeSame(200);
    }

    public function testThePermissionComesOnTopOfTheBackOffice(): void
    {
        $client = $this->client(['jmonitor' => ['easyadmin' => ['permission' => 'ROLE_SUPER_ADMIN']]]);

        $client->request('GET', '/admin/jmonitor', server: self::SUPER);
        static::assertResponseStatusCodeSame(200);

        $client->request('GET', '/admin/jmonitor');
        static::assertResponseStatusCodeSame(403);
        $client->request('GET', '/admin/jmonitor/_fragment/probe');
        static::assertResponseStatusCodeSame(403);
        $client->request('POST', '/admin/jmonitor/_action/probe', ['action' => 'ping'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/admin/jmonitor/probe', server: self::SUPER);
        static::assertResponseStatusCodeSame(200);
        static::assertSame('0', $crawler->filter('.jm-card__value')->text());
        static::assertCount(0, $crawler->filter('tr[data-jm-probe-runs]'));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function emptyPermissions(): iterable
    {
        yield 'variable empty' => ['%env(JMONITOR_TEST_PERMISSION)%', ''];
        yield 'variable empty, with a default' => ['%env(default::JMONITOR_TEST_PERMISSION)%', ''];
        yield 'variable absent, with a default' => ['%env(default::JMONITOR_TEST_PERMISSION)%', null];
    }

    #[DataProvider('emptyPermissions')]
    public function testAnEmptyPermissionAtRuntimeOpensNothing(string $permission, ?string $variable): void
    {
        if ($variable !== null) {
            self::setVariable('JMONITOR_TEST_PERMISSION', $variable);
        }
        $client = $this->client(['jmonitor' => ['easyadmin' => ['permission' => $permission]]]);
        $client->setServerParameters(self::SUPER);

        $client->request('GET', '/admin/jmonitor');
        static::assertResponseStatusCodeSame(403);
        $client->request('GET', '/admin/jmonitor/_fragment/probe');
        static::assertResponseStatusCodeSame(403);
        $client->request('POST', '/admin/jmonitor/_action/probe', ['action' => 'ping'], server: self::SAME_ORIGIN);
        static::assertResponseStatusCodeSame(403);
    }

    public function testAMissingPermissionVariableOnlyBreaksThePageOfJmonitor(): void
    {
        $client = $this->client(['jmonitor' => ['easyadmin' => ['permission' => '%env(JMONITOR_TEST_ABSENT)%']]]);

        $client->request('GET', '/hello/world');
        static::assertResponseStatusCodeSame(200);
        $client->request('GET', '/admin');
        static::assertResponseStatusCodeSame(200);
        $client->request('GET', '/admin/jmonitor');
        static::assertResponseStatusCodeSame(500);
    }

    public function testTheAssetsCarryTheNonceOfTheApplication(): void
    {
        $client = $this->client(['nonce' => true]);

        $crawler = $client->request('GET', '/admin/jmonitor/requests');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('script[src*="/bundles/jmonitor/"][nonce="n0nce"]'));
        static::assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="/bundles/jmonitor/"][nonce="n0nce"]'));

        self::ensureKernelShutdown();
        $client = $this->client();

        $crawler = $client->request('GET', '/admin/jmonitor/requests');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('script[src*="/bundles/jmonitor/"]'));
        static::assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="/bundles/jmonitor/"]'));
        static::assertCount(0, $crawler->filter('script[src*="/bundles/jmonitor/"][nonce]'));
        static::assertCount(0, $crawler->filter('link[href*="/bundles/jmonitor/"][nonce]'));
    }

    public function testAnOldTabAddressIsNotFound(): void
    {
        $client = $this->client();

        $client->request('GET', '/admin/jmonitor/mysql');
        static::assertResponseStatusCodeSame(404);

        $client->request('GET', '/admin/jmonitor/Nope');
        static::assertResponseStatusCodeSame(404);

        $client->request('GET', '/admin/jmonitor/_action/probe');
        static::assertResponseStatusCodeSame(405);
    }

    public function testThePageSaysWhenRecordingIsOff(): void
    {
        $client = $this->client(['jmonitor' => ['recording' => ['enabled' => false]]]);

        $crawler = $client->request('GET', '/admin/jmonitor/requests');

        static::assertResponseIsSuccessful();
        static::assertSame('recording_disabled', $crawler->filter('.jm-screen')->attr('data-jm-state'));
    }

    private static function setVariable(string $name, string $value): void
    {
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }

    private static function unsetVariable(string $name): void
    {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    }

    /**
     * @return list<string>
     */
    private static function urlsOf(Crawler $jmonitor): array
    {
        return [
            ...$jmonitor->filter('[href]')->each(static fn(Crawler $node): string => (string) $node->attr('href')),
            ...$jmonitor->filter('[data-jm-fragment-url]')->each(static fn(Crawler $node): string => (string) $node->attr('data-jm-fragment-url')),
        ];
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, messenger?: bool, scheduler?: bool, csrf?: bool, nonce?: bool, open?: bool, fakeWebServer?: string, queuesOutput?: string, connections?: array<string, array<string, mixed>>} $options
     */
    private function client(array $options = []): KernelBrowser
    {
        $options['jmonitor'] = array_replace_recursive(
            ['easyadmin' => true, 'recording' => ['enabled' => true, 'flush_interval' => 1, 'buffer_dir' => $this->bufferDir]],
            $options['jmonitor'] ?? [],
        );
        $client = static::createClient($options);
        $client->setServerParameters(self::ADMIN);

        return $client;
    }

    /**
     * @param array{environment?: string, debug?: bool, jmonitor?: array<string, mixed>, messenger?: bool, scheduler?: bool, csrf?: bool, nonce?: bool, open?: bool, fakeWebServer?: string, queuesOutput?: string, connections?: array<string, array<string, mixed>>} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel(
            $options['environment'] ?? 'test',
            $options['debug'] ?? true,
            $options['jmonitor'] ?? [],
            withMessenger: $options['messenger'] ?? false,
            withScheduler: $options['scheduler'] ?? false,
            withEmbedHost: true,
            withCsrf: $options['csrf'] ?? false,
            withEasyAdmin: true,
            withCspNonce: $options['nonce'] ?? false,
            withOpenBackOffice: $options['open'] ?? false,
            fakeWebServer: $options['fakeWebServer'] ?? null,
            queuesOutput: $options['queuesOutput'] ?? null,
            connections: $options['connections'] ?? [],
        );
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }
}
