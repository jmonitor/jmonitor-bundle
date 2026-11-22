<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\ActionScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\BlockScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\Format;
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
use Jmonitor\JmonitorBundle\Stack\Check\Verdict;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\DetailInterface;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

final class ComponentScreen implements BlockScreenInterface, ActionScreenInterface
{
    /**
     * @param ContainerInterface $services gives StackReaderInterface, StackChecks and StackSummary under their class names
     */
    public function __construct(
        private readonly Component $component,
        private readonly DetailInterface $detail,
        private readonly ContainerInterface $services,
        private readonly CardBuilder $cards,
        private readonly ClockInterface $clock,
    ) {}

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
            $this->reader()->refresh($this->component);
        }
    }

    public function blocks(ScreenRequest $request): array
    {
        $state = $this->reader()->component($this->component, null, $request->fragment ? ReadMode::Fragment : ReadMode::Page);

        if ($state->error !== null) {
            return [
                Block::subnav($this->subnav(Verdict::Unknown, $state->node)),
                Block::notice(new Notice('bad', 'The stack could not be read', $state->error)),
            ];
        }

        $health = $this->checks()->health($state);
        $blocks = [Block::subnav($this->subnav($health->componentVerdict($this->component), $state->node))];

        if ($state->cache === CacheState::OutOfOrder) {
            $blocks[] = Block::notice(StackScreen::cacheOutOfOrder());
        }

        $instance = $state->component($this->component)?->instances[0] ?? null;

        if ($instance === null) {
            $blocks[] = Block::notice(new Notice('info', $this->label() . ' is not part of this stack', $state->notDetected[$this->component->value] ?? ''));

            return $blocks;
        }

        $reading = $state->cache !== CacheState::OutOfOrder;
        $card = Block::components(new ComponentCards([$this->cards->card($this->component, $instance, $health, null, $reading)]));
        $findings = $health->of($this->component, $instance->id);

        return [
            ...$blocks,
            $state->isReading() ? $card->withReading() : $card,
            Block::checks($this->checkList($findings)),
            ...$this->detail->sections($instance, $findings, $this->now(), $reading),
        ];
    }

    /**
     * Overview, then the components of the last visit of the Stack screen with their verdict then,
     * this one with its verdict now.
     */
    private function subnav(Verdict $verdict, ?string $node): SubNav
    {
        $last = [];

        foreach ($this->summary()->last()['components'] ?? [] as $component => $lastVerdict) {
            $known = Verdict::tryFrom($lastVerdict);

            if ($known !== null && Component::tryFrom($component) !== null) {
                $last[$component] = $known;
            }
        }

        $items = [new SubNavItem('Overview', StackScreen::path())];

        foreach (Component::cases() as $component) {
            if ($component === $this->component) {
                $items[] = new SubNavItem($component->label(), StackScreen::path($component), true, Marks::verdict($verdict));
            } elseif (isset($last[$component->value])) {
                $items[] = new SubNavItem($component->label(), StackScreen::path($component), false, Marks::verdict($last[$component->value]));
            }
        }

        return new SubNav($items, $node);
    }

    /**
     * @param list<Finding> $findings
     */
    private function checkList(array $findings): CheckList
    {
        $now = $this->now();

        return new CheckList(array_map(static fn(Finding $finding): CheckLine => new CheckLine(
            $finding->id,
            Marks::severity($finding->severity),
            $finding->sentence,
            $finding->explanation,
            $finding->instance === null ? $finding->component->label() : $finding->component->label() . ' · ' . $finding->instance,
            null,
            $finding->age === null ? null : Format::ago($now - $finding->age, $now),
        ), $findings), 'Nothing to check on ' . $this->label());
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
