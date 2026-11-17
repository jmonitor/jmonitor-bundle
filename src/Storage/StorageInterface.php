<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage;

use Jmonitor\JmonitorBundle\Aggregation\Aggregate;

interface StorageInterface
{
    /** Throws when the storage cannot write now. */
    public function prepare(): void;

    /** @param list<Aggregate> $aggregates */
    public function store(array $aggregates): void;

    public function purge(int $now): void;
}
