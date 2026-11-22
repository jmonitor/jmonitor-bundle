<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\ActionScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\BlockScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\NavBadge;
use Jmonitor\JmonitorBundle\Dashboard\NavBadgeScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ParentScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCards;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Check\StackHealth;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

final class StackScreen implements BlockScreenInterface, ActionScreenInterface, ParentScreenInterface, NavBadgeScreenInterface
{
    private const ID = 'stack';

    /**
     * @param ContainerInterface $services gives StackReaderInterface, StackChecks and StackSummary under their class names
     */
    public function __construct(
        private readonly ContainerInterface $services,
        private readonly CardBuilder $cards,
        private readonly ClockInterface $clock,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Stack';
    }

    public function heading(): string
    {
        return 'What runs the application, read live';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function actions(): array
    {
        return ['refresh' => 'Refresh'];
    }

    public function run(string $action): void
    {
        if ($action === 'refresh') {
            $this->reader()->refresh();
        }
    }

    public function child(string $id): ?ScreenInterface
    {
        return null;
    }

    public function blocks(ScreenRequest $request): array
    {
        $state = $this->reader()->overview($request->fragment ? ReadMode::Fragment : ReadMode::Page);

        if ($state->error !== null) {
            return [
                Block::subnav(new SubNav([self::overviewItem()], $state->node)),
                Block::notice(new Notice('bad', 'The stack could not be read', $state->error)),
            ];
        }

        $health = $this->checks()->health($state);
        $this->summary()->remember($state, $health);

        $blocks = [Block::subnav(self::subnav($state, $health))];

        if ($state->cache === CacheState::OutOfOrder) {
            $blocks[] = Block::notice(new Notice('warn', 'The live stack needs a working cache', 'Only what PHP can read in its own process is shown. Check the cache.app pool of the application.'));
        }

        $blocks[] = Block::checks($this->checkList($health));
        $components = Block::components($this->componentCards($state, $health));
        $blocks[] = $state->isReading() ? $components->withReading() : $components;

        return $blocks;
    }

    public function navBadge(): ?NavBadge
    {
        $last = $this->summary()->last();

        if ($last === null || $last['critical'] + $last['warning'] === 0) {
            return null;
        }

        $counts = array_filter([
            $last['critical'] > 0 ? $last['critical'] . ' critical' : null,
            $last['warning'] > 0 ? $last['warning'] . ($last['warning'] === 1 ? ' warning' : ' warnings') : null,
        ]);

        return new NavBadge(
            (string) ($last['critical'] + $last['warning']),
            $last['critical'] > 0 ? 'bad' : 'warn',
            sprintf('%s at the last visit of the Stack screen, %s', implode(', ', $counts), Format::ago($last['at'], $this->now())),
        );
    }

    private static function subnav(StackState $state, StackHealth $health): SubNav
    {
        $items = [self::overviewItem()];

        foreach ($state->components as $component) {
            $items[] = new SubNavItem($component->component->label(), self::path($component->component), false, Marks::verdict($health->componentVerdict($component->component)));
        }

        return new SubNav($items, $state->node);
    }

    private static function overviewItem(): SubNavItem
    {
        return new SubNavItem('Overview', self::ID, true);
    }

    private function checkList(StackHealth $health): CheckList
    {
        $now = $this->now();

        return new CheckList(array_map(static fn(Finding $finding): CheckLine => new CheckLine(
            $finding->id,
            Marks::severity($finding->severity),
            $finding->sentence,
            $finding->explanation,
            $finding->instance === null ? $finding->component->label() : $finding->component->label() . ' · ' . $finding->instance,
            self::path($finding->component),
            $finding->age === null ? null : Format::ago($now - $finding->age, $now),
        ), $health->findings));
    }

    private function componentCards(StackState $state, StackHealth $health): ComponentCards
    {
        $cards = [];
        $unread = 0;

        foreach ($state->components as $component) {
            $unread += $component->unread;

            foreach ($component->instances as $instance) {
                if ($instance->snapshot !== null) {
                    $cards[] = $this->cards->card($component->component, $instance, $health, self::path($component->component), $state->cache !== CacheState::OutOfOrder);
                }
            }
        }

        $notDetected = [];

        foreach ($state->notDetected as $value => $reason) {
            $notDetected[] = (Component::tryFrom($value)?->label() ?? $value) . ': ' . $reason;
        }

        return new ComponentCards(
            $cards,
            $notDetected,
            $state->node,
            match ($unread) {
                0 => null,
                1 => '1 more connection',
                default => $unread . ' more connections',
            },
        );
    }

    private static function path(Component $component): string
    {
        return self::ID . '/' . $component->value;
    }

    private function reader(): StackReaderInterface
    {
        $reader = $this->services->get(StackReaderInterface::class);

        return $reader instanceof StackReaderInterface ? $reader : throw new \LogicException('No reader of the live stack.');
    }

    private function checks(): StackChecks
    {
        $checks = $this->services->get(StackChecks::class);

        return $checks instanceof StackChecks ? $checks : throw new \LogicException('No checks of the live stack.');
    }

    private function summary(): StackSummary
    {
        $summary = $this->services->get(StackSummary::class);

        return $summary instanceof StackSummary ? $summary : throw new \LogicException('No summary of the live stack.');
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
