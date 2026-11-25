<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\ActionScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\BlockScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCards;
use Jmonitor\JmonitorBundle\Dashboard\View\InstanceNav;
use Jmonitor\JmonitorBundle\Dashboard\View\InstanceNavItem;
use Jmonitor\JmonitorBundle\Dashboard\View\InstanceNavRow;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNavItem;
use Jmonitor\JmonitorBundle\Stack\Check\StackHealth;
use Jmonitor\JmonitorBundle\Stack\Check\Verdict;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\ComponentState;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\DetailInterface;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

final class ComponentScreen implements BlockScreenInterface, ActionScreenInterface
{
    private const SHOWN_NAME_LENGTH = 64;

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
            Component::FrankenPhp => 'The FrankenPHP server answering this page, read live',
            Component::Apache => 'The Apache server answering this page, read live',
            Component::Nginx => 'The Nginx server answering this page, read live',
            Component::Mysql => 'A MySQL or MariaDB server of the application, read live',
            Component::Postgresql => 'A PostgreSQL database of the application, read live',
            Component::Redis => 'A Redis or Valkey server of the application, read live',
        };
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
        $asked = $this->component->isNodeLocal() ? null : $request->instance;
        $state = $this->parts->reader()->component($this->component, $asked, $request->fragment ? ReadMode::Fragment : ReadMode::Page);

        if ($state->error !== null) {
            return [
                Block::subnav($this->subnav(Verdict::Unknown, $state->node)),
                Block::notice(StackScreenParts::engineFailure($state->error)),
            ];
        }

        $health = $this->parts->health($state);
        $blocks = [Block::subnav($this->subnav($health->componentVerdict($this->component), $state->node))];
        $component = $state->component($this->component);
        $instance = $component === null ? null : self::shown($component, $asked);

        if ($component !== null && $instance === null) {
            $blocks[] = Block::notice(new Notice('warn', sprintf('No connection named "%s" is read', self::cut((string) $asked)), 'It may have been renamed or left out of the configuration.'));
        }

        if ($component !== null && count($component->instances) > 1) {
            $blocks[] = Block::instances($this->instances($component, $instance, $health));
        }

        if ($state->cache === CacheState::OutOfOrder) {
            $blocks[] = Block::notice(StackScreenParts::cacheOutOfOrder());
        }

        if ($component === null) {
            $blocks[] = Block::notice(new Notice('info', $this->label() . ' is not part of this stack', $state->notDetected[$this->component->value] ?? 'It is turned off, or was not found on this server.'));

            return $blocks;
        }

        if ($instance === null) {
            return $blocks;
        }

        $reading = $state->cache !== CacheState::OutOfOrder;
        $card = Block::components(new ComponentCards([$this->cards->card($this->component, $instance, $health, null, $reading)]));
        $findings = $health->of($this->component, $instance->id);

        return [
            ...$blocks,
            StackScreenParts::followUp($state) ? $card->withReading() : $card,
            Block::checks($this->parts->checkList($findings, false, 'Nothing to check on ' . $this->label())),
            ...$this->detail->sections($instance, $findings, $this->parts->now(), $reading),
        ];
    }

    private static function shown(ComponentState $component, ?string $id): ?InstanceState
    {
        if ($id === null) {
            return $component->instances[0] ?? null;
        }

        foreach ($component->instances as $instance) {
            if ($instance->id === $id) {
                return $instance;
            }
        }

        return null;
    }

    private static function cut(string $name): string
    {
        return mb_strlen($name) > self::SHOWN_NAME_LENGTH ? mb_substr($name, 0, self::SHOWN_NAME_LENGTH) . '…' : $name;
    }

    /**
     * One row of every instance; for a component grouped by server, one row of the servers, each by its first
     * instance, and one row of the instances of the server shown. A row of a single item is left out.
     */
    private function instances(ComponentState $component, ?InstanceState $shown, StackHealth $health): InstanceNav
    {
        $item = fn(InstanceState $instance, ?string $detail, bool $current): InstanceNavItem => new InstanceNavItem(
            $instance->id ?? $this->component->label(),
            StackScreenParts::path($this->component),
            $instance->id === null ? [] : [ScreenRequest::INSTANCE => $instance->id],
            $current,
            $detail,
            $this->mark($instance, $health),
        );

        if (!$this->component->groupsByServer()) {
            return new InstanceNav([new InstanceNavRow('Connections', array_map(
                static fn(InstanceState $instance): InstanceNavItem => $item($instance, $instance->detail, $instance === $shown),
                $component->instances,
            ))]);
        }

        $servers = [];

        foreach ($component->instances as $instance) {
            $servers[$instance->server === null ? "\0" . $instance->id : $instance->server->key][] = $instance;
        }

        $rows = [];

        if (count($servers) > 1) {
            $rows[] = new InstanceNavRow('Servers', array_map(
                static fn(array $server): InstanceNavItem => $item($server[0], $server[0]->server?->address, in_array($shown, $server, true)),
                array_values($servers),
            ));
        }

        $around = $shown ?? $component->instances[0];
        $databases = $servers[$around->server === null ? "\0" . $around->id : $around->server->key];

        if (count($databases) > 1) {
            $rows[] = new InstanceNavRow('Databases', array_map(
                static fn(InstanceState $instance): InstanceNavItem => $item($instance, $instance->detail, $instance === $shown),
                $databases,
            ));
        }

        return new InstanceNav($rows);
    }

    private function mark(InstanceState $instance, StackHealth $health): ?Mark
    {
        return match (true) {
            ($instance->error ?? $instance->snapshot?->error) !== null => new Mark('warn', 'Unavailable'),
            $instance->snapshot !== null => Marks::verdict($health->verdict($this->component, $instance->id)),
            default => null,
        };
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
