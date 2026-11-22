<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Fact;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauge;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauges;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use Symfony\Component\Clock\MockClock;

trait DescribesSections
{
    private const NBSP = "\u{00A0}";

    /**
     * Each block as a line: a value is followed by " [<mark>]" when it carries one.
     *
     * @param list<Block> $blocks
     *
     * @return list<array<mixed>>
     */
    private static function describe(array $blocks): array
    {
        return array_map(static function (Block $block): array {
            if ($block->state !== BlockState::Ready) {
                return [$block->state->value, $block->type];
            }

            $content = $block->content;

            return match (true) {
                $content instanceof Notice => ['notice', $content->tone, $content->title, $content->text],
                $content instanceof Gauges => ['gauges', $content->title, array_combine(
                    array_map(static fn(Gauge $gauge): string => $gauge->label, $content->gauges),
                    array_map(static fn(Gauge $gauge): string => $gauge->value . ($gauge->mark === null ? '' : ' [' . $gauge->mark->label . ']'), $content->gauges),
                )],
                $content instanceof Facts => ['facts', $content->title, array_combine(
                    array_map(static fn(Fact $fact): string => $fact->label, $content->facts),
                    array_map(static fn(Fact $fact): string => $fact->value . ($fact->mark === null ? '' : ' [' . $fact->mark->label . ']'), $content->facts),
                )],
                $content instanceof Table => ['table', $content->title, array_map(static fn(Row $row): string => implode(' | ', array_map(static fn(Cell $cell): string => $cell->text, $row->cells)), $content->rows)],
                default => throw new \LogicException('Unexpected block ' . $block->type),
            };
        }, $blocks);
    }

    /**
     * @param list<Block> $blocks
     *
     * @return list<string>
     */
    private static function titles(array $blocks): array
    {
        return array_map(static fn(Block $block): string => match (true) {
            $block->content instanceof Gauges, $block->content instanceof Facts, $block->content instanceof Table => $block->content->title,
            $block->content instanceof Notice => 'notice: ' . $block->content->title,
            default => $block->state->value,
        }, $blocks);
    }

    /**
     * @param list<Block> $blocks
     *
     * @return array<string, ?string> label => hint, over every gauge and fact
     */
    private static function hints(array $blocks): array
    {
        $hints = [];

        foreach ($blocks as $block) {
            $items = match (true) {
                $block->content instanceof Gauges => $block->content->gauges,
                $block->content instanceof Facts => $block->content->facts,
                default => [],
            };

            foreach ($items as $item) {
                $hints[$block->content->title . ' / ' . $item->label] = $item->hint;
            }
        }

        return $hints;
    }

    private static function nb(string $value): string
    {
        return str_replace(' ', self::NBSP, $value);
    }

    private static function instance(ComponentSnapshot $snapshot): InstanceState
    {
        return new InstanceState(null, null, null, $snapshot);
    }

    /**
     * @return list<Finding>
     */
    private static function findings(ComponentSnapshot $snapshot, bool $debug = false): array
    {
        return (new StackChecks([], $debug, new MockClock('@' . Snapshots::NOW)))->health(Snapshots::stack($snapshot))->of($snapshot->component);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $run
     *
     * @return T
     */
    private static function withoutWarnings(\Closure $run): mixed
    {
        set_error_handler(static fn(int $level, string $message): never => throw new \ErrorException($message, 0, $level));

        try {
            return $run();
        } finally {
            restore_error_handler();
        }
    }
}
