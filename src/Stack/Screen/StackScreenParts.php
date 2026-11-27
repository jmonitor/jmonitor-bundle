<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Check\StackHealth;
use Jmonitor\JmonitorBundle\Stack\Check\Verdict;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\ComponentState;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

/**
 * What the Stack screen, the detail of a component and the glance at the stack share.
 */
final class StackScreenParts
{
    public const READ_AGAIN = 'Read again now';

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
        return new SubNavItem('All components', self::path(), $current, icon: 'stack');
    }

    public static function tab(Component $component, bool $current, Verdict $verdict): SubNavItem
    {
        return new SubNavItem($component->label(), self::path($component), $current, $verdict === Verdict::Healthy ? null : Marks::verdict($verdict), icon: $component->value);
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
     * Whether the screen asks for its rest: a value was never read, one is outdated and nothing says its renewal failed,
     * or a failure is due and could not be read again yet.
     */
    public static function followUp(StackState $state): bool
    {
        if ($state->isReading()) {
            return true;
        }

        if ($state->cache === CacheState::OutOfOrder) {
            return false;
        }

        foreach ($state->components as $component) {
            foreach ($component->instances as $instance) {
                foreach ($instance->snapshot->results ?? [] as $result) {
                    if ($result->state === ResultState::Stale && $result->error === null) {
                        return true;
                    }

                    if ($result->error !== null && $result->expiresAt !== null && $result->expiresAt <= $state->readAt && $state->cache === CacheState::Working) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * What was left out of the instances listed, as a sentence.
     */
    public static function more(StackState $state): ?string
    {
        $unread = ['connection' => 0, 'server' => 0];

        foreach ($state->components as $component) {
            $unread[$component->component->groupsByServer() ? 'server' : 'connection'] += $component->unread;
        }

        $more = array_map(
            static fn(string $noun, int $count): ?string => match ($count) {
                0 => null,
                1 => '1 more ' . $noun,
                default => $count . ' more ' . $noun . 's',
            },
            array_keys($unread),
            array_values($unread),
        );

        return implode(', ', array_filter($more)) ?: null;
    }

    /**
     * @param list<Finding> $findings
     * @param bool          $linked   whether each line links to the detail of its component
     */
    public function checkList(array $findings, bool $linked, string $emptyTitle = 'Nothing to flag'): CheckList
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
            $linked && $finding->instance !== null ? [ScreenRequest::INSTANCE => $finding->instance] : [],
        ), $findings);

        return new CheckList($lines, $emptyTitle, title: 'Worth a look');
    }

    /**
     * The instances listed without being read are left out: they have nothing to judge.
     */
    public function health(StackState $state): StackHealth
    {
        $components = array_map(static fn(ComponentState $component): ComponentState => new ComponentState(
            $component->component,
            array_values(array_filter($component->instances, static fn(InstanceState $instance): bool => $instance->snapshot !== null || $instance->error !== null)),
            $component->unread,
        ), $state->components);

        return $this->checks()->health(new StackState($state->node, $state->readAt, $state->cache, $components, $state->notDetected, $state->error));
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
