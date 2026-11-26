<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\ParameterType;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Storage\Read\Totals;

final class DbalReader implements ReaderInterface
{
    private const SERIES_BUCKETS = 60;
    private const LABELS_PER_QUERY = 500;
    private const SORT_EXPRESSIONS = [
        'count' => 'SUM(CASE WHEN metric_type = ? THEN entry_count ELSE 0 END)',
        'sum' => 'SUM(CASE WHEN metric_type = ? THEN value_sum ELSE 0 END)',
        'max' => 'MAX(CASE WHEN metric_type = ? THEN value_max ELSE 0 END)',
        'avg' => 'COALESCE(SUM(CASE WHEN metric_type = ? THEN value_sum ELSE 0 END)'
            . ' / NULLIF(SUM(CASE WHEN metric_type = ? THEN entry_count ELSE 0 END), 0), 0)',
    ];

    public function __construct(private readonly Connection $connection) {}

    public function read(ReportQuery $query): Report
    {
        $otherHash = AggregatesTable::keyHash(Aggregator::OTHER);

        try {
            if ($query->family->isOpen()) {
                $hashes = $this->topKeys($query);
                $metrics = $this->metrics($query, [...$hashes, $otherHash]);
                [$totals, $keyCount] = $this->familyTotals($query);
            } else {
                $metrics = $this->metrics($query);
                $hashes = array_values(array_diff(array_map('strval', array_keys($metrics)), [$otherHash]));
                $keyCount = \count($hashes);
                $totals = self::sumKeys($metrics);
            }

            $series = $query->seriesMetric === null ? [] : $this->series($query->period, $query->now, $query->seriesMetric);
        } catch (TableNotFoundException) {
            return new Report();
        }

        $labels = $this->labelsByHash(array_map('strval', array_keys($metrics)));
        $rows = [];
        foreach ($hashes as $hash) {
            $rows[] = new ReportRow($hash, $labels[$hash] ?? $hash, $labels[$hash] ?? null, $metrics[$hash] ?? []);
        }
        $other = isset($metrics[$otherHash]) ? new ReportRow($otherHash, Aggregator::OTHER, $labels[$otherHash] ?? null, $metrics[$otherHash]) : null;

        return new Report($rows, $other, $keyCount, $series, $totals);
    }

    public function labels(array $keys): array
    {
        $hashes = [];
        foreach ($keys as $key) {
            $hashes[AggregatesTable::keyHash($key)] = $key;
        }

        $labels = [];
        foreach ($this->labelsByHash(array_map('strval', array_keys($hashes))) as $hash => $label) {
            $labels[$hashes[$hash]] = $label;
        }

        return $labels;
    }

    public function totals(Period $period, int $now, array $metricTypes, array $keyed = []): Totals
    {
        // @phpstan-ignore identical.alwaysFalse (the type is only documented)
        if ($metricTypes === []) {
            throw new \InvalidArgumentException('The totals need at least one metric type.');
        }

        $metricTypes = array_values(array_unique($metricTypes));
        $keyed = array_values(array_unique($keyed));

        if (array_diff($keyed, $metricTypes) !== []) {
            throw new \InvalidArgumentException('Only an asked metric type can be read key by key.');
        }

        [$periodSeconds, $since] = self::window($period, $now);
        $ordinary = array_values(array_diff($metricTypes, $keyed));

        if ($keyed === []) {
            $sql = self::totalsSelect('metric_type', \count($ordinary), 'metric_type');
            $params = [$periodSeconds, $since, ...$ordinary];
        } else {
            $sql = self::totalsSelect('metric_type, key_hash', \count($keyed), 'metric_type, key_hash');
            $params = [$periodSeconds, $since, ...$keyed];

            if ($ordinary !== []) {
                $sql = self::totalsSelect("metric_type, '' AS key_hash", \count($ordinary), 'metric_type') . ' UNION ALL ' . $sql;
                $params = [$periodSeconds, $since, ...$ordinary, ...$params];
            }
        }

        try {
            $rows = $this->connection->fetchAllAssociative($sql, $params);
        } catch (TableNotFoundException) {
            return new Totals();
        }

        $byKey = array_flip($keyed);
        $otherHash = AggregatesTable::keyHash(Aggregator::OTHER);
        $types = [];
        $keys = [];

        foreach ($rows as $row) {
            $type = (string) $row['metric_type'];
            $value = new MetricValue((int) $row['entry_count'], (float) $row['value_sum'], (float) $row['value_max']);

            if (!isset($byKey[$type])) {
                $types[$type] = $value;

                continue;
            }

            $types[$type] = self::add($types[$type] ?? null, $value);

            if ((string) $row['key_hash'] !== $otherHash) {
                $keys[$type][(string) $row['key_hash']] = $value;
            }
        }

        return new Totals($types, $keys);
    }

