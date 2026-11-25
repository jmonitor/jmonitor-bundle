<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\Embed;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\Section\SectionInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Brief;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Guide;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;

/**
 * A section of the composed gallery screen, whatever is stored: one in alert, one steady, one that brings
 * nothing to the top of its page.
 */
final class GallerySection implements SectionInterface
{
    /** @param 'failing'|'steady'|'unbriefed' $id */
    public function __construct(private readonly string $id) {}

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return match ($this->id) {
            'failing' => 'Deliveries',
            'steady' => 'Imports',
            'unbriefed' => 'Reports',
        };
    }

    public function summary(): string
    {
        return match ($this->id) {
            'failing' => 'A section in alert: its anchor card is in the bad tone.',
            'steady' => 'A steady section, with four cards that share the width.',
            'unbriefed' => 'A section without brief: no part of the sentence, no anchor card.',
        };
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function sorts(): array
    {
        return ['count' => 'desc', 'name' => 'asc'];
    }

    public function emptyState(Period $period): array
    {
        return ['title' => 'Nothing in the ' . $period->label() . '.', 'text' => ''];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction, RaisedChecks $raised): ScreenView
    {
        return match ($this->id) {
            'failing' => new ScreenView(
                [],
                [$this->table([['carrier:pickup', '30', '3'], ['carrier:label', '15', '0']])],
                checks: new CheckList([
                    new CheckLine('deliveries.failed', new Mark('bad', 'Critical'), 'carrier:pickup failed 3 times.', 'A failed delivery is work that did not happen.', '', title: '3 deliveries failed', action: 'Open Exceptions to see what was thrown.'),
                ], emptyText: 'no failed delivery'),
                guide: new Guide([new GuideItem('Deliveries.', 'One row per carrier, the most active first.')]),
                brief: new Brief('45', '45 deliveries', 'handled, 48 sent, 3 failed', '3 deliveries failed.', 'bad'),
            ),
            'steady' => new ScreenView(
                [new Card('Runs', '12', 'in the last hour'), new Card('Imports', '2', 'Declared'), new Card('Failed', '0', 'None failed'), new Card('Rows', '1,204', 'Read from the files')],
                [$this->table([['import:prices', '8', '0'], ['import:stock', '4', '0']], [1 => [['import:stock --full', '1', '0']]])],
                checks: new CheckList([], emptyText: 'no failed import'),
                guide: new Guide([new GuideItem('Imports.', 'Run by the scheduler, every hour.'), new GuideItem('Rows.', 'What the imports read, whatever they kept.'), new GuideItem('A fourth item.', 'Left out: a guide keeps the first three.')]),
                brief: new Brief('12', '12 imports', 'runs of 2 imports'),
            ),
            'unbriefed' => new ScreenView(
                [new Card('Reports', '4', 'in the last hour', series: [1, 0, 2, 1], wide: true), new Card('Pages', '38', 'Rendered')],
                [$this->table([['report:weekly', '4', '0']])],
                guide: new Guide([new GuideItem('Reports.', 'Left out: this section brings no brief.')]),
            ),
        };
    }

    /**
     * @param list<array{string, string, string}>             $rows   name, count, failed
     * @param array<int, list<array{string, string, string}>> $nested position of a row => the rows nested under it
     */
    private function table(array $rows, array $nested = []): Table
    {
        $built = [];

        foreach ($rows as $i => $row) {
            $built[] = self::row($row, false);

            foreach ($nested[$i] ?? [] as $child) {
                $built[] = self::row($child, true);
            }
        }

        return new Table($this->id, $this->title(), [
            new Column('name', 'Name', false, 'name'),
            new Column('count', 'Count', sort: 'count'),
            new Column('failed', 'Failed'),
        ], $built);
    }

    /** @param array{string, string, string} $row */
    private static function row(array $row, bool $nested): Row
    {
        return new Row(($nested ? 'nested-' : '') . $row[0], [
            'name' => new Cell($row[0], kind: 'code'),
            'count' => new Cell($row[1]),
            'failed' => new Cell($row[2], $row[2] === '0' ? 'zero' : 'bad'),
        ], nested: $nested);
    }
}
