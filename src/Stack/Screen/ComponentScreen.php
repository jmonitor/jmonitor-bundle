<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\ActionScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\BlockScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCards;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
use Jmonitor\JmonitorBundle\Stack\Check\Verdict;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\DetailInterface;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

final class ComponentScreen implements BlockScreenInterface, ActionScreenInterface
{
    private readonly StackScreenParts $parts;

    /**
     * @param ContainerInterface $services gives StackReaderInterface, StackChecks and StackSummary under their class names
     */
    public function __construct(
        private readonly Component $component,
        private readonly DetailInterface $detail,
        ContainerInterface $services,
        private readonly CardBuilder $cards,
        ClockInterface $clock,
    ) {
        $this->parts = new StackScreenParts($services, $clock);
    }

    public function id(): string
    {
        return $this->component->value;
    }

    public function label(): string
    {
        return $this->component->label();
    }

    public function heading(): string
    {
        return match ($this->component) {
            Component::System => 'The server answering this page, read live',
            Component::Php => 'The PHP runtime of this web server, read live',
            Component::Symfony => 'The Symfony application, read live',
        };
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
            $this->parts->reader()->refresh($this->component);
        }
    }

    public function blocks(ScreenRequest $request): array
    {
        $state = $this->parts->reader()->component($this->component, null, $request->fragment ? ReadMode::Fragment : ReadMode::Page);

        if ($state->error !== null) {
            return [
                Block::subnav($this->subnav(Verdict::Unknown, $state->node)),
                Block::notice(StackScreenParts::engineFailure($state->error)),
            ];
        }

        $health = $this->parts->checks()->health($state);
        $blocks = [Block::subnav($this->subnav($health->componentVerdict($this->component), $state->node))];

        if ($state->cache === CacheState::OutOfOrder) {
            $blocks[] = Block::notice(StackScreenParts::cacheOutOfOrder());
        }

        $instance = $state->component($this->component)?->instances[0] ?? null;

        if ($instance === null) {
            $blocks[] = Block::notice(new Notice('info', $this->label() . ' is not part of this stack', $state->notDetected[$this->component->value] ?? 'It is turned off, or was not found on this server.'));

            return $blocks;
        }

        $reading = $state->cache !== CacheState::OutOfOrder;
        $card = Block::components(new ComponentCards([$this->cards->card($this->component, $instance, $health, null, $reading)]));
        $findings = $health->of($this->component, $instance->id);

        return [
            ...$blocks,
            $state->isReading() ? $card->withReading() : $card,
            Block::checks($this->parts->checkList($findings, false, 'Nothing to check on ' . $this->label())),
            ...$this->detail->sections($instance, $findings, $this->parts->now(), $reading),
        ];
    }

    private function subnav(Verdict $verdict, ?string $node): SubNav
    {
        $last = [];

        foreach ($this->parts->summary()->last()['components'] ?? [] as $component => $lastVerdict) {
            $known = Verdict::tryFrom($lastVerdict);

            if ($known !== null && Component::tryFrom($component) !== null) {
                $last[$component] = $known;
            }
        }

        $items = [StackScreenParts::overview(false)];

        foreach (Component::cases() as $component) {
            if ($component === $this->component) {
                $items[] = new SubNavItem($component->label(), StackScreenParts::path($component), true, Marks::verdict($verdict));
            } elseif (isset($last[$component->value])) {
                $items[] = new SubNavItem($component->label(), StackScreenParts::path($component), false, Marks::verdict($last[$component->value]));
            }
        }

        return new SubNav($items, $node);
    }
}
