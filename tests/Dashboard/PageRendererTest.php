<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Dashboard\ActionGuard;
use Jmonitor\JmonitorBundle\Dashboard\Assets;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\CacheHitRateCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedMessagesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpFailuresCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\RepeatedQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\ServerErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowRequestsCheck;
use Jmonitor\JmonitorBundle\Dashboard\NavBadge;
use Jmonitor\JmonitorBundle\Dashboard\PageRenderer;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ComposedScreen;
use Jmonitor\JmonitorBundle\Dashboard\Section\SectionInterface;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Guide;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Lead;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Screen\CardBuilder;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\PhpDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\ServerDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\SymfonyDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\StackScreen;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\Totals;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\FakeStackReader;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use Jmonitor\JmonitorBundle\Twig\JmonitorExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class PageRendererTest extends TestCase
{
    private const SERVER_ERRORS = Metric::REQUEST_STATUS_PREFIX . '5xx';

    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            (new Filesystem())->remove($this->dir);
        }
    }

    public function testThePageCarriesTitleAssetsAndHeaders(): void
    {
        $content = $this->renderer()->page(Request::create('/'), 'stub', new FakeHostUrls());

        static::assertSame('JMonitor — Stub', $content->title);
        static::assertCount(1, $content->stylesheets);
        static::assertMatchesRegularExpression('#^/bundles/jmonitor/jmonitor\.css\?v=[0-9a-f]{8}$#', $content->stylesheets[0]);
        static::assertCount(1, $content->scripts);
        static::assertMatchesRegularExpression('#^/bundles/jmonitor/jmonitor\.js\?v=[0-9a-f]{8}$#', $content->scripts[0]);
        static::assertSame(['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'], $content->headers);
    }

    public function testEveryLinkComesFromTheHost(): void
    {
        $request = Request::create('/', parameters: ['period' => '6h', 'sort' => 'name', 'dir' => 'asc']);

        $html = $this->renderer()->page($request, 'stub', new FakeHostUrls())->html;
        $crawler = new Crawler($html);

        static::assertSame('/host/page/stub?menu=3&period=6h', $crawler->filter('.jm-nav__item')->attr('href'));
        static::assertSame('/host/page/stub?menu=3&period=6h&sort=name&dir=desc', $crawler->filter('th.is-sorted a.jm-sort')->attr('href'));
        static::assertSame('/host/page/stub?menu=3&period=24h&sort=name&dir=asc', $crawler->filter('.jm-segmented__item')->eq(2)->attr('href'));
        static::assertStringContainsString('data-jm-fragment-url="/host/fragment/stub?menu=3"', $html);
        static::assertStringNotContainsString('/jmonitor', $html);
    }

    public function testAHostileHostUrlIsEscaped(): void
    {
        $renderer = $this->renderer();
        $urls = new FakeHostUrls('"><script>');

        foreach ([
            $renderer->page(Request::create('/'), 'stub', $urls)->html,
            (string) $renderer->fragment(Request::create('/'), 'stub', $urls)->getContent(),
        ] as $html) {
            static::assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
            static::assertStringNotContainsString('"><script>', $html);
        }
    }

    public function testTheStandaloneFrameAddsTheBrandAndTheLandmarks(): void
    {
        $standalone = $this->renderer()->page(Request::create('/'), 'stub', new FakeHostUrls(), true)->html;
        $embedded = $this->renderer()->page(Request::create('/'), 'stub', new FakeHostUrls())->html;

        foreach (['<header', '<main id="jm-main"', 'jm-brand'] as $part) {
            static::assertStringContainsString($part, $standalone);
            static::assertStringNotContainsString($part, $embedded);
        }
        static::assertStringContainsString('<nav class="jm-nav"', $standalone);
        static::assertStringContainsString('<nav class="jm-nav"', $embedded);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownPaths(): iterable
    {
        yield 'unknown screen' => ['nope'];
        yield 'upper case' => ['Stub'];
        yield 'two segments' => ['stub/x'];
        yield 'parent segment' => ['../stub'];
        yield 'trailing slash' => ['stub/'];
        yield 'unavailable screen' => ['hidden'];
    }

    #[DataProvider('unknownPaths')]
    public function testAnUnknownPathIsNotFound(string $path): void
    {
        $renderer = $this->renderer([new StubScreen(), new StubScreen('hidden', false)]);

        foreach ([
            static fn() => $renderer->page(Request::create('/'), $path, new FakeHostUrls()),
            static fn() => $renderer->fragment(Request::create('/'), $path, new FakeHostUrls()),
        ] as $call) {
            try {
                $call();
                static::fail(sprintf('"%s" was found.', $path));
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheEmptyPathIsTheDefaultScreen(): void
    {
        $content = $this->renderer([new StubScreen(), new StubScreen('other')])->page(Request::create('/'), '', new FakeHostUrls());

        static::assertSame('JMonitor — Stub', $content->title);
        static::assertSame('Stub', (new Crawler($content->html))->filter('.jm-subnav__item[aria-current="page"]')->text());
    }

    public function testWithoutUsableStorageTheEmptyPathIsTheFirstBlockScreen(): void
    {
        $content = $this->renderer([new StubScreen(), new StubBlockScreen()], reader: null)->page(Request::create('/'), '', new FakeHostUrls());

        static::assertSame('JMonitor — Probe', $content->title);
    }

    public function testOnlyAStorageScreenFlushesTheBuffer(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-renderer-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $clock = new MockClock('@1000');
        $directory = new BufferDirectory($this->dir, 'app');
        $buffer = new FileBuffer($directory, 10, $clock);
        $buffer->append(new Entry(Entry::TYPE_REQUEST, 'GET app_home', 1000.0, 20.0, ['status' => 200]));
        $clock->sleep(11);
        $storage = new class implements StorageInterface {
            public int $stores = 0;

            public function prepare(): bool
            {
                return false;
            }

            public function store(array $aggregates, int $now, array $labels = []): void
            {
                ++$this->stores;
            }

            public function purge(int $now): void {}
        };
        $flusher = new Flusher($buffer, new Aggregator(500), $storage, new FlushLock($directory, 10, $clock), $clock);
        $renderer = $this->renderer([new StubScreen(), new StubBlockScreen()], $flusher);

        $renderer->page(Request::create('/'), 'probe', new FakeHostUrls());
        $renderer->fragment(Request::create('/'), 'probe', new FakeHostUrls());
        static::assertSame(0, $storage->stores);

        $renderer->page(Request::create('/'), 'stub', new FakeHostUrls());
        static::assertSame(1, $storage->stores);
    }

    public function testABlockScreenHasNoPeriodSelector(): void
    {
        $screen = new StubBlockScreen('stub', blocks: [Block::cards([new Card('Hits', '12')])]);

        $html = $this->renderer([$screen])->page(Request::create('/', parameters: ['period' => '6h']), 'stub', new FakeHostUrls())->html;

        static::assertStringNotContainsString('jm-period', $html);
        static::assertStringContainsString('<p class="jm-heading__text">Stub heading.</p>', $html);
        static::assertSame('/host/page/stub?menu=3', (new Crawler($html))->filter('.jm-nav__item')->attr('href'));
    }

    public function testTheFragmentIsTheScreenAloneWithTheHeaders(): void
    {
        $response = $this->renderer()->fragment(Request::create('/'), 'stub', new FakeHostUrls());
        $html = (string) $response->getContent();

        static::assertSame(200, $response->getStatusCode());
        static::assertStringStartsWith('<div class="jm-screen"', trim($html));
        static::assertStringContainsString('<table', $html);
        static::assertStringNotContainsString('jm-nav', $html);
        static::assertStringContainsString('<h1 class="jm-heading__title">Stub</h1>', $html);
        static::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        static::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    public function testAnActionRunsThenRedirectsToThePageOfTheHost(): void
    {
        $screen = new StubActionScreen();

        $response = $this->renderer([$screen])->action($this->post(['action' => 'ping']), 'stub', new FakeHostUrls());

        static::assertSame(303, $response->getStatusCode());
        static::assertSame('/host/page/stub?menu=3', $response->headers->get('Location'));
        static::assertSame(['ping'], $screen->ran);
        static::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        static::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    public function testARefusedActionRunsNothing(): void
    {
        $screen = new StubActionScreen();

        try {
            $this->renderer([$screen])->action($this->post(['action' => 'ping'], 'cross-site'), 'stub', new FakeHostUrls());
            static::fail('The action was not refused.');
        } catch (AccessDeniedHttpException $e) {
            static::assertSame(403, $e->getStatusCode());
        }

        static::assertSame([], $screen->ran);
    }

    public function testAnActionMustBePosted(): void
    {
        $screen = new StubActionScreen();
        $request = Request::create('/', 'GET', ['action' => 'ping'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        try {
            $this->renderer([$screen])->action($request, 'stub', new FakeHostUrls());
            static::fail('A GET request ran the action.');
        } catch (MethodNotAllowedHttpException $e) {
            static::assertSame(405, $e->getStatusCode());
            static::assertSame('POST', $e->getHeaders()['Allow'] ?? null);
        }

        static::assertSame([], $screen->ran);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function unknownActions(): iterable
    {
        yield 'missing field' => ['stub', []];
        yield 'undeclared name' => ['stub', ['action' => 'pong']];
        yield 'array field' => ['stub', ['action' => ['ping']]];
        yield 'screen without action' => ['plain', ['action' => 'ping']];
        yield 'unknown path' => ['nope', ['action' => 'ping']];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('unknownActions')]
    public function testAnUnknownActionIsNotFound(string $path, array $fields): void
    {
        $screen = new StubActionScreen();

        try {
            $this->renderer([$screen, new StubBlockScreen('plain')])->action($this->post($fields), $path, new FakeHostUrls());
            static::fail('The action was found.');
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }

        static::assertSame([], $screen->ran);
    }

    public function testAnActionThatThrowsStillRedirectsAndIsLogged(): void
    {
        $screen = new StubActionScreen(failure: new \RuntimeException('Ping failed'));
        $logger = $this->logger();

        $response = $this->renderer([$screen], logger: $logger)->action($this->post(['action' => 'ping']), 'stub', new FakeHostUrls());

        static::assertSame(303, $response->getStatusCode());
        static::assertSame('/host/page/stub?menu=3', $response->headers->get('Location'));
        static::assertSame(['error JMonitor could not run the "ping" action of the "stub" screen: Ping failed'], $logger->messages);
    }

    public function testTheToolbarShowsOneFormPerAction(): void
    {
        $screen = new StubActionScreen(actions: ['ping' => '<b>Ping</b>', 'purge-all' => 'Purge']);

        $html = $this->renderer([$screen])->page(Request::create('/'), 'stub', new FakeHostUrls())->html;
        $forms = (new Crawler($html))->filter('.jm-toolbar form');

        static::assertCount(2, $forms);
        static::assertSame('/host/action/stub?menu=3', $forms->attr('action'));
        static::assertSame('post', $forms->attr('method'));
        static::assertSame('ping', $forms->filter('input[type="hidden"][name="action"]')->attr('value'));
        static::assertSame('purge-all', $forms->eq(1)->filter('input[type="hidden"][name="action"]')->attr('value'));
        static::assertSame('<b>Ping</b>', $forms->filter('button[type="submit"].jm-button')->text());
        static::assertStringContainsString('&lt;b&gt;Ping&lt;/b&gt;', $html);
        static::assertCount(0, $forms->filter('input[name="token"]'));

        $stack = new RequestStack();
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack->push($request);
        $tokens = new CsrfTokenManager(storage: new SessionTokenStorage($stack));

        $forms = (new Crawler($this->renderer([$screen], guard: new ActionGuard($tokens))->page($request, 'stub', new FakeHostUrls())->html))->filter('.jm-toolbar form');

        static::assertCount(2, $forms->filter('input[type="hidden"][name="token"]'));
        static::assertTrue($tokens->isTokenValid(new CsrfToken('jmonitor', (string) $forms->filter('input[name="token"]')->attr('value'))));
    }

    public function testAHostileActionUrlAndActionNameAreEscaped(): void
    {
        $screen = new StubActionScreen(actions: ['x"><script>' => 'Ping']);

        $html = $this->renderer([$screen])->page(Request::create('/'), 'stub', new FakeHostUrls('"><script>'))->html;
        $form = (new Crawler($html))->filter('.jm-toolbar form');

        static::assertSame('"><script>/action/stub?menu=3', $form->attr('action'));
        static::assertSame('x"><script>', $form->filter('input[name="action"]')->attr('value'));
        static::assertStringContainsString('action="&quot;&gt;&lt;script&gt;/action/stub?menu=3"', $html);
        static::assertStringContainsString('value="x&quot;&gt;&lt;script&gt;"', $html);
        static::assertStringNotContainsString('"><script>', $html);
    }

    public function testAScreenWithoutActionAsksForNoToken(): void
    {
        $tokens = new class implements CsrfTokenManagerInterface {
            public int $calls = 0;

            public function getToken(string $tokenId): CsrfToken
            {
                ++$this->calls;

                return new CsrfToken($tokenId, 'spy');
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                ++$this->calls;

                return new CsrfToken($tokenId, 'spy');
            }

            public function removeToken(string $tokenId): ?string
            {
                ++$this->calls;

                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                ++$this->calls;

                return false;
            }
        };
        $renderer = $this->renderer([$this->screenWithATable(), new StubBlockScreen(), new StubActionScreen('idle', [])], guard: new ActionGuard($tokens));

        foreach (['stub', 'probe', 'idle'] as $path) {
            $request = Request::create('/');
            $request->setSession(new Session(new MockArraySessionStorage()));

            $html = $renderer->page($request, $path, new FakeHostUrls())->html;
            $renderer->fragment($request, $path, new FakeHostUrls());

            static::assertStringNotContainsString('<form', $html);
            static::assertFalse($request->getSession()->isStarted());
        }

        static::assertSame(0, $tokens->calls);
    }

    public function testASubScreenIsReachedThroughItsParent(): void
    {
        $child = new StubParentScreen('child', blocks: [Block::cards([new Card('Hits', '12')])], actions: ['ping' => 'Ping']);
        $renderer = $this->renderer([new StubParentScreen('stub', ['child' => $child]), new StubBlockScreen('other')]);

        $content = $renderer->page(Request::create('/'), 'stub/child', new FakeHostUrls());
        $crawler = new Crawler($content->html);

        static::assertSame('JMonitor — Child', $content->title);
        static::assertSame('Child', $crawler->filter('h1')->text());
        static::assertSame('Child heading.', $crawler->filter('.jm-heading__text')->text());
        static::assertSame(['Stub', 'Other'], $crawler->filter('.jm-subnav__item')->each(static fn(Crawler $item): string => $item->text()));
        static::assertSame('Stub', $crawler->filter('.jm-subnav__item[aria-current="page"]')->text());
        static::assertSame('12', $crawler->filter('.jm-card__value')->text());
        static::assertSame('/host/fragment/stub/child?menu=3', $crawler->filter('[data-jm-fragment-url]')->attr('data-jm-fragment-url'));
        static::assertSame('/host/action/stub/child?menu=3', $crawler->filter('.jm-toolbar form')->attr('action'));

        $fragment = (string) $renderer->fragment(Request::create('/'), 'stub/child', new FakeHostUrls())->getContent();

        static::assertSame('12', (new Crawler($fragment))->filter('.jm-card__value')->text());
    }

    public function testTheSortLinksOfASubScreenStayOnItsPath(): void
    {
        $child = new StubScreen('child', view: static fn(): ScreenView => new ScreenView([], [
            new Table('stubs', 'Stubs', [new Column('name', 'Name', false, 'name'), new Column('count', 'Count', sort: 'count')], [
                new Row('k1', ['name' => new Cell('a'), 'count' => new Cell('1')]),
            ]),
        ]));
        $renderer = $this->renderer([new StubParentScreen('stub', ['child' => $child])]);
        $request = Request::create('/', parameters: ['period' => '6h', 'sort' => 'name', 'dir' => 'asc']);

        foreach ([
            $renderer->page($request, 'stub/child', new FakeHostUrls())->html,
            (string) $renderer->fragment($request, 'stub/child', new FakeHostUrls())->getContent(),
        ] as $html) {
            $sorts = (new Crawler($html))->filter('a.jm-sort');

            static::assertSame('/host/page/stub/child?menu=3&period=6h&sort=name&dir=desc', $sorts->eq(0)->attr('href'));
            static::assertSame('/host/page/stub/child?menu=3&period=6h&sort=count&dir=desc', $sorts->eq(1)->attr('href'));
        }
    }

    public function testAnUnknownSubScreenAThirdSegmentAndAChildOfAPlainScreenAreNotFound(): void
    {
        $child = new StubParentScreen('child', actions: ['ping' => 'Ping']);
        $parent = new StubParentScreen('stub', ['child' => $child]);
        $logger = $this->logger();
        $renderer = $this->renderer([
            $parent,
            new StubBlockScreen('plain'),
            new StubParentScreen('hidden', ['child' => $child], available: false),
            new StubParentScreen('broken', new \RuntimeException('Lookup failed')),
        ], logger: $logger);

        foreach (['stub/nope', 'stub/child/x', 'stub/child/', 'stub/Child', 'stub/../child', 'stub//child', 'plain/child', 'hidden/child', 'broken/child'] as $path) {
            foreach ([
                static fn() => $renderer->page(Request::create('/'), $path, new FakeHostUrls()),
                static fn() => $renderer->fragment(Request::create('/'), $path, new FakeHostUrls()),
                fn() => $renderer->action($this->post(['action' => 'ping']), $path, new FakeHostUrls()),
            ] as $call) {
                try {
                    $call();
                    static::fail(sprintf('"%s" was found.', $path));
                } catch (NotFoundHttpException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        static::assertSame(['nope', 'nope', 'nope'], $parent->asked);
        static::assertSame([], $child->ran);
        static::assertSame(array_fill(0, 3, 'error JMonitor could not open the "broken/child" screen: Lookup failed'), $logger->messages);
    }

    public function testAnActionOfASubScreenRunsOnItAndRedirectsToIt(): void
    {
        $child = new StubParentScreen('child', actions: ['ping' => 'Ping']);
        $parent = new StubParentScreen('stub', ['child' => $child], actions: ['ping' => 'Ping']);

        $response = $this->renderer([$parent])->action($this->post(['action' => 'ping']), 'stub/child', new FakeHostUrls());

        static::assertSame(303, $response->getStatusCode());
        static::assertSame('/host/page/stub/child?menu=3', $response->headers->get('Location'));
        static::assertSame(['ping'], $child->ran);
        static::assertSame([], $parent->ran);
    }

    public function testAnUnknownActionOfASubScreenIsNotFound(): void
    {
        $child = new StubParentScreen('child', actions: ['ping' => 'Ping']);
        $broken = new StubParentScreen('broken', actions: new \RuntimeException('Actions failed'));
        $renderer = $this->renderer([new StubParentScreen('stub', ['child' => $child, 'broken' => $broken, 'plain' => new StubBlockScreen('plain')], actions: ['pong' => 'Pong'])]);

        foreach ([['stub/child', 'pong'], ['stub/broken', 'ping'], ['stub/plain', 'ping'], ['stub/nope', 'ping']] as [$path, $action]) {
            try {
                $renderer->action($this->post(['action' => $action]), $path, new FakeHostUrls());
                static::fail(sprintf('The "%s" action of "%s" was found.', $action, $path));
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }

        static::assertSame([], $child->ran);
        static::assertSame([], $broken->ran);
    }

    public function testAFragmentAndAPageAskedForTheRestAreFollowUps(): void
    {
        $screen = new StubBlockScreen('probe');
        $renderer = $this->renderer([$screen]);

        $renderer->page(Request::create('/'), 'probe', new FakeHostUrls());
        $renderer->fragment(Request::create('/'), 'probe', new FakeHostUrls());
        $html = $renderer->page(Request::create('/', parameters: ['rest' => '1']), 'probe', new FakeHostUrls())->html;
        $renderer->page(Request::create('/', parameters: ['rest' => '0']), 'probe', new FakeHostUrls());
        $renderer->page(Request::create('/', parameters: ['rest' => ['1']]), 'probe', new FakeHostUrls());

        static::assertSame([false, true, true, false, false], array_map(static fn(ScreenRequest $request): bool => $request->fragment, $screen->requests));
        static::assertStringNotContainsString('rest=', $html);
    }

    public function testTheConnectionAskedForReachesTheScreen(): void
    {
        $screen = new StubBlockScreen('probe');
        $renderer = $this->renderer([$screen]);

        $renderer->page(Request::create('/', parameters: ['connection' => 'reports']), 'probe', new FakeHostUrls());
        $renderer->fragment(Request::create('/', parameters: ['connection' => 'a.b/é']), 'probe', new FakeHostUrls());
        $renderer->page(Request::create('/'), 'probe', new FakeHostUrls());
        $renderer->page(Request::create('/', parameters: ['connection' => '']), 'probe', new FakeHostUrls());

        static::assertSame(['reports', 'a.b/é', null, null], array_map(static fn(ScreenRequest $request): ?string => $request->instance, $screen->requests));
    }

    public function testAFragmentAndTheRestKeepTheConnection(): void
    {
        $renderer = $this->renderer([new StubBlockScreen('probe', blocks: [Block::unavailable(Block::TABLE, BlockState::Reading)])]);
        $request = Request::create('/', parameters: ['connection' => 'reports']);

        $crawler = new Crawler($renderer->page($request, 'probe', new FakeHostUrls())->html);

        static::assertSame('/host/fragment/probe?menu=3&connection=reports', $crawler->filter('[data-jm-fragment-url]')->attr('data-jm-fragment-url'));
        static::assertSame('/host/page/probe?menu=3&connection=reports&rest=1', $crawler->filter('a[data-jm-rest]')->attr('href'));

        $fragment = new Crawler((string) $renderer->fragment($request, 'probe', new FakeHostUrls())->getContent());

        static::assertSame('/host/page/probe?menu=3&connection=reports&rest=1', $fragment->filter('a[data-jm-rest]')->attr('href'));

        $without = new Crawler($renderer->page(Request::create('/'), 'probe', new FakeHostUrls())->html);

        static::assertSame('/host/fragment/probe?menu=3', $without->filter('[data-jm-fragment-url]')->attr('data-jm-fragment-url'));
        static::assertSame('/host/page/probe?menu=3&rest=1', $without->filter('a[data-jm-rest]')->attr('href'));
    }

    public function testAnActionComesBackToTheSameConnection(): void
    {
        $screen = new StubActionScreen();
        $renderer = $this->renderer([$screen]);

        $form = (new Crawler($renderer->page(Request::create('/', parameters: ['connection' => 'reports']), 'stub', new FakeHostUrls())->html))->filter('.jm-toolbar form');

        static::assertSame('reports', $form->filter('input[type="hidden"][name="connection"]')->attr('value'));
        static::assertCount(0, (new Crawler($renderer->page(Request::create('/'), 'stub', new FakeHostUrls())->html))->filter('input[name="connection"]'));

        foreach ([
            ['reports', '/host/page/stub?menu=3&connection=reports'],
            ['a/b é', '/host/page/stub?menu=3&connection=a%2Fb%20%C3%A9'],
            ['', '/host/page/stub?menu=3'],
            [['x'], '/host/page/stub?menu=3'],
        ] as [$connection, $location]) {
            $response = $renderer->action($this->post(['action' => 'ping', 'connection' => $connection]), 'stub', new FakeHostUrls());

            static::assertSame($location, $response->headers->get('Location'));
        }

        static::assertSame(['ping', 'ping', 'ping', 'ping'], $screen->ran);
    }

    public function testAnArrayInPlaceOfTheConnectionIsIgnored(): void
    {
        $screen = new StubBlockScreen('probe', blocks: [Block::unavailable(Block::TABLE, BlockState::Reading)]);
        $renderer = $this->renderer([$screen]);
        $request = Request::create('/', parameters: ['connection' => ['x']]);

        $html = $renderer->page($request, 'probe', new FakeHostUrls())->html;
        $renderer->fragment($request, 'probe', new FakeHostUrls());

        static::assertSame([null, null], array_map(static fn(ScreenRequest $request): ?string => $request->instance, $screen->requests));
        static::assertStringNotContainsString('connection', $html);
    }

    public function testAScreenWhoseActionsThrowStillRenders(): void
    {
        $logger = $this->logger();
        $screen = new StubParentScreen('stub', blocks: [Block::cards([new Card('Hits', '12')])], actions: new \RuntimeException('Actions failed'));

        $html = $this->renderer([$screen], logger: $logger)->page(Request::create('/'), 'stub', new FakeHostUrls())->html;

        static::assertSame('12', (new Crawler($html))->filter('.jm-card__value')->text());
        static::assertStringNotContainsString('<form', $html);
        static::assertSame(['error JMonitor could not list the actions of the "stub" screen: Actions failed'], $logger->messages);
    }

    public function testATokenManagerThatThrowsStillRenders(): void
    {
        $tokens = new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                throw new \RuntimeException('Session failed');
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                throw new \RuntimeException('Session failed');
            }

            public function removeToken(string $tokenId): ?string
            {
                throw new \RuntimeException('Session failed');
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                throw new \RuntimeException('Session failed');
            }
        };
        $logger = $this->logger();
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $screen = new StubActionScreen();
        $renderer = $this->renderer([$screen], guard: new ActionGuard($tokens), logger: $logger);

        $html = $renderer->page($request, 'stub', new FakeHostUrls())->html;

        static::assertStringContainsString('<h1 class="jm-heading__title">Stub</h1>', $html);
        static::assertStringNotContainsString('<form', $html);

        $post = $this->post(['action' => 'ping', 'token' => 'x']);
        $post->setSession(new Session(new MockArraySessionStorage()));

        try {
            $renderer->action($post, 'stub', new FakeHostUrls());
            static::fail('The action was not refused.');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }

        static::assertSame([], $screen->ran);
        static::assertSame([
            'error JMonitor could not protect the actions of the "stub" screen: Session failed',
            'error JMonitor could not check the token of an action of the "stub" screen: Session failed',
        ], $logger->messages);
    }

    public function testABadgeShowsInTheNavigationAndInTheFragment(): void
    {
        $watched = new StubBadgeScreen('watched', new NavBadge('2', 'warn', '2 warnings'), space: Space::Stack);
        $quiet = new StubBadgeScreen('quiet', space: Space::Background);
        $hidden = new StubBadgeScreen('hidden', new NavBadge('1', 'bad', '1 problem'), false, Space::Stack);
        $renderer = $this->renderer([$watched, $quiet, new StubBlockScreen('plain'), $hidden]);

        $html = $renderer->page(Request::create('/'), 'watched', new FakeHostUrls())->html;
        $nav = (new Crawler($html))->filter('.jm-nav__item');

        static::assertSame(['blocks', 'badge'], $watched->calls);
        static::assertSame([], $hidden->calls);
        static::assertCount(3, $nav);
        static::assertCount(0, $nav->eq(0)->filter('[data-jm-slot]'));
        static::assertSame('<span data-jm-slot="nav-background"></span>', $nav->eq(1)->filter('[data-jm-slot]')->outerHtml());
        static::assertSame('<span data-jm-slot="nav-stack"><span class="jm-nav__badge jm-nav__badge--warn" title="2 warnings">2<span class="jm-visually-hidden"> — 2 warnings</span></span></span>', $nav->eq(2)->filter('[data-jm-slot]')->outerHtml());
        static::assertStringNotContainsString('nav-hidden', $html);

        $fragment = (string) $renderer->fragment(Request::create('/'), 'plain', new FakeHostUrls())->getContent();

        static::assertStringContainsString('<template data-jm-slot-content="nav-stack"><span class="jm-nav__badge jm-nav__badge--warn" title="2 warnings">2<span class="jm-visually-hidden"> — 2 warnings</span></span></template>', $fragment);
        static::assertStringContainsString('<template data-jm-slot-content="nav-background"></template>', $fragment);
        static::assertStringNotContainsString('nav-application', $fragment);
        static::assertStringNotContainsString('nav-hidden', $fragment);
        static::assertStringNotContainsString('jm-nav__item', $fragment);
    }

    public function testABadgeThatThrowsIsLeftOut(): void
    {
        $logger = $this->logger();
        $renderer = $this->renderer([new StubBlockScreen('plain'), new StubBadgeScreen('watched', new \RuntimeException('Cache down'), space: Space::Stack)], logger: $logger);

        $html = $renderer->page(Request::create('/'), 'plain', new FakeHostUrls())->html;
        $fragment = (string) $renderer->fragment(Request::create('/'), 'plain', new FakeHostUrls())->getContent();

        static::assertSame('<span data-jm-slot="nav-stack"></span>', (new Crawler($html))->filter('[data-jm-slot]')->outerHtml());
        static::assertStringContainsString('<template data-jm-slot-content="nav-stack"></template>', $fragment);
        static::assertSame(array_fill(0, 2, 'error JMonitor could not read the navigation badge of the "watched" screen: Cache down'), $logger->messages);
    }

    public function testATabBadgeThatThrowsLeavesTheTabWithoutBadge(): void
    {
        $logger = $this->logger();
        $renderer = $this->renderer([new StubBlockScreen('plain'), new StubBadgeScreen('watched', new \RuntimeException('Cache down'))], logger: $logger);

        foreach ([
            'page' => $renderer->page(Request::create('/'), 'plain', new FakeHostUrls())->html,
            'fragment' => (string) $renderer->fragment(Request::create('/'), 'plain', new FakeHostUrls())->getContent(),
        ] as $kind => $html) {
            $tabs = (new Crawler($html))->filter('.jm-subnav__item');

            static::assertSame(['Plain', 'Watched'], $tabs->each(static fn(Crawler $item): string => $item->text()), $kind);
            static::assertCount(0, $tabs->filter('.jm-nav__badge'), $kind);
        }

        static::assertSame(array_fill(0, 2, 'error JMonitor could not read the navigation badge of the "watched" screen: Cache down'), $logger->messages);
    }

    public function testAReadingBlockMarksTheScreenAndOffersToLoadTheRest(): void
    {
        $reading = new StubBlockScreen('probe', blocks: [Block::cards([new Card('Hits', '12')]), Block::unavailable(Block::TABLE, BlockState::Reading)]);
        $partial = new StubParentScreen('child', blocks: [Block::cards([new Card('Hits', '12')])->withReading()]);
        $renderer = $this->renderer([$reading, new StubParentScreen('stub', ['child' => $partial]), new StubBlockScreen('plain', blocks: [Block::cards([new Card('Hits', '12')])])]);

        foreach (['probe' => '/host/page/probe?menu=3&rest=1', 'stub/child' => '/host/page/stub/child?menu=3&rest=1'] as $path => $rest) {
            foreach ([
                $renderer->page(Request::create('/'), $path, new FakeHostUrls())->html,
                (string) $renderer->fragment(Request::create('/'), $path, new FakeHostUrls())->getContent(),
            ] as $html) {
                $crawler = new Crawler($html);

                static::assertCount(1, $crawler->filter('.jm-screen[data-jm-reading]'));
                static::assertSame($rest, $crawler->filter('.jm-screen > p.jm-rest > a[data-jm-rest]')->attr('href'));
                static::assertSame('Load the rest', $crawler->filter('a[data-jm-rest]')->text());
            }
        }

        foreach ([
            $renderer->page(Request::create('/'), 'plain', new FakeHostUrls())->html,
            (string) $renderer->fragment(Request::create('/'), 'plain', new FakeHostUrls())->getContent(),
        ] as $html) {
            static::assertStringNotContainsString('data-jm-reading', $html);
            static::assertStringNotContainsString('data-jm-rest', $html);
            static::assertStringNotContainsString('Load the rest', $html);
        }
    }

    public function testAStackScreenWithAnOutdatedValueOffersToLoadTheRest(): void
    {
        $flex = ['up_to_date' => true, 'outdated_recipes' => []];
        $outdated = Snapshots::stack(Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony(), 'symfony.flex' => $flex], ['symfony.flex' => ResultState::Stale]));
        $fresh = Snapshots::stack(Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony(), 'symfony.flex' => $flex]));

        foreach (['stack' => '/host/page/stack?menu=3&rest=1', 'stack/symfony' => '/host/page/stack/symfony?menu=3&rest=1'] as $path => $rest) {
            $crawler = new Crawler($this->renderer([self::stackScreen($outdated)])->page(Request::create('/'), $path, new FakeHostUrls())->html);

            static::assertCount(1, $crawler->filter('.jm-screen[data-jm-reading]'), $path);
            static::assertSame($rest, $crawler->filter('.jm-screen > p.jm-rest > a[data-jm-rest]')->attr('href'));
            static::assertSame('Load the rest', $crawler->filter('a[data-jm-rest]')->text());
            static::assertStringNotContainsString('Reading…', $crawler->filter('.jm-screen')->text());

            $html = $this->renderer([self::stackScreen($fresh)])->page(Request::create('/'), $path, new FakeHostUrls())->html;

            static::assertStringNotContainsString('data-jm-reading', $html);
            static::assertStringNotContainsString('Load the rest', $html);
        }
    }

    public function testTheFirstLevelListsTheSpacesAndMarksTheOneOfTheScreen(): void
    {
        $renderer = $this->renderer([new StubScreen('requests'), new StubScreen('queries'), new StubScreen('jobs', available: false, space: Space::Background), new StubBlockScreen('stack', space: Space::Stack)]);

        foreach (['queries' => 'Application', 'stack' => 'Stack'] as $path => $current) {
            $nav = (new Crawler($renderer->page(Request::create('/'), $path, new FakeHostUrls())->html))->filter('nav.jm-nav');

            static::assertSame(['Application', 'Stack'], $nav->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()), $path);
            static::assertSame($current, $nav->filter('.jm-nav__item[aria-current="page"]')->text(), $path);
            static::assertCount(1, $nav->filter('[aria-current]'), $path);
        }
    }

    public function testASpaceLeadsToItsFirstAvailableScreenAndKeepsThePeriod(): void
    {
        $renderer = $this->renderer([new StubScreen('hidden', false), new StubScreen('requests'), new StubScreen('queries'), new StubBlockScreen('stack', space: Space::Stack)]);
        $hrefs = static fn(string $html): array => (new Crawler($html))->filter('.jm-nav__item')->each(static fn(Crawler $item): string => (string) $item->attr('href'));

        static::assertSame(
            ['/host/page/requests?menu=3&period=6h', '/host/page/stack?menu=3&period=6h'],
            $hrefs($renderer->page(Request::create('/', parameters: ['period' => '6h', 'sort' => 'name', 'dir' => 'asc']), 'queries', new FakeHostUrls())->html),
        );
        static::assertSame(
            ['/host/page/requests?menu=3', '/host/page/stack?menu=3'],
            $hrefs($renderer->page(Request::create('/', parameters: ['period' => '6h']), 'stack', new FakeHostUrls())->html),
        );
    }

    public function testTheTabsOfASpaceWithSeveralScreensAreInTheFragment(): void
    {
        $renderer = $this->renderer([new StubScreen('requests'), new StubScreen('hidden', false), new StubScreen('queries'), new StubBlockScreen('stack', space: Space::Stack)]);
        $request = Request::create('/', parameters: ['period' => '6h', 'sort' => 'name', 'dir' => 'asc']);

        foreach ([
            'page' => $renderer->page($request, 'queries', new FakeHostUrls())->html,
            'fragment' => (string) $renderer->fragment($request, 'queries', new FakeHostUrls())->getContent(),
        ] as $kind => $html) {
            $tabs = (new Crawler($html))->filter('.jm-screen nav.jm-subnav');

            static::assertCount(1, $tabs, $kind);
            static::assertSame('Application', $tabs->attr('aria-label'), $kind);
            static::assertSame(['Requests', 'Queries'], $tabs->filter('.jm-subnav__item')->each(static fn(Crawler $item): string => $item->text()), $kind);
            static::assertSame(
                ['/host/page/requests?menu=3&period=6h', '/host/page/queries?menu=3&period=6h'],
                $tabs->filter('.jm-subnav__item')->each(static fn(Crawler $item): string => (string) $item->attr('href')),
                $kind,
            );
            static::assertSame('Queries', $tabs->filter('.jm-subnav__item[aria-current="page"]')->text(), $kind);
            static::assertCount(1, $tabs->filter('[aria-current]'), $kind);
        }

        $html = $renderer->page(Request::create('/'), 'requests', new FakeHostUrls())->html;

        static::assertSame(['/host/page/requests?menu=3&period=1h', '/host/page/queries?menu=3&period=1h'], (new Crawler($html))->filter('.jm-subnav__item')->each(static fn(Crawler $item): string => (string) $item->attr('href')));
    }

    public function testASpaceWithOneScreenHasNoTabs(): void
    {
        $renderer = $this->renderer([new StubScreen('requests'), new StubScreen('queries', false), new StubBlockScreen('stack', space: Space::Stack)]);

        foreach (['requests', 'stack'] as $path) {
            static::assertStringNotContainsString('jm-subnav', $renderer->page(Request::create('/'), $path, new FakeHostUrls())->html, $path);
            static::assertStringNotContainsString('jm-subnav', (string) $renderer->fragment(Request::create('/'), $path, new FakeHostUrls())->getContent(), $path);
        }
    }

    public function testTheTabsComeBeforeTheTitleAndTheTitleIsInTheFragment(): void
    {
        $renderer = $this->renderer([$this->screenWithATable(), new StubScreen('queries')]);

        foreach ([
            'page' => $renderer->page(Request::create('/'), 'stub', new FakeHostUrls())->html,
            'fragment' => (string) $renderer->fragment(Request::create('/'), 'stub', new FakeHostUrls())->getContent(),
        ] as $kind => $html) {
            $crawler = new Crawler($html);

            static::assertCount(1, $crawler->filter('.jm-heading'), $kind);
            static::assertSame('Stub', $crawler->filter('.jm-screen > .jm-heading h1')->text(), $kind);
            static::assertSame('Stub heading, last hour.', $crawler->filter('.jm-screen > .jm-heading .jm-heading__text')->text(), $kind);
            $subnav = strpos($html, 'class="jm-subnav"');
            $heading = strpos($html, 'class="jm-heading"');
            $cards = strpos($html, 'jm-card__value');
            static::assertIsInt($subnav, $kind);
            static::assertIsInt($heading, $kind);
            static::assertIsInt($cards, $kind);
            static::assertLessThan($heading, $subnav, $kind);
            static::assertLessThan($cards, $heading, $kind);
        }
    }

    public function testASubScreenKeepsTheTabsOfItsParentAboveItsTitle(): void
    {
        $child = new StubBlockScreen('child', blocks: [Block::cards([new Card('Hits', '12')])]);
        $renderer = $this->renderer([new StubParentScreen('stub', ['child' => $child]), new StubBlockScreen('other')]);

        foreach ([
            'page' => $renderer->page(Request::create('/'), 'stub/child', new FakeHostUrls())->html,
            'fragment' => (string) $renderer->fragment(Request::create('/'), 'stub/child', new FakeHostUrls())->getContent(),
        ] as $kind => $html) {
            $crawler = new Crawler($html);

            static::assertSame(['Stub', 'Other'], $crawler->filter('.jm-subnav__item')->each(static fn(Crawler $item): string => $item->text()), $kind);
            static::assertSame('Stub', $crawler->filter('.jm-subnav__item[aria-current="page"]')->text(), $kind);
            static::assertSame('Child', $crawler->filter('h1')->text(), $kind);
            static::assertLessThan((int) strpos($html, '<h1'), (int) strpos($html, 'class="jm-subnav"'), $kind);
        }

        $detail = new StubBlockScreen('php', blocks: [
            Block::subnav(new SubNav('Stack', [new SubNavItem('All components', 'stack'), new SubNavItem('PHP', 'stack/php', true)])),
            Block::cards([new Card('Hits', '12')]),
        ], space: Space::Stack);
        $renderer = $this->renderer([new StubScreen('requests'), new StubScreen('queries'), new StubParentScreen('stack', ['php' => $detail], space: Space::Stack)]);

        foreach ([
            'page' => $renderer->page(Request::create('/'), 'stack/php', new FakeHostUrls())->html,
            'fragment' => (string) $renderer->fragment(Request::create('/'), 'stack/php', new FakeHostUrls())->getContent(),
        ] as $kind => $html) {
            $crawler = new Crawler($html);

            static::assertCount(1, $crawler->filter('nav.jm-subnav'), $kind);
            static::assertSame(['All components', 'PHP'], $crawler->filter('.jm-subnav__item')->each(static fn(Crawler $item): string => $item->text()), $kind);
            static::assertSame('PHP', $crawler->filter('.jm-subnav__item[aria-current="page"]')->text(), $kind);
            static::assertLessThan((int) strpos($html, '<h1'), (int) strpos($html, 'class="jm-subnav"'), $kind);
            static::assertLessThan((int) strpos($html, 'jm-card__value'), (int) strpos($html, '<h1'), $kind);
        }
    }

    public function testTheBadgeOfASingleScreenSpaceFillsTheSlotOfTheSpace(): void
    {
        $watched = new StubBadgeScreen('watched', new NavBadge('2', 'warn', '2 warnings'), space: Space::Stack);
        $renderer = $this->renderer([new StubBlockScreen('requests'), new StubBlockScreen('queries'), $watched]);

        $nav = (new Crawler($renderer->page(Request::create('/'), 'requests', new FakeHostUrls())->html))->filter('.jm-nav__item');

        static::assertSame(['badge'], $watched->calls);
        static::assertCount(0, $nav->eq(0)->filter('[data-jm-slot]'));
        static::assertSame('<span data-jm-slot="nav-stack"><span class="jm-nav__badge jm-nav__badge--warn" title="2 warnings">2<span class="jm-visually-hidden"> — 2 warnings</span></span></span>', $nav->eq(1)->filter('[data-jm-slot]')->outerHtml());

        $fragment = (string) $renderer->fragment(Request::create('/'), 'requests', new FakeHostUrls())->getContent();

        static::assertStringContainsString('<template data-jm-slot-content="nav-stack"><span class="jm-nav__badge jm-nav__badge--warn" title="2 warnings">2<span class="jm-visually-hidden"> — 2 warnings</span></span></template>', $fragment);
        static::assertSame(1, substr_count($fragment, 'data-jm-slot-content'));
        static::assertStringNotContainsString('nav-watched', $fragment);

        $quiet = $this->renderer([new StubBlockScreen('requests'), new StubBadgeScreen('quiet', space: Space::Stack)]);

        static::assertSame('<span data-jm-slot="nav-stack"></span>', (new Crawler($quiet->page(Request::create('/'), 'quiet', new FakeHostUrls())->html))->filter('[data-jm-slot]')->outerHtml());
        static::assertStringContainsString('<template data-jm-slot-content="nav-stack"></template>', (string) $quiet->fragment(Request::create('/'), 'requests', new FakeHostUrls())->getContent());
    }

    public function testATabCarriesTheBadgeOfItsScreen(): void
    {
        $watched = new StubBadgeScreen('watched', new NavBadge('2', 'warn', '2 warnings'));
        $renderer = $this->renderer([$watched, new StubBlockScreen('plain'), new StubBadgeScreen('quiet'), new StubBlockScreen('stack', space: Space::Stack)]);

        foreach ([
            'page' => $renderer->page(Request::create('/'), 'plain', new FakeHostUrls())->html,
            'fragment' => (string) $renderer->fragment(Request::create('/'), 'plain', new FakeHostUrls())->getContent(),
        ] as $kind => $html) {
            $tabs = (new Crawler($html))->filter('.jm-subnav__item');

            static::assertSame('<span class="jm-nav__badge jm-nav__badge--warn" title="2 warnings">2<span class="jm-visually-hidden"> — 2 warnings</span></span>', $tabs->eq(0)->filter('.jm-nav__badge')->outerHtml(), $kind);
            static::assertCount(0, $tabs->eq(1)->filter('.jm-nav__badge'), $kind);
            static::assertCount(0, $tabs->eq(2)->filter('.jm-nav__badge'), $kind);
            static::assertStringNotContainsString('data-jm-slot', $html, $kind);
        }
    }

    public function testATabCarriesTheBadgeOfItsRaisedChecks(): void
    {
        $reader = new FixedReader(totals: [self::SERVER_ERRORS => new MetricValue(2, 0.0, 0.0), Metric::QUERY_SLOW => new MetricValue(1, 0.0, 0.0)]);
        $renderer = $this->renderer([self::tableScreen('requests'), self::tableScreen('queries'), self::tableScreen('exceptions'), new StubBlockScreen('stack', space: Space::Stack)], reader: $reader, checks: self::checks());
        $request = Request::create('/', parameters: ['period' => '6h']);

        foreach ([
            'page' => $renderer->page($request, 'exceptions', new FakeHostUrls())->html,
            'fragment' => (string) $renderer->fragment($request, 'exceptions', new FakeHostUrls())->getContent(),
        ] as $kind => $html) {
            $tabs = (new Crawler($html))->filter('.jm-subnav__item');

            static::assertSame('<span class="jm-nav__badge jm-nav__badge--bad" title="1 critical in the last 6 hours">1 critical<span class="jm-visually-hidden"> — 1 critical in the last 6 hours</span></span>', $tabs->eq(0)->filter('.jm-nav__badge')->outerHtml(), $kind);
            static::assertSame('<span class="jm-nav__badge jm-nav__badge--warn" title="1 warning in the last 6 hours">1 warning<span class="jm-visually-hidden"> — 1 warning in the last 6 hours</span></span>', $tabs->eq(1)->filter('.jm-nav__badge')->outerHtml(), $kind);
            static::assertCount(0, $tabs->eq(2)->filter('.jm-nav__badge'), $kind);
            static::assertStringNotContainsString('data-jm-slot', $html, $kind);
        }

        static::assertSame([Period::SixHours, Period::SixHours], array_column($reader->totalRequests, 0));
    }

    public function testATabKeepsTheBadgeOfItsScreenOverItsRaisedChecks(): void
    {
        $reader = new FixedReader(totals: [self::SERVER_ERRORS => new MetricValue(2, 0.0, 0.0), Metric::QUERY_SLOW => new MetricValue(1, 0.0, 0.0)]);
        $renderer = $this->renderer([new StubBadgeScreen('requests', new NavBadge('7', 'warn', '7 things')), self::tableScreen('queries')], reader: $reader, checks: self::checks());

        $tabs = (new Crawler($renderer->page(Request::create('/'), 'queries', new FakeHostUrls())->html))->filter('.jm-subnav__item');

        static::assertSame(['7 things', '1 warning in the last hour'], $tabs->filter('.jm-nav__badge')->each(static fn(Crawler $badge): string => (string) $badge->attr('title')));
    }

    public function testTheTotalsAreReadOncePerDisplay(): void
    {
        $checks = self::checks();
        $reader = new FixedReader(totals: [self::SERVER_ERRORS => new MetricValue(1, 0.0, 0.0)]);
        $requests = new StubScreen('requests', view: static fn(ReaderInterface $reader): ScreenView => new ScreenView(
            self::tableView()->cards,
            self::tableView()->tables,
            raised: $checks->raised($reader, Period::OneHour, 1800000000),
        ));
        $renderer = $this->renderer([$requests, self::tableScreen('queries')], reader: $reader, checks: $checks);

        foreach (['requests', 'queries'] as $path) {
            $reader->totalRequests = [];
            $page = new Crawler($renderer->page(Request::create('/'), $path, new FakeHostUrls())->html);

            static::assertCount(1, $reader->totalRequests, $path);
            static::assertSame('1 critical in the last hour', $page->filter('.jm-subnav__item')->eq(0)->filter('.jm-nav__badge')->attr('title'), $path);

            $reader->totalRequests = [];
            $renderer->fragment(Request::create('/'), $path, new FakeHostUrls());

            static::assertCount(1, $reader->totalRequests, $path);
        }
    }

    public function testNoTotalsAreReadAfterAFailedStorageRead(): void
    {
        $reader = new FixedReader(totals: [self::SERVER_ERRORS => new MetricValue(1, 0.0, 0.0)]);
        $failing = new StubScreen('queries', view: static fn(): ScreenView => throw new \RuntimeException('Gone'));
        $composed = new ComposedScreen('cache-http', 'Cache & HTTP', 'What your code waits for', Space::Application, [
            new StubSection('cache', static fn(): ScreenView => throw new \RuntimeException('Gone')),
        ]);
        $renderer = $this->renderer([self::tableScreen('requests'), $failing, $composed], reader: $reader, checks: self::checks());

        foreach (['queries', 'cache-http'] as $path) {
            foreach ([
                'page' => $renderer->page(Request::create('/'), $path, new FakeHostUrls())->html,
                'fragment' => (string) $renderer->fragment(Request::create('/'), $path, new FakeHostUrls())->getContent(),
            ] as $kind => $html) {
                static::assertStringContainsString('data-jm-state="storage_error"', $html, $path . ' ' . $kind);
                static::assertCount(3, (new Crawler($html))->filter('.jm-subnav__item'), $path . ' ' . $kind);
                static::assertCount(0, (new Crawler($html))->filter('.jm-nav__badge'), $path . ' ' . $kind);
            }
        }

        $flusher = $this->failingFlusher();
        $renderer = $this->renderer([self::tableScreen('requests'), self::tableScreen('queries')], $flusher, $reader, checks: self::checks());

        $html = $renderer->page(Request::create('/'), 'queries', new FakeHostUrls())->html;
        $renderer->fragment(Request::create('/'), 'queries', new FakeHostUrls());

        static::assertNotNull($flusher->lastFailure());
        static::assertStringContainsString('data-jm-state="storage_error"', $html);
        static::assertSame([], $reader->totalRequests);
    }

    public function testNoTotalsAreReadWithoutATabThatHasACheck(): void
    {
        $reader = new FixedReader(totals: [self::SERVER_ERRORS => new MetricValue(1, 0.0, 0.0)]);
        $renderer = $this->renderer([
            self::tableScreen('requests'),
            self::tableScreen('queries'),
            new StubBlockScreen('probe'),
            self::composed(self::sectionWithATable('commands')),
            new StubBlockScreen('stack', space: Space::Stack),
            new StubBlockScreen('server', space: Space::Stack),
        ], reader: $reader, checks: self::checks());

        foreach (['background', 'stack', 'server', 'probe'] as $path) {
            $renderer->page(Request::create('/'), $path, new FakeHostUrls());
            $renderer->fragment(Request::create('/'), $path, new FakeHostUrls());
        }

        static::assertSame([], $reader->totalRequests);

        $renderer = $this->renderer([self::tableScreen('exceptions'), self::tableScreen('users')], reader: $reader, checks: self::checks());
        $renderer->page(Request::create('/'), 'exceptions', new FakeHostUrls());
        $renderer->fragment(Request::create('/'), 'users', new FakeHostUrls());

        static::assertSame([], $reader->totalRequests);
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function totalsFailures(): iterable
    {
        yield 'exception' => [new \RuntimeException('Connection lost')];
        yield 'error' => [new \TypeError('Connection lost')];
    }

    #[DataProvider('totalsFailures')]
    public function testATotalsReadThatThrowsLeavesThePageWithoutBadge(\Throwable $failure): void
    {
        $logger = $this->logger();
        $reader = new class ($failure) implements ReaderInterface {
            public function __construct(private readonly \Throwable $failure) {}


            public function read(ReportQuery $query): Report
            {
                return new Report();
            }

            public function labels(array $keys): array
            {
                return [];
            }

            public function totals(Period $period, int $now, array $metricTypes, array $keyed = []): Totals
            {
                throw $this->failure;
            }
        };
        $renderer = $this->renderer([self::tableScreen('requests'), self::tableScreen('queries'), self::tableScreen('exceptions')], reader: $reader, logger: $logger, checks: self::checks());

        foreach ([
            'page' => $renderer->page(Request::create('/'), 'exceptions', new FakeHostUrls())->html,
            'fragment' => (string) $renderer->fragment(Request::create('/'), 'exceptions', new FakeHostUrls())->getContent(),
        ] as $kind => $html) {
            $crawler = new Crawler($html);

            static::assertSame(['Requests', 'Queries', 'Exceptions'], $crawler->filter('.jm-subnav__item')->each(static fn(Crawler $item): string => $item->text()), $kind);
            static::assertCount(0, $crawler->filter('.jm-nav__badge'), $kind);
            static::assertCount(1, $crawler->filter('table'), $kind);
        }

        static::assertSame(array_fill(0, 2, 'error JMonitor could not read the checks of the application: Connection lost'), $logger->messages);
    }

    public function testAComposedScreenOfTheApplicationReadsTheTotalsOnce(): void
    {
        $reader = new FixedReader(totals: [self::SERVER_ERRORS => new MetricValue(2, 0.0, 0.0), Metric::NPLUS1_CALLS => new MetricValue(1, 0.0, 0.0)]);
        $composed = new ComposedScreen('cache-http', 'Cache & HTTP', 'What your code waits for', Space::Application, [self::sectionWithATable('cache'), self::sectionWithATable('http')]);
        $renderer = $this->renderer([self::tableScreen('requests'), self::tableScreen('queries'), $composed], reader: $reader, checks: self::checks());
        $request = Request::create('/', parameters: ['period' => '24h']);

        foreach ([
            'page' => $renderer->page($request, 'cache-http', new FakeHostUrls())->html,
            'fragment' => (string) $renderer->fragment($request, 'cache-http', new FakeHostUrls())->getContent(),
        ] as $kind => $html) {
            static::assertSame(
                ['1 critical in the last 24 hours', '1 warning in the last 24 hours'],
                (new Crawler($html))->filter('.jm-subnav__item .jm-nav__badge')->each(static fn(Crawler $badge): string => (string) $badge->attr('title')),
                $kind,
            );
        }

        static::assertSame([Period::OneDay, Period::OneDay], array_column($reader->totalRequests, 0));
    }

    public function testTheCacheAndHttpTabCarriesTheBadgeOfItsChecks(): void
    {
        $checks = new ApplicationChecks([
            new ServerErrorsCheck(),
            new SlowRequestsCheck(500),
            new SlowQueriesCheck(100),
            new RepeatedQueriesCheck(10),
            new HttpFailuresCheck(),
            new HttpErrorsCheck(),
            new CacheHitRateCheck(),
            new FailedMessagesCheck(),
        ]);
        $reader = new FixedReader(
            totals: array_fill_keys([Metric::HTTP_FAILED, Metric::HTTP_STATUS_PREFIX . '5xx', Metric::CACHE_HIT, Metric::CACHE_MISS, Metric::MESSAGE_FAILED], new MetricValue(2, 0.0, 0.0)),
            keyTotals: [Metric::CACHE_MISS => [md5('cache.app') => new MetricValue(20, 0.0, 0.0)]],
        );
        $composed = new ComposedScreen('cache-http', 'Cache & HTTP', 'What your code waits for', Space::Application, [self::sectionWithATable('cache'), self::sectionWithATable('http')]);
        $renderer = $this->renderer([self::tableScreen('requests'), self::tableScreen('queries'), $composed, self::composed(self::sectionWithATable('messages'))], reader: $reader, checks: $checks);

        foreach (['requests', 'cache-http'] as $path) {
            foreach ([
                'page' => $renderer->page(Request::create('/'), $path, new FakeHostUrls())->html,
                'fragment' => (string) $renderer->fragment(Request::create('/'), $path, new FakeHostUrls())->getContent(),
            ] as $kind => $html) {
                $tabs = (new Crawler($html))->filter('.jm-subnav__item');

                static::assertCount(3, $tabs, $path . ' ' . $kind);
                static::assertCount(0, $tabs->eq(0)->filter('.jm-nav__badge'), $path . ' ' . $kind);
                static::assertCount(0, $tabs->eq(1)->filter('.jm-nav__badge'), $path . ' ' . $kind);
                static::assertSame(
                    '<span class="jm-nav__badge jm-nav__badge--bad" title="1 critical, 2 warnings in the last hour">3 critical<span class="jm-visually-hidden"> — 1 critical, 2 warnings in the last hour</span></span>',
                    $tabs->eq(2)->filter('.jm-nav__badge')->outerHtml(),
                    $path . ' ' . $kind,
                );
            }
        }

        static::assertCount(4, $reader->totalRequests);
        static::assertSame(array_fill(0, 4, [Metric::CACHE_HIT, Metric::CACHE_MISS]), $reader->keyedRequests);
    }

    public function testTheBadgesAreReadWhileRecordingIsOffOrTheBufferIsUnusable(): void
    {
        $reader = new FixedReader(totals: [self::SERVER_ERRORS => new MetricValue(2, 0.0, 0.0)]);
        $screens = [
            self::tableScreen('requests'),
            self::tableScreen('queries'),
            new StubScreen('exceptions'),
            new ComposedScreen('cache-http', 'Cache & HTTP', 'What your code waits for', Space::Application, [new StubSection('cache')]),
        ];

        foreach ([
            'recording_disabled' => $this->renderer($screens, reader: $reader, checks: self::checks(), recording: false),
            'buffer_unavailable' => $this->renderer($screens, reader: $reader, checks: self::checks(), bufferDirectory: new BufferDirectory(__FILE__, 'app')),
        ] as $state => $renderer) {
            foreach (['exceptions', 'cache-http'] as $path) {
                $reader->totalRequests = [];
                $html = $renderer->page(Request::create('/'), $path, new FakeHostUrls())->html;

                static::assertStringContainsString('data-jm-state="' . $state . '"', $html, $state . ' ' . $path);
                static::assertCount(1, $reader->totalRequests, $state . ' ' . $path);
                static::assertSame(['1 critical in the last hour'], (new Crawler($html))->filter('.jm-subnav__item .jm-nav__badge')->each(static fn(Crawler $badge): string => (string) $badge->attr('title')), $state . ' ' . $path);
            }
        }
    }

    public function testAnEmptyViewThatCarriesItsRaisedChecksIsNotReadAgain(): void
    {
        $checks = self::checks();

        foreach (['empty' => true, 'recording_disabled' => false] as $state => $recording) {
            $reader = new FixedReader(totals: [self::SERVER_ERRORS => new MetricValue(2, 0.0, 0.0)]);
            $requests = new StubScreen('requests', view: static fn(ReaderInterface $reader): ScreenView => new ScreenView(raised: $checks->raised($reader, Period::OneHour, 1800000000)));
            $renderer = $this->renderer([$requests, self::tableScreen('queries')], reader: $reader, checks: $checks, recording: $recording);

            $html = $renderer->page(Request::create('/'), 'requests', new FakeHostUrls())->html;

            static::assertStringContainsString('data-jm-state="' . $state . '"', $html, $state);
            static::assertCount(1, $reader->totalRequests, $state);
            static::assertSame(['1 critical in the last hour'], (new Crawler($html))->filter('.jm-subnav__item .jm-nav__badge')->each(static fn(Crawler $badge): string => (string) $badge->attr('title')), $state);
        }
    }

    public function testTheBrandLeadsToTheHomeOfThePage(): void
    {
        $renderer = $this->renderer();

        $brand = (new Crawler($renderer->page(Request::create('/'), 'stub', new FakeHostUrls(), true)->html))->filter('a.jm-brand');

        static::assertSame('/host/page/?menu=3', $brand->attr('href'));
        static::assertSame('JMonitor', $brand->text());
        static::assertStringNotContainsString('jm-brand', $renderer->page(Request::create('/'), 'stub', new FakeHostUrls())->html);
    }

    public function testAScreenWithoutPeriodSaysItIsReadRightNow(): void
    {
        $renderer = $this->renderer([$this->screenWithATable(), new StubBlockScreen('probe')]);

        $toolbar = (new Crawler($renderer->page(Request::create('/', parameters: ['period' => '6h']), 'probe', new FakeHostUrls())->html))->filter('.jm-toolbar');

        static::assertStringContainsString('Read right now, no period', $toolbar->text());
        static::assertCount(0, $toolbar->filter('.jm-segmented'));
        static::assertStringNotContainsString('Read right now', $renderer->page(Request::create('/'), 'stub', new FakeHostUrls())->html);
    }

    public function testALeadIsTheOnlyHeadingOfThePage(): void
    {
        $lead = new StubScreen(view: static fn(): ScreenView => new ScreenView([], [
            new Table('stubs', 'Stubs', [new Column('name', 'Name', false)], [new Row('k1', ['name' => new Cell('a')])]),
        ], new Lead('42 requests in the last hour', 'None ended in an error.', [1, 3, 2], 'requests per minute', '1 hour ago')));

        foreach ([[$lead], [$lead, new StubScreen('queries')]] as $screens) {
            $renderer = $this->renderer($screens);

            foreach ([
                'page' => $renderer->page(Request::create('/'), 'stub', new FakeHostUrls())->html,
                'fragment' => (string) $renderer->fragment(Request::create('/'), 'stub', new FakeHostUrls())->getContent(),
            ] as $kind => $html) {
                $crawler = new Crawler($html);

                static::assertCount(1, $crawler->filter('h1'), $kind);
                static::assertSame('42 requests in the last hour', $crawler->filter('.jm-screen > section.jm-lead > h1')->text(), $kind);
                static::assertSame('None ended in an error.', $crawler->filter('.jm-lead > h1 + p')->text(), $kind);
                static::assertSame(['1 hour ago', 'requests per minute', 'now'], $crawler->filter('.jm-lead [aria-hidden="true"] > span')->each(static fn(Crawler $span): string => $span->text()), $kind);
                static::assertCount(1, $crawler->filter('.jm-lead svg.jm-sparkline'), $kind);
                static::assertCount(0, $crawler->filter('.jm-heading'), $kind);
                static::assertSame(\count($screens) > 1 ? 1 : 0, $crawler->filter('.jm-screen > .jm-subbar + section.jm-lead')->count(), $kind);
            }
        }
    }

    public function testWithoutALeadTheHeadingIsTheOneOfToday(): void
    {
        $renderer = $this->renderer();

        foreach ([
            'page' => $renderer->page(Request::create('/'), 'stub', new FakeHostUrls())->html,
            'fragment' => (string) $renderer->fragment(Request::create('/'), 'stub', new FakeHostUrls())->getContent(),
        ] as $kind => $html) {
            $crawler = new Crawler($html);

            static::assertCount(1, $crawler->filter('h1'), $kind);
            static::assertSame('Stub', $crawler->filter('.jm-screen > .jm-heading > h1.jm-heading__title')->text(), $kind);
            static::assertCount(0, $crawler->filter('.jm-lead'), $kind);
        }
    }

    public function testALinkToAnUnavailableScreenIsNotRendered(): void
    {
        $view = static fn(): ScreenView => new ScreenView([], [
            new Table('stubs', 'Stubs', [new Column('name', 'Name', false)], [new Row('k1', ['name' => new Cell('a')])]),
        ], new Lead('Sentence'), new CheckList([
            new CheckLine('queries.repeated', new Mark('warn', 'Warning'), 'One query ran 12 times', 'Why.', 'GET app_home', 'queries', action: 'What.'),
            new CheckLine('requests.slow', new Mark('warn', 'Warning'), 'One route is slow', 'Why.', 'GET app_slow', 'stub', action: 'What.'),
        ], title: 'Worth a look'), new Guide([
            new GuideItem('Repeated', 'queries are listed apart.', ['See the queries' => 'queries', 'See the routes' => 'stub']),
            new GuideItem('Stack', 'reads the server.', ['Open the stack' => 'stack/php']),
        ]));

        foreach ([[false, false], [true, true]] as [$queries, $stack]) {
            $renderer = $this->renderer([new StubScreen(view: $view), new StubScreen('queries', $queries), new StubBlockScreen('stack', $stack, space: Space::Stack)]);

            foreach ([
                'page' => $renderer->page(Request::create('/'), 'stub', new FakeHostUrls())->html,
                'fragment' => (string) $renderer->fragment(Request::create('/'), 'stub', new FakeHostUrls())->getContent(),
            ] as $kind => $html) {
                $crawler = new Crawler($html);
                $repeated = $crawler->filter('[data-jm-check="queries.repeated"] .jm-check__where');

                static::assertSame('GET app_home', $repeated->text(), $kind);
                static::assertSame($queries ? 1 : 0, $repeated->filter('a')->count(), $kind);
                static::assertSame(['/host/page/stub?menu=3'], $crawler->filter('[data-jm-check="requests.slow"] .jm-check__where a')->each(static fn(Crawler $a): string => (string) $a->attr('href')), $kind);
                static::assertSame(
                    [...($queries ? ['See the queries'] : []), 'See the routes', ...($stack ? ['Open the stack'] : [])],
                    $crawler->filter('.jm-guide a')->each(static fn(Crawler $a): string => $a->text()),
                    $kind,
                );
                static::assertSame($queries, str_contains($crawler->filter('.jm-guide')->text(), 'See the queries'), $kind);
                static::assertStringStartsWith('Repeated queries are listed apart.', $crawler->filter('.jm-guide li')->eq(0)->text(), $kind);
            }
        }
    }

    public function testALinkOfACheckKeepsThePeriod(): void
    {
        $renderer = $this->renderer([new StubScreen(view: static fn(): ScreenView => new ScreenView([], [
            new Table('stubs', 'Stubs', [new Column('name', 'Name', false)], [new Row('k1', ['name' => new Cell('a')])]),
        ], null, new CheckList([
            new CheckLine('requests.slow', new Mark('warn', 'Warning'), 'One route is slow', 'Why.', 'GET app_slow', 'stub', null, ['period' => '6h'], action: 'What.'),
        ]), new Guide([new GuideItem('Slow', 'routes take long.', ['See the routes' => 'stub'])])))]);

        foreach ([
            'page' => $renderer->page(Request::create('/', parameters: ['period' => '6h']), 'stub', new FakeHostUrls())->html,
            'fragment' => (string) $renderer->fragment(Request::create('/', parameters: ['period' => '6h']), 'stub', new FakeHostUrls())->getContent(),
        ] as $kind => $html) {
            $crawler = new Crawler($html);

            static::assertSame('/host/page/stub?menu=3&period=6h', $crawler->filter('[data-jm-check="requests.slow"] .jm-check__where a')->attr('href'), $kind);
            static::assertSame('/host/page/stub?menu=3&period=6h', $crawler->filter('.jm-guide a')->attr('href'), $kind);
        }
    }

    public function testAComposedScreenFlushesBeforeItReads(): void
    {
        [$flusher, $storage] = $this->flusher();
        $seen = [];
        $section = new StubSection('commands', static function () use ($storage, &$seen): ScreenView {
            $seen[] = $storage->stores;

            return new ScreenView();
        });
        $renderer = $this->renderer([self::composed($section), new StubBlockScreen()], $flusher);

        $renderer->page(Request::create('/'), 'probe', new FakeHostUrls());
        static::assertSame(0, $storage->stores);

        $renderer->page(Request::create('/'), 'background', new FakeHostUrls());
        static::assertSame(1, $storage->stores);
        static::assertSame([1], $seen);
    }

    public function testASortLinkOfASectionNamesItAndEndsWithItsAnchor(): void
    {
        $renderer = $this->renderer([self::composed(self::sectionWithATable('tasks'), self::sectionWithATable('commands'))]);
        $links = static fn(Crawler $crawler, string $section): array => $crawler->filter('section#jm-' . $section . ' a.jm-sort')->each(static fn(Crawler $link): string => (string) $link->attr('href'));
        $sorted = static fn(Crawler $crawler, string $section): array => [$crawler->filter('section#jm-' . $section . ' th[aria-sort]')->text(), $crawler->filter('section#jm-' . $section . ' th[aria-sort]')->attr('aria-sort')];

        foreach ([
            'page' => $renderer->page(Request::create('/', parameters: ['period' => '6h']), 'background', new FakeHostUrls())->html,
            'fragment' => (string) $renderer->fragment(Request::create('/', parameters: ['period' => '6h']), 'background', new FakeHostUrls())->getContent(),
        ] as $kind => $html) {
            $crawler = new Crawler($html);

            static::assertSame(['/host/page/background?menu=3&period=6h&sort=tasks.name&dir=asc#jm-tasks', '/host/page/background?menu=3&period=6h&sort=tasks.runs&dir=asc#jm-tasks'], $links($crawler, 'tasks'), $kind);
            static::assertSame(['/host/page/background?menu=3&period=6h&sort=commands.name&dir=asc#jm-commands', '/host/page/background?menu=3&period=6h&sort=commands.runs&dir=asc#jm-commands'], $links($crawler, 'commands'), $kind);
            static::assertSame(['Runs ↓', 'descending'], $sorted($crawler, 'commands'), $kind);
        }

        foreach ([
            'page' => $renderer->page(Request::create('/', parameters: ['period' => '6h', 'sort' => 'commands.name', 'dir' => 'asc']), 'background', new FakeHostUrls())->html,
            'fragment' => (string) $renderer->fragment(Request::create('/', parameters: ['period' => '6h', 'sort' => 'commands.name', 'dir' => 'asc']), 'background', new FakeHostUrls())->getContent(),
        ] as $kind => $html) {
            $crawler = new Crawler($html);

            static::assertSame(['/host/page/background?menu=3&period=6h&sort=commands.name&dir=desc#jm-commands', '/host/page/background?menu=3&period=6h&sort=commands.runs&dir=desc#jm-commands'], $links($crawler, 'commands'), $kind);
            static::assertSame(['Name ↑', 'ascending'], $sorted($crawler, 'commands'), $kind);
            static::assertSame(['/host/page/background?menu=3&period=6h&sort=tasks.name&dir=asc#jm-tasks', '/host/page/background?menu=3&period=6h&sort=tasks.runs&dir=asc#jm-tasks'], $links($crawler, 'tasks'), $kind);
            static::assertSame(['Runs ↓', 'descending'], $sorted($crawler, 'tasks'), $kind);
        }
    }

    public function testThePeriodLinksKeepTheChosenSortOfASection(): void
    {
        $renderer = $this->renderer([self::composed(self::sectionWithATable('tasks'), self::sectionWithATable('commands'))]);
        $day = static fn(array $parameters): string => (string) (new Crawler($renderer->page(Request::create('/', parameters: $parameters), 'background', new FakeHostUrls())->html))->filter('.jm-segmented__item')->eq(2)->attr('href');

        static::assertSame('/host/page/background?menu=3&period=24h&sort=commands.name&dir=asc', $day(['period' => '6h', 'sort' => 'commands.name', 'dir' => 'asc']));
        static::assertSame('/host/page/background?menu=3&period=24h&sort=commands.runs&dir=desc', $day(['sort' => 'commands.runs']));
        static::assertSame('/host/page/background?menu=3&period=24h', $day(['period' => '6h']));
        static::assertSame('/host/page/background?menu=3&period=24h', $day(['period' => '6h', 'sort' => 'x.y', 'dir' => 'asc']));
    }

    public function testLoadTheRestKeepsThePeriodAndTheSort(): void
    {
        $queues = new StubSourceSection(new StubSection('messages'), [Block::unavailable(Block::FACTS, BlockState::Reading)]);
        $renderer = $this->renderer([self::composed($queues, self::sectionWithATable('commands'))]);

        foreach ([
            '/host/page/background?menu=3&period=6h&sort=commands.name&dir=asc&rest=1' => ['period' => '6h', 'sort' => 'commands.name', 'dir' => 'asc'],
            '/host/page/background?menu=3&period=6h&rest=1' => ['period' => '6h'],
        ] as $rest => $parameters) {
            foreach ([
                'page' => $renderer->page(Request::create('/', parameters: $parameters), 'background', new FakeHostUrls())->html,
                'fragment' => (string) $renderer->fragment(Request::create('/', parameters: $parameters), 'background', new FakeHostUrls())->getContent(),
            ] as $kind => $html) {
                $crawler = new Crawler($html);

                static::assertCount(1, $crawler->filter('.jm-screen[data-jm-reading]'), $kind);
                static::assertSame($rest, $crawler->filter('.jm-screen > p.jm-rest > a[data-jm-rest]')->attr('href'), $kind);
            }
        }
    }

    public function testAComposedScreenWithoutAvailableSectionIsNotFound(): void
    {
        $renderer = $this->renderer([new StubScreen(), self::composed(new StubSection('tasks', available: false))]);

        foreach ([
            static fn() => $renderer->page(Request::create('/'), 'background', new FakeHostUrls()),
            static fn() => $renderer->fragment(Request::create('/'), 'background', new FakeHostUrls()),
            fn() => $renderer->action($this->post(['action' => 'ping']), 'background', new FakeHostUrls()),
        ] as $call) {
            try {
                $call();
                static::fail('The composed screen was found.');
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }

        static::assertSame(['Application'], (new Crawler($renderer->page(Request::create('/'), 'stub', new FakeHostUrls())->html))->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()));
    }

    public function testAHostileSortOfAComposedScreenSortsEachSectionByDefault(): void
    {
        $renderer = $this->renderer([self::composed(self::sectionWithATable('commands'))]);

        foreach ([['sort' => ['commands.name']], ['sort' => 'commands.', 'dir' => ['asc']], ['sort' => 'commands.name.x'], ['sort' => 'commands.handled', 'dir' => 'asc']] as $parameters) {
            $crawler = new Crawler($renderer->page(Request::create('/', parameters: $parameters), 'background', new FakeHostUrls())->html);

            static::assertSame('Runs ↓', $crawler->filter('section#jm-commands th[aria-sort]')->text());
            static::assertSame('/host/page/background?menu=3&period=24h', $crawler->filter('.jm-segmented__item')->eq(2)->attr('href'));
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(array $fields, string $site = 'same-origin'): Request
    {
        return Request::create('https://app.example/host/action/stub', 'POST', $fields, server: ['HTTP_SEC_FETCH_SITE' => $site]);
    }

    /** @return AbstractLogger&object{messages: list<string>} */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = $level . ' ' . $message;
            }
        };
    }

    /**
     * @param list<ScreenInterface>|null $screens
     */
    private function renderer(?array $screens = null, ?Flusher $flusher = null, ?ReaderInterface $reader = new FixedReader(), ?ActionGuard $guard = null, ?LoggerInterface $logger = null, ?ApplicationChecks $checks = null, bool $recording = true, ?BufferDirectory $bufferDirectory = null): PageRenderer
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../templates', 'Jmonitor');
        $twig = new Environment($loader, ['autoescape' => false, 'strict_variables' => true]);
        $twig->addExtension(new JmonitorExtension());

        return new PageRenderer(
            new ScreenRegistry($screens ?? [$this->screenWithATable()]),
            new ScreenBuilder($reader, $recording, new MockClock('@1800000000'), $bufferDirectory, $logger, $checks ?? new ApplicationChecks([])),
            $flusher,
            $twig,
            new Assets(),
            $guard ?? new ActionGuard(),
            $logger,
        );
    }

    private static function stackScreen(StackState $state): StackScreen
    {
        $clock = new MockClock('@' . Snapshots::NOW);
        $summary = new StackSummary(new ServiceLocator(['cache' => static fn(): CountingCachePool => new CountingCachePool()]), new WebNode('web-1'), $clock);

        return new StackScreen(new ServiceLocator([
            StackReaderInterface::class => static fn(): StackReaderInterface => new FakeStackReader($state),
            StackChecks::class => static fn(): StackChecks => new StackChecks([], false, $clock),
            StackSummary::class => static fn(): StackSummary => $summary,
        ]), new CardBuilder($clock), $clock, new ServiceLocator([
            'system' => static fn(): ServerDetail => new ServerDetail(),
            'php' => static fn(): PhpDetail => new PhpDetail(),
            'symfony' => static fn(): SymfonyDetail => new SymfonyDetail(),
        ]));
    }

    private static function composed(SectionInterface ...$sections): ComposedScreen
    {
        return new ComposedScreen('background', 'Background', 'Work done in the background', Space::Background, $sections);
    }

    /** @param non-empty-string $id */
    private static function sectionWithATable(string $id): StubSection
    {
        return new StubSection($id, static fn(): ScreenView => new ScreenView([new Card('Runs', '3')], [
            new Table($id, ucfirst($id), [new Column('name', 'Name', false, 'name'), new Column('runs', 'Runs', sort: 'runs')], [
                new Row('k1', ['name' => new Cell('a'), 'runs' => new Cell('1')]),
            ]),
        ]), sorts: ['runs' => 'desc', 'name' => 'asc']);
    }

    /**
     * @return array{Flusher, StorageInterface&object{stores: int}} a flusher with one entry to store
     */
    private function flusher(): array
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-renderer-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $clock = new MockClock('@1000');
        $directory = new BufferDirectory($this->dir, 'app');
        $buffer = new FileBuffer($directory, 10, $clock);
        $buffer->append(new Entry(Entry::TYPE_REQUEST, 'GET app_home', 1000.0, 20.0, ['status' => 200]));
        $clock->sleep(11);
        $storage = new class implements StorageInterface {
            public int $stores = 0;

            public function prepare(): bool
            {
                return false;
            }

            public function store(array $aggregates, int $now, array $labels = []): void
            {
                ++$this->stores;
            }

            public function purge(int $now): void {}
        };

        return [new Flusher($buffer, new Aggregator(500), $storage, new FlushLock($directory, 10, $clock), $clock), $storage];
    }

    private static function checks(): ApplicationChecks
    {
        return new ApplicationChecks([new ServerErrorsCheck(), new SlowRequestsCheck(500), new SlowQueriesCheck(100), new RepeatedQueriesCheck(10)]);
    }

    private static function tableScreen(string $id): StubScreen
    {
        return new StubScreen($id, view: static fn(): ScreenView => self::tableView());
    }

    private static function tableView(): ScreenView
    {
        return new ScreenView([new Card('Hits', '12')], [
            new Table('stubs', 'Stubs', [new Column('name', 'Name', false, 'name'), new Column('count', 'Count', sort: 'count')], [
                new Row('k1', ['name' => new Cell('a'), 'count' => new Cell('1')]),
            ]),
        ]);
    }

    private function failingFlusher(): Flusher
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-renderer-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $clock = new MockClock('@1000');
        $directory = new BufferDirectory($this->dir, 'app');
        $buffer = new FileBuffer($directory, 10, $clock);
        $buffer->append(new Entry(Entry::TYPE_REQUEST, 'GET app_home', 1000.0, 20.0, ['status' => 200]));
        $clock->sleep(11);
        $storage = new class implements StorageInterface {
            public function prepare(): bool
            {
                return false;
            }

            public function store(array $aggregates, int $now, array $labels = []): void
            {
                throw new \RuntimeException('Gone');
            }

            public function purge(int $now): void {}
        };

        return new Flusher($buffer, new Aggregator(500), $storage, new FlushLock($directory, 10, $clock), $clock);
    }

    private function screenWithATable(): StubScreen
    {
        return new StubScreen(view: static fn(): ScreenView => new ScreenView([new Card('Hits', '12')], [
            new Table('stubs', 'Stubs', [new Column('name', 'Name', false, 'name'), new Column('count', 'Count', sort: 'count')], [
                new Row('k1', ['name' => new Cell('a'), 'count' => new Cell('1')]),
            ]),
        ]));
    }
}
