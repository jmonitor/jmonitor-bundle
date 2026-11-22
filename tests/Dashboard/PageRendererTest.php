<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Dashboard\ActionGuard;
use Jmonitor\JmonitorBundle\Dashboard\Assets;
use Jmonitor\JmonitorBundle\Dashboard\NavBadge;
use Jmonitor\JmonitorBundle\Dashboard\PageRenderer;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;
use Jmonitor\JmonitorBundle\Twig\JmonitorExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
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
        static::assertSame('Stub', (new Crawler($content->html))->filter('.jm-nav__item[aria-current="page"]')->text());
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
        static::assertStringNotContainsString('jm-heading', $html);
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
        static::assertSame(['Stub', 'Other'], $crawler->filter('.jm-nav__item')->each(static fn(Crawler $item): string => $item->text()));
        static::assertSame('Stub', $crawler->filter('.jm-nav__item[aria-current="page"]')->text());
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
        $watched = new StubBadgeScreen('watched', new NavBadge('2', 'warn', '2 warnings'));
        $quiet = new StubBadgeScreen('quiet');
        $hidden = new StubBadgeScreen('hidden', new NavBadge('1', 'bad', '1 problem'), false);
        $renderer = $this->renderer([$watched, $quiet, new StubBlockScreen('plain'), $hidden]);

        $html = $renderer->page(Request::create('/'), 'watched', new FakeHostUrls())->html;
        $nav = (new Crawler($html))->filter('.jm-nav__item');

        static::assertSame(['blocks', 'badge'], $watched->calls);
        static::assertSame([], $hidden->calls);
        static::assertCount(3, $nav);
        static::assertSame('<span data-jm-slot="nav-watched"><span class="jm-nav__badge jm-nav__badge--warn" title="2 warnings">2</span></span>', $nav->eq(0)->filter('[data-jm-slot]')->outerHtml());
        static::assertSame('<span data-jm-slot="nav-quiet"></span>', $nav->eq(1)->filter('[data-jm-slot]')->outerHtml());
        static::assertCount(0, $nav->eq(2)->filter('[data-jm-slot]'));
        static::assertStringNotContainsString('nav-hidden', $html);

        $fragment = (string) $renderer->fragment(Request::create('/'), 'plain', new FakeHostUrls())->getContent();

        static::assertStringContainsString('<template data-jm-slot-content="nav-watched"><span class="jm-nav__badge jm-nav__badge--warn" title="2 warnings">2</span></template>', $fragment);
        static::assertStringContainsString('<template data-jm-slot-content="nav-quiet"></template>', $fragment);
        static::assertStringNotContainsString('nav-plain', $fragment);
        static::assertStringNotContainsString('nav-hidden', $fragment);
        static::assertStringNotContainsString('jm-nav__item', $fragment);
    }

    public function testABadgeThatThrowsIsLeftOut(): void
    {
        $logger = $this->logger();
        $renderer = $this->renderer([new StubBlockScreen('plain'), new StubBadgeScreen('watched', new \RuntimeException('Cache down'))], logger: $logger);

        $html = $renderer->page(Request::create('/'), 'plain', new FakeHostUrls())->html;
        $fragment = (string) $renderer->fragment(Request::create('/'), 'plain', new FakeHostUrls())->getContent();

        static::assertSame('<span data-jm-slot="nav-watched"></span>', (new Crawler($html))->filter('[data-jm-slot]')->outerHtml());
        static::assertStringContainsString('<template data-jm-slot-content="nav-watched"></template>', $fragment);
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
    private function renderer(?array $screens = null, ?Flusher $flusher = null, ?ReaderInterface $reader = new FixedReader(), ?ActionGuard $guard = null, ?LoggerInterface $logger = null): PageRenderer
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../templates', 'Jmonitor');
        $twig = new Environment($loader, ['autoescape' => false, 'strict_variables' => true]);
        $twig->addExtension(new JmonitorExtension());

        return new PageRenderer(
            new ScreenRegistry($screens ?? [$this->screenWithATable()]),
            new ScreenBuilder($reader, true, new MockClock('@1800000000')),
            $flusher,
            $twig,
            new Assets(),
            $guard ?? new ActionGuard(),
            $logger,
        );
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
