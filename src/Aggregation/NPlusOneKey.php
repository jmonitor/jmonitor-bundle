<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Aggregation;

use Jmonitor\JmonitorBundle\Recording\Entry;

final class NPlusOneKey
{
    public function __construct(
        public readonly string $unitType,
        public readonly string $unitKey,
        public readonly string $queryId,
    ) {}

    /**
     * @return self|null null when the key does not split into exactly a unit type, a unit key and a query id
     */
    public static function parse(string $key): ?self
    {
        $parts = explode(Entry::KEY_SEPARATOR, $key);

        return \count($parts) === 3 ? new self($parts[0], $parts[1], $parts[2]) : null;
    }

    public function toString(): string
    {
        return $this->unitType . Entry::KEY_SEPARATOR . $this->unitKey . Entry::KEY_SEPARATOR . $this->queryId;
    }
}
