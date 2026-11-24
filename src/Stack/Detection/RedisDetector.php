<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Component;

/**
 * One instance per Redis or Valkey server: those of the configuration, otherwise those of the cache and
 * of the sessions, otherwise the one of REDIS_URL.
 */
final readonly class RedisDetector implements ComponentDetectorInterface
{
    private const NONE = 'No Redis DSN found: set jmonitor.stack.redis.connections, use Redis for a cache pool or the sessions, or set the REDIS_URL environment variable.';

    public function __construct(
        private RedisDsns $dsns,
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
        $instances = $this->found($found);

        if ($instances === [] && \in_array(RedisDsns::ENVIRONMENT, $this->dsns->found(), true)) {
            $instances = $this->found([RedisDsns::ENVIRONMENT]);
        }

        return $instances === [] ? Detection::unavailable(self::NONE) : Detection::instances($instances);
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
                $instances[] = $this->instance($id, $server);
            }
        }

        return $instances;
    }

    /**
     * A found entry that cannot be read, or is not one of a Redis server, is left out: Memcached goes the same way.
     *
     * @param list<string> $entries
     *
     * @return list<Instance>
     */
    private function found(array $entries): array
    {
        $instances = [];

        foreach ($entries as $entry) {
            try {
                $server = $this->dsns->target($entry)->server();
            } catch (\RuntimeException) {
                continue;
            }

            $instances[$server->key] ??= $this->instance($server->address, $server);
        }

        return array_values($instances);
    }

    private function instance(string $id, Server $server): Instance
    {
        return Instance::found(Component::Redis, new CollectorSet(Component::Redis, []), $id, null, $server);
    }
}
