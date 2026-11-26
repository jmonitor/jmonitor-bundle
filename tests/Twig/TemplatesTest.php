<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Twig;

use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\DeclaredTasks;
use Jmonitor\JmonitorBundle\Dashboard\NavBadge;
use Jmonitor\JmonitorBundle\Dashboard\Page;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ComposedScreen;
use Jmonitor\JmonitorBundle\Dashboard\Section\CommandsSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\MessagesSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\SectionInterface;
use Jmonitor\JmonitorBundle\Dashboard\Section\TasksSection;
use Jmonitor\JmonitorBundle\Dashboard\Space;
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
use Jmonitor\JmonitorBundle\Dashboard\View\Guide;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\InstanceNav;
use Jmonitor\JmonitorBundle\Dashboard\View\InstanceNavItem;
use Jmonitor\JmonitorBundle\Dashboard\View\InstanceNavRow;
use Jmonitor\JmonitorBundle\Dashboard\View\Lead;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Section;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
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
use Jmonitor\JmonitorBundle\Tests\Dashboard\StubSection;
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

    private const LEGACY_CHECKS = <<<'HTML'
            <section class="jm-panel jm-checks" aria-label="To check">
                <div class="jm-panel__header">
                    <h2 class="jm-panel__title">To check</h2>
                </div>
                <ul class="jm-checks__list">
                                    <li class="jm-check" data-jm-check="php.opcache_memory" data-jm-severity="bad">
                            <div class="jm-check__mark"><span class="jm-mark jm-mark--bad">Critical</span></div>
                            <div class="jm-check__body">
                                <p class="jm-check__sentence">OPcache memory is full</p>
                                                            <details class="jm-check__more" data-jm-key="check-php.opcache_memory-PHP">
                                        <summary>Why and what to do</summary>
                                        <p>Raise opcache.memory_consumption.</p>
                                    </details>
                                                    </div>
                            <p class="jm-check__where"><a href="/host/page/stack/php?menu=3">PHP</a> <span class="jm-check__age">3 min ago</span></p>
                        </li>
                                    <li class="jm-check" data-jm-check="mysql.connections" data-jm-severity="warn">
                            <div class="jm-check__mark"><span class="jm-mark jm-mark--warn">Warning</span></div>
                            <div class="jm-check__body">
                                <p class="jm-check__sentence">Connections &lt;near&gt; the limit</p>
                                                    </div>
                            <p class="jm-check__where"><a href="/host/page/stack/mysql?menu=3&amp;connection=main">MySQL · main</a></p>
                        </li>
                                    <li class="jm-check" data-jm-check="server.load" data-jm-severity="info">
                            <div class="jm-check__mark"><span class="jm-mark jm-mark--info">Info</span></div>
                            <div class="jm-check__body">
                                <p class="jm-check__sentence">The load is high</p>
                                                            <details class="jm-check__more" data-jm-key="check-server.load-Server">
                                        <summary>Why and what to do</summary>
                                        <p>Inside a container, the load is the one of the host node.</p>
                                    </details>
                                                    </div>
                            <p class="jm-check__where">Server</p>
                        </li>
                            </ul>
            </section>
        HTML;

    private const LEGACY_EMPTY_CHECKS = <<<'HTML'
            <section class="jm-checks jm-checks--empty" aria-label="To check">
                <h2 class="jm-checks__empty-title">Nothing to check on PHP</h2>
                            <p class="jm-checks__empty-text">No check was raised.</p>
                    </section>
        HTML;

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
            $this->render('page.html.twig', ['page' => $this->page($view), 'spaces' => [['id' => '<b>', 'label' => '<b>', 'path' => '<b>', 'current' => true]], 'periods' => Period::cases()]),
            $this->render('page.html.twig', ['page' => $this->page($view), 'spaces' => [['id' => '<b>', 'label' => '<b>', 'path' => '<b>', 'current' => true]], 'periods' => Period::cases(), 'standalone' => true]),
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

    public function testTheCommandsAndMessagesSectionsEscapeTheirKeys(): void
    {
        $hostile = new ReportRow('"><i>', self::HOSTILE_SQL, self::HOSTILE_SQL, [
            Metric::COMMAND_DURATION => new MetricValue(1, 10.0, 10.0),
            Metric::MESSAGE_DURATION => new MetricValue(1, 10.0, 10.0),
            Metric::MESSAGE_SENT => new MetricValue(1, 0.0, 0.0),
        ]);
        $reader = new FixedReader(new Report([$hostile], keyCount: 1));

        foreach ([new CommandsSection(true, new ApplicationChecks([])), new MessagesSection(true, true, null, new ApplicationChecks([]))] as $section) {
            $view = $section->build($reader, Period::OneHour, 1800000000, (string) array_key_first($section->sorts()), 'desc', new RaisedChecks([], [], Period::OneHour));
            $page = $this->sectionPage($section, $view, (string) array_key_first($section->sorts()), 'desc');

            foreach ([
                $this->render('_screen.html.twig', ['page' => $page, 'path' => 'background', 'badges' => []]),
                $this->render('page.html.twig', ['page' => $page, 'spaces' => [], 'periods' => Period::cases()]),
            ] as $html) {
                static::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, $section->id());
                static::assertStringNotContainsString(self::HOSTILE_SQL, $html, $section->id());
                static::assertStringNotContainsString('"><i>', $html, $section->id());
            }
        }
    }

    public function testTheTasksSectionEscapesTaskLabels(): void
    {
        $label = 'app:work --note="<script>alert(1)</script>"';
        $declared = new DeclaredTasks(FixedSchedules::locator([
            'default' => static fn(): Schedule => (new Schedule())->add(RecurringMessage::every('1 hour', new RunCommandMessage($label), '@1799999000')),
        ]));
        $stored = new ReportRow('"><i>', (new TaskKey('"><i>', 'gone', $label, '"><i>'))->toString(), null, [
            Metric::TASK_DURATION => new MetricValue(1, 10.0, 10.0),
            Metric::TASK_LAST_RUN => new MetricValue(1, 0.0, 1799999990.0),
        ]);
        $section = new TasksSection(true, $declared, new ApplicationChecks([]));
        $view = $section->build(new FixedReader(new Report([$stored], keyCount: 1)), Period::OneHour, 1800000000, 'next', 'asc', new RaisedChecks([], [], Period::OneHour));
        $page = $this->sectionPage($section, $view, 'next', 'asc');

        static::assertCount(2, $view->tables[0]->rows);
        foreach ([
            $this->render('_screen.html.twig', ['page' => $page, 'path' => 'background', 'badges' => []]),
            $this->render('page.html.twig', ['page' => $page, 'spaces' => [], 'periods' => Period::cases()]),
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

        $html = $this->render('page.html.twig', ['page' => $page, 'spaces' => [], 'periods' => Period::cases()]);

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

    public function testAReadingStateCanNameWhatItReads(): void
    {
        $page = new Page(new StubBlockScreen(), [Block::unavailable(Block::FACTS, BlockState::Reading, '<b>Counting', '<b>Text')], null, '', '', []);

        $html = $this->render('_screen.html.twig', ['page' => $page, 'path' => 'probe', 'badges' => []]);

        static::assertStringContainsString('<div class="jm-state jm-state--info" data-jm-reading>', $html);
        static::assertStringContainsString('<h2 class="jm-state__title">&lt;b&gt;Counting</h2>', $html);
        static::assertStringContainsString('<p class="jm-state__text">&lt;b&gt;Text</p>', $html);
        static::assertStringNotContainsString('<b>', $html);
        static::assertStringNotContainsString('Reading…', $html);
    }

    public function testTheLinkToTheRestKeepsThePeriodAndTheSort(): void
    {
        $screen = new StubScreen('messages');
        $page = new Page($screen, [Block::unavailable(Block::FACTS, BlockState::Reading)], Period::SixHours, 'count', 'asc', $screen->sorts());

        $html = $this->render('_screen.html.twig', ['page' => $page, 'path' => 'messages', 'badges' => []]);

        static::assertStringContainsString('<p class="jm-rest"><a data-jm-rest href="/host/page/messages?menu=3&amp;period=6h&amp;sort=count&amp;dir=asc&amp;rest=1">Load the rest</a></p>', $html);
    }

    public function testTheLinkToTheRestOfAScreenWithoutPeriodOnlyAsksForTheRest(): void
    {
        $page = new Page(new StubBlockScreen('stack'), [Block::unavailable(Block::FACTS, BlockState::Reading)], null, '', '', []);

        $html = $this->render('_screen.html.twig', ['page' => $page, 'path' => 'stack', 'badges' => []]);

        static::assertStringContainsString('<p class="jm-rest"><a data-jm-rest href="/host/page/stack?menu=3&amp;rest=1">Load the rest</a></p>', $html);
    }

    public function testTheNoteOfAFactListIsEscaped(): void
    {
        $html = $this->screen(Block::facts(new Facts('Queues', [new Fact('async', '3')], self::HOSTILE)));

        static::assertStringContainsString('<h2 class="jm-panel__title">Queues</h2>', $html);
        static::assertStringContainsString('<p class="jm-panel__note">&lt;b&gt;&amp;&quot;x</p>', $html);
        static::assertStringNotContainsString('<b>', $html);
        static::assertStringNotContainsString('jm-panel__note', $this->screen(Block::facts(new Facts('Runtime', [new Fact('SAPI', 'cli')]))));
    }

    public function testABadgeIsEscaped(): void
    {
        $page = new Page(new StubBlockScreen(), [], null, '', '', []);
        $badges = ['stack' => new NavBadge('"><script>', 'warn', '"><script>')];
        $context = ['page' => $page, 'path' => 'probe', 'badges' => $badges, 'spaces' => [['id' => 'stack', 'label' => 'Stack', 'path' => 'stack', 'current' => false]], 'periods' => Period::cases()];

        foreach (['page.html.twig', '_screen.html.twig'] as $template) {
            $html = $this->render($template, $context);

            static::assertStringContainsString('<span class="jm-nav__badge jm-nav__badge--warn" title="&quot;&gt;&lt;script&gt;">&quot;&gt;&lt;script&gt;<span class="jm-visually-hidden"> — &quot;&gt;&lt;script&gt;</span></span>', $html, $template);
            static::assertStringNotContainsString('<script>', $html, $template);
        }
    }

    public function testTheToolbarOfAScreenWithoutPeriodHoldsNoParagraph(): void
    {
        $page = new Page(new StubBlockScreen('stack'), [], null, '', '', []);
        $context = ['page' => $page, 'path' => 'stack', 'spaces' => [['id' => 'stack', 'label' => 'Stack', 'path' => 'stack', 'current' => true]], 'periods' => Period::cases(), 'actions' => ['refresh' => 'Refresh']];
        $toolbar = (new Crawler($this->render('page.html.twig', $context)))->filter('.jm-toolbar');

        static::assertSame('Read right now, no period', $toolbar->filter('.jm-toolbar__note')->text());
        static::assertCount(1, $toolbar->filter('form button'));
        static::assertCount(0, $toolbar->filter('p'));
    }

    public function testTheLiveIndicatorIsAHiddenButtonWithAStateAndAnAge(): void
    {
        $page = new Page(new StubBlockScreen(), [], Period::OneHour, '', '', []);
        $context = ['page' => $page, 'path' => 'probe', 'spaces' => [['id' => 'application', 'label' => 'Application', 'path' => 'probe', 'current' => true]], 'periods' => Period::cases()];

        foreach ([false, true] as $standalone) {
            $crawler = new Crawler($this->render('page.html.twig', $context + ['standalone' => $standalone]));
            $live = $crawler->filter('[data-jm-live-toggle]');

            static::assertCount(1, $live);
            static::assertSame('button', $live->nodeName());
            static::assertSame('button', $live->attr('type'));
            static::assertSame('jm-live', $live->attr('class'));
            static::assertNotNull($live->attr('hidden'));
            static::assertNull($live->attr('aria-pressed'));
            static::assertSame('live', $live->attr('data-jm-live-state'));
            static::assertSame('Live', $live->filter('[data-jm-live-word]')->text());
            static::assertNull($live->filter('[data-jm-live-word]')->attr('aria-hidden'));
            static::assertSame('updated just now', $live->filter('[data-jm-live-age][aria-hidden="true"]')->text());
            static::assertCount(1, $live->filter('[data-jm-live-progress][aria-hidden="true"]'));
            static::assertCount(1, $live->filter('.jm-live__mark[aria-hidden="true"]'));
            $name = '';
            foreach ($live->getNode(0)?->childNodes ?? [] as $child) {
                $name .= $child instanceof \DOMElement && $child->getAttribute('aria-hidden') === 'true' ? ' ' : $child->textContent;
            }
            static::assertSame('Live', trim((string) preg_replace('/\s+/', ' ', $name)));
            static::assertCount(0, $live->filter('input, [aria-live], [role]'));
            static::assertNull($live->attr('aria-live'));
            static::assertNull($live->attr('role'));
        }
    }

    public function testATabLabelAndItsBadgeAreEscaped(): void
    {
        $h = self::HOSTILE;
        $tabs = new SubNav($h, [new SubNavItem($h, 'probe', true, null, ['period' => $h], new NavBadge($h, 'warn', $h)), new SubNavItem('Other', 'other')]);
        $page = (new Page(new StubBlockScreen(), [], null, '', '', []))->withTabs($tabs);

        $html = $this->render('_screen.html.twig', ['page' => $page, 'path' => 'probe', 'badges' => []]);
        $tab = (new Crawler($html))->filter('.jm-subnav__item')->eq(0);

        static::assertSame($h, (new Crawler($html))->filter('nav.jm-subnav')->attr('aria-label'));
        static::assertSame($h, $tab->filter('span')->eq(0)->text());
        static::assertSame('/host/page/probe?menu=3&period=%3Cb%3E%26%22x', $tab->attr('href'));
        static::assertStringContainsString('<span class="jm-nav__badge jm-nav__badge--warn" title="&lt;b&gt;&amp;&quot;x">&lt;b&gt;&amp;&quot;x<span class="jm-visually-hidden"> — &lt;b&gt;&amp;&quot;x</span></span>', $html);
        static::assertStringNotContainsString('<b>', $html);
        static::assertStringNotContainsString('&"x', $html);
    }

    /**
     * @return iterable<string, array{Block}>
     */
    public static function hostileStackBlocks(): iterable
    {
        $h = self::HOSTILE;
        $mark = new Mark('warn', $h);

        yield 'subnav' => [Block::subnav(new SubNav($h, [new SubNavItem($h, $h, true, $mark), new SubNavItem($h, $h)], $h))];
        yield 'notice' => [Block::notice(new Notice('bad', $h, $h))];
        yield 'checks' => [Block::checks(new CheckList([new CheckLine($h, $mark, $h, $h, $h, $h, $h), new CheckLine($h, $mark, $h, $h, $h)]))];
        yield 'empty checks' => [Block::checks(new CheckList([], $h, $h))];
        yield 'components' => [Block::components(new ComponentCards([
            new ComponentCard($h, $h, $mark, [new Vital($h, $h, $h)], $h, $h, false, null, [$mark], $h, 1800000000),
            new ComponentCard($h, $h, $mark, [new Vital($h, $h, $h)], reading: true),
            new ComponentCard($h, $h, $mark, reason: $h),
        ], [$h, $h], $h, $h))];
        yield 'gauges' => [Block::gauges(new Gauges($h, [new Gauge($h, 42.0, $h, $mark, $h), new Gauge($h, null, $h, hint: $h)]))];
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
                Block::subnav(new SubNav('Stack', [new SubNavItem('PHP', 'stack/php', false, $mark)])),
                Block::checks(new CheckList([new CheckLine('php.x', $mark, 'Sentence', 'Explanation', 'PHP')])),
                Block::components(new ComponentCards([new ComponentCard('php', 'PHP', $mark)])),
                Block::components(new ComponentCards([new ComponentCard('php', 'PHP', new Mark('ok', 'Healthy'), badges: [$mark])])),
                Block::facts(new Facts('Runtime', [new Fact('Version', '8.4', $mark)])),
                Block::gauges(new Gauges('Memory', [new Gauge('Used', 50.0, '50 %', $mark)])),
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
            new Gauge('Used', 142.5, '142 %', new Mark('bad', 'Critical')),
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

    public function testAGaugeShowsTheWordOfItsMark(): void
    {
        $gauges = (new Crawler($this->screen(Block::gauges(new Gauges('Memory', [
            new Gauge('OPcache memory', 92.0, '92 %', new Mark('bad', 'Critical')),
            new Gauge('APCu', 12.0, '12 %', new Mark('info', 'Info')),
            new Gauge('Swap', 5.0, '5 %'),
        ])))))->filter('.jm-gauge');

        static::assertSame('jm-gauge jm-gauge--bad', $gauges->eq(0)->attr('class'));
        static::assertSame('Critical', $gauges->eq(0)->filter('.jm-gauge__head .jm-mark.jm-mark--bad')->text());
        static::assertSame('jm-gauge', $gauges->eq(1)->attr('class'));
        static::assertSame('Info', $gauges->eq(1)->filter('.jm-mark--info')->text());
        static::assertSame('jm-gauge', $gauges->eq(2)->attr('class'));
        static::assertCount(0, $gauges->eq(2)->filter('.jm-mark'));
    }

    public function testNeighbouringWordsAreSeparatedInTheText(): void
    {
        $warning = new Mark('warn', 'Warning');
        $crawler = new Crawler($this->screen(
            Block::subnav(new SubNav('Stack', [new SubNavItem('PHP', 'stack/php', false, $warning)])),
            Block::notice(new Notice('bad', 'The stack could not be read')),
            Block::checks(new CheckList([new CheckLine('php.x', $warning, 'Sentence', 'Explanation', 'PHP', 'stack/php', '12 s ago')])),
            Block::components(new ComponentCards([new ComponentCard('php', 'PHP', $warning, version: '8.2.29', badges: [new Mark('warn', 'Security fixes only')])])),
            Block::facts(new Facts('Runtime', [new Fact('Version', '8.2.29', new Mark('warn', 'Security fixes only'))])),
            Block::gauges(new Gauges('Memory', [new Gauge('Used', 50.0, '50 %', $warning)])),
        ));

        static::assertSame('PHP Warning', $crawler->filter('.jm-subnav__item')->text());
        static::assertSame('Error The stack could not be read', $crawler->filter('.jm-notice__title')->text());
        static::assertSame('PHP 12 s ago', $crawler->filter('.jm-check__where')->text());
        static::assertSame('PHP 8.2.29 Warning Security fixes only', $crawler->filter('[data-jm-component="php"] .jm-component__head')->text());
        static::assertSame('8.2.29 Security fixes only', $crawler->filter('.jm-fact__value')->text());
        static::assertSame('Used Warning 50 %', $crawler->filter('.jm-gauge__head')->text());
    }

    public function testEveryDashboardTemplateEscapesExplicitlyAndOnlyTheLayoutPrintsRawHtml(): void
    {
        $root = __DIR__ . '/../../templates/dashboard/';
        $raw = [];
        $templates = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!str_ends_with($file->getFilename(), '.html.twig')) {
                continue;
            }

            ++$templates;
            $name = substr($file->getPathname(), \strlen($root));
            $source = (string) file_get_contents($file->getPathname());

            static::assertStringStartsWith("{% autoescape 'html' %}", $source, $name);
            if (preg_match_all('/\|\s*raw\b/', $source, $matches) > 0) {
                $raw[$name] = \count($matches[0]);
            }
        }

        static::assertGreaterThan(10, $templates);
        static::assertSame(['layout.html.twig' => 1], $raw);
    }

    public function testTheNodeIsNamedOnceAndItsChangeNoticeStartsHidden(): void
    {
        $html = $this->screen(Block::subnav(new SubNav('Stack', [new SubNavItem('Overview', 'stack', true)], 'web-1')));
        $without = $this->screen(Block::subnav(new SubNav('Stack', [new SubNavItem('Overview', 'stack', true)])));

        static::assertStringContainsString('<nav class="jm-subnav" aria-label="Stack">', $html);
        static::assertStringContainsString('<p class="jm-node" data-jm-node="web-1">Answered by <strong>web-1</strong><span data-jm-node-changed hidden> — another web server answered this refresh</span></p>', $html);
        static::assertSame(1, substr_count($html, 'data-jm-node='));
        static::assertSame(1, substr_count($html, 'web-1</strong>'));
        static::assertStringNotContainsString('data-jm-node', $without);
    }

    public function testLinksOfABlockGoThroughTheHostUrls(): void
    {
        $crawler = new Crawler($this->screen(
            Block::subnav(new SubNav('Stack', [new SubNavItem('Overview', 'stack', true), new SubNavItem('PHP', 'stack/php')])),
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

    public function testTheInstancesBlockEscapesItsLabels(): void
    {
        $html = $this->screen(Block::instances(new InstanceNav([
            new InstanceNavRow('Servers', [new InstanceNavItem('<b>x</b>', 'stack/mysql', ['connection' => '<b>x</b>'], true, '"><script>', new Mark('warn', '<b>x</b>'))]),
            new InstanceNavRow('Databases', [new InstanceNavItem('main', 'stack/mysql', ['connection' => 'main'], false, '"><script>')]),
        ])));

        static::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        static::assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
        static::assertStringNotContainsString('<b>', $html);
        static::assertStringNotContainsString('<script>', $html);
        static::assertStringNotContainsString('"><script', $html);

        $crawler = new Crawler($html);
        static::assertSame(['Servers', 'Databases'], $crawler->filter('[data-jm-instances] nav')->each(static fn(Crawler $row): string => (string) $row->attr('aria-label')));
        static::assertSame('/host/page/stack/mysql?menu=3&connection=%3Cb%3Ex%3C%2Fb%3E', $crawler->filter('[data-jm-instances] a')->eq(0)->attr('href'));
        static::assertSame('true', $crawler->filter('[data-jm-instances] a')->eq(0)->attr('aria-current'));
        static::assertNull($crawler->filter('[data-jm-instances] a')->eq(1)->attr('aria-current'));
        static::assertSame('<b>x</b>', $crawler->filter('[data-jm-instances] a .jm-mark')->text());
    }

    public function testAConnectionNameWithADotASlashOrASpaceMakesAUsableLink(): void
    {
        $names = ['a.b', 'a/b', 'é è'];
        $item = static fn(string $name): InstanceNavItem => new InstanceNavItem($name, 'stack/mysql', ['connection' => $name]);
        $crawler = new Crawler($this->screen(
            Block::instances(new InstanceNav([new InstanceNavRow('Databases', array_map($item, $names))])),
            Block::components(new ComponentCards(array_map(static fn(string $name): ComponentCard => new ComponentCard('mysql.' . $name, 'MySQL · ' . $name, new Mark('ok', 'Healthy'), path: 'stack/mysql', query: ['connection' => $name]), $names))),
            Block::checks(new CheckList(array_map(static fn(string $name): CheckLine => new CheckLine('mysql.connections', new Mark('warn', 'Warning'), 'Sentence', '', 'MySQL · ' . $name, 'stack/mysql', query: ['connection' => $name]), $names))),
        ));

        $links = $crawler->filter('a[href]')->each(static fn(Crawler $a): string => (string) $a->attr('href'));

        static::assertCount(9, $links);
        foreach ($links as $i => $link) {
            $url = parse_url($link);
            static::assertSame('/host/page/stack/mysql', $url['path'] ?? null);
            parse_str($url['query'] ?? '', $query);
            static::assertSame($names[$i % 3], $query['connection'] ?? null, $link);
        }
    }

    public function testASectionIsNamedByItsTitleAndItsTableHasNoTitleOfItsOwn(): void
    {
        $table = $this->table([new Row('k1', ['name' => new Cell('a'), 'count' => new Cell('3')])]);
        $section = new Section('commands', 'Commands', 'Runs of bin/console, by cron, a deploy or by hand.', [Block::cards([new Card('Runs', '3')]), Block::table($table)], 'count', 'desc', ['count' => 'desc', 'name' => 'asc']);

        $html = $this->composed(Block::section($section));
        $crawler = new Crawler($html);
        $element = $crawler->filter('.jm-screen > section.jm-section');

        static::assertCount(1, $element);
        static::assertSame('jm-commands', $element->attr('id'));
        static::assertSame('jm-section-commands-title', $element->attr('aria-labelledby'));
        static::assertSame('Commands', $element->filter('h2#jm-section-commands-title')->text());
        static::assertSame('Runs of bin/console, by cron, a deploy or by hand.', $element->filter('.jm-section__summary')->text());
        static::assertSame('3', $element->filter('.jm-cards .jm-card__value')->text());
        static::assertCount(0, $element->filter('.jm-panel h2'));
        static::assertSame('div', $element->filter('.jm-panel')->nodeName());
        static::assertNull($element->filter('.jm-panel')->attr('aria-labelledby'));
        static::assertSame('jm-section-commands-title', $element->filter('.jm-table-wrap')->attr('aria-labelledby'));
        static::assertSame('1 row', $element->filter('.jm-panel__meta')->text());
        static::assertStringNotContainsString('Stubs', $html);
        static::assertLessThan((int) strpos($html, 'jm-cards'), (int) strpos($html, 'jm-section__title'));
        static::assertLessThan((int) strpos($html, 'jm-panel'), (int) strpos($html, 'jm-cards'));

        $alone = new Crawler($this->render('block/table.html.twig', ['page' => $this->page(new ScreenView([], [$table])), 'block' => Block::table($table)]));

        static::assertSame('Stubs', $alone->filter('.jm-panel h2#jm-stubs-title')->text());
        static::assertSame('jm-stubs-title', $alone->filter('.jm-panel')->attr('aria-labelledby'));
    }

    public function testAnEmptySectionShowsNoTable(): void
    {
        $own = Block::notice(new Notice('info', 'No transport to count'));
        $empty = ['title' => 'No scheduled task is declared.', 'text' => 'Tasks show up here once a schedule declares them.'];

        $crawler = new Crawler($this->composed(Block::section(new Section('tasks', 'Scheduled tasks', 'What Symfony Scheduler starts by the clock.', [$own], 'count', 'desc', ['count' => 'desc'], $empty))));
        $element = $crawler->filter('section#jm-tasks');

        foreach (['table', '.jm-panel', '.jm-cards', '.jm-state'] as $selector) {
            static::assertCount(0, $element->filter($selector), $selector);
        }
        static::assertCount(1, $element->filter('.jm-notice'));
        static::assertCount(1, $element->filter('p.jm-section__empty'));
        static::assertSame('No scheduled task is declared. Tasks show up here once a schedule declares them.', $element->filter('p.jm-section__empty')->text());
        static::assertSame('No scheduled task is declared.', $element->filter('p.jm-section__empty > strong')->text());
        static::assertLessThan((int) strpos($element->html(), 'jm-section__empty'), (int) strpos($element->html(), 'jm-notice'));

        $html = $this->composed(Block::section(new Section('tasks', 'Scheduled tasks', '', [], empty: ['title' => 'No scheduled task is declared.', 'text' => ''])));

        static::assertStringContainsString('<p class="jm-section__empty"><strong>No scheduled task is declared.</strong></p>', $html);
        static::assertStringNotContainsString('jm-section__empty', $this->composed(Block::section(new Section('tasks', 'Scheduled tasks', '', [Block::cards([])]))));
    }

    public function testTheTextsOfASectionAreEscaped(): void
    {
        $table = $this->table([new Row('k1', ['name' => new Cell(self::HOSTILE)])]);

        foreach ([
            $this->composed(Block::section(new Section('commands', self::HOSTILE, self::HOSTILE, [Block::table($table)], 'count', 'desc', ['count' => 'desc']))),
            $this->composed(Block::section(new Section('tasks', self::HOSTILE, self::HOSTILE, [], empty: ['title' => self::HOSTILE, 'text' => self::HOSTILE_SQL]))),
        ] as $html) {
            static::assertStringContainsString('&lt;b&gt;&amp;&quot;x', $html);
            static::assertStringNotContainsString('<b>', $html);
            static::assertStringNotContainsString('<script>', $html);
        }
    }

    public function testGlobalsOfTheHostNamedLikeTheVariablesOfABlockChangeNothing(): void
    {
        $table = $this->table([new Row('k1', ['name' => new Cell('a'), 'count' => new Cell('3')])]);
        $stack = Block::subnav(new SubNav('Stack', [new SubNavItem('All components', 'stack'), new SubNavItem('PHP', 'stack/php', true)]));
        $tabs = new SubNav('Application', [new SubNavItem('Stub', 'stub', true), new SubNavItem('Other', 'other')]);
        $screen = new Page(new StubScreen(), [Block::cards([]), Block::table($table), $stack], Period::OneHour, 'name', 'asc', (new StubScreen())->sorts());
        $section = new Section('commands', 'Commands', 'Runs of bin/console.', [Block::table($table)], 'count', 'desc', ['count' => 'desc', 'name' => 'asc']);
        $composed = new Page(new ComposedScreen('background', 'Background', 'Work done in the background', Space::Background, [new StubSection('commands')]), [Block::section($section)], Period::OneHour, '', '', []);

        foreach (['screen' => [$screen->withTabs($tabs), 'stub'], 'composed' => [$composed, 'background']] as $kind => [$page, $path]) {
            $context = ['page' => $page, 'path' => $path, 'badges' => []];
            $expected = $this->render('_screen.html.twig', $context);

            static::assertStringContainsString('aria-sort=', $expected, $kind);
            foreach (['a string', new \ArrayObject(['id' => 'x', 'sort' => 'name', 'items' => []]), new \stdClass()] as $global) {
                static::assertSame($expected, $this->render('_screen.html.twig', $context, ['section' => $global, 'subnav' => $global]), $kind);
            }
        }
    }

    public function testACheckWithoutActionIsRenderedAsBefore(): void
    {
        $list = new CheckList([
            new CheckLine('php.opcache_memory', new Mark('bad', 'Critical'), 'OPcache memory is full', 'Raise opcache.memory_consumption.', 'PHP', 'stack/php', '3 min ago'),
            new CheckLine('mysql.connections', new Mark('warn', 'Warning'), 'Connections <near> the limit', '', 'MySQL · main', 'stack/mysql', null, ['connection' => 'main']),
            new CheckLine('server.load', new Mark('info', 'Info'), 'The load is high', 'Inside a container, the load is the one of the host node.', 'Server'),
        ]);

        static::assertSame(self::LEGACY_CHECKS . "\n", $this->render('block/checks.html.twig', ['block' => Block::checks($list)]));
        static::assertSame(self::LEGACY_EMPTY_CHECKS . "\n", $this->render('block/checks.html.twig', ['block' => Block::checks(new CheckList([], 'Nothing to check on PHP', 'No check was raised.'))]));
    }

    public function testACheckWithAnActionShowsWhyAndWhatToDoSideBySide(): void
    {
        $crawler = new Crawler($this->screen(Block::checks(new CheckList([
            new CheckLine('queries.slow', new Mark('warn', 'Warning'), 'One query ran slower than 100 ms', 'A slow query holds the request.', 'Queries', 'stack', title: 'Slow queries', action: 'Add an index.', code: 'SELECT * FROM orders WHERE customer_id = ?'),
            new CheckLine('requests.server_errors', new Mark('bad', 'Critical'), '3 requests ended in a server error', 'Each one showed an error page.', '', action: 'Open Exceptions.'),
        ], title: 'Worth a look'))));
        $slow = $crawler->filter('li.jm-check[data-jm-check="queries.slow"][data-jm-severity="warn"]');
        $errors = $crawler->filter('li.jm-check[data-jm-check="requests.server_errors"][data-jm-severity="bad"]');

        static::assertSame('jm-panel jm-checks jm-checks--bad', $crawler->filter('section.jm-checks')->attr('class'));
        static::assertSame('Slow queries · One query ran slower than 100 ms', $slow->filter('.jm-check__sentence')->text());
        static::assertSame('Slow queries', $slow->filter('.jm-check__sentence > strong')->text());
        static::assertSame('SELECT * FROM orders WHERE customer_id = ?', $slow->filter('.jm-check__body > pre.jm-check__code > code')->text());
        static::assertSame(['Why it matters A slow query holds the request.', 'What to do Add an index.'], $slow->filter('.jm-check__advice > p')->each(static fn(Crawler $p): string => $p->text()));
        static::assertSame(['Why it matters', 'What to do'], $slow->filter('.jm-check__advice > p > strong:first-child')->each(static fn(Crawler $strong): string => $strong->text()));
        static::assertSame('/host/page/stack?menu=3', $slow->filter('.jm-check__where a')->attr('href'));
        static::assertCount(0, $crawler->filter('details'));

        static::assertSame('3 requests ended in a server error', $errors->filter('.jm-check__sentence')->text());
        static::assertCount(0, $errors->filter('.jm-check__sentence strong, pre, .jm-check__where'));
        static::assertSame(['Why it matters Each one showed an error page.', 'What to do Open Exceptions.'], $errors->filter('.jm-check__advice > p')->each(static fn(Crawler $p): string => $p->text()));

        $warning = new Crawler($this->screen(Block::checks(new CheckList([
            new CheckLine('queries.slow', new Mark('warn', 'Warning'), 'Sentence', 'Why.', 'Queries', action: 'What.'),
            new CheckLine('php.x', new Mark('bad', 'Critical'), 'Sentence', 'Why.', 'PHP'),
        ]))));

        static::assertSame('jm-panel jm-checks jm-checks--bad', $warning->filter('section.jm-checks')->attr('class'));
        static::assertSame('jm-panel jm-checks jm-checks--warn', (new Crawler($this->screen(Block::checks(new CheckList([
            new CheckLine('queries.slow', new Mark('warn', 'Warning'), 'Sentence', 'Why.', 'Queries', action: 'What.'),
        ])))))->filter('section.jm-checks')->attr('class'));
        static::assertSame('jm-panel jm-checks', (new Crawler($this->screen(Block::checks(new CheckList([
            new CheckLine('php.x', new Mark('bad', 'Critical'), 'Sentence', 'Why.', 'PHP'),
        ])))))->filter('section.jm-checks')->attr('class'));
    }

    public function testTheTitleOfTheChecksComesFromTheList(): void
    {
        $line = new CheckLine('php.x', new Mark('warn', 'Warning'), 'Sentence', 'Explanation', 'PHP');
        $titled = new Crawler($this->screen(Block::checks(new CheckList([$line], title: 'Worth a look'))));
        $empty = new Crawler($this->screen(Block::checks(new CheckList([], 'Nothing to flag', '', 'Worth a look'))));
        $default = new Crawler($this->screen(Block::checks(new CheckList([$line]))));

        static::assertSame(['Worth a look', 'Worth a look'], [$titled->filter('section.jm-checks')->attr('aria-label'), $titled->filter('.jm-checks h2')->text()]);
        static::assertSame(['Worth a look', 'Nothing to flag'], [$empty->filter('section.jm-checks')->attr('aria-label'), $empty->filter('.jm-checks h2')->text()]);
        static::assertSame(['To check', 'To check'], [$default->filter('section.jm-checks')->attr('aria-label'), $default->filter('.jm-checks h2')->text()]);
    }

    public function testASplitReadsItsNoteAndTitlesItsShortText(): void
    {
        $cell = (new Crawler($this->cells(new Column('split', 'Time split', false), Cell::split(['php' => 1.0, 'sql' => 1.0, 'http' => 0.0], 'SQL 1 ms · 13 queries', '13 queries'))))->filter('td.jm-col-split');

        static::assertSame('PHP 1 ms, SQL 1 ms, HTTP out 0 ms, 13 queries', $cell->filter('.jm-visually-hidden')->text());
        static::assertSame('SQL 1 ms · 13 queries', $cell->filter('.jm-split__text[aria-hidden="true"]')->attr('title'));
    }

    public function testATitledRouteRepeatsItsName(): void
    {
        $crawler = new Crawler($this->cells(new Column('route', 'Route', false), Cell::route('GET app_report_export', true), Cell::route('GET app_home')));

        static::assertSame(['app_report_export', null], $crawler->filter('td.jm-col-route code.jm-route')->each(static fn(Crawler $code): ?string => $code->attr('title')));
    }

    public function testASplitCellSaysItsThreePartsInWords(): void
    {
        $cell = (new Crawler($this->cells(new Column('split', 'Time split', false), Cell::split(['php' => 77.0, 'sql' => 10.0, 'http' => 57.0], '77 · 10 · 57'))))->filter('td.jm-col-split');

        static::assertSame('PHP 77 ms, SQL 10 ms, HTTP out 57 ms', $cell->filter('.jm-visually-hidden')->text());
        static::assertSame('77 · 10 · 57', $cell->filter('.jm-split__text[aria-hidden="true"]')->text());
        static::assertSame('true', $cell->filter('svg')->attr('aria-hidden'));
        static::assertSame(['jm-split__part jm-split__part--php', 'jm-split__part jm-split__part--sql', 'jm-split__part jm-split__part--http'], $cell->filter('svg rect')->each(static fn(Crawler $rect): string => (string) $rect->attr('class')));
        static::assertSame(['0', '51.19', '59.58'], $cell->filter('svg rect')->each(static fn(Crawler $rect): string => (string) $rect->attr('x')));
        static::assertSame(['49.19', '6.39', '36.42'], $cell->filter('svg rect')->each(static fn(Crawler $rect): string => (string) $rect->attr('width')));

        $odd = (new Crawler($this->cells(new Column('split', 'Time split', false), Cell::split(['php' => -3.0, 'sql' => \NAN, 'http' => \INF], 'odd'))))->filter('td.jm-col-split');

        static::assertSame('PHP 0 ms, SQL 0 ms, HTTP out 0 ms', $odd->filter('.jm-visually-hidden')->text());
        static::assertCount(0, $odd->filter('svg'));
        static::assertSame('odd', $odd->filter('.jm-split__text')->text());
    }

    public function testASplitWithoutSqlNorHttpDrawsOnePart(): void
    {
        $cell = (new Crawler($this->cells(new Column('split', 'Time split', false), Cell::split(['php' => 120.0, 'sql' => 0.0, 'http' => 0.0], 'PHP only'))))->filter('td.jm-col-split');
        $rect = $cell->filter('svg rect');

        static::assertCount(1, $rect);
        static::assertSame(['jm-split__part jm-split__part--php', '0', '96'], [$rect->attr('class'), $rect->attr('x'), $rect->attr('width')]);
        static::assertSame('PHP only', $cell->filter('.jm-split__text')->text());
        static::assertSame('PHP 120 ms, SQL 0 ms, HTTP out 0 ms', $cell->filter('.jm-visually-hidden')->text());
        static::assertSame('PHP 120 ms, SQL <1 ms, HTTP out 0 ms', (new Crawler($this->cells(new Column('split', 'Time split', false), Cell::split(['php' => 120.0, 'sql' => 0.4, 'http' => 0.0], 'PHP only'))))->filter('td.jm-col-split .jm-visually-hidden')->text());
    }

    public function testABarIsBoundedAndHiddenFromAssistiveTechnology(): void
    {
        foreach ([[0.424, '42'], [1.7, '100'], [-0.2, '0'], [\NAN, '0'], [\INF, '0'], [0.0, '0'], [1.0, '100']] as [$share, $width]) {
            $cell = (new Crawler($this->cells(new Column('total', 'Total time'), Cell::bar('1.2 s', $share))))->filter('td.jm-num');
            $svg = $cell->filter('.jm-bar > svg');

            static::assertSame('true', $svg->attr('aria-hidden'), (string) $share);
            static::assertSame('100', $svg->filter('rect.jm-bar__rail')->attr('width'), (string) $share);
            static::assertSame($width, $svg->filter('rect.jm-bar__fill')->attr('width'), (string) $share);
            static::assertSame('1.2 s', $cell->filter('.jm-bar__value')->text(), (string) $share);
            static::assertSame('1.2 s', trim($cell->text()), (string) $share);
        }
    }

    public function testTagsCarryTheirTextAndTone(): void
    {
        $crawler = new Crawler($this->cells(new Column('problems', 'Problems', false), Cell::tags([new Tag('N+1', 'warn'), new Tag('5xx', 'bad')]), Cell::tags([])));
        $cells = $crawler->filter('td.jm-col-problems');

        static::assertSame([['jm-tag jm-tag--warn', 'N+1'], ['jm-tag jm-tag--bad', '5xx']], $cells->eq(0)->filter('span')->each(static fn(Crawler $tag): array => [(string) $tag->attr('class'), $tag->text()]));
        static::assertSame('N+1 5xx', $cells->eq(0)->text());
        static::assertSame('', $cells->eq(1)->html());
    }

    public function testTheLegendNamesTheThreeParts(): void
    {
        $columns = [new Column('name', 'Name', false), new Column('split', 'Time split', false)];
        $rows = [new Row('k1', ['name' => new Cell('a'), 'split' => Cell::split(['php' => 1.0, 'sql' => 1.0, 'http' => 1.0], '1 · 1 · 1')])];
        $with = new Table('routes', 'Routes', $columns, $rows, 3, 'route', partsLegend: true, footnote: 'Averages per request.');
        $without = new Table('routes', 'Routes', $columns, $rows);

        $crawler = new Crawler($this->render('block/table.html.twig', ['page' => $this->page(new ScreenView([], [$with])), 'block' => Block::table($with)]));
        $legend = $crawler->filter('.jm-panel__header > .jm-legend');

        static::assertSame(['your PHP code', 'SQL', 'HTTP out'], $legend->filter('.jm-legend__item')->each(static fn(Crawler $item): string => $item->text()));
        static::assertSame(['jm-legend__swatch jm-legend__swatch--php', 'jm-legend__swatch jm-legend__swatch--sql', 'jm-legend__swatch jm-legend__swatch--http'], $legend->filter('.jm-legend__item > span[aria-hidden="true"]')->each(static fn(Crawler $swatch): string => (string) $swatch->attr('class')));
        static::assertSame('4 routes', $crawler->filter('.jm-panel__header > .jm-panel__meta')->text());
        static::assertSame(['3 more routes not shown', 'Averages per request.'], $crawler->filter('.jm-panel > p')->each(static fn(Crawler $p): string => $p->text()));
        static::assertSame('jm-panel__footnote', $crawler->filter('.jm-panel > p')->eq(1)->attr('class'));

        $plain = new Crawler($this->render('block/table.html.twig', ['page' => $this->page(new ScreenView([], [$without])), 'block' => Block::table($without)]));

        static::assertCount(0, $plain->filter('.jm-legend, .jm-panel__footnote'));
    }

    public function testTheTextsOfTheNewBlocksAreEscaped(): void
    {
        $h = self::HOSTILE;
        $table = new Table('routes', $h, [new Column('name', $h, false), new Column('total', $h), new Column('split', $h, false), new Column('problems', $h, false)], [
            new Row('k1', ['name' => new Cell($h), 'total' => Cell::bar($h, 0.5), 'split' => Cell::split(['php' => 1.0, 'sql' => 2.0, 'http' => 3.0], $h), 'problems' => Cell::tags([new Tag($h, 'warn')])]),
        ], partsLegend: true, footnote: $h);
        $page = new Page(new StubScreen('stub'), [
            Block::lead(new Lead($h, $h, [1, 2, 3], $h, $h)),
            Block::checks(new CheckList([new CheckLine($h, new Mark('bad', $h), $h, $h, $h, 'stub', $h, [], $h, $h, $h)], $h, $h, $h)),
            Block::table($table),
            Block::guide(new Guide([new GuideItem($h, $h, [$h => 'stub'])])),
            Block::lead(new Lead($h)),
        ], Period::OneHour, 'name', 'asc', (new StubScreen())->sorts());

        $html = $this->render('_screen.html.twig', ['page' => $page, 'path' => 'stub', 'badges' => [], 'screens' => ['stub']]);

        static::assertStringContainsString('&lt;b&gt;&amp;&quot;x', $html);
        static::assertStringNotContainsString('<b>', $html);
        static::assertStringNotContainsString('&"x', $html);
        $crawler = new Crawler($html);
        static::assertSame([1, 2, 1, 1], [$crawler->filter('h1')->count(), $crawler->filter('.jm-lead')->count(), $crawler->filter('.jm-guide')->count(), $crawler->filter('.jm-legend')->count()]);
        static::assertCount(1, $crawler->filter('.jm-lead')->eq(0)->filter('h1 + p.jm-lead__detail'));
        static::assertSame(['h2'], $crawler->filter('.jm-lead')->eq(1)->children()->each(static fn(Crawler $child): string => $child->nodeName()));
        static::assertSame('How to read this screen', $crawler->filter('section.jm-guide')->attr('aria-label'));
        static::assertSame('How to read this screen', $crawler->filter('section.jm-guide > h2')->text());
    }

    public function testTheLinksOfAGuideItemAreSeparatedByADot(): void
    {
        $guide = Block::guide(new Guide([
            new GuideItem('Where', 'the time goes.', ['Queries' => 'queries', 'Gone' => 'gone', 'Cache & HTTP' => 'cache-http', 'Stack' => 'stack/php']),
            new GuideItem('Alone', 'one link.', ['Gone' => 'gone', 'Queries' => 'queries']),
            new GuideItem('None', 'no link.', ['Gone' => 'gone']),
        ]));

        $items = (new Crawler($this->render('_screen.html.twig', ['page' => new Page(new StubBlockScreen('stack'), [$guide], null, '', '', []), 'path' => 'stack', 'badges' => [], 'screens' => ['stack', 'queries', 'cache-http']])))->filter('.jm-guide li');

        static::assertSame('Where the time goes. Queries · Cache & HTTP · Stack', $items->eq(0)->text());
        static::assertSame(['Queries', 'Cache & HTTP', 'Stack'], $items->eq(0)->filter('a')->each(static fn(Crawler $a): string => $a->text()));
        static::assertSame([' · ', ' · '], $items->eq(0)->filter('span.jm-guide__separator[aria-hidden="true"]')->each(static fn(Crawler $span): string => $span->text(null, false)));
        static::assertSame('Alone one link. Queries', $items->eq(1)->text());
        static::assertCount(0, $items->eq(1)->filter('.jm-guide__separator'));
        static::assertSame('None no link.', $items->eq(2)->text());
        static::assertCount(0, $items->eq(2)->filter('a, .jm-guide__separator'));
    }

    public function testALeadInASectionIsASecondLevelHeading(): void
    {
        $lead = Block::lead(new Lead('Sentence of the section', 'Detail.', [1, 3, 2], 'runs per minute', '1 hour ago'));
        $section = new Section('commands', 'Commands', 'Runs of bin/console.', [$lead, Block::cards([new Card('Runs', '3')])]);
        $crawler = new Crawler($this->composed(Block::section($section)));

        static::assertSame(['Background'], $crawler->filter('h1')->each(static fn(Crawler $h1): string => $h1->text()));
        static::assertSame('Background', $crawler->filter('.jm-screen > .jm-heading > h1')->text());
        static::assertSame('Sentence of the section', $crawler->filter('section#jm-commands > section.jm-lead > h2.jm-lead__title')->text());
    }

    public function testAHostGlobalDoesNotStandInForABlockVariable(): void
    {
        $table = new Table('routes', 'Routes', [new Column('name', 'Name', false, 'name'), new Column('split', 'Time split', false), new Column('total', 'Total', sort: 'total')], [
            new Row('k1', ['name' => new Cell('a'), 'split' => Cell::split(['php' => 3.0, 'sql' => 1.0, 'http' => 0.0], '3 · 1'), 'total' => Cell::bar('4 ms', 0.4)]),
        ], partsLegend: true, footnote: 'Averages per request.');
        $checks = new CheckList([
            new CheckLine('queries.repeated', new Mark('warn', 'Warning'), 'One query ran 12 times', 'Why.', 'Queries', 'queries', null, ['period' => '1h'], 'Repeated queries', 'What.', 'SELECT 1'),
            new CheckLine('php.x', new Mark('bad', 'Critical'), 'Sentence', 'Explanation', 'PHP', 'stack/php'),
        ], title: 'Worth a look');
        $guide = new Guide([new GuideItem('Total', 'is the time.', ['Queries' => 'queries', 'Stack' => 'stack'])]);
        $blocks = [Block::lead(new Lead('Sentence', 'Detail', [1, 4, 2], 'requests per minute', '1 hour ago')), Block::checks($checks), Block::cards([new Card('Hits', '3')]), Block::table($table), Block::guide($guide)];
        $tabs = new SubNav('Application', [new SubNavItem('Stub', 'stub', true), new SubNavItem('Other', 'other')]);
        $screen = (new Page(new StubScreen(), $blocks, Period::OneHour, 'name', 'asc', (new StubScreen())->sorts()))->withTabs($tabs);
        $section = new Section('commands', 'Commands', 'Runs of bin/console.', $blocks, 'total', 'desc', ['total' => 'desc', 'name' => 'asc']);
        $composed = new Page(new ComposedScreen('background', 'Background', 'Work done in the background', Space::Background, [new StubSection('commands')]), [Block::section($section)], Period::OneHour, '', '', []);

        foreach (['screen' => [$screen, 'stub'], 'composed' => [$composed, 'background']] as $kind => [$page, $path]) {
            $context = ['page' => $page, 'path' => $path, 'badges' => [], 'screens' => ['stub', 'stack']];
            $expected = $this->render('_screen.html.twig', $context);

            static::assertStringContainsString('jm-guide', $expected, $kind);
            static::assertStringContainsString('/host/page/stack/php?menu=3', $expected, $kind);
            static::assertStringNotContainsString('/host/page/queries', $expected, $kind);
            foreach (['a string', ['stub', 'stack', 'queries'], new \ArrayObject(['id' => 'x', 'sort' => 'name', 'items' => [], 'lines' => []]), new \stdClass(), true] as $global) {
                static::assertSame($expected, $this->render('_screen.html.twig', $context, array_fill_keys(['screens', 'lead', 'guide', 'checks', 'legend', 'section', 'subnav', 'list', 'table', 'cell', 'page_title'], $global)), $kind);
            }
        }
    }

    public function testAnAnchorCardLinksToItsSection(): void
    {
        $html = $this->render('block/cards.html.twig', ['block' => Block::cards([
            new Card('Messages', '42', 'handled, 45 sent, 3 failed', 'bad', anchor: 'messages'),
            new Card('Runs', '3', 'in the last hour'),
        ])]);
        $cards = (new Crawler($html))->filter('.jm-card');

        static::assertSame('jm-card jm-card--bad', $cards->eq(0)->attr('class'));
        static::assertSame('<h2 class="jm-card__label"><a class="jm-card__link" href="#jm-messages">Messages</a></h2>', $cards->eq(0)->filter('h2')->outerHtml());
        static::assertSame(['42', 'handled, 45 sent, 3 failed'], $cards->eq(0)->filter('p')->each(static fn(Crawler $p): string => $p->text()));
        static::assertStringContainsString("<article class=\"jm-card\">\n            <h2 class=\"jm-card__label\">Runs</h2>\n                            <p class=\"jm-card__value\">3</p>\n", $html);
        static::assertCount(1, (new Crawler($html))->filter('a'));
    }

    public function testANestedRowIsMarkedAndNotCounted(): void
    {
        $table = new Table('exceptions', 'Exceptions', [new Column('name', 'Name', false), new Column('count', 'Count')], [
            new Row('k1', ['name' => new Cell('RuntimeException'), 'count' => new Cell('3')]),
            new Row('k1-a', ['name' => new Cell('GET app_home'), 'count' => new Cell('2')], ['data-jm-place' => 'a'], nested: true),
            new Row('k1-b', ['name' => new Cell('app:import'), 'count' => new Cell('1')], nested: true),
            new Row('k2', ['name' => new Cell('LogicException'), 'count' => new Cell('1')]),
            new Row('other', ['name' => new Cell('Other places'), 'count' => new Cell('4')], muted: true, nested: true),
            new Row('rest', ['name' => new Cell('Other exceptions'), 'count' => new Cell('5')], muted: true),
        ], 1, 'exception');

        $html = $this->render('block/table.html.twig', ['page' => $this->page(new ScreenView([], [$table])), 'block' => Block::table($table)]);
        $rows = (new Crawler($html))->filter('tbody tr');

        static::assertSame('3 exceptions', (new Crawler($html))->filter('.jm-panel__meta')->text());
        static::assertSame([null, 'jm-row--nested', 'jm-row--nested', null, 'jm-row--muted jm-row--nested', 'jm-row--muted'], $rows->each(static fn(Crawler $row): ?string => $row->attr('class')));
        static::assertStringContainsString('<tr class="jm-row--nested" data-jm-place="a">', $html);
        static::assertSame(3, substr_count($html, '<tr>'), 'The row of the header and the two rows that are neither nested nor muted.');
        static::assertSame(1, substr_count($html, '<tr class="jm-row--muted">'));
    }

    public function testAHostGlobalDoesNotStandInForTheVariablesOfTheTopOfAComposedPage(): void
    {
        $table = new Table('exceptions', 'Exceptions', [new Column('name', 'Name', false, 'name'), new Column('count', 'Count', sort: 'count')], [
            new Row('k1', ['name' => new Cell('RuntimeException'), 'count' => new Cell('3')]),
            new Row('k1-a', ['name' => new Cell('GET app_home'), 'count' => new Cell('3')], nested: true),
        ]);
        $checks = new CheckList([], 'Nothing to flag', 'No failed message in the last hour.', 'Worth a look');
        $section = new Section('messages', 'Messages', 'Work handed to Messenger.', [Block::cards([new Card('Sent', '4'), new Card('Failed', '1', tone: 'bad')]), Block::table($table)], 'count', 'desc', ['count' => 'desc', 'name' => 'asc']);
        $blocks = [
            Block::lead(new Lead('4 messages handled in the last hour.', '1 message failed.')),
            Block::checks($checks),
            Block::cards([new Card('Messages', '4', 'handled, 4 sent, 1 failed', 'bad', anchor: 'messages'), new Card('Tasks', '2', anchor: 'tasks')]),
            Block::section($section),
            Block::guide(new Guide([new GuideItem('Sent and handled.', 'Text.')])),
        ];
        $page = new Page(new ComposedScreen('background', 'Background', 'Work done in the background', Space::Background, [new StubSection('messages')], true), $blocks, Period::OneHour, '', '', []);
        $context = ['page' => $page, 'path' => 'background', 'badges' => [], 'screens' => ['background']];
        $expected = $this->render('_screen.html.twig', $context);

        static::assertStringContainsString('href="#jm-messages"', $expected);
        static::assertStringContainsString('class="jm-row--nested"', $expected);
        static::assertStringContainsString('1 row', $expected);
        foreach (['a string', new \ArrayObject(['id' => 'x', 'anchor' => 'x', 'nested' => true, 'muted' => true]), new \stdClass(), true, false, null] as $global) {
            static::assertSame($expected, $this->render('_screen.html.twig', $context, array_fill_keys(['brief', 'anchor', 'outline', 'nested', 'card', 'row', 'block', 'section', 'page_title'], $global)));
        }
    }

    private function cells(Column $column, Cell ...$cells): string
    {
        $table = new Table('stubs', 'Stubs', [new Column('name', 'Name', false), $column], array_map(
            static fn(int $i, Cell $cell): Row => new Row('k' . $i, ['name' => new Cell('row ' . $i), $column->id => $cell]),
            array_keys($cells),
            $cells,
        ));

        return $this->render('block/table.html.twig', ['page' => $this->page(new ScreenView([], [$table])), 'block' => Block::table($table)]);
    }

    private function composed(Block ...$blocks): string
    {
        $screen = new ComposedScreen('background', 'Background', 'Work done in the background', Space::Background, [new StubSection('commands')]);

        return $this->render('_screen.html.twig', ['page' => new Page($screen, $blocks, Period::OneHour, '', '', []), 'path' => 'background', 'badges' => []]);
    }

    private function sectionPage(SectionInterface $section, ScreenView $view, string $sort, string $direction): Page
    {
        $screen = new ComposedScreen('background', 'Background', 'Work done in the background', Space::Background, [$section]);
        $block = Block::section(new Section($section->id(), $section->title(), $section->summary(), $this->blocks($view), $sort, $direction, $section->sorts()));

        return new Page($screen, [$block], Period::OneHour, '', '', []);
    }

    private function screen(Block ...$blocks): string
    {
        return $this->render('_screen.html.twig', ['page' => new Page(new StubBlockScreen('stack'), $blocks, null, '', '', []), 'path' => 'stack', 'badges' => []]);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $globals
     */
    private function render(string $template, array $context, array $globals = []): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../templates', 'Jmonitor');
        $twig = new Environment($loader, ['autoescape' => false, 'strict_variables' => true]);
        $twig->addExtension(new JmonitorExtension());
        foreach ($globals as $name => $value) {
            $twig->addGlobal($name, $value);
        }

        return $twig->render('@Jmonitor/dashboard/' . $template, $context + ['urls' => new FakeHostUrls(), 'standalone' => false, 'actions' => [], 'token' => null, 'screens' => ['stack']]);
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