    private static function totalsSelect(string $columns, int $typeCount, string $groupBy): string
    {
        return 'SELECT ' . $columns . ', SUM(entry_count) AS entry_count, SUM(value_sum) AS value_sum, MAX(value_max) AS value_max'
            . ' FROM ' . AggregatesTable::NAME . ' WHERE period = ? AND bucket > ? AND metric_type IN ('
            . implode(', ', array_fill(0, $typeCount, '?')) . ') GROUP BY ' . $groupBy;
    }

    /**
     * @return list<string> the hashes of the first keys of the weight metric, in the requested order on the sort
     *                      metric, a key without it sorting as zero
     */
    private function topKeys(ReportQuery $query): array
    {
        [$period, $since] = self::window($query->period, $query->now);
        $sortExpression = self::SORT_EXPRESSIONS[$query->sortField];
        $weightMetric = $query->family->weightMetric();
        $params = [
            $period,
            $since,
            $weightMetric,
            $query->sortMetric,
            AggregatesTable::keyHash(Aggregator::OTHER),
            $weightMetric,
            ...array_fill(0, substr_count($sortExpression, '?'), $query->sortMetric),
            $query->limit,
        ];

        return array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT key_hash FROM ' . AggregatesTable::NAME
            . ' WHERE period = ? AND bucket > ? AND metric_type IN (?, ?) AND key_hash <> ? GROUP BY key_hash'
            . ' HAVING SUM(CASE WHEN metric_type = ? THEN 1 ELSE 0 END) > 0'
            . ' ORDER BY ' . $sortExpression . ($query->descending ? ' DESC' : ' ASC') . ', key_hash LIMIT ?',
            $params,
            [\count($params) - 1 => ParameterType::INTEGER],
        ));
    }

    /**
     * @param list<string>|null $hashes null for every key of the family
     *
     * @return array<string, array<string, MetricValue>> hash => metric type => value
     */
    private function metrics(ReportQuery $query, ?array $hashes = null): array
    {
        [$period, $since] = self::window($query->period, $query->now);
        $sql = 'SELECT key_hash, metric_type, SUM(entry_count) AS entry_count, SUM(value_sum) AS value_sum,'
            . ' MAX(value_max) AS value_max FROM ' . AggregatesTable::NAME
            . ' WHERE period = ? AND bucket > ? AND '
            . ($query->metrics === [] ? 'metric_type >= ? AND metric_type < ?' : 'metric_type IN (' . implode(', ', array_fill(0, \count($query->metrics), '?')) . ')');
        $params = [$period, $since, ...($query->metrics === [] ? AggregatesTable::familyBounds($query->family) : $query->metrics)];

        if ($hashes !== null) {
            $sql .= ' AND key_hash IN (' . implode(', ', array_fill(0, \count($hashes), '?')) . ')';
            array_push($params, ...$hashes);
        }

        $metrics = [];
        foreach ($this->connection->fetchAllAssociative($sql . ' GROUP BY key_hash, metric_type', $params) as $row) {
            $metrics[(string) $row['key_hash']][(string) $row['metric_type']] = new MetricValue(
                (int) $row['entry_count'],
                (float) $row['value_sum'],
                (float) $row['value_max'],
            );
        }

        return $metrics;
    }

    /**
     * @return array{array<string, MetricValue>, int} metric type => total over every key, and the number of keys
     *                                                of the weight metric
     */
    private function familyTotals(ReportQuery $query): array
    {
        [$period, $since] = self::window($query->period, $query->now);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT metric_type, SUM(entry_count) AS entry_count, SUM(value_sum) AS value_sum, MAX(value_max) AS value_max,'
            . ' (SELECT COUNT(DISTINCT key_hash) FROM ' . AggregatesTable::NAME
            . ' WHERE period = ? AND bucket > ? AND metric_type = ? AND key_hash <> ?) AS key_count FROM ' . AggregatesTable::NAME
            . ' WHERE period = ? AND bucket > ? AND metric_type >= ? AND metric_type < ? GROUP BY metric_type',
            [$period, $since, $query->family->weightMetric(), AggregatesTable::keyHash(Aggregator::OTHER), $period, $since, ...AggregatesTable::familyBounds($query->family)],
        );

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['metric_type']] = new MetricValue((int) $row['entry_count'], (float) $row['value_sum'], (float) $row['value_max']);
        }

        return [$totals, (int) ($rows[0]['key_count'] ?? 0)];
    }

    /**
     * @param array<string, array<string, MetricValue>> $metrics hash => metric type => value
     *
     * @return array<string, MetricValue> metric type => total over every key
     */
    private static function sumKeys(array $metrics): array
    {
        $totals = [];
        foreach ($metrics as $values) {
            foreach ($values as $type => $value) {
                $totals[(string) $type] = self::add($totals[(string) $type] ?? null, $value);
            }
        }

        return $totals;
    }

    private static function add(?MetricValue $total, MetricValue $value): MetricValue
    {
        return $total === null ? $value : new MetricValue($total->count + $value->count, $total->sum + $value->sum, max($total->max, $value->max));
    }

    /** @return list<int> 60 values, oldest first */
    private function series(Period $period, int $now, string $metricType): array
    {
        $size = $period->bucketSeconds();
        $current = $period->bucketFor($now);
        $series = array_fill(0, self::SERIES_BUCKETS, 0);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT bucket, SUM(entry_count) AS entry_count FROM ' . AggregatesTable::NAME
            . ' WHERE period = ? AND metric_type = ? AND bucket > ? GROUP BY bucket',
            [$period->value, $metricType, $current - $period->value],
        );

        foreach ($rows as $row) {
            $index = self::SERIES_BUCKETS - 1 - intdiv($current - (int) $row['bucket'], $size);
            if (isset($series[$index])) {
                $series[$index] += (int) $row['entry_count'];
            }
        }

        return $series;
    }

    /** @return array{int, int} the period and the last bucket before its window */
    private static function window(Period $period, int $now): array
    {
        return [$period->value, $period->bucketFor($now) - $period->value];
    }

    /**
     * @param list<string> $hashes
     *
     * @return array<string, string> hash => label, a key stored without its text yet being left out
     */
    private function labelsByHash(array $hashes): array
    {
        $labels = [];

        try {
            foreach (array_chunk($hashes, self::LABELS_PER_QUERY) as $chunk) {
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT key_hash, metric_key FROM ' . KeysTable::NAME
                    . ' WHERE key_hash IN (' . implode(', ', array_fill(0, \count($chunk), '?')) . ')',
                    $chunk,
                );
                foreach ($rows as $row) {
                    if ((string) $row['metric_key'] !== '') {
                        $labels[(string) $row['key_hash']] = (string) $row['metric_key'];
                    }
                }
            }
        } catch (TableNotFoundException) {
        }

        return $labels;
    }
}
