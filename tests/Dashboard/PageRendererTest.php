<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Dashboard\Assets;
use Jmonitor\JmonitorBundle\Dashboard\PageRenderer;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
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
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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

    /**
     * @param list<ScreenInterface>|null $screens
     */
    private function renderer(?array $screens = null, ?Flusher $flusher = null, ?ReaderInterface $reader = new FixedReader()): PageRenderer
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
