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

        $crawler = $client->request('GET', '/jmonitor/');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('[data-jm-state="empty"]'));
        static::assertSame('JMonitor — Requests', $crawler->filter('title')->text());
    }

    public function testNoConnectionState(): void
    {
        $client = static::createClient(['doctrine' => false]);

        $crawler = $client->request('GET', '/jmonitor/');

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

    public function testStorageFailureIsShown(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/hello/world');
        $client->disableReboot();
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        $connection->beginTransaction();

        $crawler = self::requestUntil($client, '/jmonitor/', '[data-jm-state="storage_error"]');
        $connection->rollBack();

        static::assertResponseStatusCodeSame(200);
        static::assertCount(1, $crawler->filter('[data-jm-state="storage_error"]'));
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

    public function testTheRequestsScreenIsTheDefaultScreen(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/jmonitor/');
        $default = (string) $client->getResponse()->getContent();
        $crawler = $client->request('GET', '/jmonitor/requests');

        static::assertResponseIsSuccessful();
        static::assertSame($default, (string) $client->getResponse()->getContent());
        static::assertSame('Requests', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
        static::assertStringContainsString('jmonitor.css?v=', $default);
        static::assertSame('/jmonitor/_fragment/requests', $crawler->filter('[data-jm-fragment-url]')->attr('data-jm-fragment-url'));

        $client->request('GET', '/jmonitor/_fragment/requests');
        static::assertResponseIsSuccessful();
        static::assertStringNotContainsString('<html', (string) $client->getResponse()->getContent());
    }

    public function testAnUnknownScreenIsNotFound(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/jmonitor/nope');
        static::assertResponseStatusCodeSame(404);

        $client->request('GET', '/jmonitor/_fragment/nope');
        static::assertResponseStatusCodeSame(404);
    }

    public function testSortAndPeriodLinksStayOnTheScreen(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/hello/world');
        $crawler = self::requestUntil($client, '/jmonitor/requests?period=6h&sort=sql&dir=asc', 'tr[data-jm-route="GET app_hello"]');

        static::assertSame('/jmonitor/requests?period=6h&sort=sql&dir=desc', $crawler->filter('th.is-sorted a.jm-sort')->attr('href'));
        static::assertSame('ascending', $crawler->filter('th.is-sorted')->attr('aria-sort'));
        static::assertSame('/jmonitor/requests?period=24h&sort=sql&dir=asc', $crawler->filter('.jm-segmented__item')->eq(2)->attr('href'));
        static::assertSame('/jmonitor/requests?period=6h', $crawler->filter('.jm-nav__item')->attr('href'));
        static::assertSame('—', trim($crawler->filter('tr[data-jm-route="GET app_hello"] td')->eq(5)->text()));
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
        static::assertStringContainsString('Queries', $crawler->filter('.jm-nav')->text());
        static::assertSame('Queries', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
    }

    public function testSortingOnSlowQueriesWhenNoneIsSlowStillListsTheQueries(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/n-plus-one');
        static::assertResponseIsSuccessful();
        $crawler = self::requestUntil($client, '/jmonitor/queries?sort=slow', 'tr[data-jm-query][data-jm-executions="12"]');

        static::assertSame('ready', $crawler->filter('.jm-screen')->attr('data-jm-state'));
        static::assertStringContainsString('demo_items', $crawler->filter('tr[data-jm-query][data-jm-executions="12"]')->text());
        static::assertStringStartsWith('Slow', trim($crawler->filter('th.is-sorted')->text()));
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
        static::assertSame('Queries', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
        static::assertStringContainsString('demo_items', $crawler->filter('tr[data-jm-query][data-jm-executions="3"]')->text());
    }

    public function testTheQueriesScreenSaysRecordingIsOffWhenNothingIsStored(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['enabled' => false] + $this->recording()['recording']]]);

        $crawler = $client->request('GET', '/jmonitor/queries');

        static::assertResponseIsSuccessful();
        static::assertSame('recording_disabled', $crawler->filter('.jm-screen')->attr('data-jm-state'));
        static::assertStringContainsString('Queries', $crawler->filter('.jm-nav')->text());
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
        static::assertStringNotContainsString('Queries', $crawler->filter('.jm-nav')->text());

        $client->request('GET', '/jmonitor/queries');
        static::assertResponseStatusCodeSame(404);

        $client->request('GET', '/jmonitor/_fragment/queries');
        static::assertResponseStatusCodeSame(404);
    }

    public function testTheNavigationListsTheScreensInOrder(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $crawler = $client->request('GET', '/jmonitor/');

        static::assertSame(
            ['Requests', 'Queries', 'Commands', 'HTTP client', 'Cache', 'Exceptions'],
            $crawler->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()),
        );
    }

    public function testTheCommandsScreenListsARunCommand(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);
        $application = new Application($client->getKernel());
        $application->setAutoExit(false);

        static::assertSame(0, (new ApplicationTester($application))->run(['command' => 'app:work']));
        $crawler = self::requestUntil($client, '/jmonitor/commands', 'tr[data-jm-command="app:work"]');

        static::assertResponseIsSuccessful();
        static::assertSame('1', $crawler->filter('tr[data-jm-command="app:work"]')->attr('data-jm-runs'));
        static::assertStringContainsString('app:work', $crawler->filter('tr[data-jm-command="app:work"]')->text());
        static::assertSame('Commands', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
    }

    public function testTheCommandsScreenIsAbsentWithoutCommandRecording(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['commands' => ['enabled' => false]] + $this->recording()['recording']]]);

        $crawler = $client->request('GET', '/jmonitor/');
        static::assertNotContains('Commands', $crawler->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()));

        $client->request('GET', '/jmonitor/commands');
        static::assertResponseStatusCodeSame(404);
    }

    public function testTheMessagesScreenIsNotFoundWithoutMessenger(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/jmonitor/messages');
        static::assertResponseStatusCodeSame(404);

        $client->request('GET', '/jmonitor/_fragment/messages');
        static::assertResponseStatusCodeSame(404);
    }

    public function testTheMessagesScreenFollowsMessenger(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'messenger' => true]);

        $crawler = $client->request('GET', '/jmonitor/messages');

        static::assertResponseIsSuccessful();
        static::assertSame(
            ['Requests', 'Queries', 'Commands', 'Messages', 'HTTP client', 'Cache', 'Exceptions'],
            $crawler->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()),
        );
        static::assertSame('Messages', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
    }

    public function testTheMessagesScreenIsAbsentWithoutMessageRecording(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['messenger' => false] + $this->recording()['recording']], 'messenger' => true]);

        $client->request('GET', '/jmonitor/messages');
        static::assertResponseStatusCodeSame(404);
    }

    public function testTheSchedulerScreenListsTheDeclaredTasksBeforeAnyRun(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'scheduler' => true]);

        $crawler = $client->request('GET', '/jmonitor/scheduler');

        static::assertResponseIsSuccessful();
        static::assertSame(
            ['Requests', 'Queries', 'Commands', 'Messages', 'Scheduler', 'HTTP client', 'Cache', 'Exceptions'],
            $crawler->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()),
        );
        static::assertSame('Scheduler', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
        static::assertSame(
            ['app:work --times=2', '@' . PurgeTask::class . '::purge', Ping::class, 'app:work'],
            $crawler->filter('tr[data-jm-task]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-task')),
        );
        $nextRuns = $crawler->filter('tr[data-jm-task]')->each(static fn(Crawler $row): string => $row->filter('td')->eq(2)->text());
        static::assertMatchesRegularExpression('/^in \d+ d$/', $nextRuns[0]);
        static::assertMatchesRegularExpression('/^in \d+ d$/', $nextRuns[1]);
        static::assertSame(['—', '—'], \array_slice($nextRuns, 2));
        static::assertSame('0', $crawler->filter('tr[data-jm-task="app:work"]')->attr('data-jm-runs'));
        static::assertStringContainsString('every 1 second', $crawler->filter('tr[data-jm-task="app:work"]')->text());
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
        $crawler = self::requestUntil($client, '/jmonitor/scheduler', 'tr[data-jm-task="app:work"][data-jm-runs="1"]');

        static::assertSame('1', $crawler->filter('tr[data-jm-task="app:work"]')->attr('data-jm-runs'));
        static::assertSame(
            ['app:work --times=2', '@' . PurgeTask::class . '::purge', Ping::class, 'app:work'],
            $crawler->filter('tr[data-jm-task]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-task')),
        );
    }

    public function testATaskDeclaredByAnAttributeIsLabelledByTheServiceMethodItCalls(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'scheduler' => true]);

        $crawler = $client->request('GET', '/jmonitor/scheduler');

        static::assertResponseIsSuccessful();
        $schedules = array_column($crawler->filter('tr[data-jm-task]')->each(
            static fn(Crawler $row): array => [(string) $row->attr('data-jm-task'), $row->filter('td')->eq(0)->text()],
        ), 1, 0);
        static::assertSame('maintenance', $schedules['@' . PurgeTask::class . '::purge'] ?? null);
        static::assertArrayNotHasKey(ServiceCallMessage::class, $schedules);
    }

    public function testTheSchedulerScreenIsNotFoundWithoutTheScheduler(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'messenger' => true]);

        $crawler = $client->request('GET', '/jmonitor/');
        static::assertNotContains('Scheduler', $crawler->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()));

        $client->request('GET', '/jmonitor/scheduler');
        static::assertResponseStatusCodeSame(404);

        $client->request('GET', '/jmonitor/_fragment/scheduler');
        static::assertResponseStatusCodeSame(404);
    }

    public function testTheSchedulerScreenIsAbsentWithoutTaskRecording(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['scheduler' => false] + $this->recording()['recording']], 'scheduler' => true]);

        $client->request('GET', '/jmonitor/scheduler');
        static::assertResponseStatusCodeSame(404);
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
     * @return array<string, mixed>
     */
    private function recording(): array
    {
        return ['recording' => ['enabled' => true, 'flush_interval' => 1, 'buffer_dir' => $this->bufferDir]];
    }
}
