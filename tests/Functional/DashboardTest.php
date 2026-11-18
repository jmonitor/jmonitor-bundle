<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

final class DashboardTest extends WebTestCase
{
    use PollsThePage;

    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-dashboard-' . bin2hex(random_bytes(6));
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
        $crawler = self::requestUntil($client, '/jmonitor/', 'tr[data-jm-route="GET app_hello"]');
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

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function withoutSqlRecording(): iterable
    {
        yield 'SQL recording off' => [['sql' => ['enabled' => false]], true];
        yield 'recording off' => [['enabled' => false], true];
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

    /**
     * @param array{environment?: string, debug?: bool, jmonitor?: array<string, mixed>, doctrine?: bool, security?: bool, twig?: bool} $options
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
