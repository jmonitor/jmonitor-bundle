<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Twig;

use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\DeclaredTasks;
use Jmonitor\JmonitorBundle\Dashboard\NavBadge;
use Jmonitor\JmonitorBundle\Dashboard\Page;
use Jmonitor\JmonitorBundle\Dashboard\Screen\CommandsScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\MessagesScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\SchedulerScreen;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCard;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCards;
use Jmonitor\JmonitorBundle\Dashboard\View\Fact;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauge;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauges;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Dashboard\View\Vital;
use Jmonitor\JmonitorBundle\Embed\PageContent;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FakeHostUrls;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedSchedules;
use Jmonitor\JmonitorBundle\Tests\Dashboard\StubBlockScreen;
use Jmonitor\JmonitorBundle\Tests\Dashboard\StubScreen;
use Jmonitor\JmonitorBundle\Twig\JmonitorExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class TemplatesTest extends TestCase
{
    private const HOSTILE_SQL = '<script>alert(1)</script>';
    private const HOSTILE = '<b>&"x';

    public function testEveryTemplateEscapesWithoutRelyingOnTheHost(): void
    {
        $table = $this->table([
            new Row('k1', [
                'name' => new Cell('<b>', kind: 'route', data: ['method' => '<b>']),
                'query' => new Cell(self::HOSTILE_SQL, kind: 'sql'),
                'count' => new Cell('<b>'),
            ], ['data-jm-route' => '"><i>']),
            new Row('"><i>', [
                'query' => new Cell(str_repeat(self::HOSTILE_SQL, 10), kind: 'sql'),
            ], ['data-jm-route' => '"><i>']),
        ]);
        $view = new ScreenView([new Card('<b>', '<b>', self::HOSTILE_SQL, series: [1, 2])], [$table]);

        $outputs = [
            $this->render('block/table.html.twig', ['page' => $this->page($view), 'block' => Block::table($table)]),
            $this->render('page.html.twig', ['page' => $this->page($view), 'screens' => [new StubScreen('<b>')], 'periods' => Period::cases()]),
            $this->render('page.html.twig', ['page' => $this->page($view), 'screens' => [new StubScreen('<b>')], 'periods' => Period::cases(), 'standalone' => true]),
        ];

        foreach ($outputs as $html) {
            static::assertStringContainsString('&lt;script&gt;', $html);
            static::assertStringContainsString('&lt;b&gt;', $html);
            static::assertStringNotContainsString('<script>', $html);
            static::assertStringNotContainsString('<b>', $html);
            static::assertStringNotContainsString('"><i>', $html);
        }
    }

    public function testTheStandaloneLayoutEscapesTheTitleAndTheAssetsAndKeepsTheContent(): void
    {
        $html = $this->render('layout.html.twig', ['content' => new PageContent(
            '<div class="jm-header"></div>',
            'JMonitor — <b>',
            ['/bundles/jmonitor/jmonitor.css?v="><i>'],
            ['/bundles/jmonitor/jmonitor.js?v=0123abcd'],
            [],
        )]);

        static::assertStringContainsString('<title>JMonitor — &lt;b&gt;</title>', $html);
        static::assertStringContainsString('<link rel="stylesheet" href="/bundles/jmonitor/jmonitor.css?v=&quot;&gt;&lt;i&gt;">', $html);
        static::assertStringContainsString('<script src="/bundles/jmonitor/jmonitor.js?v=0123abcd" defer></script>', $html);
        static::assertStringContainsString('<body class="jm">', $html);
        static::assertStringContainsString('<a class="jm-skip" href="#jm-main">', $html);
        static::assertStringContainsString('<div class="jm-header"></div>', $html);
        static::assertStringNotContainsString('<b>', $html);
        static::assertStringNotContainsString('"><i>', $html);
    }

    public function testTheCommandsAndMessagesScreensEscapeTheirKeys(): void
    {
        $hostile = new ReportRow('"><i>', self::HOSTILE_SQL, self::HOSTILE_SQL, [
            Metric::COMMAND_DURATION => new MetricValue(1, 10.0, 10.0),
            Metric::MESSAGE_DURATION => new MetricValue(1, 10.0, 10.0),
            Metric::MESSAGE_SENT => new MetricValue(1, 0.0, 0.0),
        ]);
        $reader = new FixedReader(new Report([$hostile], keyCount: 1));

        foreach ([new CommandsScreen(true), new MessagesScreen(true)] as $screen) {
            $view = $screen->build($reader, Period::OneHour, 1800000000, (string) array_key_first($screen->sorts()), 'desc');
            $page = new Page($screen, $this->blocks($view), Period::OneHour, (string) array_key_first($screen->sorts()), 'desc', $screen->sorts());

            foreach ([
                $this->render('block/table.html.twig', ['page' => $page, 'block' => Block::table($view->tables[0])]),
                $this->render('page.html.twig', ['page' => $page, 'screens' => [$screen], 'periods' => Period::cases()]),
            ] as $html) {
                static::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, $screen->id());
                static::assertStringNotContainsString(self::HOSTILE_SQL, $html, $screen->id());
                static::assertStringNotContainsString('"><i>', $html, $screen->id());
            }
        }
    }

    public function testTheSchedulerScreenEscapesTaskLabels(): void
    {
        $label = 'app:work --note="<script>alert(1)</script>"';
        $declared = new DeclaredTasks(FixedSchedules::locator([
            'default' => static fn(): Schedule => (new Schedule())->add(RecurringMessage::every('1 hour', new RunCommandMessage($label), '@1799999000')),
        ]));
        $stored = new ReportRow('"><i>', (new TaskKey('"><i>', 'gone', $label, '"><i>'))->toString(), null, [
            Metric::TASK_DURATION => new MetricValue(1, 10.0, 10.0),
            Metric::TASK_LAST_RUN => new MetricValue(1, 0.0, 1799999990.0),
        ]);
        $screen = new SchedulerScreen(true, $declared);
        $view = $screen->build(new FixedReader(new Report([$stored], keyCount: 1)), Period::OneHour, 1800000000, 'next', 'asc');
        $page = new Page($screen, $this->blocks($view), Period::OneHour, 'next', 'asc', $screen->sorts());

        static::assertCount(2, $view->tables[0]->rows);
        foreach ([
            $this->render('block/table.html.twig', ['page' => $page, 'block' => Block::table($view->tables[0])]),
            $this->render('page.html.twig', ['page' => $page, 'screens' => [$screen], 'periods' => Period::cases()]),
        ] as $html) {
            static::assertStringContainsString('app:work --note=&quot;&lt;script&gt;alert(1)&lt;/script&gt;&quot;', $html);
            static::assertStringNotContainsString(self::HOSTILE_SQL, $html);
            static::assertStringNotContainsString('"><i>', $html);
        }
    }

    public function testALongQueryIsFoldedAndAShortOneIsNot(): void
    {
        $long = 'SELECT ' . implode(', ', array_map(static fn(int $i): string => 'column_' . $i, range(1, 30))) . ' FROM t WHERE id = ?';
        $table = $this->table([
            new Row('hash-long', ['query' => new Cell($long, kind: 'sql')]),
            new Row('hash-short', ['query' => new Cell('SELECT 1', kind: 'sql')]),
        ]);

        $html = $this->render('block/table.html.twig', ['page' => $this->page(new ScreenView([], [$table])), 'block' => Block::table($table)]);

        static::assertGreaterThan(120, \strlen($long));
        static::assertStringContainsString(
            '<details class="jm-sql" data-jm-key="hash-long"><summary><code>' . substr($long, 0, 120) . '</code></summary><pre><code>' . $long . '</code></pre></details>',
            $html,
        );
        static::assertStringContainsString('<code class="jm-sql">SELECT 1</code>', $html);
        static::assertSame(1, substr_count($html, '<details'));
    }

    public function testOnlySortableColumnsLinkAndHiddenRowsAreCounted(): void
    {
        $table = new Table('stubs', 'Stubs', [new Column('name', 'Name', false, 'name'), new Column('query', 'Query', false)], [
            new Row('k1', ['name' => new Cell('a')]),
            new Row('k2', ['name' => new Cell('b')]),
        ], 7, 'stub');

        $html = $this->render('block/table.html.twig', ['page' => $this->page(new ScreenView([], [$table])), 'block' => Block::table($table)]);

        static::assertSame(1, substr_count($html, 'class="jm-sort"'));
        static::assertStringContainsString('9 stubs', $html);
        static::assertStringContainsString('7 more stubs not shown', $html);
    }

    public function testCountsUseThePluralOfTheNoun(): void
    {
        $one = new Table('stubs', 'Stubs', [new Column('name', 'Name', false)], [new Row('k1', ['name' => new Cell('a')])], 1, 'query', 'queries');
        $many = new Table('stubs', 'Stubs', [new Column('name', 'Name', false)], [new Row('k1', ['name' => new Cell('a')])], 2, 'query', 'queries');

        $html = $this->render('block/table.html.twig', ['page' => $this->page(new ScreenView([], [$one])), 'block' => Block::table($one)]);
        static::assertStringContainsString('2 queries', $html);
        static::assertStringContainsString('1 more query not shown', $html);

        $html = $this->render('block/table.html.twig', ['page' => $this->page(new ScreenView([], [$many])), 'block' => Block::table($many)]);
        static::assertStringContainsString('3 queries', $html);
        static::assertStringContainsString('2 more queries not shown', $html);
    }

    public function testTheHeadingNamesThePeriod(): void
    {
        $page = new Page(new StubScreen(), [], Period::SevenDays, 'name', 'asc', (new StubScreen())->sorts());

        $html = $this->render('page.html.twig', ['page' => $page, 'screens' => [new StubScreen()], 'periods' => Period::cases()]);

        static::assertStringContainsString('Stub heading, last 7 days.', $html);
    }

    public function testABlockThatIsNotReadyShowsItsPanelNextToTheOthers(): void
    {
        $page = new Page(new StubBlockScreen(), [
            Block::cards([new Card('Hits', '12')]),
            Block::unavailable(Block::TABLE, BlockState::Empty, '<b>', '<b>'),
        ], null, '', '', []);

        $html = $this->render('_screen.html.twig', ['page' => $page]);

        static::assertStringContainsString('<div class="jm-screen" data-jm-state="mixed">', $html);
        static::assertStringContainsString('<p class="jm-card__value">12</p>', $html);
        static::assertStringContainsString('<h2 class="jm-state__title">&lt;b&gt;</h2>', $html);
        static::assertStringContainsString('<p class="jm-state__text">&lt;b&gt;</p>', $html);
        static::assertStringNotContainsString('<b>', $html);
    }

    public function testAScrollableTableRegionHasAStableId(): void
    {
        $table = $this->table([new Row('k1', ['name' => new Cell('a')])]);
        $hostile = new Table('"><i>', 'Stubs', [new Column('name', 'Name', false)], [new Row('k1', ['name' => new Cell('a')])]);

        $html = $this->render('block/table.html.twig', ['page' => $this->page(new ScreenView([], [$table])), 'block' => Block::table($table)]);
        $escaped = $this->render('block/table.html.twig', ['page' => $this->page(new ScreenView([], [$hostile])), 'block' => Block::table($hostile)]);

        static::assertStringContainsString('<div class="jm-table-wrap" id="jm-stubs-wrap" role="region"', $html);
        static::assertStringContainsString('id="jm-&quot;&gt;&lt;i&gt;-wrap"', $escaped);
        static::assertStringNotContainsString('"><i>', $escaped);
    }

    public function testTheErrorStateHasItsOwnText(): void
    {
        $page = new Page(new StubBlockScreen(), [Block::unavailable(Block::TABLE, BlockState::Error)], null, '', '', []);

        $html = $this->render('_screen.html.twig', ['page' => $page]);

        static::assertStringContainsString('<div class="jm-screen" data-jm-state="error">', $html);
        static::assertStringContainsString('<div class="jm-state jm-state--bad">', $html);
        static::assertStringContainsString('<h2 class="jm-state__title">Section unavailable</h2>', $html);
        static::assertStringContainsString('<p class="jm-state__text">JMonitor could not display this section. Check the application logs.</p>', $html);
    }

    public function testTheReadingStateHasItsOwnText(): void
    {
        $page = new Page(new StubBlockScreen(), [Block::unavailable(Block::TABLE, BlockState::Reading)], null, '', '', []);

        $html = $this->render('_screen.html.twig', ['page' => $page, 'path' => 'probe', 'badges' => []]);

        static::assertStringContainsString('<div class="jm-screen" data-jm-state="reading" data-jm-reading>', $html);
        static::assertStringContainsString('<div class="jm-state jm-state--info" data-jm-reading>', $html);
        static::assertStringContainsString('<h2 class="jm-state__title">Reading…</h2>', $html);
        static::assertStringContainsString('<p class="jm-state__text">JMonitor is reading this section.</p>', $html);
        static::assertStringContainsString('<p class="jm-rest"><a data-jm-rest href="/host/page/probe?menu=3&amp;rest=1">Load the rest</a></p>', $html);
    }

    public function testABadgeIsEscaped(): void
    {
        $page = new Page(new StubBlockScreen(), [], null, '', '', []);
        $badges = ['probe' => new NavBadge('"><script>', 'warn', '"><script>')];
        $context = ['page' => $page, 'path' => 'probe', 'root' => $page->screen, 'badges' => $badges, 'screens' => [$page->screen], 'periods' => Period::cases()];

        foreach (['page.html.twig', '_screen.html.twig'] as $template) {
            $html = $this->render($template, $context);

            static::assertStringContainsString('<span class="jm-nav__badge jm-nav__badge--warn" title="&quot;&gt;&lt;script&gt;">&quot;&gt;&lt;script&gt;</span>', $html, $template);
            static::assertStringNotContainsString('<script>', $html, $template);
        }
    }

    /**
     * @return iterable<string, array{Block}>
     */
    public static function hostileStackBlocks(): iterable
    {
        $h = self::HOSTILE;
        $mark = new Mark('warn', $h);

        yield 'subnav' => [Block::subnav(new SubNav([new SubNavItem($h, $h, true, $mark), new SubNavItem($h, $h)], $h))];
        yield 'notice' => [Block::notice(new Notice('bad', $h, $h))];
        yield 'checks' => [Block::checks(new CheckList([new CheckLine($h, $mark, $h, $h, $h, $h, $h), new CheckLine($h, $mark, $h, $h, $h)]))];
        yield 'empty checks' => [Block::checks(new CheckList([], $h, $h))];
        yield 'components' => [Block::components(new ComponentCards([
            new ComponentCard($h, $h, $mark, [new Vital($h, $h, $h)], $h, $h, false, null, [$mark], $h, 1800000000),
            new ComponentCard($h, $h, $mark, [new Vital($h, $h, $h)], reading: true),
            new ComponentCard($h, $h, $mark, reason: $h),
        ], [$h, $h], $h, $h))];
        yield 'gauges' => [Block::gauges(new Gauges($h, [new Gauge($h, 42.0, $h, 'warn', $h), new Gauge($h, null, $h, hint: $h)]))];
        yield 'facts' => [Block::facts(new Facts($h, [new Fact($h, $h, $mark, $h), new Fact($h, $h)]))];
    }

    #[DataProvider('hostileStackBlocks')]
    public function testEachStackBlockEscapesWhatItShows(Block $block): void
    {
        $html = $this->screen($block);

        static::assertStringContainsString('&lt;b&gt;&amp;&quot;x', $html);
        static::assertStringNotContainsString('<b>', $html);
        static::assertStringNotContainsString('&"x', $html);
    }

    public function testAMarkAlwaysCarriesItsWord(): void
    {
        foreach (['bad', 'warn', 'ok', 'unknown', 'info'] as $tone) {
            $mark = new Mark($tone, 'Word ' . $tone);
            $expected = '<span class="jm-mark jm-mark--' . $tone . '">Word ' . $tone . '</span>';

            foreach ([
                Block::subnav(new SubNav([new SubNavItem('PHP', 'stack/php', false, $mark)])),
                Block::checks(new CheckList([new CheckLine('php.x', $mark, 'Sentence', 'Explanation', 'PHP')])),
                Block::components(new ComponentCards([new ComponentCard('php', 'PHP', $mark)])),
                Block::components(new ComponentCards([new ComponentCard('php', 'PHP', new Mark('ok', 'Healthy'), badges: [$mark])])),
                Block::facts(new Facts('Runtime', [new Fact('Version', '8.4', $mark)])),
            ] as $block) {
                static::assertStringContainsString($expected, $this->screen($block), $block->type . ' ' . $tone);
            }
        }
    }

    public function testAnEmptyCheckListSaysNothingToCheck(): void
    {
        $default = new Crawler($this->screen(Block::checks(new CheckList([]))));
        $custom = new Crawler($this->screen(Block::checks(new CheckList([], 'All good', 'No check was raised.'))));

        static::assertCount(0, $default->filter('[data-jm-check]'));
        static::assertStringContainsString('Nothing to check', $default->filter('.jm-checks')->text());
        static::assertStringContainsString('All good', $custom->filter('.jm-checks')->text());
        static::assertStringContainsString('No check was raised.', $custom->filter('.jm-checks')->text());
    }

    public function testACheckCarriesItsSeverityAndAKeyedExplanation(): void
    {
        $line = new CheckLine('php.opcache_memory', new Mark('bad', 'Critical'), 'OPcache memory is full', 'Raise opcache.memory_consumption.', 'PHP', 'stack/php', '3 min ago');

        $check = (new Crawler($this->screen(Block::checks(new CheckList([$line])))))->filter('[data-jm-check="php.opcache_memory"][data-jm-severity="bad"]');

        static::assertCount(1, $check);
        static::assertStringContainsString('OPcache memory is full', $check->text());
        static::assertSame('Raise opcache.memory_consumption.', $check->filter('details[data-jm-key="check-php.opcache_memory-PHP"] p')->text());
        static::assertStringContainsString('3 min ago', $check->text());
    }

    public function testACardThatIsReadingSaysSoAndMarksItself(): void
    {
        $reading = new ComponentCard('php', 'PHP', new Mark('unknown', 'Unknown'), [new Vital('OPcache memory', '42 %')], '8.4.1', 'stack/php', reading: true);
        $read = new ComponentCard('symfony', 'Symfony', new Mark('ok', 'Healthy'), [new Vital('Environment', 'prod')], readAt: 1800000000);

        $crawler = new Crawler($this->screen(Block::components(new ComponentCards([$reading, $read]))));
        $card = $crawler->filter('[data-jm-component="php"][data-jm-verdict="unknown"]');

        static::assertCount(1, $crawler->filter('[data-jm-component="php"][data-jm-reading]'));
        static::assertStringContainsString('Reading…', $card->text());
        static::assertStringNotContainsString('42 %', $card->text());
        static::assertNull($card->attr('data-jm-read-at'));
        static::assertNull($crawler->filter('[data-jm-component="symfony"]')->attr('data-jm-reading'));
        static::assertSame('1800000000', $crawler->filter('[data-jm-component="symfony"]')->attr('data-jm-read-at'));
        static::assertStringContainsString('prod', $crawler->filter('[data-jm-component="symfony"]')->text());
    }

    public function testACardWithAReasonShowsItInsteadOfItsVitals(): void
    {
        $card = new ComponentCard('server', 'Server', new Mark('unknown', 'Unknown'), [new Vital('Memory', '61 %')], reason: 'Cannot read /proc/meminfo');

        $crawler = (new Crawler($this->screen(Block::components(new ComponentCards([$card])))))->filter('[data-jm-component="server"]');

        static::assertStringContainsString('Cannot read /proc/meminfo', $crawler->text());
        static::assertStringNotContainsString('Memory', $crawler->text());
        static::assertStringNotContainsString('61 %', $crawler->text());
    }

    public function testACardWithoutPathIsNotALink(): void
    {
        $crawler = new Crawler($this->screen(Block::components(new ComponentCards([
            new ComponentCard('php', 'PHP', new Mark('ok', 'Healthy'), path: 'stack/php'),
            new ComponentCard('redis', 'Redis', new Mark('ok', 'Healthy')),
        ]))));

        static::assertSame(['/host/page/stack/php?menu=3'], $crawler->filter('[data-jm-component="php"] a')->each(static fn(Crawler $a): string => (string) $a->attr('href')));
        static::assertCount(0, $crawler->filter('[data-jm-component="redis"] a'));
        static::assertSame('Redis', $crawler->filter('[data-jm-component="redis"] .jm-component__name')->text());
    }

    public function testAGaugeWithoutValueShowsADash(): void
    {
        $crawler = new Crawler($this->screen(Block::gauges(new Gauges('Memory', [
            new Gauge('Used', 142.5, '142 %', 'bad'),
            new Gauge('Swap', null, ''),
            new Gauge('Pool', null, 'unlimited'),
        ]))));
        $gauges = $crawler->filter('.jm-gauge');

        $meter = $gauges->eq(0)->filter('[role="meter"]');
        static::assertSame(['100', '0', '100', 'Used'], [$meter->attr('aria-valuenow'), $meter->attr('aria-valuemin'), $meter->attr('aria-valuemax'), $meter->attr('aria-label')]);
        static::assertStringContainsString('142 %', $gauges->eq(0)->text());
        static::assertCount(0, $gauges->eq(1)->filter('[role="meter"]'));
        static::assertSame('—', $gauges->eq(1)->filter('.jm-gauge__value')->text());
        static::assertCount(0, $gauges->eq(2)->filter('[role="meter"]'));
        static::assertSame('unlimited', $gauges->eq(2)->filter('.jm-gauge__value')->text());
    }

    public function testTheNodeIsNamedOnceAndItsChangeNoticeStartsHidden(): void
    {
        $html = $this->screen(Block::subnav(new SubNav([new SubNavItem('Overview', 'stack', true)], 'web-1')));
        $without = $this->screen(Block::subnav(new SubNav([new SubNavItem('Overview', 'stack', true)])));

        static::assertStringContainsString('<nav class="jm-subnav" aria-label="Stack">', $html);
        static::assertStringContainsString('<p class="jm-node" data-jm-node="web-1">Answered by <strong>web-1</strong><span data-jm-node-changed hidden> — another web server answered this refresh</span></p>', $html);
        static::assertSame(1, substr_count($html, 'data-jm-node='));
        static::assertSame(1, substr_count($html, 'web-1</strong>'));
        static::assertStringNotContainsString('data-jm-node', $without);
    }

    public function testLinksOfABlockGoThroughTheHostUrls(): void
    {
        $crawler = new Crawler($this->screen(
            Block::subnav(new SubNav([new SubNavItem('Overview', 'stack', true), new SubNavItem('PHP', 'stack/php')])),
            Block::checks(new CheckList([new CheckLine('php.x', new Mark('warn', 'Warning'), 'Sentence', 'Explanation', 'PHP', 'stack/php')])),
            Block::components(new ComponentCards([new ComponentCard('php', 'PHP', new Mark('ok', 'Healthy'), path: 'stack/php')])),
        ));

        static::assertSame(
            ['/host/page/stack?menu=3', '/host/page/stack/php?menu=3', '/host/page/stack/php?menu=3', '/host/page/stack/php?menu=3'],
            $crawler->filter('a[href]')->each(static fn(Crawler $a): string => (string) $a->attr('href')),
        );
        static::assertSame('page', $crawler->filter('.jm-subnav a')->eq(0)->attr('aria-current'));
        static::assertNull($crawler->filter('.jm-subnav a')->eq(1)->attr('aria-current'));
    }

    private function screen(Block ...$blocks): string
    {
        return $this->render('_screen.html.twig', ['page' => new Page(new StubBlockScreen('stack'), $blocks, null, '', '', []), 'path' => 'stack', 'badges' => []]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../templates', 'Jmonitor');
        $twig = new Environment($loader, ['autoescape' => false, 'strict_variables' => true]);
        $twig->addExtension(new JmonitorExtension());

        return $twig->render('@Jmonitor/dashboard/' . $template, $context + ['urls' => new FakeHostUrls(), 'standalone' => false, 'actions' => [], 'token' => null]);
    }

    private function page(ScreenView $view): Page
    {
        return new Page(new StubScreen(), $this->blocks($view), Period::OneHour, 'name', 'asc', (new StubScreen())->sorts());
    }

    /** @return list<Block> */
    private function blocks(ScreenView $view): array
    {
        return [Block::cards($view->cards), ...array_map(Block::table(...), $view->tables)];
    }

    /**
     * @param list<Row> $rows
     */
    private function table(array $rows): Table
    {
        return new Table('stubs', 'Stubs', [
            new Column('name', 'Name', false, 'name'),
            new Column('query', 'Query', false),
            new Column('count', 'Count', sort: 'count'),
        ], $rows);
    }
}
