<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Flush;

use Jmonitor\JmonitorBundle\Aggregation\Aggregate;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\HotKeys;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Sql\LabelStore;

final class LabelSync
{
    public function __construct(
        private readonly LabelStore $labels,
        private readonly ?HotKeys $hotKeys = null,
    ) {}

    /**
     * @param list<Aggregate> $aggregates
     *
     * @return array<string, ?string> for the queries of the batch, repeated ones included: the text to store, or null when it is not due or cannot be read
     */
    public function labelsFor(array $aggregates, int $now): array
    {
        $labels = [];
        foreach ($aggregates as $aggregate) {
            $id = match (Family::ofMetric($aggregate->type)) {
                Family::Query => $aggregate->key,
                Family::NPlusOne => self::repeatedQuery($aggregate->key),
                default => null,
            };
            if ($id === null || $id === Aggregator::OTHER || \array_key_exists($id, $labels)) {
                continue;
            }

            $labels[$id] = $this->labels->isDue($id, $now) ? $this->labels->read($id) : null;
        }

        return $labels;
    }

    /**
     * @param array<string, ?string> $labels
     */
    public function confirm(array $labels, int $now): void
    {
        foreach ($labels as $id => $text) {
            if ($text !== null) {
                $this->labels->markStored((string) $id, $now);
            }
        }

        $repeated = array_filter(array_map(self::repeatedQuery(...), $this->hotKeys?->keys(Family::NPlusOne) ?? []));
        $this->labels->prune([...$this->hotKeys?->keys(Family::Query) ?? [], ...array_values($repeated)], $now);
    }

    public function forgetStored(): void
    {
        $this->labels->forgetStored();
    }

    private static function repeatedQuery(string $key): ?string
    {
        $parts = explode(Entry::KEY_SEPARATOR, $key);

        return \count($parts) === 3 ? $parts[2] : null;
    }
}
