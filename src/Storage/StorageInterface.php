<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage;

use Jmonitor\JmonitorBundle\Aggregation\Aggregate;

interface StorageInterface
{
    /**
     * Throws when the storage cannot write now.
     *
     * @return bool true on the first call since the storage installed its tables, which then hold no label stored before
     */
    public function prepare(): bool;

    /**
     * @param list<Aggregate>        $aggregates
     * @param array<string, ?string> $labels     key => label to write, or null to only refresh its last sight; a key absent from the map is its
     *                                           own label, a key of the map without aggregate is written only with a label
     */
    public function store(array $aggregates, int $now, array $labels = []): void;

    public function purge(int $now): void;
}
