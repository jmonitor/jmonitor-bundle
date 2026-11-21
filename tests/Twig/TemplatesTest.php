<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Twig;

use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\DeclaredTasks;
use Jmonitor\JmonitorBundle\Dashboard\Page;
use Jmonitor\JmonitorBundle\Dashboard\Screen\CommandsScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\MessagesScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\SchedulerScreen;
use Jmonitor\JmonitorBundle\Dashboard\ScreenState;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedSchedules;
use Jmonitor\JmonitorBundle\Tests\Dashboard\StubScreen;
use Jmonitor\JmonitorBundle\Twig\JmonitorExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class TemplatesTest extends TestCase
{
    private const HOSTILE_SQL = '<script>alert(1)</script>';

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
            $this->render('_table.html.twig', ['page' => $this->page($view), 'table' => $table]),
            $this->render('screen.html.twig', ['page' => $this->page($view), 'screens' => [new StubScreen('<b>')], 'periods' => Period::cases()]),
        ];

        foreach ($outputs as $html) {
            static::assertStringContainsString('&lt;script&gt;', $html);
            static::assertStringContainsString('&lt;b&gt;', $html);
            static::assertStringNotContainsString('<script>', $html);
            static::assertStringNotContainsString('<b>', $html);
            static::assertStringNotContainsString('"><i>', $html);
        }
        static::assertStringContainsString('<script src="/bundles/jmonitor/jmonitor.js?v=', $outputs[1]);
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
            $page = new Page($screen, ScreenState::Ready, Period::OneHour, (string) array_key_first($screen->sorts()), 'desc', $view);

            foreach ([
                $this->render('_table.html.twig', ['page' => $page, 'table' => $view->tables[0]]),
                $this->render('screen.html.twig', ['page' => $page, 'screens' => [$screen], 'periods' => Period::cases()]),
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
            'default' => static fn(): Schedule => (new Schedule())->add(RecurringMessage::every('1 hour', new RunCommandMessage($label))),
        ]));
        $stored = new ReportRow('"><i>', (new TaskKey('"><i>', 'gone', $label, '"><i>'))->toString(), null, [
            Metric::TASK_DURATION => new MetricValue(1, 10.0, 10.0),
            Metric::TASK_LAST_RUN => new MetricValue(1, 0.0, 1799999990.0),
        ]);
        $screen = new SchedulerScreen(true, $declared);
        $view = $screen->build(new FixedReader(new Report([$stored], keyCount: 1)), Period::OneHour, 1800000000, 'next', 'asc');
        $page = new Page($screen, ScreenState::Ready, Period::OneHour, 'next', 'asc', $view);

        static::assertCount(2, $view->tables[0]->rows);
        foreach ([
            $this->render('_table.html.twig', ['page' => $page, 'table' => $view->tables[0]]),
            $this->render('screen.html.twig', ['page' => $page, 'screens' => [$screen], 'periods' => Period::cases()]),
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

        $html = $this->render('_table.html.twig', ['page' => $this->page(new ScreenView([], [$table])), 'table' => $table]);

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

        $html = $this->render('_table.html.twig', ['page' => $this->page(new ScreenView([], [$table])), 'table' => $table]);

        static::assertSame(1, substr_count($html, 'class="jm-sort"'));
        static::assertStringContainsString('9 stubs', $html);
        static::assertStringContainsString('7 more stubs not shown', $html);
    }

    public function testCountsUseThePluralOfTheNoun(): void
    {
        $one = new Table('stubs', 'Stubs', [new Column('name', 'Name', false)], [new Row('k1', ['name' => new Cell('a')])], 1, 'query', 'queries');
        $many = new Table('stubs', 'Stubs', [new Column('name', 'Name', false)], [new Row('k1', ['name' => new Cell('a')])], 2, 'query', 'queries');

        $html = $this->render('_table.html.twig', ['page' => $this->page(new ScreenView([], [$one])), 'table' => $one]);
        static::assertStringContainsString('2 queries', $html);
        static::assertStringContainsString('1 more query not shown', $html);

        $html = $this->render('_table.html.twig', ['page' => $this->page(new ScreenView([], [$many])), 'table' => $many]);
        static::assertStringContainsString('3 queries', $html);
        static::assertStringContainsString('2 more queries not shown', $html);
    }

    public function testTheHeadingNamesThePeriod(): void
    {
        $page = new Page(new StubScreen(), ScreenState::Ready, Period::SevenDays, 'name', 'asc', new ScreenView());

        $html = $this->render('screen.html.twig', ['page' => $page, 'screens' => [new StubScreen()], 'periods' => Period::cases()]);

        static::assertStringContainsString('Stub heading, last 7 days.', $html);
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
        $twig->addFunction(new TwigFunction('path', static fn(string $name, array $parameters = []): string => '/' . $name . '?' . http_build_query($parameters)));

        return $twig->render('@Jmonitor/dashboard/' . $template, $context);
    }

    private function page(ScreenView $view): Page
    {
        return new Page(new StubScreen(), ScreenState::Ready, Period::OneHour, 'name', 'asc', $view);
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
