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

final class DbalReader implements ReaderInterface
{
    private const SERIES_BUCKETS = 60;
    private const LABELS_PER_QUERY = 500;
    private const SORT_EXPRESSIONS = [
        'count' => 'SUM(entry_count)',
        'sum' => 'SUM(value_sum)',
        'max' => 'MAX(value_max)',
        'avg' => 'SUM(value_sum) / NULLIF(SUM(entry_count), 0)',
    ];

    public function __construct(private readonly Connection $connection) {}

    public function read(ReportQuery $query): Report
    {
        $otherHash = AggregatesTable::keyHash(Aggregator::OTHER);

        try {
            if ($query->family->isOpen()) {
                $hashes = $this->topKeys($query);
                $metrics = $this->metrics($query, [...$hashes, $otherHash]);
                $keyCount = $this->keyCount($query);
            } else {
                $metrics = $this->metrics($query);
                $hashes = array_values(array_diff(array_map('strval', array_keys($metrics)), [$otherHash]));
                $keyCount = \count($hashes);
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

        return new Report($rows, $other, $keyCount, $series);
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

    /** @return list<string> the hashes of the first keys on the sort metric, in the requested order */
    private function topKeys(ReportQuery $query): array
    {
        [$period, $since] = self::window($query);

        return array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT key_hash FROM ' . AggregatesTable::NAME
            . ' WHERE period = ? AND bucket > ? AND metric_type = ? AND key_hash <> ? GROUP BY key_hash'
            . ' ORDER BY ' . self::SORT_EXPRESSIONS[$query->sortField] . ($query->descending ? ' DESC' : ' ASC') . ', key_hash LIMIT ?',
            [$period, $since, $query->sortMetric, AggregatesTable::keyHash(Aggregator::OTHER), $query->limit],
            [4 => ParameterType::INTEGER],
        ));
    }

    private function keyCount(ReportQuery $query): int
    {
        [$period, $since] = self::window($query);

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT key_hash) FROM ' . AggregatesTable::NAME
            . ' WHERE period = ? AND bucket > ? AND metric_type = ? AND key_hash <> ?',
            [$period, $since, $query->sortMetric, AggregatesTable::keyHash(Aggregator::OTHER)],
        );
    }

    /**
     * @param list<string>|null $hashes null for every key of the family
     *
     * @return array<string, array<string, MetricValue>> hash => metric type => value
     */
    private function metrics(ReportQuery $query, ?array $hashes = null): array
    {
        [$period, $since] = self::window($query);
        $sql = 'SELECT key_hash, metric_type, SUM(entry_count) AS entry_count, SUM(value_sum) AS value_sum,'
            . ' MAX(value_max) AS value_max FROM ' . AggregatesTable::NAME
            . ' WHERE period = ? AND bucket > ? AND metric_type LIKE ?';
        $params = [$period, $since, $query->family->value . '.%'];

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
    private static function window(ReportQuery $query): array
    {
        return [$query->period->value, $query->period->bucketFor($query->now) - $query->period->value];
    }

    /**
     * @param list<string> $hashes
     *
     * @return array<string, string> hash => label
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
                    $labels[(string) $row['key_hash']] = (string) $row['metric_key'];
                }
            }
        } catch (TableNotFoundException) {
        }

        return $labels;
    }
}
