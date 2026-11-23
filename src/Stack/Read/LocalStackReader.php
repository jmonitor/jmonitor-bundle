<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Read;

use Jmonitor\JmonitorBundle\Stack\Collector\ErrorMessage;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\Availability;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentRegistry;
use Jmonitor\JmonitorBundle\Stack\Detection\ComponentStatus;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Stack\Detection\QueuesInstance;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheProbe;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectBudget;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Reads the stack of the web server answering the request, all the instances of a screen in one read.
 */
final readonly class LocalStackReader implements StackReaderInterface
{
    private const OVERVIEW_INSTANCES = 8;
    private const REFRESHED_KEY = 'jmonitor.stack.refreshed.';
    private const REFRESH_SECONDS = 10;

    private LoggerInterface $logger;

    public function __construct(
        private ComponentRegistry $registry,
        private SnapshotProvider $snapshots,
        private CacheProbe $probe,
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        private WebNode $node,
        ?LoggerInterface $logger = null,
        private ?QueuesInstance $queues = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function overview(ReadMode $mode): StackState
    {
        $cache = CacheState::OutOfOrder;

        try {
            $cache = $this->probe->state();
            $statuses = $this->statuses();
            $snapshots = $this->snapshots->read(self::overviewInstances($statuses), $this->budget($mode), $cache);
            $components = [];

            foreach ($statuses as $status) {
                if ($status->instances !== []) {
                    $components[] = self::componentState($status, $snapshots);
                }
            }

            return $this->state($cache, $components, self::notDetected($statuses));
        } catch (\Throwable $e) {
            return $this->failed($e, $cache);
        }
    }

    public function component(Component $component, ?string $instance, ReadMode $mode): StackState
    {
        $cache = CacheState::OutOfOrder;

        try {
            $cache = $this->probe->state();
            $status = $this->registry->status($component);
            $read = $this->registry->instance($component, $instance);

            if ($read === null) {
                return $status->instances === [] || $component->isNodeLocal()
                    ? $this->state($cache, [], self::notDetected([$status]))
                    : $this->state($cache, [self::componentState($status, [])], []);
            }

            $snapshots = $this->snapshots->read([$read], $this->budget($mode), $cache, detail: true);

            return $this->state($cache, [self::componentState($status, $snapshots)], []);
        } catch (\Throwable $e) {
            return $this->failed($e, $cache);
        }
    }

    public function queues(ReadMode $mode): ?StackState
    {
        if ($this->queues === null) {
            return null;
        }

        $cache = CacheState::OutOfOrder;

        try {
            $cache = $this->probe->state();
            $instance = $this->queues->instance();
            $snapshots = $this->snapshots->read([$instance], $this->budget($mode), $cache);
            $state = new InstanceState(
                $instance->id,
                null,
                $instance->error === null ? null : ErrorMessage::clean($instance->error),
                $snapshots[$instance->key()] ?? null,
            );

            return $this->state($cache, [new ComponentState(Component::Symfony, [$state], 0)], []);
        } catch (\Throwable $e) {
            return $this->failed($e, $cache);
        }
    }

    public function refresh(?Component $component = null): bool
    {
        try {
            if ($this->probe->state() === CacheState::OutOfOrder) {
                return false;
            }

            $item = $this->cache->getItem(self::REFRESHED_KEY . ($component->value ?? 'all'));
            $refreshedAt = $item->isHit() ? $item->get() : null;
            $now = $this->now();

            if (is_int($refreshedAt) && $now - $refreshedAt < self::REFRESH_SECONDS) {
                return false;
            }

            $instances = $component === null ? self::overviewInstances($this->statuses()) : $this->registry->instancesFor($component);

            if ($this->queues !== null && ($component === null || $component === Component::Symfony)) {
                $instances[] = $this->queues->instance();
            }

            if ($instances === [] || !$this->cache->save($item->set($now)->expiresAfter(self::REFRESH_SECONDS))) {
                return false;
            }

            $this->snapshots->forget($instances);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('The live stack could not be refreshed: {reason}', ['reason' => self::describe($e), 'exception' => $e]);

            return false;
        }
    }

    /**
     * @return list<ComponentStatus>
     */
    private function statuses(): array
    {
        return array_map($this->registry->status(...), Component::cases());
    }

    /**
     * The first instances of each component, or the first instance of each of its first servers.
     *
     * @param list<ComponentStatus> $statuses
     *
     * @return list<Instance>
     */
    private static function overviewInstances(array $statuses): array
    {
        $instances = [];

        foreach ($statuses as $status) {
            $servers = self::servers($status->instances);
            $firsts = $servers === null ? $status->instances : array_map(static fn(array $server): Instance => $server[0], $servers);
            array_push($instances, ...array_slice($firsts, 0, self::OVERVIEW_INSTANCES));
        }

        return $instances;
    }

    /**
     * The instances grouped by the server they reach, in the order they were detected; one that reaches
     * no known server is alone in its group. Null when none reaches a known server.
     *
     * @param list<Instance> $instances
     *
     * @return ?list<non-empty-list<Instance>>
     */
    private static function servers(array $instances): ?array
    {
        $servers = [];

        foreach ($instances as $instance) {
            $servers[$instance->server === null ? "\0" . $instance->key() : $instance->server->key][] = $instance;
        }

        return array_filter($instances, static fn(Instance $instance): bool => $instance->server !== null) === [] ? null : array_values($servers);
    }

    /**
     * @param array<string, ComponentSnapshot> $snapshots keyed by Instance::key()
     */
    private static function componentState(ComponentStatus $status, array $snapshots): ComponentState
    {
        $servers = self::servers($status->instances);
        $siblings = [];

        foreach ($servers ?? [] as $server) {
            foreach ($server as $instance) {
                $siblings[$instance->key()] = count($server) - 1;
            }
        }

        $instances = array_map(static fn(Instance $instance): InstanceState => new InstanceState(
            $instance->id,
            $instance->detail,
            $instance->error === null ? null : ErrorMessage::clean($instance->error),
            $snapshots[$instance->key()] ?? null,
            $instance->server,
            $siblings[$instance->key()] ?? 0,
        ), $status->instances);
        $unread = $servers === null
            ? count(array_filter($instances, static fn(InstanceState $instance): bool => $instance->snapshot === null))
            : count(array_filter($servers, static fn(array $server): bool => array_filter($server, static fn(Instance $instance): bool => isset($snapshots[$instance->key()])) === []));

        return new ComponentState($status->component, $instances, $unread);
    }

    /**
     * @param list<ComponentStatus> $statuses
     *
     * @return array<string, string>
     */
    private static function notDetected(array $statuses): array
    {
        $reasons = [];

        foreach ($statuses as $status) {
            if ($status->availability === Availability::NotDetected) {
                $reasons[$status->component->value] = ErrorMessage::clean($status->reason ?? Instance::UNAVAILABLE);
            }
        }

        return $reasons;
    }

    /**
     * @param list<ComponentState>  $components
     * @param array<string, string> $notDetected
     */
    private function state(CacheState $cache, array $components, array $notDetected): StackState
    {
        return new StackState($this->node->name, $this->now(), $cache, $components, $notDetected);
    }

    private function failed(\Throwable $e, CacheState $cache): StackState
    {
        $reason = self::describe($e);
        $this->logger->error('The live stack could not be read: {reason}', ['reason' => $reason, 'exception' => $e]);

        return new StackState($this->node->name, $this->now(), $cache, [], [], $reason);
    }

    private function budget(ReadMode $mode): CollectBudget
    {
        return $mode === ReadMode::Page ? CollectBudget::page($this->clock) : CollectBudget::fragment($this->clock);
    }

    private static function describe(\Throwable $e): string
    {
        return ErrorMessage::clean(ErrorMessage::of($e));
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
