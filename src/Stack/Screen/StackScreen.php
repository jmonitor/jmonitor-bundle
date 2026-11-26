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
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCards;
use Jmonitor\JmonitorBundle\Dashboard\View\Guide;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Lead;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Check\Severity;
use Jmonitor\JmonitorBundle\Stack\Check\StackHealth;
use Jmonitor\JmonitorBundle\Stack\Check\Verdict;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\ComponentState;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\DetailInterface;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

final class StackScreen implements BlockScreenInterface, ActionScreenInterface, ParentScreenInterface, NavBadgeScreenInterface
{
    private const NO_WEB_SERVER = 'Web server: FrankenPHP, Apache and Nginx were not found serving this page';

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

    public function space(): Space
    {
        return Space::Stack;
    }

    public function actions(): array
    {
        return ['refresh' => StackScreenParts::READ_AGAIN];
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

        if ($component === null || !$this->details->has($component->value)) {
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
                Block::subnav(new SubNav(Space::Stack->label(), [StackScreenParts::overview(true)], $state->node)),
                Block::notice(StackScreenParts::engineFailure($state->error)),
            ];
        }

        $health = $this->parts->health($state);
        $summary = $this->parts->summary();

        if (!$state->isReading() || $summary->last() === null) {
            $summary->remember($state, $health);
        }

        $blocks = [Block::subnav(self::subnav($state, $health))];

        if ($state->components !== []) {
            $blocks[] = Block::lead(self::lead($state, $health));
        }

        if ($state->cache === CacheState::OutOfOrder) {
            $blocks[] = Block::notice(StackScreenParts::cacheOutOfOrder());
        }

        if ($state->components === []) {
            $blocks[] = Block::notice(new Notice('info', 'No component is read', 'Every component of the stack is turned off or was not found.'));

            return $state->notDetected === [] ? $blocks : [...$blocks, Block::components(new ComponentCards([], self::notDetected($state)))];
        }

        $blocks[] = Block::checks($this->parts->checkList($health->findings, true, $state->isReading() ? 'Nothing to flag so far' : 'Nothing to flag'));
        $components = Block::components($this->componentCards($state, $health));
        $blocks[] = StackScreenParts::followUp($state) ? $components->withReading() : $components;
        $blocks[] = Block::guide(new Guide([
            new GuideItem('Four words.', 'Healthy: nothing to do. Info: good to know. Warning: act before it becomes a problem. Critical: it is a problem now.'),
            new GuideItem('Found by itself.', 'The components come from your Symfony configuration: Doctrine connections, cache and session DSN. Nothing to declare.'),
            new GuideItem('A check that does not apply to you.', 'Each one has a name, such as php.opcache_memory. Put it in stack.ignore_checks to silence it.'),
        ]));

        return $blocks;
    }

    public function navBadge(): ?NavBadge
    {
        $last = $this->parts->summary()->last();
        $count = $last === null ? 0 : $last['critical'] + $last['warning'];

        if ($last === null || $count === 0) {
            return null;
        }

        $counts = array_filter([
            $last['critical'] > 0 ? $last['critical'] . ' critical' : null,
            $last['warning'] > 0 ? $last['warning'] . ($last['warning'] === 1 ? ' warning' : ' warnings') : null,
        ]);

        return new NavBadge(
            $last['critical'] > 0 ? $count . ' critical' : ($count === 1 ? '1 warning' : $count . ' warnings'),
            $last['critical'] > 0 ? 'bad' : 'warn',
            sprintf('%s at the last read of the stack, %s', implode(', ', $counts), Format::ago($last['at'], $this->parts->now())),
        );
    }

    private static function lead(StackState $state, StackHealth $health): Lead
    {
        $detail = 'Read on your servers as you open this screen. Nothing is kept: it shows now, not the past.';

        if ($state->isReading()) {
            return new Lead('Reading what runs your application…', $detail);
        }

        $critical = $health->count(Severity::Critical);
        $warning = $health->count(Severity::Warning);
        $sentence = match (true) {
            $critical > 0 => 'What runs your application needs attention.',
            self::hasUnknown($state, $health) => 'Part of what runs your application could not be read.',
            default => 'Everything that runs your application answers.',
        };

        if ($critical + $warning === 0) {
            return new Lead($sentence, $detail);
        }

        $counts = array_filter([
            $critical > 0 ? $critical . ' critical' : null,
            match ($warning) {
                0 => null,
                1 => '1 warning',
                default => $warning . ' warnings',
            },
        ]);
        $flagged = array_map(
            static fn(Finding $finding): Component => $finding->component,
            array_filter($health->findings, static fn(Finding $finding): bool => $finding->severity !== Severity::Info),
        );
        $components = [];

        foreach ($state->components as $component) {
            if (in_array($component->component, $flagged, true)) {
                $components[] = $component->component->label();
            }
        }

        return new Lead(sprintf('%s %s, on %s.', $sentence, implode(' and ', $counts), self::enumerate($components)), $detail);
    }

    private static function hasUnknown(StackState $state, StackHealth $health): bool
    {
        foreach ($state->components as $component) {
            if ($health->componentVerdict($component->component) === Verdict::Unknown) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $labels
     */
    private static function enumerate(array $labels): string
    {
        if (count($labels) > 3) {
            return count($labels) . ' components';
        }

        $last = array_pop($labels);

        return $labels === [] ? (string) $last : implode(', ', $labels) . ' and ' . $last;
    }

    private static function subnav(StackState $state, StackHealth $health): SubNav
    {
        $items = [StackScreenParts::overview(true)];

        foreach ($state->components as $component) {
            $items[] = new SubNavItem($component->component->label(), StackScreenParts::path($component->component), false, Marks::verdict($health->componentVerdict($component->component)));
        }

        return new SubNav(Space::Stack->label(), $items, $state->node);
    }

    private function componentCards(StackState $state, StackHealth $health): ComponentCards
    {
        $cards = [];

        foreach ($state->components as $component) {
            foreach ($component->instances as $instance) {
                if ($instance->snapshot !== null) {
                    $cards[] = $this->cards->card($component->component, $instance, $health, StackScreenParts::path($component->component), $state->cache !== CacheState::OutOfOrder);
                }
            }
        }

        return new ComponentCards($cards, self::notDetected($state), $state->node, StackScreenParts::more($state));
    }

    /**
     * @return list<string>
     */
    private static function notDetected(StackState $state): array
    {
        $served = array_filter($state->components, static fn(ComponentState $component): bool => $component->component->isWebServer()) !== [];
        $turnedOff = array_filter(Component::cases(), static fn(Component $component): bool => $component->isWebServer() && !isset($state->notDetected[$component->value]));
        $noneFound = !$served && $turnedOff === [];
        $notDetected = [];

        foreach ($state->notDetected as $value => $reason) {
            $component = Component::tryFrom($value);

            if ($component?->isWebServer() !== true) {
                $notDetected[] = ($component?->label() ?? $value) . ': ' . $reason;
            } elseif ($noneFound) {
                $notDetected[self::NO_WEB_SERVER] = self::NO_WEB_SERVER;
            } elseif (!$served) {
                $notDetected[] = $component->label() . ': ' . $reason;
            }
        }

        return array_values($notDetected);
    }
}
