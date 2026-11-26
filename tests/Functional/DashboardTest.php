<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Jmonitor\JmonitorBundle\Aggregation\Aggregate;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\Ping;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\PurgeTask;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;

final class DashboardTest extends WebTestCase
{
    use PollsThePage;

    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-dashboard-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testPageListsRecordedRoutes(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/hello/world');
        }
        $crawler = self::requestUntil($client, '/jmonitor/', 'tr[data-jm-route="GET app_hello"][data-jm-requests="3"]');
        $response = $client->getResponse();

        static::assertResponseIsSuccessful();
        static::assertSame('3', $crawler->filter('tr[data-jm-route="GET app_hello"]')->attr('data-jm-requests'));
        static::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        static::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    public function testNonStandardMethodsShowAsOtherWithTheNeutralBadge(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('POST', '/hello/world', server: ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'ZZZ']);
        $crawler = self::requestUntil($client, '/jmonitor/', 'tr[data-jm-route="OTHER app_hello"]');

        $badge = $crawler->filter('tr[data-jm-route="OTHER app_hello"] .jm-method');
        static::assertCount(1, $badge);
        static::assertSame('OTHER', $badge->text());
        static::assertStringContainsString('jm-method--other', (string) $badge->attr('class'));
    }

    public function testDashboardRoutesAreNotRecorded(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/hello/world');
        for ($i = 0; $i < 5; ++$i) {
            $client->request('GET', '/jmonitor/');
            static::assertResponseIsSuccessful();
        }
        $crawler = self::requestUntil($client, '/jmonitor/', 'tr[data-jm-route="GET app_hello"]');

        static::assertCount(1, $crawler->filter('tr[data-jm-route="GET app_hello"]'));
        static::assertCount(0, $crawler->filter('tr[data-jm-route^="GET jmonitor_"]'));
    }

    public function testTheDashboardRoutesCarryTheMark(): void
    {
        static::createClient(['jmonitor' => $this->recording()]);

        $routes = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            if (str_starts_with($name, 'jmonitor_')) {
                $routes[$name] = $route->getDefault('_jmonitor');
            }
        }

        static::assertSame([
            'jmonitor_dashboard' => true,
            'jmonitor_dashboard_fragment' => true,
            'jmonitor_screen_fragment' => true,
            'jmonitor_action' => true,
            'jmonitor_screen' => true,
        ], $routes);
    }

    public function testTheActionRouteOnlyAcceptsPost(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/jmonitor/_action/requests');

        static::assertResponseStatusCodeSame(405);
    }

    public function testAnActionOnAScreenWithoutOneIsNotFound(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('POST', '/jmonitor/_action/requests', ['action' => 'refresh'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        static::assertResponseStatusCodeSame(404);
    }

    public function testFragmentReturnsOnlyTheContent(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/hello/world');
        self::requestUntil($client, '/jmonitor/_fragment', 'tr[data-jm-route="GET app_hello"]');
        $html = (string) $client->getResponse()->getContent();

        static::assertResponseIsSuccessful();
        static::assertStringNotContainsString('<html', $html);
        static::assertStringContainsString('<table', $html);
        static::assertStringContainsString('data-jm-route="GET app_hello"', $html);
        static::assertSame('noindex', $client->getResponse()->headers->get('X-Robots-Tag'));
    }

    public function testInvalidQueryParametersDoNotBreakThePage(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/jmonitor/?period=abc&sort=x&dir=%00');
        static::assertResponseIsSuccessful();

        $client->request('GET', '/jmonitor/_fragment?period[]=1h&sort[x]=y');
        static::assertResponseIsSuccessful();
    }

    public function testEmptyState(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $crawler = $client->request('GET', '/jmonitor/requests');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('[data-jm-state="empty"]'));
        static::assertSame('JMonitor — Requests', $crawler->filter('title')->text());
    }

    public function testNoConnectionState(): void
    {
        $client = static::createClient(['doctrine' => false]);

        $crawler = $client->request('GET', '/jmonitor/requests');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('[data-jm-state="no_connection"]'));
    }

    public function testNotFoundOutsideDebug(): void
    {
        $kernel = static::createKernel(['environment' => 'prod', 'debug' => false, 'jmonitor' => ['recording' => ['buffer_dir' => $this->bufferDir]]]);

        $response = $kernel->handle(Request::create('/jmonitor/'));
        $kernel->shutdown();

        static::assertSame(404, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{bool, bool, int}>
     */
    public static function enabledCombinations(): iterable
    {
        yield 'recording on, page off' => [true, false, 404];
        yield 'recording off, page off' => [false, false, 404];
        yield 'recording on, page on' => [true, true, 200];
        yield 'recording off, page on' => [false, true, 200];
    }

    #[DataProvider('enabledCombinations')]
    public function testDisabledPageIsNotFound(bool $recording, bool $dashboard, int $status): void
    {
        $client = static::createClient(['jmonitor' => [
            'recording' => ['enabled' => $recording, 'buffer_dir' => $this->bufferDir],
            'dashboard' => ['enabled' => $dashboard],
        ]]);

        $client->request('GET', '/jmonitor/');
        static::assertResponseStatusCodeSame($status);

        $client->request('GET', '/jmonitor/_fragment');
        static::assertResponseStatusCodeSame($status);
    }

    public function testWithoutTwigTheApplicationStillWorksAndThePageIsNotFound(): void
    {
        $client = static::createClient(['twig' => false, 'jmonitor' => $this->recording()]);

        $client->request('GET', '/hello/world');
        static::assertResponseIsSuccessful();

        $client->request('GET', '/jmonitor/');
        static::assertResponseStatusCodeSame(404);

        $client->request('GET', '/jmonitor/_fragment');
        static::assertResponseStatusCodeSame(404);
    }

    public function testPermission(): void
    {
        $client = static::createClient(['debug' => false, 'security' => true, 'jmonitor' => ['dashboard' => ['permission' => 'ROLE_ADMIN']]]);

        $client->request('GET', '/jmonitor/');
        static::assertResponseStatusCodeSame(401);

        $client->request('GET', '/jmonitor/', server: ['PHP_AUTH_USER' => 'user', 'PHP_AUTH_PW' => 'test']);
        static::assertResponseStatusCodeSame(403);

        $client->request('GET', '/jmonitor/', server: ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'test']);
        static::assertResponseStatusCodeSame(200);
    }

    public function testAnEmptyPermissionVariableDoesNotOpenThePageInDebug(): void
    {
        $client = static::createClient(['debug' => true, 'security' => true, 'jmonitor' => ['dashboard' => ['permission' => '%env(default::JMONITOR_TEST_ABSENT)%']]]);

        $client->request('GET', '/jmonitor/');
        static::assertResponseStatusCodeSame(401);

        $client->request('GET', '/jmonitor/_fragment');
        static::assertResponseStatusCodeSame(401);

        $client->request('GET', '/jmonitor/', server: ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'test']);
        static::assertResponseStatusCodeSame(403);
    }

    public function testStorageFailureIsShown(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/hello/world');
        $client->disableReboot();
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        $connection->beginTransaction();

        $crawler = self::requestUntil($client, '/jmonitor/requests', '[data-jm-state="storage_error"]');
        $overview = $client->request('GET', '/jmonitor/');
        $connection->rollBack();

        static::assertResponseStatusCodeSame(200);
        static::assertCount(1, $crawler->filter('[data-jm-state="storage_error"]'));
        static::assertSame(['Storage unavailable'], $overview->filter('.jm-state__title')->each(static fn(Crawler $title): string => $title->text()));
    }

    public function testAssetsAreReferencedWithTheBasePath(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/jmonitor/');
        $html = (string) $client->getResponse()->getContent();

        static::assertStringContainsString('"/bundles/jmonitor/jmonitor.css?v=', $html);
        static::assertStringContainsString('"/bundles/jmonitor/jmonitor.js?v=', $html);

        $client->request('GET', '/sub/index.php/jmonitor/', server: ['SCRIPT_NAME' => '/sub/index.php', 'SCRIPT_FILENAME' => '/srv/sub/index.php']);
        $html = (string) $client->getResponse()->getContent();

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('"/sub/bundles/jmonitor/jmonitor.css?v=', $html);
        static::assertStringContainsString('"/sub/bundles/jmonitor/jmonitor.js?v=', $html);
    }

    public function testJmonitorOpensTheOverview(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording() + ['stack' => false]]);

        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/hello/world');
        }
        $crawler = self::requestUntil($client, '/jmonitor/', 'tr[data-jm-route="GET app_hello"][data-jm-requests="3"]');
        $html = (string) $client->getResponse()->getContent();

        static::assertSame('JMonitor — Overview', $crawler->filter('title')->text());
        static::assertStringContainsString('jmonitor.css?v=', $html);
        static::assertSame('Overview', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
        static::assertSame('/jmonitor/?period=1h', $crawler->filter('.jm-nav__item')->first()->attr('href'));
        static::assertSame('/jmonitor/?period=24h', $crawler->filter('.jm-segmented__item')->eq(2)->attr('href'));
        static::assertSame('/jmonitor/_fragment', $crawler->filter('[data-jm-fragment-url]')->attr('data-jm-fragment-url'));
        static::assertSame('/jmonitor/', $crawler->filter('a.jm-brand')->attr('href'));
        static::assertSame('Your application answers normally. Nothing is worth a look.', $crawler->filter('h1')->text());

        $same = $client->request('GET', '/jmonitor/overview');

        static::assertResponseIsSuccessful();
        static::assertSame('JMonitor — Overview', $same->filter('title')->text());
        static::assertSame($crawler->filter('h1')->text(), $same->filter('h1')->text());
        static::assertSame('/jmonitor/_fragment/overview', $same->filter('[data-jm-fragment-url]')->attr('data-jm-fragment-url'));

        $fragment = $client->request('GET', '/jmonitor/_fragment');

        static::assertResponseIsSuccessful();
        static::assertStringNotContainsString('<html', (string) $client->getResponse()->getContent());
        static::assertCount(1, $fragment->filter('.jm-screen > section.jm-map'));
        static::assertCount(1, $fragment->filter('tr[data-jm-route="GET app_hello"][data-jm-requests="3"]'));
    }

    public function testAFreshInstallSaysItIsRecordingAndShowsWhatToFindHere(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $crawler = $client->request('GET', '/jmonitor/');

        static::assertResponseIsSuccessful();
        static::assertSame('JMonitor is installed and recording.', $crawler->filter('h1')->text());
        static::assertSame('Nothing was recorded in the last hour. The figures fill in as your application receives requests. Your servers are already read.', $crawler->filter('.jm-lead__detail')->text());
        static::assertSame(['jm-lead', 'jm-checks', 'jm-map', 'jm-places'], self::blocksOf($crawler));
        static::assertCount(0, $crawler->filter('.jm-state'));
        static::assertCount(0, $crawler->filter('table'));
        static::assertCount(0, $crawler->filter('.jm-guide'));
        static::assertSame(['Requests', 'Commands', 'Exceptions', 'SQL queries', 'Cache', 'Other sites and APIs'], $crawler->filter('.jm-map .jm-box .jm-box__label')->each(static fn(Crawler $label): string => $label->text()));
        static::assertCount(0, $crawler->filter('.jm-map .jm-box__value'));
        static::assertSame(['Server', 'PHP', 'Symfony'], $crawler->filter('.jm-runs__item .jm-runs__name')->each(static fn(Crawler $name): string => $name->text()));
        static::assertSame('What you will find here', $crawler->filter('.jm-places .jm-panel__title')->text());
        static::assertSame(['Overview', 'Application', 'Background', 'Stack'], $crawler->filter('.jm-place__label')->each(static fn(Crawler $link): string => $link->text()));
        static::assertSame(
            ['/jmonitor/?period=1h', '/jmonitor/requests?period=1h', '/jmonitor/background?period=1h', '/jmonitor/stack?period=1h'],
            $crawler->filter('.jm-place__label')->each(static fn(Crawler $link): string => (string) $link->attr('href')),
        );
        static::assertSame('Are the servers healthy right now?', $crawler->filter('.jm-place')->last()->filter('.jm-place__question')->text());

        self::ensureKernelShutdown();
        $client = static::createClient(['jmonitor' => $this->recording() + ['stack' => false]]);
        $crawler = $client->request('GET', '/jmonitor/');

        static::assertSame('Nothing was recorded in the last hour. The figures fill in as your application receives requests.', $crawler->filter('.jm-lead__detail')->text());
        static::assertCount(0, $crawler->filter('.jm-checks'));
        static::assertCount(0, $crawler->filter('.jm-runs'));
        static::assertSame(['Overview', 'Application', 'Background'], $crawler->filter('.jm-place__label')->each(static fn(Crawler $link): string => $link->text()));
    }

    public function testWithoutStorageTheOverviewStillShowsTheStack(): void
    {
        $client = static::createClient(['doctrine' => false]);

        $crawler = $client->request('GET', '/jmonitor/');

        static::assertResponseIsSuccessful();
        static::assertSame('JMonitor — Overview', $crawler->filter('title')->text());
        static::assertSame('Overview', $crawler->filter('h1')->text());
        static::assertSame(['No storage configured'], $crawler->filter('.jm-state__title')->each(static fn(Crawler $title): string => $title->text()));
        static::assertSame(['jm-heading', 'jm-state', 'jm-map', 'jm-places'], array_values(array_diff(self::blocksOf($crawler), ['jm-checks'])));
        static::assertCount(0, $crawler->filter('.jm-map .jm-box'));
        static::assertGreaterThan(0, $crawler->filter('.jm-runs__item')->count());
        static::assertCount(1, $crawler->filter('.jm-places'));
        static::assertCount(0, $crawler->filter('.jm-lead'));
        static::assertCount(0, $crawler->filter('.jm-guide'));
    }

    public function testRequestsReadsFromTheSentenceToTheGuide(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/hello/world');
            static::assertResponseIsSuccessful();
        }
        $crawler = self::requestUntil($client, '/jmonitor/requests', 'tr[data-jm-route="GET app_hello"][data-jm-requests="3"]');

        static::assertSame(
            ['jm-lead', 'jm-checks', 'jm-cards', 'jm-panel', 'jm-guide'],
            $crawler->filter('.jm-screen > section')->each(static fn(Crawler $block): string => explode(' ', (string) $block->attr('class'))[0]),
        );
        static::assertCount(1, $crawler->filter('h1'));
        static::assertCount(0, $crawler->filter('.jm-heading'));
        static::assertMatchesRegularExpression('/^3 requests in the last hour, 95 % answered in under \S+ ms\.$/', $crawler->filter('h1.jm-lead__title')->text());
        static::assertSame('No server error, no slow request.', $crawler->filter('.jm-lead__detail')->text());
        static::assertSame('Worth a look', $crawler->filter('section.jm-checks')->attr('aria-label'));
        static::assertSame('Nothing to flag', $crawler->filter('.jm-checks__empty-title')->text());
        static::assertSame('No server error, no slow request and no route that repeats a query in the last hour.', $crawler->filter('.jm-checks__empty-text')->text());
        static::assertSame(['Average', 'p95', 'Slowest', 'Server errors', 'Slow requests'], $crawler->filter('.jm-card__label')->each(static fn(Crawler $label): string => $label->text()));
        static::assertSame('Routes', $crawler->filter('#jm-routes-title')->text());
        static::assertSame('How to read this screen', $crawler->filter('.jm-guide__title')->text());
        static::assertSame(
            ['/jmonitor/queries?period=1h', '/jmonitor/cache-http?period=1h'],
            $crawler->filter('.jm-guide a')->each(static fn(Crawler $link): string => (string) $link->attr('href')),
        );
        static::assertCount(0, $crawler->filter('.jm-subnav .jm-nav__badge'));
    }

    public function testTheOverviewReadsFromTheSentenceToTheGuide(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording() + ['stack' => false]]);

        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/hello/world');
            static::assertResponseIsSuccessful();
        }
        $crawler = self::requestUntil($client, '/jmonitor/overview', 'tr[data-jm-route="GET app_hello"][data-jm-requests="3"]');

        static::assertSame('JMonitor — Overview', $crawler->filter('title')->text());
        static::assertSame(
            ['jm-lead', 'jm-checks', 'jm-map', 'jm-panel', 'jm-guide'],
            $crawler->filter('.jm-screen > section')->each(static fn(Crawler $block): string => explode(' ', (string) $block->attr('class'))[0]),
        );
        static::assertCount(1, $crawler->filter('h1'));
        static::assertCount(0, $crawler->filter('.jm-heading'));
        static::assertSame('Your application answers normally. Nothing is worth a look.', $crawler->filter('h1.jm-lead__title')->text());
        static::assertSame('Last hour. Follow any box to its detail.', $crawler->filter('.jm-lead__detail')->text());
        static::assertSame('Worth a look', $crawler->filter('section.jm-checks')->attr('aria-label'));
        static::assertSame('Nothing to flag', $crawler->filter('.jm-checks__empty-title')->text());
        static::assertSame('No server error, no slow request in the last hour.', $crawler->filter('.jm-checks__empty-text')->text());

        $requests = $crawler->filter('.jm-map__column--incoming .jm-box')->first();
        static::assertSame('/jmonitor/requests?period=1h', $requests->attr('href'));
        static::assertSame(['Requests', '3'], [$requests->filter('.jm-box__label')->text(), $requests->filter('.jm-box__value')->text()]);
        static::assertSame('No server error, no slow request.', $requests->filter('.jm-box__text')->text());
        static::assertSame('An average request', $crawler->filter('.jm-map__average .jm-box__label')->text());
        static::assertCount(0, $crawler->filter('.jm-runs'));

        static::assertSame('Where the time goes', $crawler->filter('#jm-routes-title')->text());
        static::assertSame('1 route, the most expensive first', $crawler->filter('.jm-panel__meta')->text());
        static::assertSame(['Route', 'Requests', 'Total time', 'Where the average goes'], $crawler->filter('.jm-panel thead th')->each(static fn(Crawler $th): string => $th->text()));
        static::assertCount(0, $crawler->filter('.jm-panel thead a'));

        static::assertSame(['Read it left to right.', 'Total time beats speed.'], $crawler->filter('.jm-guide li strong')->each(static fn(Crawler $term): string => $term->text()));
        static::assertSame(['/jmonitor/requests?period=1h'], $crawler->filter('.jm-guide a')->each(static fn(Crawler $link): string => (string) $link->attr('href')));

        static::assertSame(['Overview', 'Application', 'Background'], $crawler->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()));
        static::assertSame('Overview', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
        static::assertSame('/jmonitor/?period=1h', $crawler->filter('.jm-nav__item')->first()->attr('href'));
        static::assertCount(0, $crawler->filter('.jm-subnav'));
    }

    public function testABoxOfTheOverviewLeadsToItsSection(): void
    {
        [$client] = $this->backgroundWithAMessageATaskAndACommand();

        $crawler = $client->request('GET', '/jmonitor/overview');
        $boxes = $crawler->filter('.jm-map__column--incoming .jm-box');

        static::assertSame(['Requests', 'Messages', 'Scheduled tasks', 'Commands'], $boxes->each(static fn(Crawler $box): string => $box->filter('.jm-box__label')->text()));
        static::assertSame(['1', '3', '1', '1'], $boxes->each(static fn(Crawler $box): string => $box->filter('.jm-box__value')->text()));
        static::assertSame('Handled by Messenger, 3 sent.', $boxes->eq(1)->filter('.jm-box__text')->text());
        $links = $boxes->each(static fn(Crawler $box): string => (string) $box->attr('href'));
        static::assertSame(['/jmonitor/requests?period=1h', '/jmonitor/background?period=1h#jm-messages', '/jmonitor/background?period=1h#jm-tasks', '/jmonitor/background?period=1h#jm-commands'], $links);

        foreach (\array_slice($links, 1) as $link) {
            [$path, $anchor] = explode('#', $link, 2);
            $section = $client->request('GET', $path);

            static::assertResponseIsSuccessful();
            static::assertCount(1, $section->filter('.jm-screen > section.jm-section#' . $anchor), $link);
            static::assertGreaterThan(0, $section->filter('section#' . $anchor . ' tbody tr')->count(), $link);
        }
    }

    public function testAServerErrorIsWorthALookAndBadgesTheTab(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        for ($i = 0; $i < 2; ++$i) {
            $client->request('GET', '/boom');
            static::assertResponseStatusCodeSame(500);
        }
        $crawler = self::requestUntil($client, '/jmonitor/requests', 'tr[data-jm-route="GET app_boom"][data-jm-requests="2"]');

        static::assertSame('2 requests', explode(' in ', $crawler->filter('h1.jm-lead__title')->text())[0]);
        static::assertSame('2 server errors, no slow request.', $crawler->filter('.jm-lead__detail')->text());
        $check = $crawler->filter('[data-jm-check="requests.server_errors"]');
        static::assertCount(1, $check);
        static::assertSame(['requests.server_errors'], $crawler->filter('[data-jm-check]')->each(static fn(Crawler $line): string => (string) $line->attr('data-jm-check')));
        static::assertSame('bad', $check->attr('data-jm-severity'));
        static::assertSame('2 server errors · GET app_boom answered with a 5xx status 2 times.', $check->filter('.jm-check__sentence')->text());
        static::assertSame('Critical', $check->filter('.jm-check__mark')->text());
        static::assertStringStartsWith('What to do Open Exceptions', $check->filter('.jm-check__todo')->text());
        static::assertCount(0, $check->filter('.jm-check__where'));
        static::assertSame(['2 × 5xx'], $crawler->filter('tr[data-jm-route="GET app_boom"] .jm-tag--bad')->each(static fn(Crawler $tag): string => $tag->text()));

        $badge = $crawler->filter('.jm-subnav__item[aria-current="page"] .jm-nav__badge');
        static::assertCount(1, $badge);
        static::assertSame('1 critical in the last hour', $badge->attr('title'));
        static::assertStringContainsString('jm-nav__badge--bad', (string) $badge->attr('class'));
        static::assertStringStartsWith('1 critical', $badge->text());
    }

    public function testTheBadgeOfRequestsShowsOnTheOtherTabs(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/boom');
        static::assertResponseStatusCodeSame(500);
        self::requestUntil($client, '/jmonitor/requests', 'tr[data-jm-route="GET app_boom"][data-jm-requests="1"]');

        foreach (['/jmonitor/exceptions', '/jmonitor/_fragment/exceptions', '/jmonitor/cache-http'] as $uri) {
            $crawler = $client->request('GET', $uri);
            static::assertResponseIsSuccessful($uri);

            $tabs = $crawler->filter('nav.jm-subnav[aria-label="Application"] .jm-subnav__item');
            static::assertSame(
                ['1 critical in the last hour'],
                $tabs->filter('.jm-nav__badge')->each(static fn(Crawler $badge): string => (string) $badge->attr('title')),
                $uri,
            );
            static::assertSame('/jmonitor/requests?period=1h', $tabs->filter('.jm-nav__badge')->closest('a')?->attr('href'), $uri);
        }
    }

    public function testTheRequestsScreenKeepsItsEmptyState(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $crawler = $client->request('GET', '/jmonitor/requests');

        static::assertResponseIsSuccessful();
        static::assertSame('empty', $crawler->filter('.jm-screen')->attr('data-jm-state'));
        static::assertSame('Requests', $crawler->filter('.jm-heading h1')->text());
        static::assertSame('Traffic and response times per route, last hour.', $crawler->filter('.jm-heading p')->text());
        static::assertStringContainsString('Waiting for traffic', $crawler->filter('[data-jm-state="empty"]')->last()->text());
        static::assertCount(1, $crawler->filter('h1'));
        static::assertCount(0, $crawler->filter('.jm-lead, .jm-checks, .jm-cards, .jm-guide, .jm-nav__badge'));
    }

    public function testAnUnknownScreenIsNotFound(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/jmonitor/nope');
        static::assertResponseStatusCodeSame(404);

        $client->request('GET', '/jmonitor/_fragment/nope');
        static::assertResponseStatusCodeSame(404);
    }

    public function testAPathOfSeveralSegmentsMatchesTheRouteAndIsNotFound(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/jmonitor/requests/detail');
        static::assertRouteSame('jmonitor_screen');
        static::assertResponseStatusCodeSame(404);

        $client->request('GET', '/jmonitor/_fragment/requests/detail');
        static::assertRouteSame('jmonitor_screen_fragment');
        static::assertResponseStatusCodeSame(404);
    }

    public function testSortAndPeriodLinksStayOnTheScreen(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/hello/world');
        $crawler = self::requestUntil($client, '/jmonitor/requests?period=6h&sort=avg&dir=asc', 'tr[data-jm-route="GET app_hello"]');

        static::assertSame('/jmonitor/requests?period=6h&sort=avg&dir=desc', $crawler->filter('th.is-sorted a.jm-sort')->attr('href'));
        static::assertSame('ascending', $crawler->filter('th.is-sorted')->attr('aria-sort'));
        static::assertSame('/jmonitor/requests?period=24h&sort=avg&dir=asc', $crawler->filter('.jm-segmented__item')->eq(2)->attr('href'));
        static::assertSame('/jmonitor/requests?period=6h', $crawler->filter('.jm-nav__item')->eq(1)->attr('href'));
        static::assertSame('/jmonitor/requests?period=6h', $crawler->filter('.jm-subnav__item')->first()->attr('href'));
        static::assertSame('PHP only', trim($crawler->filter('tr[data-jm-route="GET app_hello"] td')->eq(5)->filter('.jm-split__text')->text()));
    }

    public function testTheQueriesScreenListsQueriesAndRepeatedOnes(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/n-plus-one');
        static::assertResponseIsSuccessful();
        $crawler = self::requestUntil($client, '/jmonitor/queries', 'tr[data-jm-nplus1="GET app_n_plus_one"]');

        static::assertResponseIsSuccessful();
        static::assertSame('12', $crawler->filter('tr[data-jm-nplus1="GET app_n_plus_one"]')->attr('data-jm-repeats-max'));
        $query = $crawler->filter('tr[data-jm-query][data-jm-executions="12"]');
        static::assertCount(1, $query);
        static::assertStringContainsString('demo_items', $query->text());
        static::assertContains('Queries', $crawler->filter('.jm-subnav__item > span:first-child')->each(static fn(Crawler $item): string => $item->text()));
        static::assertSame('Queries', $crawler->filter('.jm-subnav__item[aria-current="page"] > span:first-child')->text());
    }

    public function testSortingOnProblemsWhenNoneIsSlowStillListsTheQueries(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/n-plus-one');
        static::assertResponseIsSuccessful();
        $crawler = self::requestUntil($client, '/jmonitor/queries?sort=problems', 'tr[data-jm-query][data-jm-executions="12"]');

        static::assertSame('ready', $crawler->filter('.jm-screen')->attr('data-jm-state'));
        static::assertStringContainsString('demo_items', $crawler->filter('tr[data-jm-query][data-jm-executions="12"]')->text());
        static::assertStringStartsWith('Problems', trim($crawler->filter('th.is-sorted')->text()));

        $crawler = $client->request('GET', '/jmonitor/queries?sort=slow');
        static::assertResponseIsSuccessful();
        static::assertStringStartsWith('Total time', trim($crawler->filter('th.is-sorted')->text()));
        static::assertSame('descending', $crawler->filter('th.is-sorted')->attr('aria-sort'));
    }

    public function testAHostileQueryTextIsShownEscaped(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/hostile-query');
        static::assertResponseIsSuccessful();
        self::requestUntil($client, '/jmonitor/queries', 'tr[data-jm-query] .jm-sql');
        $html = (string) $client->getResponse()->getContent();

        static::assertStringContainsString('&lt;img', $html);
        static::assertStringNotContainsString('<img src=x', $html);
        static::assertStringNotContainsString('<script>', $html);
    }

    public function testTheQueriesScreenShowsStoredQueriesWhileRecordingIsOff(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['enabled' => false] + $this->recording()['recording']]]);
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        static::assertInstanceOf(Connection::class, $connection);
        $now = time();
        (new DbalStorage($connection, 604800))->store(
            [new Aggregate(Period::OneHour, Period::OneHour->bucketFor($now), Metric::QUERY_DURATION, 'a1b2c3d4e5f6a7b8', 3, 6.0, 4.0)],
            $now,
            ['a1b2c3d4e5f6a7b8' => 'SELECT * FROM demo_items WHERE id = ?'],
        );

        $crawler = $client->request('GET', '/jmonitor/queries');

        static::assertResponseIsSuccessful();
        static::assertSame('ready', $crawler->filter('.jm-screen')->attr('data-jm-state'));
        static::assertSame('Queries', $crawler->filter('.jm-subnav__item[aria-current="page"]')->text());
        static::assertStringContainsString('demo_items', $crawler->filter('tr[data-jm-query][data-jm-executions="3"]')->text());
    }

    public function testTheQueriesScreenSaysRecordingIsOffWhenNothingIsStored(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['enabled' => false] + $this->recording()['recording']]]);

        $crawler = $client->request('GET', '/jmonitor/queries');

        static::assertResponseIsSuccessful();
        static::assertSame('recording_disabled', $crawler->filter('.jm-screen')->attr('data-jm-state'));
        static::assertContains('Queries', $crawler->filter('.jm-subnav__item')->each(static fn(Crawler $item): string => $item->text()));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function withoutSqlRecording(): iterable
    {
        yield 'SQL recording off' => [['sql' => ['enabled' => false]], true];
        yield 'SQL recording off while recording is off' => [['enabled' => false, 'sql' => ['enabled' => false]], true];
        yield 'no Doctrine connection' => [[], false];
    }

    /**
     * @param array<string, mixed> $recording
     */
    #[DataProvider('withoutSqlRecording')]
    public function testTheQueriesScreenIsAbsentWithoutSqlRecording(array $recording, bool $doctrine): void
    {
        $client = static::createClient(['doctrine' => $doctrine, 'jmonitor' => ['recording' => $recording + $this->recording()['recording']]]);

        $crawler = $client->request('GET', '/jmonitor/');
        static::assertResponseIsSuccessful();
        static::assertNotContains('Queries', $crawler->filter('.jm-nav__item, .jm-subnav__item')->each(static fn(Crawler $item): string => $item->text()));

        $client->request('GET', '/jmonitor/queries');
        static::assertResponseStatusCodeSame(404);

        $client->request('GET', '/jmonitor/_fragment/queries');
        static::assertResponseStatusCodeSame(404);
    }

    public function testTheNavigationHasTwoLevels(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $crawler = $client->request('GET', '/jmonitor/requests');

        static::assertSame(['Overview', 'Application', 'Background', 'Stack'], $crawler->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()));
        static::assertSame('Application', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
        static::assertSame(
            ['/jmonitor/?period=1h', '/jmonitor/requests?period=1h', '/jmonitor/background?period=1h', '/jmonitor/stack?period=1h'],
            $crawler->filter('.jm-nav__item')->each(static fn(Crawler $item): string => (string) $item->attr('href')),
        );
        static::assertSame('/jmonitor/', $crawler->filter('a.jm-brand')->attr('href'));

        $fragment = $client->request('GET', '/jmonitor/_fragment/queries?period=6h');
        $tabs = $fragment->filter('nav.jm-subnav[aria-label="Application"] .jm-subnav__item');

        static::assertSame(['Requests', 'Queries', 'Cache & HTTP', 'Exceptions'], $tabs->each(static fn(Crawler $item): string => $item->text()));
        static::assertSame('Queries', $fragment->filter('.jm-subnav__item[aria-current="page"]')->text());
        static::assertSame('/jmonitor/requests?period=6h', $tabs->first()->attr('href'));
        static::assertSame('Queries', $fragment->filter('.jm-heading h1')->text());
        static::assertCount(0, $fragment->filter('.jm-nav'));
    }

    public function testTheNavigationListsTheScreensInOrder(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $crawler = $client->request('GET', '/jmonitor/requests');

        static::assertSame(
            ['Requests', 'Queries', 'Cache & HTTP', 'Exceptions'],
            $crawler->filter('.jm-subnav__item')->each(static fn(Crawler $item): string => $item->text()),
        );
    }

    public function testTheNavigationHasThreeSpacesAndFiveTabs(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'security' => true, 'scheduler' => true]);

        $crawler = $client->request('GET', '/jmonitor/requests');

        static::assertSame(['Overview', 'Application', 'Background', 'Stack'], $crawler->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()));
        $tabs = $crawler->filter('nav.jm-subnav[aria-label="Application"] .jm-subnav__item');
        static::assertSame(['Requests', 'Queries', 'Cache & HTTP', 'Exceptions', 'Users'], $tabs->each(static fn(Crawler $item): string => $item->text()));
        static::assertSame(
            ['/jmonitor/requests?period=1h', '/jmonitor/queries?period=1h', '/jmonitor/cache-http?period=1h', '/jmonitor/exceptions?period=1h', '/jmonitor/users?period=1h'],
            $tabs->each(static fn(Crawler $item): string => (string) $item->attr('href')),
        );

        $background = $client->request('GET', '/jmonitor/background', ['period' => '6h']);
        static::assertResponseIsSuccessful();
        static::assertSame('Background', $background->filter('.jm-nav__item[aria-current="page"]')->text());
        static::assertCount(0, $background->filter('.jm-subnav'));
        static::assertSame('Background', $background->filter('.jm-heading h1')->text());
        static::assertSame('Everything your application does without a visitor waiting for the answer, last 6 hours.', $background->filter('.jm-heading p')->text());

        $cacheHttp = $client->request('GET', '/jmonitor/cache-http');
        static::assertResponseIsSuccessful();
        static::assertSame('Application', $cacheHttp->filter('.jm-nav__item[aria-current="page"]')->text());
        static::assertSame('Cache & HTTP', $cacheHttp->filter('.jm-subnav__item[aria-current="page"]')->text());
        static::assertSame('Cache & HTTP', $cacheHttp->filter('.jm-heading h1')->text());
        static::assertSame('What your code waits for besides the database, last hour.', $cacheHttp->filter('.jm-heading p')->text());
    }

    public function testBackgroundShowsMessagesTasksAndCommands(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'scheduler' => true]);
        $application = new Application($client->getKernel());
        $application->setAutoExit(false);

        $client->request('GET', '/dispatch');
        static::assertResponseIsSuccessful();
        static::assertSame(0, (new ApplicationTester($application))->run(
            ['command' => 'messenger:consume', 'receivers' => ['async'], '--limit' => '3', '--time-limit' => '10'],
            ['interactive' => false],
        ));
        static::assertSame(0, (new ApplicationTester($application))->run(['command' => 'app:work']));
        self::requestUntil($client, '/jmonitor/background', 'section#jm-commands tr[data-jm-command="app:work"][data-jm-runs="1"]');
        $crawler = self::requestUntil($client, '/jmonitor/background', 'section#jm-messages tr[data-jm-message][data-jm-sent="3"][data-jm-handled="3"]');

        static::assertResponseIsSuccessful();
        $sections = $crawler->filter('.jm-screen > section.jm-section');
        static::assertSame(['jm-messages', 'jm-tasks', 'jm-commands'], $sections->each(static fn(Crawler $section): string => (string) $section->attr('id')));
        static::assertSame(['Messages', 'Scheduled tasks', 'Commands'], $sections->each(static fn(Crawler $section): string => $section->filter('h2.jm-section__title')->text()));
        static::assertSame(
            ['Work your code hands to Messenger to do later.', 'What Symfony Scheduler starts by the clock.', 'Runs of bin/console, by cron, a deploy or by hand.'],
            $sections->each(static fn(Crawler $section): string => $section->filter('.jm-section__summary')->text()),
        );
        static::assertSame([Ping::class], $crawler->filter('section#jm-messages tr[data-jm-message]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-message')));
        static::assertSame(
            ['app:work --times=2', '@' . PurgeTask::class . '::purge', Ping::class, 'app:work'],
            $crawler->filter('section#jm-tasks tr[data-jm-task]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-task')),
        );
        static::assertSame(['app:work'], $crawler->filter('section#jm-commands tr[data-jm-command]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-command')));
        static::assertSame(['1'], $crawler->filter('section#jm-commands tr[data-jm-command]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-runs')));
        foreach (['messages', 'tasks', 'commands'] as $id) {
            static::assertCount(0, $crawler->filter('section#jm-' . $id . ' .jm-cards'), $id);
            static::assertCount(0, $crawler->filter('section#jm-' . $id . ' .jm-section__empty'), $id);
        }
        static::assertCount(0, $crawler->filter('.jm-state'));
        static::assertSame('Background', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
    }

    public function testBackgroundReadsFromTheSentenceToTheGuide(): void
    {
        [$client, $crawler] = $this->backgroundWithAMessageATaskAndACommand();

        static::assertSame(
            ['jm-lead', 'jm-checks', 'jm-cards', 'jm-section', 'jm-section', 'jm-section', 'jm-guide'],
            $crawler->filter('.jm-screen > section')->each(static fn(Crawler $block): string => explode(' ', (string) $block->attr('class'))[0]),
        );
        static::assertCount(1, $crawler->filter('h1'));
        static::assertCount(0, $crawler->filter('.jm-heading'));
        static::assertSame('3 messages handled, 1 scheduled run and 1 command run in the last hour.', $crawler->filter('h1.jm-lead__title')->text());
        static::assertCount(0, $crawler->filter('.jm-lead__detail'));
        static::assertSame('Worth a look', $crawler->filter('section.jm-checks')->attr('aria-label'));
        static::assertSame('Nothing to flag', $crawler->filter('.jm-checks__empty-title')->text());
        static::assertSame('No failed message, no failed scheduled run, no failed command run in the last hour.', $crawler->filter('.jm-checks__empty-text')->text());

        $cards = $crawler->filter('.jm-screen > .jm-cards .jm-card');
        static::assertSame(['Messages', 'Scheduled tasks', 'Commands'], $cards->each(static fn(Crawler $card): string => $card->filter('.jm-card__label')->text()));
        static::assertSame(['3', '1', '1'], $cards->each(static fn(Crawler $card): string => $card->filter('.jm-card__value')->text()));
        static::assertSame(['handled, 3 sent', 'run of 4 declared tasks', 'run of 1 command'], $cards->each(static fn(Crawler $card): string => $card->filter('.jm-card__hint')->text()));
        static::assertSame(['jm-card', 'jm-card', 'jm-card'], $cards->each(static fn(Crawler $card): string => (string) $card->attr('class')));

        static::assertCount(0, $crawler->filter('.jm-section .jm-cards, .jm-section .jm-lead, .jm-section .jm-checks, .jm-section .jm-guide, .jm-tag'));
        $headers = static fn(string $section): array => $crawler->filter('section#jm-' . $section . ' thead th')->each(static fn(Crawler $th): string => trim(($th->filter('span')->count() > 0 ? $th->filter('span')->first() : $th)->text()));
        static::assertSame(['Message', 'Sent', 'Handled', 'Avg', 'p95', 'Max', 'Where the average goes', 'Problems'], $headers('messages'));
        static::assertSame(['Task', 'Schedule', 'Next run', 'Last run', 'Runs', 'Avg', 'Problems'], $headers('tasks'));
        static::assertSame(['Command', 'Runs', 'Avg', 'Max', 'Last run', 'Where the average goes', 'Problems', 'Memory'], $headers('commands'));
        static::assertCount(1, $crawler->filter('section#jm-messages .jm-legend'));
        static::assertCount(0, $crawler->filter('section#jm-tasks .jm-legend'));
        static::assertCount(1, $crawler->filter('section#jm-commands .jm-legend'));
        static::assertSame([Ping::class], $crawler->filter('section#jm-messages tr[data-jm-message]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-message')));
        static::assertCount(1, $crawler->filter('section#jm-messages tr[data-jm-message] .jm-split'));
        static::assertCount(1, $crawler->filter('section#jm-commands tr[data-jm-command="app:work"] .jm-split'));
        static::assertSame('every 1 second', $crawler->filter('section#jm-tasks tr[data-jm-task="app:work"] td')->eq(0)->text());

        static::assertSame('How to read this screen', $crawler->filter('.jm-guide__title')->text());
        static::assertSame(['Sent and handled.', 'A task with 0 run.', 'Commands.'], $crawler->filter('.jm-guide li strong')->each(static fn(Crawler $term): string => $term->text()));
        static::assertCount(0, $crawler->filter('.jm-nav__badge'));

        foreach (['messages.failed', 'messages.retried', 'messages.sql', 'tasks.max', 'tasks.failed', 'commands.failed', 'commands.sql'] as $removed) {
            $old = $client->request('GET', '/jmonitor/background', ['sort' => $removed, 'dir' => 'asc']);

            static::assertResponseIsSuccessful($removed);
            static::assertSame(
                [['Handled', 'descending'], ['Next run', 'ascending'], ['Last run', 'descending']],
                $old->filter('th.is-sorted')->each(static fn(Crawler $th): array => [$th->filter('.jm-sort span')->first()->text(), (string) $th->attr('aria-sort')]),
                $removed,
            );
        }
    }

    public function testTheAnchorCardsLeadToTheirSections(): void
    {
        [, $crawler] = $this->backgroundWithAMessageATaskAndACommand();

        $links = $crawler->filter('.jm-screen > .jm-cards a.jm-card__link');
        static::assertSame(['#jm-messages', '#jm-tasks', '#jm-commands'], $links->each(static fn(Crawler $link): string => (string) $link->attr('href')));
        foreach ($links as $link) {
            \assert($link instanceof \DOMElement);
            $id = substr($link->getAttribute('href'), 1);
            static::assertCount(1, $crawler->filter('.jm-screen > section.jm-section#' . $id), $id);
            static::assertSame($crawler->filter('section#' . $id . ' h2.jm-section__title')->text(), $link->textContent, $id);
        }
    }

    public function testBackgroundWithoutAnythingRecordedKeepsItsTitle(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'scheduler' => true]);

        $crawler = $client->request('GET', '/jmonitor/background');

        static::assertResponseIsSuccessful();
        static::assertSame('Background', $crawler->filter('.jm-heading h1')->text());
        static::assertCount(1, $crawler->filter('h1'));
        static::assertSame(['jm-section', 'jm-section', 'jm-section'], $crawler->filter('.jm-screen > section')->each(static fn(Crawler $block): string => explode(' ', (string) $block->attr('class'))[0]));
        static::assertCount(4, $crawler->filter('section#jm-tasks tr[data-jm-task]'));
        static::assertCount(0, $crawler->filter('.jm-lead, .jm-checks, .jm-guide, .jm-cards'));
    }

    public function testCacheAndHttpShareAScreen(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/cached');
            static::assertResponseIsSuccessful();
        }
        for ($i = 0; $i < 2; ++$i) {
            $client->request('GET', '/call');
            static::assertResponseIsSuccessful();
        }
        self::requestUntil($client, '/jmonitor/cache-http', 'section#jm-cache tr[data-jm-pool="cache.app"][data-jm-reads="3"]');
        $crawler = self::requestUntil($client, '/jmonitor/cache-http', 'section#jm-http tr[data-jm-host="api.example.com:8443"][data-jm-calls="2"]');

        static::assertResponseIsSuccessful();
        $sections = $crawler->filter('.jm-screen > section.jm-section');
        static::assertSame(['jm-cache', 'jm-http'], $sections->each(static fn(Crawler $section): string => (string) $section->attr('id')));
        static::assertSame(['Cache', 'Outgoing HTTP'], $sections->each(static fn(Crawler $section): string => $section->filter('h2.jm-section__title')->text()));
        static::assertSame(
            ['What your code keeps aside to avoid computing or fetching it again.', 'Calls your code makes to other sites and APIs through the Symfony HTTP client.'],
            $sections->each(static fn(Crawler $section): string => $section->filter('.jm-section__summary')->text()),
        );
        static::assertSame(['cache.app'], $crawler->filter('section#jm-cache tr[data-jm-pool]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-pool')));
        static::assertSame(['3'], $crawler->filter('section#jm-cache tr[data-jm-pool]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-reads')));
        static::assertSame(['api.example.com:8443'], $crawler->filter('section#jm-http tr[data-jm-host]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-host')));
        static::assertSame('Cache & HTTP', $crawler->filter('.jm-subnav__item[aria-current="page"]')->text());
    }

    public function testCacheAndHttpReadsFromTheSentenceToTheGuide(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/cached');
            static::assertResponseIsSuccessful();
        }
        for ($i = 0; $i < 2; ++$i) {
            $client->request('GET', '/call');
            static::assertResponseIsSuccessful();
        }
        self::requestUntil($client, '/jmonitor/cache-http', 'section#jm-cache tr[data-jm-pool="cache.app"][data-jm-reads="3"]');
        $crawler = self::requestUntil($client, '/jmonitor/cache-http', 'section#jm-http tr[data-jm-host="api.example.com:8443"][data-jm-calls="2"]');

        static::assertSame(
            ['jm-lead', 'jm-checks', 'jm-section', 'jm-section', 'jm-guide'],
            $crawler->filter('.jm-screen > section')->each(static fn(Crawler $block): string => explode(' ', (string) $block->attr('class'))[0]),
        );
        static::assertCount(1, $crawler->filter('h1'));
        static::assertCount(0, $crawler->filter('.jm-heading'));
        static::assertSame('3 cache reads (67 % found) and 2 outgoing calls in the last hour.', $crawler->filter('h1.jm-lead__title')->text());
        static::assertCount(0, $crawler->filter('.jm-lead__detail'));
        static::assertSame('Worth a look', $crawler->filter('section.jm-checks')->attr('aria-label'));
        static::assertSame('Nothing to flag', $crawler->filter('.jm-checks__empty-title')->text());
        static::assertSame('No pool with a low hit rate, no call without an answer, no error response in the last hour.', $crawler->filter('.jm-checks__empty-text')->text());
        static::assertSame(['Reads', 'Hit rate', 'Writes', 'Deletes'], $crawler->filter('section#jm-cache .jm-card__label')->each(static fn(Crawler $label): string => $label->text()));
        static::assertSame(['Calls', 'Time in calls', 'Error responses', 'No answer'], $crawler->filter('section#jm-http .jm-card__label')->each(static fn(Crawler $label): string => $label->text()));
        static::assertSame('To 1 host.', $crawler->filter('section#jm-http .jm-card__hint')->first()->text());
        static::assertSame(
            ['Pool', 'Reads', 'Hit rate', 'Misses', 'Writes', 'Deletes', 'Problems'],
            $crawler->filter('section#jm-cache thead th')->each(static fn(Crawler $th): string => trim(($th->filter('span')->count() > 0 ? $th->filter('span')->first() : $th)->text())),
        );
        static::assertSame(
            ['Host', 'Calls', 'Total time', 'Avg', 'Max', 'Problems'],
            $crawler->filter('section#jm-http thead th')->each(static fn(Crawler $th): string => trim(($th->filter('span')->count() > 0 ? $th->filter('span')->first() : $th)->text())),
        );
        static::assertSame('1 host, the most expensive first', $crawler->filter('section#jm-http .jm-panel__meta')->text());
        static::assertSame('A host appears here the first time your code calls it.', $crawler->filter('section#jm-http .jm-panel__footnote')->text());
        static::assertSame('67 %', $crawler->filter('section#jm-cache tr[data-jm-pool="cache.app"] .jm-bar__value')->text());
        static::assertCount(1, $crawler->filter('section#jm-http tr[data-jm-host="api.example.com:8443"] .jm-bar'));
        static::assertCount(0, $crawler->filter('.jm-section .jm-lead, .jm-section .jm-checks, .jm-section .jm-guide, .jm-tag'));
        static::assertSame('How to read this screen', $crawler->filter('.jm-guide__title')->text());
        static::assertSame(
            ['Hit rate.', 'Time in outgoing calls.', 'Three kinds of problem.'],
            $crawler->filter('.jm-guide li strong')->each(static fn(Crawler $term): string => $term->text()),
        );
        static::assertSame(['/jmonitor/requests?period=1h'], $crawler->filter('.jm-guide a')->each(static fn(Crawler $link): string => (string) $link->attr('href')));
        static::assertCount(0, $crawler->filter('.jm-subnav .jm-nav__badge'));

        foreach (['cache.hits', 'http.4xx', 'http.5xx', 'http.failed'] as $removed) {
            $old = $client->request('GET', '/jmonitor/cache-http', ['sort' => $removed, 'dir' => 'asc']);

            static::assertResponseIsSuccessful($removed);
            static::assertSame(
                [['Reads', 'descending'], ['Total time', 'descending']],
                $old->filter('th.is-sorted')->each(static fn(Crawler $th): array => [$th->filter('.jm-sort span')->first()->text(), (string) $th->attr('aria-sort')]),
                $removed,
            );
        }
    }

    public function testTheFormerAddressesAreNotFound(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'scheduler' => true]);

        foreach (['commands', 'messages', 'scheduler', 'http-client', 'cache'] as $address) {
            $client->request('GET', '/jmonitor/' . $address);
            static::assertResponseStatusCodeSame(404, $address);

            $client->request('GET', '/jmonitor/_fragment/' . $address);
            static::assertResponseStatusCodeSame(404, '_fragment/' . $address);

            $client->request('POST', '/jmonitor/_action/' . $address, ['action' => 'refresh'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
            static::assertResponseStatusCodeSame(404, '_action/' . $address);
        }

        foreach (['background', 'cache-http', 'stack', '_fragment/background', '_fragment/cache-http', '_fragment/stack'] as $address) {
            $client->request('GET', '/jmonitor/' . $address);
            static::assertResponseIsSuccessful($address);
        }
    }

    public function testASectionWithoutActivityIsOneLine(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $crawler = $client->request('GET', '/jmonitor/background');

        static::assertResponseIsSuccessful();
        $commands = $crawler->filter('section#jm-commands');
        static::assertSame(['jm-section__header', 'jm-section__empty'], $commands->children()->each(static fn(Crawler $child): string => (string) $child->attr('class')));
        static::assertSame('No command ran in the last hour.', $commands->filter('.jm-section__empty strong')->text());
        static::assertSame(
            'No command ran in the last hour. Run a console command: it shows up here as soon as it ends. Internal commands such as cache:clear and messenger:consume are not recorded.',
            $commands->filter('.jm-section__empty')->text(),
        );
        static::assertCount(0, $crawler->filter('.jm-state, .jm-cards, table'));

        $week = $client->request('GET', '/jmonitor/_fragment/background', ['period' => '7d']);
        static::assertSame('No command ran in the last 7 days.', $week->filter('section#jm-commands .jm-section__empty strong')->text());

        $cacheHttp = $client->request('GET', '/jmonitor/cache-http');
        static::assertSame(
            ['No cache read or write in the last hour.', 'No outgoing call in the last hour.'],
            $cacheHttp->filter('.jm-screen > section.jm-section .jm-section__empty strong')->each(static fn(Crawler $line): string => $line->text()),
        );
        static::assertCount(0, $cacheHttp->filter('.jm-state, .jm-cards, table'));
    }

    public function testBackgroundDisappearsWithoutAnyOfItsSections(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['commands' => ['enabled' => false]] + $this->recording()['recording']]]);

        $crawler = $client->request('GET', '/jmonitor/');
        static::assertSame(['Overview', 'Application', 'Stack'], $crawler->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()));

        foreach (['background', '_fragment/background', 'commands'] as $address) {
            $client->request('GET', '/jmonitor/' . $address);
            static::assertResponseStatusCodeSame(404, $address);
        }
        $client->request('POST', '/jmonitor/_action/background', ['action' => 'refresh'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        static::assertResponseStatusCodeSame(404);
    }

    public function testCacheAndHttpDisappearsWithoutAnyOfItsSections(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['cache' => false, 'http_client' => false] + $this->recording()['recording']]]);

        $crawler = $client->request('GET', '/jmonitor/requests');
        static::assertSame(['Requests', 'Queries', 'Exceptions'], $crawler->filter('.jm-subnav__item')->each(static fn(Crawler $item): string => $item->text()));

        foreach (['cache-http', '_fragment/cache-http'] as $address) {
            $client->request('GET', '/jmonitor/' . $address);
            static::assertResponseStatusCodeSame(404, $address);
        }

        self::ensureKernelShutdown();
        $client = static::createClient(['jmonitor' => ['recording' => ['cache' => false] + $this->recording()['recording']]]);
        $crawler = $client->request('GET', '/jmonitor/cache-http');
        static::assertResponseIsSuccessful();
        static::assertSame(['jm-http'], $crawler->filter('.jm-screen > section.jm-section')->each(static fn(Crawler $section): string => (string) $section->attr('id')));
    }

    public function testSortingCommandsLeavesMessagesAndTasksAlone(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'scheduler' => true]);
        $application = new Application($client->getKernel());
        $application->setAutoExit(false);

        $client->request('GET', '/dispatch', ['count' => '1']);
        static::assertResponseIsSuccessful();
        static::assertSame(0, (new ApplicationTester($application))->run(['command' => 'app:work']));
        self::requestUntil($client, '/jmonitor/background', 'section#jm-messages tr[data-jm-message][data-jm-sent="1"]');
        $crawler = self::requestUntil($client, '/jmonitor/background?sort=commands.runs&dir=asc', 'section#jm-commands tr[data-jm-command="app:work"][data-jm-runs="1"]');

        static::assertResponseIsSuccessful();
        $sorted = static fn(string $section): array => $crawler->filter('section#jm-' . $section . ' th.is-sorted')->each(static fn(Crawler $th): array => [$th->filter('.jm-sort span')->first()->text(), (string) $th->attr('aria-sort')]);
        static::assertSame([['Runs', 'ascending']], $sorted('commands'));
        static::assertSame([['Handled', 'descending']], $sorted('messages'));
        static::assertSame([['Next run', 'ascending']], $sorted('tasks'));

        $link = static fn(string $section, string $label): string => (string) $crawler->filter('section#jm-' . $section . ' th a.jm-sort')->reduce(static fn(Crawler $a): bool => $a->filter('span')->first()->text() === $label)->attr('href');
        static::assertSame('/jmonitor/background?period=1h&sort=commands.runs&dir=desc#jm-commands', $link('commands', 'Runs'));
        static::assertSame('/jmonitor/background?period=1h&sort=messages.handled&dir=asc#jm-messages', $link('messages', 'Handled'));
        static::assertSame('/jmonitor/background?period=1h&sort=tasks.next&dir=desc#jm-tasks', $link('tasks', 'Next run'));
    }

    public function testTheCommandsSectionIsAbsentWithoutCommandRecording(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['commands' => ['enabled' => false]] + $this->recording()['recording']], 'messenger' => true]);

        $crawler = $client->request('GET', '/jmonitor/background');

        static::assertResponseIsSuccessful();
        static::assertSame(['jm-messages'], $crawler->filter('.jm-screen > section.jm-section')->each(static fn(Crawler $section): string => (string) $section->attr('id')));
    }

    public function testTheMessagesSectionFollowsMessenger(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $crawler = $client->request('GET', '/jmonitor/background');
        static::assertResponseIsSuccessful();
        static::assertSame(['jm-commands'], $crawler->filter('.jm-screen > section.jm-section')->each(static fn(Crawler $section): string => (string) $section->attr('id')));

        self::ensureKernelShutdown();
        $client = static::createClient(['jmonitor' => $this->recording(), 'messenger' => true]);

        $crawler = $client->request('GET', '/jmonitor/background');
        static::assertResponseIsSuccessful();
        static::assertSame(['jm-messages', 'jm-commands'], $crawler->filter('.jm-screen > section.jm-section')->each(static fn(Crawler $section): string => (string) $section->attr('id')));
        static::assertSame(['Overview', 'Application', 'Background', 'Stack'], $crawler->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()));
    }

    public function testTheNavigationIsTheSameWithTheQueuesCounted(): void
    {
        $counting = ['stack' => ['end_of_life' => false, 'symfony' => ['flex' => false, 'messenger' => true]]];
        $client = static::createClient(['jmonitor' => $this->recording() + $counting, 'messenger' => true]);
        $spaces = ['Overview', 'Application', 'Background', 'Stack'];
        $tabs = ['Requests', 'Queries', 'Cache & HTTP', 'Exceptions'];

        $home = $client->request('GET', '/jmonitor/requests');
        static::assertResponseIsSuccessful();
        static::assertSame($spaces, $home->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()));
        static::assertSame($tabs, $home->filter('.jm-subnav__item')->each(static fn(Crawler $item): string => $item->text()));
        static::assertSame('Requests', $home->filter('.jm-subnav__item[aria-current="page"]')->text());

        $background = $client->request('GET', '/jmonitor/background');
        static::assertResponseIsSuccessful();
        static::assertSame($spaces, $background->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()));
        static::assertSame('Background', $background->filter('.jm-nav__item[aria-current="page"]')->text());
    }

    public function testTheMessagesSectionIsAbsentWithoutMessageRecording(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['messenger' => false] + $this->recording()['recording']], 'messenger' => true]);

        $crawler = $client->request('GET', '/jmonitor/background');

        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('section#jm-messages'));
    }

    public function testTheTasksSectionListsTheDeclaredTasksBeforeAnyRun(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'scheduler' => true]);

        $crawler = $client->request('GET', '/jmonitor/background');

        static::assertResponseIsSuccessful();
        static::assertSame(
            ['app:work --times=2', '@' . PurgeTask::class . '::purge', Ping::class, 'app:work'],
            $crawler->filter('section#jm-tasks tr[data-jm-task]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-task')),
        );
        $nextRuns = $crawler->filter('section#jm-tasks tr[data-jm-task]')->each(static fn(Crawler $row): string => $row->filter('td')->eq(1)->text());
        static::assertMatchesRegularExpression('/^in \d+ d$/', $nextRuns[0]);
        static::assertMatchesRegularExpression('/^in \d+ d$/', $nextRuns[1]);
        static::assertSame(['—', '—'], \array_slice($nextRuns, 2));
        static::assertSame('0', $crawler->filter('section#jm-tasks tr[data-jm-task="app:work"]')->attr('data-jm-runs'));
        static::assertStringContainsString('every 1 second', $crawler->filter('section#jm-tasks tr[data-jm-task="app:work"]')->text());
    }

    public function testARunOfADeclaredTaskLandsOnItsRow(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'scheduler' => true]);
        $application = new Application($client->getKernel());
        $application->setAutoExit(false);

        static::assertSame(0, (new ApplicationTester($application))->run(
            ['command' => 'messenger:consume', 'receivers' => ['scheduler_default'], '--limit' => '1', '--time-limit' => '10'],
            ['interactive' => false],
        ));
        $crawler = self::requestUntil($client, '/jmonitor/background', 'section#jm-tasks tr[data-jm-task="app:work"][data-jm-runs="1"]');

        static::assertSame('1', $crawler->filter('section#jm-tasks tr[data-jm-task="app:work"]')->attr('data-jm-runs'));
        static::assertSame(
            ['app:work --times=2', '@' . PurgeTask::class . '::purge', Ping::class, 'app:work'],
            $crawler->filter('section#jm-tasks tr[data-jm-task]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-task')),
        );
    }

    public function testATaskDeclaredByAnAttributeIsLabelledByTheServiceMethodItCalls(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'scheduler' => true]);

        $crawler = $client->request('GET', '/jmonitor/background');

        static::assertResponseIsSuccessful();
        $schedules = array_column($crawler->filter('section#jm-tasks tr[data-jm-task]')->each(
            static fn(Crawler $row): array => [(string) $row->attr('data-jm-task'), $row->filter('td')->eq(0)->text()],
        ), 1, 0);
        static::assertSame('every 1 day', $schedules['@' . PurgeTask::class . '::purge'] ?? null);
        static::assertArrayNotHasKey(ServiceCallMessage::class, $schedules);
    }

    public function testTheTasksSectionIsAbsentWithoutTheScheduler(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'messenger' => true]);

        $crawler = $client->request('GET', '/jmonitor/background');

        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('section#jm-tasks'));
    }

    public function testTheTasksSectionIsAbsentWithoutTaskRecording(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['scheduler' => false] + $this->recording()['recording']], 'scheduler' => true]);

        $crawler = $client->request('GET', '/jmonitor/background');

        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('section#jm-tasks'));
    }

    public function testRecordingOffIsToldOnceOnBackgroundEvenWithDeclaredTasks(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['enabled' => false] + $this->recording()['recording']], 'scheduler' => true]);

        $crawler = $client->request('GET', '/jmonitor/background');
        $html = (string) $client->getResponse()->getContent();

        static::assertResponseIsSuccessful();
        static::assertSame(1, substr_count($html, 'Recording is off'));
        static::assertSame(['Recording is off'], $crawler->filter('.jm-screen > .jm-state .jm-state__title')->each(static fn(Crawler $title): string => $title->text()));
        static::assertSame(['jm-tasks'], $crawler->filter('.jm-screen > section.jm-section')->each(static fn(Crawler $section): string => (string) $section->attr('id')));
        static::assertCount(4, $crawler->filter('section#jm-tasks tr[data-jm-task]'));
        static::assertStringNotContainsString('No command ran', $html);
        static::assertStringNotContainsString('No message was sent', $html);
    }

    /**
     * @param array{environment?: string, debug?: bool, jmonitor?: array<string, mixed>, doctrine?: bool, security?: bool, twig?: bool, messenger?: bool, scheduler?: bool} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel(
            $options['environment'] ?? 'test',
            $options['debug'] ?? true,
            $options['jmonitor'] ?? [],
            $options['doctrine'] ?? true,
            $options['security'] ?? false,
            $options['twig'] ?? true,
            withMessenger: $options['messenger'] ?? false,
            withScheduler: $options['scheduler'] ?? false,
        );
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }

    /**
     * @return array{KernelBrowser, Crawler} the page once 3 messages are handled, the task app:work ran once and so did its command
     */
    private function backgroundWithAMessageATaskAndACommand(): array
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'scheduler' => true]);
        $application = new Application($client->getKernel());
        $application->setAutoExit(false);

        $client->request('GET', '/dispatch');
        static::assertResponseIsSuccessful();
        static::assertSame(0, (new ApplicationTester($application))->run(
            ['command' => 'messenger:consume', 'receivers' => ['async'], '--limit' => '3', '--time-limit' => '10'],
            ['interactive' => false],
        ));
        static::assertSame(0, (new ApplicationTester($application))->run(
            ['command' => 'messenger:consume', 'receivers' => ['scheduler_default'], '--limit' => '1', '--time-limit' => '10'],
            ['interactive' => false],
        ));
        self::requestUntil($client, '/jmonitor/background', 'section#jm-messages tr[data-jm-message][data-jm-sent="3"][data-jm-handled="3"]');
        self::requestUntil($client, '/jmonitor/background', 'section#jm-tasks tr[data-jm-task="app:work"][data-jm-runs="1"]');
        $crawler = self::requestUntil($client, '/jmonitor/background', 'section#jm-commands tr[data-jm-command="app:work"][data-jm-runs="1"]');

        return [$client, $crawler];
    }

    /**
     * @return list<string> each element of the screen that is a block of the Overview, named by its class
     */
    private static function blocksOf(Crawler $crawler): array
    {
        $blocks = ['jm-heading', 'jm-lead', 'jm-state', 'jm-checks', 'jm-map', 'jm-places'];

        return array_values(array_filter($crawler->filter('.jm-screen > *')->each(
            static fn(Crawler $element): string => array_values(array_intersect($blocks, explode(' ', (string) $element->attr('class'))))[0] ?? '',
        ), static fn(string $block): bool => $block !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function recording(): array
    {
        return ['recording' => ['enabled' => true, 'flush_interval' => 1, 'buffer_dir' => $this->bufferDir]];
    }
}
