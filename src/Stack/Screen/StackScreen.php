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
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCards;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
use Jmonitor\JmonitorBundle\Stack\Check\StackHealth;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\DetailInterface;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

final class StackScreen implements BlockScreenInterface, ActionScreenInterface, ParentScreenInterface, NavBadgeScreenInterface
{
    private readonly StackScreenParts $parts;

    /**
     * @param ContainerInterface $services gives StackReaderInterface, StackChecks and StackSummary under their class names
     * @param ContainerInterface $details  gives the DetailInterface of each component under its value
     */
    public function __construct(
        private readonly ContainerInterface $services,
        private readonly CardBuilder $cards,
        private readonly ClockInterface $clock,
        private readonly ContainerInterface $details,
    ) {
        $this->parts = new StackScreenParts($services, $clock);
    }

    public function id(): string
    {
        return StackScreenParts::path();
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
            $this->parts->reader()->refresh();
        }
    }

    public function child(string $id): ?ScreenInterface
    {
        $component = Component::tryFrom($id);

        if ($component === null) {
            return null;
        }

        $detail = $this->details->get($component->value);

        return new ComponentScreen(
            $component,
            $detail instanceof DetailInterface ? $detail : throw new \LogicException('No detail of ' . $component->label() . '.'),
            $this->services,
            $this->cards,
            $this->clock,
        );
    }

    public function blocks(ScreenRequest $request): array
    {
        $state = $this->parts->reader()->overview($request->fragment ? ReadMode::Fragment : ReadMode::Page);

        if ($state->error !== null) {
            return [
                Block::subnav(new SubNav([StackScreenParts::overview(true)], $state->node)),
                Block::notice(StackScreenParts::engineFailure($state->error)),
            ];
        }

        $health = $this->parts->checks()->health($state);
        $this->parts->summary()->remember($state, $health);

        $blocks = [Block::subnav(self::subnav($state, $health))];

        if ($state->cache === CacheState::OutOfOrder) {
            $blocks[] = Block::notice(StackScreenParts::cacheOutOfOrder());
        }

        $blocks[] = Block::checks($this->parts->checkList($health->findings, true));
        $components = Block::components($this->componentCards($state, $health));
        $blocks[] = $state->isReading() ? $components->withReading() : $components;

        return $blocks;
    }

    public function navBadge(): ?NavBadge
    {
        $last = $this->parts->summary()->last();

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
            sprintf('%s at the last visit of the Stack screen, %s', implode(', ', $counts), Format::ago($last['at'], $this->parts->now())),
        );
    }

    private static function subnav(StackState $state, StackHealth $health): SubNav
    {
        $items = [StackScreenParts::overview(true)];

        foreach ($state->components as $component) {
            $items[] = new SubNavItem($component->component->label(), StackScreenParts::path($component->component), false, Marks::verdict($health->componentVerdict($component->component)));
        }

        return new SubNav($items, $state->node);
    }

    private function componentCards(StackState $state, StackHealth $health): ComponentCards
    {
        $cards = [];
        $unread = 0;

        foreach ($state->components as $component) {
            $unread += $component->unread;

            foreach ($component->instances as $instance) {
                if ($instance->snapshot !== null) {
                    $cards[] = $this->cards->card($component->component, $instance, $health, StackScreenParts::path($component->component), $state->cache !== CacheState::OutOfOrder);
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
}
