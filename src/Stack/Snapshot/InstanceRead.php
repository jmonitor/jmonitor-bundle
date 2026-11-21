<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Psr\Cache\CacheItemInterface;

/**
 * What one read found in the cache for an instance, and what it writes back.
 *
 * @internal
 */
final class InstanceRead
{
    /** @var array<string, CollectorResult> keyed by collector name */
    public array $results = [];

    /** @var list<CacheItemInterface> */
    public array $writes = [];

    /** proves the witness is ours, once set */
    public ?string $token = null;

    public bool $ran = false;

    /**
     * @param list<CollectorSpec>               $specs
     * @param array<string, CacheItemInterface> $items    keyed by cache key, none without a working cache
     * @param bool                              $busy     another request holds the witness
     * @param array<string, StoredValue>        $values   the values that may show, keyed by collector name
     * @param array<string, StoredFailure>      $failures the kept failures, keyed by collector name
     */
    public function __construct(
        public readonly Instance $instance,
        public readonly array $specs,
        public readonly array $items,
        public readonly bool $busy,
        public readonly array $values,
        public readonly array $failures,
    ) {}

    public function write(string $key, mixed $payload, int $seconds): void
    {
        $item = $this->items[$key] ?? null;

        if ($item !== null) {
            $this->writes[] = $item->set($payload)->expiresAfter($seconds);
        }
    }

    /**
     * @return array<string, CollectorResult> in the order of the specs
     */
    public function orderedResults(): array
    {
        $results = [];

        foreach ($this->specs as $spec) {
            if (isset($this->results[$spec->name])) {
                $results[$spec->name] = $this->results[$spec->name];
            }
        }

        return $results;
    }
}
