<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentRegistry;
use Jmonitor\JmonitorBundle\Stack\Detection\PhpDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\SymfonyDetector;
use Jmonitor\JmonitorBundle\Stack\Detection\SystemDetector;
use Jmonitor\JmonitorBundle\Stack\Read\LocalStackReader;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheProbe;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeStatusPages;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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

        foreach (['/jmonitor/stack/mysql', '/jmonitor/stack/php/x', '/jmonitor/_fragment/stack/mysql'] as $url) {
            $client->request('GET', $url);
            static::assertResponseStatusCodeSame(404, $url);
        }

        $client->request('POST', '/jmonitor/_action/stack/mysql', ['action' => 'refresh'], server: self::SAME_ORIGIN);
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

    private static function statusPages(KernelBrowser $client): FakeStatusPages
    {
        $pages = $client->getContainer()->get(FakeStatusPages::class);
        static::assertInstanceOf(FakeStatusPages::class, $pages);

        return $pages;
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, doctrine?: bool, internals?: bool, fakeWebServer?: string} $options
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
     * @param array{jmonitor?: array<string, mixed>, doctrine?: bool, internals?: bool, fakeWebServer?: string} $options
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
        );
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }
}
