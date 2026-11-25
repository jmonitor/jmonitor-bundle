<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\View;

use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use PHPUnit\Framework\TestCase;

final class CellTest extends TestCase
{
    public function testARequestEntryPointIsARoute(): void
    {
        $cell = Cell::entryPoint('request', 'GET app_home');

        static::assertSame(['app_home', 'route', ['method' => 'GET']], [$cell->text, $cell->kind, $cell->data]);
    }

    public function testACommandEntryPointShowsItsName(): void
    {
        $cell = Cell::entryPoint('command', 'app:work');

        static::assertSame(['app:work', 'route', ['method' => 'CLI']], [$cell->text, $cell->kind, $cell->data]);
    }

    public function testAMessageEntryPointShowsItsClass(): void
    {
        $cell = Cell::entryPoint('message', 'App\Message\SendInvoice');

        static::assertSame(['App\Message\SendInvoice', 'route', ['method' => 'MSG']], [$cell->text, $cell->kind, $cell->data]);
    }

    public function testATaskEntryPointShowsItsLabel(): void
    {
        $cell = Cell::entryPoint('task', (new TaskKey('default', '28af6db0', 'app:cleanup', 'every 1 hour'))->toString());

        static::assertSame(['app:cleanup', 'route', ['method' => 'TASK']], [$cell->text, $cell->kind, $cell->data]);
    }

    public function testATaskEntryPointThatDoesNotParseShowsItsRawKey(): void
    {
        $cell = Cell::entryPoint('task', 'not a task key');

        static::assertSame(['not a task key', 'route', ['method' => 'TASK']], [$cell->text, $cell->kind, $cell->data]);
    }

    public function testAnUnknownEntryPointShowsItsRawKeyWithAnEmptyBadge(): void
    {
        $cell = Cell::entryPoint('job', 'GET app_home');

        static::assertSame(['GET app_home', 'route', ['method' => '']], [$cell->text, $cell->kind, $cell->data]);
    }

    public function testABarIsBoundedBetweenNothingAndTheWholeTrack(): void
    {
        foreach ([[0.424, '42'], [0.005, '1'], [1.7, '100'], [-0.2, '0'], [\NAN, '0'], [\INF, '0'], [-\INF, '0']] as [$share, $percent]) {
            $cell = Cell::bar('1.2 s', $share);

            static::assertSame(['1.2 s', 'bar', ['share' => $percent]], [$cell->text, $cell->kind, $cell->data], (string) $share);
        }
    }

    public function testARouteCanRepeatItsNameAsItsTitle(): void
    {
        static::assertSame(['method' => 'GET', 'title' => 'app_home'], Cell::route('GET app_home', true)->data);
        static::assertSame(['method' => 'GET'], Cell::route('GET app_home')->data);
    }

    public function testANoteOfASplitIsReadAfterItsThreeParts(): void
    {
        static::assertSame('PHP 1 ms, SQL 1 ms, HTTP out 0 ms, 13 queries', Cell::split(['php' => 1.0, 'sql' => 1.0, 'http' => 0.0], 'SQL 1 ms · 13 queries', '13 queries')->data['spoken']);
        static::assertSame('PHP 1 ms, SQL 0 ms, HTTP out 0 ms', Cell::split(['php' => 1.0, 'sql' => 0.0, 'http' => 0.0], 'PHP only')->data['spoken']);
    }

    public function testASplitKeepsItsThreePartsInOrderAndDrawsThemSideBySide(): void
    {
        $cell = Cell::split(['http' => 57.0, 'php' => 77.0, 'sql' => 10.0], '77 · 10 · 57');

        static::assertSame(['77 · 10 · 57', 'split'], [$cell->text, $cell->kind]);
        static::assertSame(['php' => 77.0, 'sql' => 10.0, 'http' => 57.0], $cell->parts);
        static::assertSame(['spoken' => 'PHP 77 ms, SQL 10 ms, HTTP out 57 ms'], $cell->data);
        static::assertSame([
            ['part' => 'php', 'x' => 0.0, 'width' => 49.19],
            ['part' => 'sql', 'x' => 51.19, 'width' => 6.39],
            ['part' => 'http', 'x' => 59.58, 'width' => 36.42],
        ], $cell->segments());
    }

