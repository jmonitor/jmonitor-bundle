<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Read;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;

final class StackState
{
    /**
     * @param list<ComponentState>  $components  the available ones, in the order of Component::cases()
     * @param array<string, string> $notDetected component value => why it was not found
     */
    public function __construct(
        public readonly ?string $node,
        public readonly int $readAt,
        public readonly CacheState $cache,
        public readonly array $components,
        public readonly array $notDetected,
    ) {}

    public function component(Component $component): ?ComponentState
    {
        foreach ($this->components as $state) {
            if ($state->component === $component) {
                return $state;
            }
        }

        return null;
    }

    /**
     * Whether a read instance still waits for a value; an instance listed without being read does not,
     * nor does one that a cache out of order keeps pending for good.
     */
    public function isReading(): bool
    {
        if ($this->cache === CacheState::OutOfOrder) {
            return false;
        }

        foreach ($this->components as $component) {
            foreach ($component->instances as $instance) {
                if ($instance->snapshot?->isReading() === true) {
                    return true;
                }
            }
        }

        return false;
    }
}
