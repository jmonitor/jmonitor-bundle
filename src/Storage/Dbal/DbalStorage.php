<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Jmonitor\JmonitorBundle\Aggregation\Aggregate;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;

final class DbalStorage implements StorageInterface
{
    private const CHUNK_SIZE = 500;

    private bool $tableReady = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $retention,
    ) {}

    public function store(array $aggregates): void
    {
        if ($aggregates === []) {
            return;
        }

        $this->assertNoOpenTransaction();
        $this->ensureTable();
        $platform = $this->connection->getDatabasePlatform();

        $rows = self::rows($aggregates);

        $this->connection->transactional(function (Connection $connection) use ($rows, $platform): void {
            foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
                $connection->executeStatement(UpsertSql::for($platform, count($chunk)), array_merge(...$chunk));
            }
        });
    }

    public function purge(int $now): void
    {
        $this->assertNoOpenTransaction();

        foreach (Period::cases() as $period) {
            $threshold = $now - min($period->value, $this->retention) - $period->bucketSeconds();

            try {
                $this->connection->executeStatement(
                    'DELETE FROM ' . AggregatesTable::NAME . ' WHERE period = ? AND bucket < ?',
                    [$period->value, $threshold],
                );
            } catch (TableNotFoundException) {
                return;
            }
        }
    }

    /**
     * Rows follow the primary key order so that concurrent flushes from several servers lock them
     * in the same order.
     *
     * @param list<Aggregate> $aggregates
     *
     * @return list<list<int|float|string>>
     */
    private static function rows(array $aggregates): array
    {
        $rows = [];
        foreach ($aggregates as $aggregate) {
            $rows[] = [
                $aggregate->period->value,
                $aggregate->type,
                $aggregate->bucket,
                AggregatesTable::keyHash($aggregate->key),
                mb_substr(mb_scrub($aggregate->key, 'UTF-8'), 0, AggregatesTable::KEY_MAX_LENGTH, 'UTF-8'),
                $aggregate->count,
                $aggregate->sum,
                $aggregate->max,
            ];
        }

        usort($rows, static fn(array $a, array $b): int
            => $a[0] <=> $b[0] ?: $a[2] <=> $b[2] ?: strcmp($a[1], $b[1]) ?: strcmp($a[3], $b[3]));

        return $rows;
    }

    /**
     * Writing inside a transaction left open by the application would mix both: MySQL commits it
     * implicitly on CREATE TABLE, PostgreSQL aborts it on a failed query, and a later rollback
     * silently loses the drained slots.
     */
    private function assertNoOpenTransaction(): void
    {
        $native = $this->connection->getNativeConnection();

        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            throw new \RuntimeException('The connection has an open transaction.');
        }
    }

    private function ensureTable(): void
    {
        if ($this->tableReady) {
            return;
        }

        try {
            $this->connection->executeQuery('SELECT 1 FROM ' . AggregatesTable::NAME . ' WHERE 1 = 0');
        } catch (TableNotFoundException) {
            try {
                foreach ($this->connection->getDatabasePlatform()->getCreateTableSQL(AggregatesTable::definition()) as $sql) {
                    $this->connection->executeStatement($sql);
                }
            } catch (TableExistsException) {
            }
        }

        $this->tableReady = true;
    }
}
