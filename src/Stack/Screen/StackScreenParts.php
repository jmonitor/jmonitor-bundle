<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

/**
 * What the Stack screen and the detail of a component share.
 */
final class StackScreenParts
{
    private const ID = 'stack';

    /**
     * @param ContainerInterface $services gives StackReaderInterface, StackChecks and StackSummary under their class names
     */
    public function __construct(
        private readonly ContainerInterface $services,
        private readonly ClockInterface $clock,
    ) {}

    public static function path(?Component $component = null): string
    {
        return $component === null ? self::ID : self::ID . '/' . $component->value;
    }

    public static function overview(bool $current): SubNavItem
    {
        return new SubNavItem('Overview', self::path(), $current);
    }

    public static function engineFailure(string $error): Notice
    {
        return new Notice('bad', 'The stack could not be read', $error);
    }

    public static function cacheOutOfOrder(): Notice
    {
        return new Notice('warn', 'The live stack needs a working cache', 'Only what PHP can read in its own process is shown. Check the cache.app pool of the application.');
    }

    /**
     * @param list<Finding> $findings
     * @param bool          $linked   whether each line links to the detail of its component
     */
    public function checkList(array $findings, bool $linked, ?string $emptyTitle = null): CheckList
    {
        $now = $this->now();
        $lines = array_map(static fn(Finding $finding): CheckLine => new CheckLine(
            $finding->id,
            Marks::severity($finding->severity),
            $finding->sentence,
            $finding->explanation,
            $finding->instance === null ? $finding->component->label() : $finding->component->label() . ' · ' . $finding->instance,
            $linked ? self::path($finding->component) : null,
            $finding->age === null ? null : Format::ago($now - $finding->age, $now),
        ), $findings);

        return $emptyTitle === null ? new CheckList($lines) : new CheckList($lines, $emptyTitle);
    }

    public function reader(): StackReaderInterface
    {
        $reader = $this->services->get(StackReaderInterface::class);

        return $reader instanceof StackReaderInterface ? $reader : throw new \LogicException('No reader of the live stack.');
    }

    public function checks(): StackChecks
    {
        $checks = $this->services->get(StackChecks::class);

        return $checks instanceof StackChecks ? $checks : throw new \LogicException('No checks of the live stack.');
    }

    public function summary(): StackSummary
    {
        $summary = $this->services->get(StackSummary::class);

        return $summary instanceof StackSummary ? $summary : throw new \LogicException('No summary of the live stack.');
    }

    public function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
