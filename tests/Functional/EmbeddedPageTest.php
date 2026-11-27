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

    public function testTheScreensShowUnderTheRoutesOfTheHost(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'messenger' => true, 'scheduler' => true, 'security' => true]);
        $client->setServerParameters(self::ADMIN);

        foreach (['requests', 'queries', 'cache-http', 'exceptions', 'users', 'background'] as $id) {
            $crawler = $client->request('GET', '/back-office/monitoring/' . $id);

            static::assertResponseIsSuccessful($id);
            $current = $crawler->filter('.jm .jm-subnav__item[aria-current="page"]');
            if ($current->count() === 0) {
                $current = $crawler->filter('.jm .jm-nav__item[aria-current="page"]');
            }
            static::assertStringStartsWith('/back-office/monitoring/' . $id . '?', (string) $current->attr('href'), $id);

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
        $crawler = self::requestUntil($client, '/back-office/monitoring/requests?menu=3&period=6h&sort=avg&dir=asc', 'tr[data-jm-route="GET app_hello"]');

        static::assertSame('/back-office/monitoring/requests?menu=3&period=6h&sort=avg&dir=desc', $crawler->filter('th.is-sorted a.jm-sort')->attr('href'));
    }

    public function testEmbeddedRequestsAreNotRecorded(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        foreach (['requests', 'queries', 'background', 'cache-http', 'probe'] as $id) {
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

    public function testADefaultScreenWithAnActionPostsItToItsOwnAddress(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'probeFirst' => true]);

        $crawler = $client->request('GET', '/jmonitor/');

        static::assertResponseIsSuccessful();
        static::assertSame('JMonitor — Probe', $crawler->filter('title')->text());
        static::assertSame('/jmonitor/_action/probe', $crawler->filter('.jm-toolbar form')->attr('action'));

        $client->request('POST', '/jmonitor/_action/probe', ['action' => 'ping'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        static::assertResponseRedirects('/jmonitor/probe', 303);
        static::assertCount(1, $client->request('GET', '/jmonitor/')->filter('tr[data-jm-probe-runs="1"]'));

        $hosted = $client->request('GET', '/back-office/monitoring/');

        static::assertSame('/back-office/monitoring-action/probe?menu=3', $hosted->filter('.jm-toolbar form')->attr('action'));
    }

    public function testAHostKeepsTheConnectionInItsOwnUrls(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/back-office/monitoring/probe?connection=a%2Fb%20%C3%A9');

        static::assertResponseIsSuccessful();
        static::assertSame('/back-office/monitoring-fragment/probe?menu=3&connection=a/b%20%C3%A9', $crawler->filter('.jm [data-jm-fragment-url]')->attr('data-jm-fragment-url'));
        static::assertSame('a/b é', $crawler->filter('.jm form input[type="hidden"][name="connection"]')->attr('value'));

        $client->request('GET', '/back-office/monitoring-fragment/probe?connection=a%2Fb%20%C3%A9');
        static::assertResponseIsSuccessful();

        $client->request('POST', '/back-office/monitoring-action/probe', ['action' => 'ping', 'connection' => 'a/b é'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        static::assertResponseStatusCodeSame(303);
        static::assertResponseRedirects('/back-office/monitoring/probe?menu=3&connection=a/b%20%C3%A9');
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
        static::assertSame(['jm-cards', 'jm-state jm-state--info'], $screen->filter('.jm-screen > .jm-heading ~ :not(template)')->each(static fn(Crawler $block): string => (string) $block->attr('class')));
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
        static::assertStringStartsWith('/back-office/monitoring/gallery?', (string) $crawler->filter('nav.jm-subnav[aria-label="Application"] [aria-current="page"]')->attr('href'));
        static::assertCount(1, $crawler->filter('nav.jm-subnav[aria-label="Application"] [aria-current="page"] .jm-nav__badge'));
    }

    public function testTheGalleryShowsTheBlocksThatGuideTheReading(): void
    {
        $client = static::createClient(['gallery' => true]);

        $crawler = $client->request('GET', '/back-office/monitoring/gallery');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('h1'));
        static::assertSame('Gallery', $crawler->filter('.jm-screen > .jm-heading > h1')->text());
        static::assertSame(2, $crawler->filter('.jm-screen > section.jm-lead > h2')->count());
        static::assertCount(1, $crawler->filter('.jm-lead--series svg.jm-sparkline'));
        $worth = $crawler->filter('.jm-screen > section.jm-checks.jm-checks--bad[aria-label="Worth a look"]');
        static::assertCount(1, $worth);
        static::assertCount(1, $worth->filter('[data-jm-severity="bad"] pre.jm-check__code'));
        static::assertCount(2, $worth->filter('[data-jm-severity="bad"] .jm-check__advice > p'));
        static::assertCount(1, $worth->filter('[data-jm-severity="bad"] .jm-check__where a'));
        static::assertCount(0, $worth->filter('[data-jm-severity="warn"] pre, [data-jm-severity="warn"] .jm-check__where'));
        static::assertSame('Nothing to flag', $crawler->filter('.jm-checks--empty[aria-label="Worth a look"] h2')->text());
        $table = $crawler->filter('#jm-gallery-routes-wrap');
        static::assertCount(4, $table->filter('.jm-bar svg[aria-hidden="true"]'));
        static::assertSame([3, 1, 2, 0], $table->filter('.jm-split')->each(static fn(Crawler $split): int => $split->filter('rect')->count()));
        static::assertCount(3, $table->filter('.jm-tag'));
        static::assertSame('your PHP code SQL HTTP out', $crawler->filter('.jm-legend')->text());
        static::assertCount(1, $crawler->filter('.jm-panel__footnote'));
        static::assertCount(3, $crawler->filter('.jm-guide li'));
        static::assertSame(['See the gallery'], $crawler->filter('.jm-guide a')->each(static fn(Crawler $a): string => $a->text()));
    }

    public function testTheGalleryShowsTilesAndATableWithoutItsLineOfTitle(): void
    {
        $client = static::createClient(['gallery' => true]);

        $crawler = $client->request('GET', '/back-office/monitoring/gallery');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('h1'));
        $tiles = $crawler->filter('.jm-screen > section.jm-tiles > .jm-tile');
        static::assertSame(
            ['jm-tile jm-tile--series', 'jm-tile', 'jm-tile jm-tile--bad', 'jm-tile jm-tile--warn', 'jm-tile jm-tile--warn', 'jm-tile jm-tile--zero'],
            $tiles->each(static fn(Crawler $tile): string => (string) $tile->attr('class')),
        );
        static::assertSame('Requests', $tiles->eq(0)->filter('h2.jm-tile__label')->text());
        static::assertSame(['div', 'div', 'a', 'a', 'div', 'div'], $tiles->each(static fn(Crawler $tile): string => $tile->nodeName()));
        static::assertSame('/back-office/monitoring/gallery?menu=3#jm-gallery-routes-wrap', $tiles->eq(2)->attr('href'));
        $table = $crawler->filter('#jm-gallery-slim-wrap');
        static::assertSame('Routes without their line of title', $table->attr('aria-label'));
        $panel = $table->closest('.jm-panel');
        static::assertNotNull($panel);
        static::assertSame('div', $panel->nodeName());
        static::assertCount(0, $panel->filter('.jm-panel__header'));
        static::assertSame('3 more routes not shown', $panel->filter('.jm-panel__footer')->text());
        static::assertSame('95 % of the requests are faster.', $table->filter('thead th')->eq(2)->attr('title'));
        static::assertSame('Where the average goes PHP SQL HTTP', $table->filter('thead th')->eq(3)->text());
        static::assertSame(['a', 'span', 'span'], $table->filter('.jm-tag')->each(static fn(Crawler $tag): string => $tag->nodeName()));
        static::assertStringStartsWith('/back-office/monitoring/gallery?', (string) $table->filter('a.jm-tag')->attr('href'));
        static::assertSame('Requests that matched no route.', $table->filter('code.jm-route--unmatched')->attr('title'));
    }

    public function testTheGalleryShowsASectionInEachState(): void
    {
        $client = static::createClient(['gallery' => true]);

        $crawler = $client->request('GET', '/back-office/monitoring/gallery');

        static::assertResponseIsSuccessful();
        $sections = $crawler->filter('.jm-screen > section.jm-section');
        static::assertSame(['jm-full', 'jm-quiet', 'jm-counting'], $sections->each(static fn(Crawler $section): string => (string) $section->attr('id')));
        static::assertCount(1, $sections->eq(0)->filter('.jm-cards'));
        static::assertCount(1, $sections->eq(0)->filter('table'));
        static::assertCount(0, $sections->eq(0)->filter('.jm-panel h2'));
        static::assertStringEndsWith('#jm-full', (string) $sections->eq(0)->filter('a.jm-sort')->attr('href'));
        static::assertCount(1, $sections->eq(1)->filter('p.jm-section__empty'));
        static::assertCount(0, $sections->eq(1)->filter('table'));
        static::assertCount(1, $sections->eq(2)->filter('[data-jm-reading]'));
    }

    public function testTheGalleryShowsAnchorCardsAndNestedRows(): void
    {
        $client = static::createClient(['gallery' => true]);

        $crawler = $client->request('GET', '/back-office/monitoring/gallery');

        static::assertResponseIsSuccessful();
        $cards = $crawler->filter('.jm-screen > .jm-cards')->reduce(static fn(Crawler $block): bool => $block->filter('.jm-card__link')->count() > 0)->children('.jm-card');
        static::assertCount(3, $cards);
        static::assertSame(['#jm-full', '#jm-quiet'], $cards->filter('h2 > a.jm-card__link')->each(static fn(Crawler $a): string => (string) $a->attr('href')));
        static::assertSame('jm-card jm-card--bad', $cards->eq(0)->attr('class'));
        static::assertSame('runs, 3 failed', $cards->eq(0)->filter('.jm-card__hint')->text());
        static::assertCount(0, $cards->eq(2)->filter('a'));
        $table = $crawler->filter('#jm-gallery-nested-wrap');
        static::assertSame(['', 'jm-row--nested', 'jm-row--nested', ''], $table->filter('tbody tr')->each(static fn(Crawler $row): string => (string) $row->attr('class')));
        static::assertSame('2 exceptions', $table->closest('.jm-panel')?->filter('.jm-panel__meta')->text());
    }

    public function testTheGalleryShowsAComposedScreenWithAnOutline(): void
    {
        $client = static::createClient(['gallery' => true]);

        $crawler = $client->request('GET', '/back-office/monitoring/gallery-composed');

        static::assertResponseIsSuccessful();
        static::assertSame(['45 deliveries and 12 imports in the last hour.'], $crawler->filter('h1')->each(static fn(Crawler $h1): string => $h1->text()));
        static::assertSame('3 deliveries failed.', $crawler->filter('.jm-screen > section.jm-lead > .jm-lead__detail')->text());
        static::assertSame(['deliveries.failed'], $crawler->filter('.jm-screen > section.jm-checks.jm-checks--bad[aria-label="Worth a look"] [data-jm-check]')->each(static fn(Crawler $line): string => (string) $line->attr('data-jm-check')));
        $anchors = $crawler->filter('.jm-screen > .jm-cards > .jm-card');
        static::assertSame(['Deliveries', 'Imports'], $anchors->filter('a.jm-card__link')->each(static fn(Crawler $a): string => $a->text()));
        static::assertSame(['#jm-failing', '#jm-steady'], $anchors->filter('a.jm-card__link')->each(static fn(Crawler $a): string => (string) $a->attr('href')));
        static::assertSame(['jm-card jm-card--bad', 'jm-card'], $anchors->each(static fn(Crawler $card): string => (string) $card->attr('class')));
        static::assertSame(['45', '12'], $anchors->filter('.jm-card__value')->each(static fn(Crawler $value): string => $value->text()));
        static::assertSame(['jm-failing', 'jm-steady', 'jm-unbriefed'], $crawler->filter('.jm-screen > section.jm-section')->each(static fn(Crawler $section): string => (string) $section->attr('id')));
        static::assertCount(0, $crawler->filter('.jm-section .jm-lead, .jm-section .jm-checks, .jm-section .jm-guide'));
        static::assertCount(4, $crawler->filter('#jm-steady > .jm-cards > .jm-card'));
        static::assertSame(['Deliveries.', 'Imports.', 'Rows.'], $crawler->filter('.jm-screen > .jm-guide li strong')->each(static fn(Crawler $term): string => $term->text()));
        static::assertSame('jm-guide', $crawler->filter('.jm-screen > section:last-of-type')->attr('class'));
    }

    public function testTheGalleryShowsTheMapAndThePlaces(): void
    {
        $client = static::createClient(['gallery' => true]);

        $crawler = $client->request('GET', '/back-office/monitoring/gallery');

        static::assertResponseIsSuccessful();
        $maps = $crawler->filter('.jm-screen > section.jm-map');
        static::assertCount(5, $maps);
        static::assertSame(['What comes in', 'Your application', 'What it calls'], $maps->eq(0)->filter('.jm-map__title')->each(static fn(Crawler $h2): string => $h2->text()));
        static::assertCount(2, $maps->eq(0)->filter('svg.jm-map__arrow[aria-hidden="true"]'));
        static::assertSame(
            ['/back-office/monitoring/gallery?menu=3#jm-full', '/back-office/monitoring/gallery?menu=3#jm-quiet', '/back-office/monitoring/gallery?menu=3'],
            $maps->eq(0)->filter('.jm-map__column--incoming a.jm-box')->each(static fn(Crawler $a): string => (string) $a->attr('href')),
        );
        static::assertSame('Commands 12', $maps->eq(0)->filter('.jm-map__column--incoming div.jm-box')->text());
        static::assertSame(['Scheduled tasks', 'Cache'], $maps->eq(0)->filter('.jm-box--zero .jm-box__label')->each(static fn(Crawler $label): string => $label->text()));
        static::assertSame(['Requests', 'Messages', 'Other sites and APIs'], $maps->eq(0)->filter('.jm-box--bad')->each(static fn(Crawler $box): string => $box->filter('.jm-box__label')->text()));
        static::assertCount(1, $maps->eq(0)->filter('.jm-box--warn'));
        static::assertCount(1, $maps->eq(0)->filter('.jm-box svg.jm-sparkline'));
        static::assertCount(0, $maps->filter('.jm-split, .jm-legend, .jm-map__text'));
        static::assertSame(['ok', 'warn', 'bad', 'unknown'], $maps->eq(0)->filter('.jm-runs__line .jm-mark')->each(static fn(Crawler $mark): string => substr((string) $mark->attr('class'), \strlen('jm-mark jm-mark--'))));
        static::assertSame('8.2.29', $maps->eq(0)->filter('.jm-runs__version')->eq(1)->text());
        static::assertCount(9, $maps->eq(1)->filter('.jm-box--zero'));
        static::assertCount(0, $maps->eq(1)->filter('.jm-runs, .jm-tag, svg.jm-sparkline'));
        static::assertCount(0, $maps->eq(2)->filter('.jm-map__title'));
        static::assertCount(2, $maps->eq(2)->filter('a.jm-runs__line'));
        static::assertSame('Warning The live stack needs a working cache', $maps->eq(3)->filter('.jm-runs .jm-notice__title')->text());
        static::assertCount(1, $maps->eq(3)->filter('.jm-runs > .jm-notice + .jm-runs__list > .jm-runs__item'));
        static::assertSame('Error The stack could not be read', $maps->eq(4)->filter('.jm-runs .jm-notice__title')->text());
        static::assertCount(0, $maps->eq(4)->filter('.jm-runs__list, .jm-runs__empty'));
        $places = $crawler->filter('.jm-screen > section.jm-places');
        static::assertSame(['Overview', 'Application', 'Stack'], $places->filter('a.jm-place__label')->each(static fn(Crawler $a): string => $a->text()));
        static::assertStringStartsWith('/back-office/monitoring', (string) $places->filter('a.jm-place__label')->attr('href'));
        static::assertDoesNotMatchRegularExpression('/<[^>]+\sstyle=/', (string) $client->getResponse()->getContent());
    }

    public function testTheGalleryShowsTheSelectorOfAConnection(): void
    {
        $client = static::createClient(['gallery' => true]);

        $crawler = $client->request('GET', '/back-office/monitoring/gallery');

        static::assertResponseIsSuccessful();
        $selector = $crawler->filter('.jm-screen > .jm-subbar + [data-jm-instances]');
        static::assertCount(1, $selector);
        static::assertSame(['Servers', 'Databases'], $selector->filter('nav')->each(static fn(Crawler $row): string => (string) $row->attr('aria-label')));
        static::assertCount(2, $selector->filter('a[aria-current="true"]'));
        static::assertSame('Unavailable', $selector->filter('a .jm-mark--warn')->text());
        static::assertSame('/back-office/monitoring/gallery?menu=3&connection=analytics', $selector->filter('a')->eq(1)->attr('href'));
    }

    public function testTheGalleryShowsANotedFactList(): void
    {
        $client = static::createClient(['gallery' => true]);

        $crawler = $client->request('GET', '/back-office/monitoring/gallery');

        static::assertResponseIsSuccessful();
        $queues = $crawler->filter('.jm-facts[aria-label="Queues"]');
        static::assertCount(1, $queues);
        static::assertSame("Messages waiting in each transport, counted 12\u{00A0}s ago", $queues->filter('.jm-panel__header .jm-panel__note')->text());
        static::assertSame(['1,234', 'Uncountable'], $queues->filter('.jm-fact__value')->each(static fn(Crawler $value): string => $value->text()));
        static::assertCount(1, $queues->filter('.jm-fact__hint'));
        static::assertCount(1, $crawler->filter('.jm-panel__note'));
    }

    public function testTheGalleryMovesOnEveryRequest(): void
    {
        $client = static::createClient(['gallery' => true]);

        $seen = [];
        for ($i = 0; $i < 2; ++$i) {
            $crawler = $client->request('GET', '/back-office/monitoring/gallery');
            static::assertResponseIsSuccessful();
            $seen[] = [(int) $crawler->filter('nav.jm-subnav[aria-label="Application"] [aria-current="page"] .jm-nav__badge')->getNode(0)?->firstChild?->textContent, (string) $crawler->filter('.jm-node')->attr('data-jm-node')];
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
        static::assertSame(1, $gallery->navBadge()?->warning);
        static::assertSame('1', file_get_contents($counter));

        $gallery->reset();
        $gallery->blocks(new ScreenRequest(1800000000));
        static::assertSame('2', file_get_contents($counter));

        $gallery->reset();
        static::assertSame(3, $gallery->navBadge()?->warning);
        static::assertSame('3', file_get_contents($counter));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function storageSettings(): iterable
    {
        yield 'storage and recording on' => [['jmonitor' => ['recording' => ['enabled' => true]]], null];
        yield 'recording off' => [['jmonitor' => ['recording' => ['enabled' => false]]], 'Recording is off'];
        yield 'no Doctrine' => [['doctrine' => false, 'jmonitor' => ['recording' => ['enabled' => true]]], 'No storage configured'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('storageSettings')]
    public function testTheEmptyPathOpensTheOverviewAtAHostWhateverTheStorage(array $options, ?string $state): void
    {
        $options['jmonitor']['recording']['buffer_dir'] = $this->bufferDir;
        $client = static::createClient($options);

        $crawler = $client->request('GET', '/back-office/monitoring/');

        static::assertResponseIsSuccessful();
        $current = $crawler->filter('.jm-nav__item[aria-current="page"]');
        static::assertSame('Overview', $current->text());
        static::assertSame($state === null ? [] : [$state], $crawler->filter('.jm-state__title')->each(static fn(Crawler $title): string => $title->text()));
        static::assertCount(1, $crawler->filter('.jm-places'));
        $href = (string) $current->attr('href');
        static::assertStringStartsWith('/back-office/monitoring', $href);
        static::assertStringNotContainsString('overview', $href);

        $again = $client->request('GET', $href);

        static::assertResponseIsSuccessful();
        static::assertSame('Overview', $again->filter('.jm-nav__item[aria-current="page"]')->text());
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
     * @param array{environment?: string, debug?: bool, jmonitor?: array<string, mixed>, doctrine?: bool, security?: bool, twig?: bool, messenger?: bool, scheduler?: bool, csrf?: bool, gallery?: bool, probeFirst?: bool} $options
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
            probeFirst: $options['probeFirst'] ?? false,
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
