<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Buffer;

use Jmonitor\JmonitorBundle\Recording\Entry;

interface BufferInterface
{
    public function append(Entry $entry): void;

    /**
     * @param callable(iterable<Entry>): void $consumer
     *
     * @return int number of entries handed to the consumer
     */
    public function drain(callable $consumer): int;

    /**
     * Whether the slot being written is filling up fast enough that recorders should shed detail.
     */
    public function isUnderPressure(): bool;
}
