<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Embed\GalleryScreen;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class EmbeddedPageTest extends WebTestCase
{
    use PollsThePage;

    private const ADMIN = ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'test'];

    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-embedded-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testTheNineScreensShowUnderTheRoutesOfTheHost(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'messenger' => true, 'scheduler' => true, 'security' => true]);
        $client->setServerParameters(self::ADMIN);

        foreach (['requests', 'queries', 'commands', 'messages', 'scheduler', 'http-client', 'cache', 'exceptions', 'users'] as $id) {
            $crawler = $client->request('GET', '/back-office/monitoring/' . $id);

            static::assertResponseIsSuccessful($id);
            static::assertStringStartsWith('/back-office/monitoring/' . $id . '?', (string) $crawler->filter('.jm .jm-nav__item[aria-current="page"]')->attr('href'), $id);

            $urls = [
                ...$crawler->filter('.jm [href]')->each(static fn(Crawler $node): string => (string) $node->attr('href')),
                ...$crawler->filter('.jm [data-jm-fragment-url]')->each(static fn(Crawler $node): string => (string) $node->attr('data-jm-fragment-url')),
            ];
            static::assertNotEmpty($urls, $id);
            foreach ($urls as $url) {
                static::assertStringStartsWith('/back-office/', $url, $id);
                static::assertStringContainsString('menu=3', $url, $id);
                static::assertStringNotContainsString('/jmonitor', $url, $id);
            }
        }
    }

    public function testThePageSitsInTheFrameOfTheHost(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $crawler = $client->request('GET', '/back-office/monitoring/requests');
        $response = $client->getResponse();

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('body[data-host="back-office"]'));
        $jmonitor = $crawler->filter('.jm');
        static::assertCount(1, $jmonitor);
        static::assertSame('div', $jmonitor->nodeName());
        static::assertSame('light', $jmonitor->attr('data-jm-theme'));
        static::assertCount(0, $jmonitor->filter('.jm-brand'));
        static::assertStringNotContainsString('<main', $jmonitor->html());
        static::assertCount(1, $crawler->filter('head link[rel="stylesheet"][href^="/bundles/jmonitor/jmonitor.css?v="]'));
        static::assertCount(1, $crawler->filter('head script[src^="/bundles/jmonitor/jmonitor.js?v="]'));
        static::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        static::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    public function testAnEmbeddedScreenShowsTheSameDataAsTheStandaloneOne(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);
        $row = 'tr[data-jm-route="GET app_hello"][data-jm-requests="3"]';

        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/hello/world');
        }
        $embedded = self::requestUntil($client, '/back-office/monitoring/requests', $row);
        static::assertCount(1, $embedded->filter($row));

        $standalone = $client->request('GET', '/jmonitor/requests');
        static::assertCount(1, $standalone->filter($row));
    }

    public function testTheFragmentIsRelayedAsIs(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/back-office/monitoring-fragment/requests');
        $response = $client->getResponse();
        $html = (string) $response->getContent();

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('jm-screen', $html);
        static::assertStringNotContainsString('<html', $html);
        static::assertStringNotContainsString('data-host', $html);
        static::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        static::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    public function testSortAndPeriodLinksKeepTheParametersOfTheHost(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/hello/world');
        $crawler = self::requestUntil($client, '/back-office/monitoring/requests?menu=3&period=6h&sort=sql&dir=asc', 'tr[data-jm-route="GET app_hello"]');

        static::assertSame('/back-office/monitoring/requests?menu=3&period=6h&sort=sql&dir=desc', $crawler->filter('th.is-sorted a.jm-sort')->attr('href'));
    }

    public function testEmbeddedRequestsAreNotRecorded(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        foreach (['requests', 'queries', 'commands', 'cache', 'probe'] as $id) {
            $client->request('GET', '/back-office/monitoring/' . $id);
            static::assertResponseIsSuccessful();
            $client->request('GET', '/back-office/monitoring-fragment/' . $id);
            static::assertResponseIsSuccessful();
        }
        $client->request('POST', '/back-office/monitoring-action/probe', ['action' => 'ping'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        static::assertResponseStatusCodeSame(303);

        $crawler = $this->assertNoBackOfficeRequestIsRecorded($client);
        static::assertSame(['GET app_hello'], $crawler->filter('tr[data-jm-route]')->each(static fn(Crawler $row): string => (string) $row->attr('data-jm-route')));
    }

    public function testARequestRefusedByTheHostIsNotRecorded(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'security' => true]);

        $client->request('GET', '/back-office/monitoring/');
        static::assertResponseStatusCodeSame(401);

        $client->setServerParameters(self::ADMIN);
        $this->assertNoBackOfficeRequestIsRecorded($client);
    }

    public function testARequestRefusedByTheFirewallIsNotRecorded(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'security' => true]);

        $client->request('GET', '/back-office/locked/');
        static::assertResponseStatusCodeSame(401);

        $client->setServerParameters(self::ADMIN);
        $this->assertNoBackOfficeRequestIsRecorded($client);
    }

    public function testAHostThatOnlyCallsTheServiceIsCoveredOnceItsControllerRuns(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'security' => true]);

        $client->request('GET', '/back-office/unmarked/', server: self::ADMIN);
        static::assertResponseStatusCodeSame(200);
        $client->request('GET', '/back-office/unmarked/');
        static::assertResponseStatusCodeSame(401);

        $client->setServerParameters(self::ADMIN);
        $crawler = $this->assertNoBackOfficeRequestIsRecorded($client);
        static::assertCount(0, $crawler->filter('tr[data-jm-route$=" back_office_unmarked"]'));
    }

    public function testTheActionOfTheProbeScreenRunsThroughTheHost(): void
    {
        $client = static::createClient();

        $client->request('POST', '/back-office/monitoring-action/probe', ['action' => 'ping'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        static::assertResponseStatusCodeSame(303);
        static::assertResponseRedirects('/back-office/monitoring/probe?menu=3');

        $crawler = $client->request('GET', '/back-office/monitoring/probe');
        static::assertCount(1, $crawler->filter('tr[data-jm-probe-runs="1"]'));
    }

    public function testTheActionIsRefusedFromAnotherSite(): void
    {
        $client = static::createClient();

        $client->request('POST', '/back-office/monitoring-action/probe', ['action' => 'ping'], server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        static::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/back-office/monitoring/probe');
        static::assertSame('0', $crawler->filter('.jm-card__value')->text());
        static::assertCount(0, $crawler->filter('tr[data-jm-probe-runs]'));
    }

    public function testWithCsrfTheFormCarriesATokenThatTheActionRequires(): void
    {
        $client = static::createClient(['csrf' => true]);

        $crawler = $client->request('GET', '/back-office/monitoring/probe');
        static::assertResponseIsSuccessful();
        $form = $crawler->filter('.jm form[action="/back-office/monitoring-action/probe?menu=3"]');
        static::assertCount(1, $form->filter('input[name="action"][value="ping"]'));
        $token = $form->filter('input[name="token"]');
        static::assertCount(1, $token);
        static::assertNotSame('', (string) $token->attr('value'));

        $client->request('POST', '/back-office/monitoring-action/probe', ['action' => 'ping'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        static::assertResponseStatusCodeSame(403);

        $client->request('POST', '/back-office/monitoring-action/probe', ['action' => 'ping', 'token' => (string) $token->attr('value')], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        static::assertResponseStatusCodeSame(303);

        $crawler = $client->request('GET', '/back-office/monitoring/probe');
        static::assertCount(1, $crawler->filter('tr[data-jm-probe-runs="1"]'));
    }

    public function testOnARouteDeclaredStatelessTheActionNeedsNoTokenButTheSameOrigin(): void
    {
        $client = static::createClient(['csrf' => true]);

        $crawler = $client->request('GET', '/back-office/stateless/probe');
        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('.jm form input[name="action"][value="ping"]'));
        static::assertCount(0, $crawler->filter('.jm form input[name="token"]'));

        $client->request('POST', '/back-office/stateless-action/probe', ['action' => 'ping'], server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        static::assertResponseStatusCodeSame(403);
        $crawler = $client->request('GET', '/back-office/stateless/probe');
        static::assertSame('0', $crawler->filter('.jm-card__value')->text());

        $client->request('POST', '/back-office/stateless-action/probe', ['action' => 'ping'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        static::assertResponseStatusCodeSame(303);
        $crawler = $client->request('GET', '/back-office/stateless/probe');
        static::assertCount(1, $crawler->filter('tr[data-jm-probe-runs="1"]'));
    }

    public function testAScreenShowsBlocksInDifferentStates(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/back-office/monitoring/probe');

        static::assertResponseIsSuccessful();
        $screen = $crawler->filter('.jm-screen');
        static::assertSame('mixed', $screen->attr('data-jm-state'));
        static::assertSame(['jm-cards', 'jm-state jm-state--info'], $screen->children()->each(static fn(Crawler $block): string => (string) $block->attr('class')));
        static::assertSame('Runs', $screen->filter('.jm-cards .jm-card__label')->text());
        static::assertSame('Nothing probed yet', $screen->filter('.jm-state .jm-state__title')->text());
    }

    public function testTheGalleryRendersEveryBlock(): void
    {
        $client = static::createClient(['gallery' => true]);

        $crawler = $client->request('GET', '/back-office/monitoring/gallery');

        static::assertResponseIsSuccessful();
        $screen = $crawler->filter('.jm-screen');
        foreach (['nav.jm-subnav', '.jm-node[data-jm-node]', '[data-jm-notice]', '[data-jm-check][data-jm-severity]', '.jm-checks--empty', '[data-jm-component][data-jm-verdict]', '[data-jm-component][data-jm-reading]', '.jm-gauge [role="meter"]', '.jm-facts'] as $selector) {
            static::assertGreaterThan(0, $screen->filter($selector)->count(), $selector);
        }
        foreach (['bad', 'warn', 'ok', 'unknown', 'info'] as $tone) {
            static::assertGreaterThan(0, $screen->filter('.jm-mark--' . $tone)->count(), $tone);
        }
        static::assertStringStartsWith('/back-office/monitoring/gallery?', (string) $crawler->filter('.jm-nav__item[aria-current="page"]')->attr('href'));
        static::assertCount(1, $crawler->filter('.jm-nav__item[aria-current="page"] [data-jm-slot="nav-gallery"] .jm-nav__badge'));
    }

    public function testTheGalleryMovesOnEveryRequest(): void
    {
        $client = static::createClient(['gallery' => true]);

        $seen = [];
        for ($i = 0; $i < 2; ++$i) {
            $crawler = $client->request('GET', '/back-office/monitoring/gallery');
            static::assertResponseIsSuccessful();
            $seen[] = [(int) $crawler->filter('[data-jm-slot="nav-gallery"] .jm-nav__badge')->getNode(0)?->firstChild?->textContent, (string) $crawler->filter('.jm-node')->attr('data-jm-node')];
        }

        static::assertSame($seen[0][0] + 1, $seen[1][0]);
        static::assertSame($seen[0][0] % 2 === 1 ? 'web-1' : 'web-2', $seen[0][1]);
        static::assertSame($seen[1][0] % 2 === 1 ? 'web-1' : 'web-2', $seen[1][1]);
    }

    public function testTheGalleryCountsARequestOnceWhateverItIsAskedFor(): void
    {
        $counter = $this->bufferDir . '/gallery-requests';
        $gallery = new GalleryScreen($counter);

        $gallery->navBadge();
        $gallery->blocks(new ScreenRequest(1800000000));
        static::assertSame('1', $gallery->navBadge()->text);
        static::assertSame('1', file_get_contents($counter));

        $gallery->reset();
        $gallery->blocks(new ScreenRequest(1800000000));
        static::assertSame('2', file_get_contents($counter));

        $gallery->reset();
        static::assertSame('3', $gallery->navBadge()->text);
        static::assertSame('3', file_get_contents($counter));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function storageSettings(): iterable
    {
        yield 'storage and recording on' => [['jmonitor' => ['recording' => ['enabled' => true]]], 'Requests'];
        yield 'recording off' => [['jmonitor' => ['recording' => ['enabled' => false]]], 'Probe'];
        yield 'no Doctrine' => [['doctrine' => false, 'jmonitor' => ['recording' => ['enabled' => true]]], 'Probe'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('storageSettings')]
    public function testTheDefaultScreenFollowsTheStorage(array $options, string $screen): void
    {
        $options['jmonitor']['recording']['buffer_dir'] = $this->bufferDir;
        $client = static::createClient($options);

        $crawler = $client->request('GET', '/back-office/monitoring/');

        static::assertResponseIsSuccessful();
        static::assertSame($screen, $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, int, ?string, array<string, int>}>
     */
    public static function missingParts(): iterable
    {
        yield 'standalone page off' => [['jmonitor' => ['dashboard' => ['enabled' => false]]], '/back-office/monitoring/', 200, null, ['/jmonitor/' => 404]];
        yield 'recording and standalone page off' => [['jmonitor' => ['recording' => ['enabled' => false], 'dashboard' => ['enabled' => false]]], '/back-office/monitoring/requests', 200, 'recording_disabled', ['/jmonitor/' => 404]];
        yield 'no Doctrine' => [['doctrine' => false], '/back-office/monitoring/requests', 200, 'no_connection', []];
        yield 'no TwigBundle' => [['twig' => false], '/back-office/monitoring/', 404, null, ['/hello/world' => 200]];
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, int>   $others
     */
    #[DataProvider('missingParts')]
    public function testTheHostWorksWhateverIsMissing(array $options, string $uri, int $status, ?string $state, array $others): void
    {
        $options['jmonitor']['recording']['buffer_dir'] = $this->bufferDir;
        $client = static::createClient($options);

        $crawler = $client->request('GET', $uri);
        static::assertResponseStatusCodeSame($status);
        if ($state !== null) {
            static::assertSame($state, $crawler->filter('.jm-screen')->attr('data-jm-state'));
        }

        foreach ($others as $other => $otherStatus) {
            $client->request('GET', $other);
            static::assertResponseStatusCodeSame($otherStatus, $other);
        }
    }

    public function testAPathOfSeveralSegmentsReachesTheServiceAndIsNotFound(): void
    {
        $client = static::createClient();

        $client->request('GET', '/back-office/monitoring/probe/detail');

        static::assertResponseStatusCodeSame(404);
        $match = static::getContainer()->get('router')->match('/back-office/monitoring/probe/detail');
        static::assertSame('back_office_monitoring', $match['_route']);
        static::assertSame('probe/detail', $match['path']);
    }

    private function assertNoBackOfficeRequestIsRecorded(KernelBrowser $client): Crawler
    {
        $client->request('GET', '/hello/world');
        static::assertResponseIsSuccessful();
        $crawler = self::requestUntil($client, '/back-office/monitoring/requests', 'tr[data-jm-route="GET app_hello"]');

        static::assertCount(1, $crawler->filter('tr[data-jm-route="GET app_hello"]'));
        static::assertCount(0, $crawler->filter('tr[data-jm-route*="back_office_"]'));

        return $crawler;
    }

    /**
     * @param array{environment?: string, debug?: bool, jmonitor?: array<string, mixed>, doctrine?: bool, security?: bool, twig?: bool, messenger?: bool, scheduler?: bool, csrf?: bool, gallery?: bool} $options
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
            withEmbedHost: true,
            withCsrf: $options['csrf'] ?? false,
            withGallery: $options['gallery'] ?? false,
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
