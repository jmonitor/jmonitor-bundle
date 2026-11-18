<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Flush;

use Jmonitor\JmonitorBundle\Aggregation\Aggregate;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\HotKeys;
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
     * @return array<string, ?string> for the keys of the query family: the text to store, or null when it is not due or cannot be read
     */
    public function labelsFor(array $aggregates, int $now): array
    {
        $labels = [];
        foreach ($aggregates as $aggregate) {
            $id = $aggregate->key;
            if ($id === Aggregator::OTHER || \array_key_exists($id, $labels) || Family::ofMetric($aggregate->type) !== Family::Query) {
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

        $this->labels->prune($this->hotKeys?->keys(Family::Query) ?? [], $now);
    }
}