    public function testASplitLeavesOutAPartWithoutTime(): void
    {
        static::assertSame([['part' => 'php', 'x' => 0.0, 'width' => 96.0]], Cell::split(['php' => 120.0, 'sql' => 0.0, 'http' => 0.0], 'PHP only')->segments());
        static::assertSame([['part' => 'sql', 'x' => 0.0, 'width' => 47.0], ['part' => 'http', 'x' => 49.0, 'width' => 47.0]], Cell::split(['php' => 0.0, 'sql' => 5.0, 'http' => 5.0], '')->segments());

        $odd = Cell::split(['php' => -4.0, 'sql' => \NAN, 'http' => \INF], '');

        static::assertSame(['php' => 0.0, 'sql' => 0.0, 'http' => 0.0], $odd->parts);
        static::assertSame([], $odd->segments());
        static::assertSame('PHP 0 ms, SQL 0 ms, HTTP out 0 ms', $odd->data['spoken']);
        static::assertSame('PHP 120 ms, SQL <1 ms, HTTP out 0 ms', Cell::split(['php' => 120.0, 'sql' => 0.4, 'http' => 0.0], '')->data['spoken']);
        static::assertSame([], Cell::split(['php' => 0.0, 'sql' => 0.0, 'http' => 0.0], '')->segments());
    }

    public function testAnAverageSplitLeavesTheRestToPhpAndNamesItsLargestOutsidePart(): void
    {
        $both = Cell::averageSplit(50.0, 10.0, 1.5, 30.0);
        static::assertSame(['HTTP out 30 ms · 1.5 queries', ['php' => 10.0, 'sql' => 10.0, 'http' => 30.0]], [$both->text, $both->parts]);
        static::assertSame('PHP 10 ms, SQL 10 ms, HTTP out 30 ms, 1.5 queries', $both->data['spoken']);

        $sql = Cell::averageSplit(50.0, 20.0, 12.0, 5.0);
        static::assertSame(['SQL 20 ms · 12 queries', ['php' => 25.0, 'sql' => 20.0, 'http' => 5.0]], [$sql->text, $sql->parts]);

        $longer = Cell::averageSplit(40.0, 30.0, 2.0, 20.0);
        static::assertSame(['SQL 30 ms · 2.0 queries', ['php' => 0.0, 'sql' => 30.0, 'http' => 20.0]], [$longer->text, $longer->parts]);

        $httpOnly = Cell::averageSplit(40.0, null, 0.0, 15.0);
        static::assertSame(['HTTP out 15 ms', ['php' => 25.0, 'sql' => 0.0, 'http' => 15.0]], [$httpOnly->text, $httpOnly->parts]);
        static::assertSame('PHP 25 ms, SQL 0 ms, HTTP out 15 ms', $httpOnly->data['spoken']);

        $php = Cell::averageSplit(40.0, null, 0.0, null);
        static::assertSame(['PHP only', ['php' => 40.0, 'sql' => 0.0, 'http' => 0.0]], [$php->text, $php->parts]);
    }

    public function testTagsKeepTheirOrder(): void
    {
        $tags = [new Tag('N+1', 'warn'), new Tag('5xx', 'bad')];
        $cell = Cell::tags($tags);

        static::assertSame(['', 'tags', $tags], [$cell->text, $cell->kind, $cell->tags]);
        static::assertSame([], Cell::tags([])->tags);
        static::assertSame([], (new Cell('a'))->tags);
    }

    public function testATinyPartStaysVisibleBesideAHugeOne(): void
    {
        $segments = Cell::split(['php' => 1e9, 'sql' => 0.001, 'http' => 0.0], '')->segments();

        static::assertSame([['part' => 'php', 'x' => 0.0, 'width' => 93.0], ['part' => 'sql', 'x' => 95.0, 'width' => 1.0]], $segments);
        static::assertSame(96.0, self::end($segments));

        $three = Cell::split(['php' => 1e9, 'sql' => 0.001, 'http' => 0.002], '')->segments();

        static::assertSame([90.0, 1.0, 1.0], array_column($three, 'width'));
        static::assertSame(96.0, self::end($three));
    }

    public function testThreeEqualPartsFillTheBoxExactly(): void
    {
        $segments = Cell::split(['php' => 10.0, 'sql' => 10.0, 'http' => 10.0], '')->segments();

        static::assertSame([30.66, 30.67, 30.67], array_column($segments, 'width'));
        static::assertSame([0.0, 32.66, 65.33], array_column($segments, 'x'));
        static::assertSame(96.0, self::end($segments));
    }

    /**
     * @param list<array{part: string, x: float, width: float}> $segments
     */
    private static function end(array $segments): float
    {
        $last = $segments[\count($segments) - 1];

        return round($last['x'] + $last['width'], 2);
    }
}
