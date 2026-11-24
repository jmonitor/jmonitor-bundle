<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\EndOfLifeCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\RedisInfoCollector;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\Utils\UrlFetcher;

/**
 * One instance per Redis or Valkey server: those of the configuration, otherwise those of the cache and
 * of the sessions, otherwise the one of REDIS_URL.
 */
final readonly class RedisDetector implements ComponentDetectorInterface
{
    private const NONE = 'No Redis DSN found: set jmonitor.stack.redis.connections, use Redis for a cache pool or the sessions, or set the REDIS_URL environment variable.';
    private const REFUSED = 'Redis is used by the application, but not read here: %s Name a server to read under jmonitor.stack.redis.connections.';

    public function __construct(
        private RedisDsns $dsns,
        private bool $endOfLife,
        private ?UrlFetcher $endOfLifeFetcher = null,
    ) {}

    public function component(): Component
    {
        return Component::Redis;
    }

    public function detect(): Detection
    {
        $configured = $this->dsns->configured();

        if ($configured !== []) {
            return Detection::instances($this->configured($configured));
        }

        $found = array_values(array_diff($this->dsns->found(), [RedisDsns::ENVIRONMENT]));
        [$instances, $refused] = $this->found($found);

        if ($instances === [] && \in_array(RedisDsns::ENVIRONMENT, $this->dsns->found(), true)) {
            [$instances, $refusedToo] = $this->found([RedisDsns::ENVIRONMENT]);
            $refused ??= $refusedToo;
        }

        if ($instances !== []) {
            return Detection::instances($instances);
        }

        return Detection::unavailable($refused === null ? self::NONE : \sprintf(self::REFUSED, $refused));
    }

    /**
     * @param non-empty-list<string> $ids
     *
     * @return non-empty-list<Instance>
     */
    private function configured(array $ids): array
    {
        $instances = [];
        $servers = [];

        foreach ($ids as $id) {
            try {
                $server = $this->dsns->target($id)->server();
            } catch (\RuntimeException $e) {
                $instances[] = Instance::unavailable(Component::Redis, $e->getMessage(), $id);

                continue;
            }

            if (!isset($servers[$server->key])) {
                $servers[$server->key] = true;
                $instances[] = $this->instance($id, $id, $server);
            }
        }

        return $instances;
    }

    /**
     * A found entry that is not one of Redis, or cannot be read, is left out without a word: Memcached goes the same way.
     *
     * @param list<string> $entries
     *
     * @return array{list<Instance>, ?string} the instances, and why the first Redis DSN that is not read is not
     */
    private function found(array $entries): array
    {
        $instances = [];
        $refused = null;

        foreach ($entries as $entry) {
            try {
                $server = $this->dsns->target($entry)->server();
            } catch (\UnexpectedValueException $e) {
                $refused ??= $e->getMessage();

                continue;
            } catch (\RuntimeException) {
                continue;
            }

            $instances[$server->key] ??= $this->instance($entry, $server->address, $server);
        }

        return [array_values($instances), $refused];
    }

    /**
     * The product is only known once its server is read, so both support lookups are set.
     */
    private function instance(string $entry, string $id, Server $server): Instance
    {
        $link = $this->dsns->link($entry);
        $specs = [new CollectorSpec(new RedisInfoCollector($link), Lifetime::Dynamic, CostClass::Network, trackDelta: true)];

        if ($this->endOfLife) {
            $fetcher = $this->endOfLifeFetcher ?? new UrlFetcher(3);
            $specs[] = new CollectorSpec(new EndOfLifeCollector('redis', $fetcher), Lifetime::Day, CostClass::Slow, shared: true);
            $specs[] = new CollectorSpec(new EndOfLifeCollector('valkey', $fetcher), Lifetime::Day, CostClass::Slow, shared: true);
        }

        return Instance::found(Component::Redis, new CollectorSet(Component::Redis, $specs, $link->release(...)), $id, null, $server);
    }
}
