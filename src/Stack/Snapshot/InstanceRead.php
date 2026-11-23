<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;
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

    /** @var list<array{item: CacheItemInterface, spec: CollectorSpec, value: bool}> value: false for a failure */
    public array $writes = [];

    /** proves the witness is ours, once set */
    public ?string $token = null;

    public bool $ran = false;

    private ?StoredFailure $hold = null;

    /**
     * @param list<CollectorSpec>               $specs
     * @param array<string, CacheItemInterface> $items     keyed by cache key, none without a working cache
     * @param bool                              $busy      another request holds the witness
     * @param array<string, ?string>            $markers   the forget marker in force for the value of each collector, stored with it, keyed by collector name
     * @param ?string                           $forgotten the forget marker of the instance, stored with a failure
     * @param array<string, StoredValue>        $values    the values that may show, keyed by collector name
     * @param array<string, StoredFailure>      $failures  the kept failures, keyed by collector name
     */
    public function __construct(
        public readonly Instance $instance,
        public readonly array $specs,
        public readonly array $items,
        public readonly bool $busy,
        public readonly array $markers,
        public readonly ?string $forgotten,
        public readonly array $values,
        public readonly array $failures,
    ) {}

    public function write(CollectorSpec $spec, string $key, mixed $payload, int $seconds, bool $value): void
    {
        $item = $this->items[$key] ?? null;

        if ($item !== null) {
            $this->writes[] = ['item' => $item->set($payload)->expiresAfter($seconds), 'spec' => $spec, 'value' => $value];
        }
    }

    /**
     * While a collector of a remote instance keeps the failure of a server that could not be reached, the other
     * collectors of the instance do not ask it again, until the latest of these failures expires.
     */
    public function holdOn(CollectorSpec $spec, StoredFailure $failure): void
    {
        if ($spec->shared || $this->instance->component->isNodeLocal() || $failure->kind !== FailureKind::Unreachable) {
            return;
        }

        if ($failure->until > ($this->hold->until ?? \PHP_INT_MIN)) {
            $this->hold = $failure;
        }
    }

    /**
     * A shared collector reads another server: it is never held.
     */
    public function heldBy(CollectorSpec $spec): ?StoredFailure
    {
        return $spec->shared ? null : $this->hold;
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
