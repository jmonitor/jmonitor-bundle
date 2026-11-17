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

        $this->ensureTable();
        $platform = $this->connection->getDatabasePlatform();

        $this->connection->transactional(function (Connection $connection) use ($aggregates, $platform): void {
            foreach (array_chunk($aggregates, self::CHUNK_SIZE) as $chunk) {
                $params = [];
                foreach ($chunk as $aggregate) {
                    $key = mb_scrub($aggregate->key, 'UTF-8');
                    array_push(
                        $params,
                        $aggregate->period->value,
                        $aggregate->type,
                        $aggregate->bucket,
                        AggregatesTable::keyHash($aggregate->key),
                        mb_strcut($key, 0, AggregatesTable::KEY_MAX_LENGTH, 'UTF-8'),
                        $aggregate->count,
                        $aggregate->sum,
                        $aggregate->max,
                    );
                }

                $connection->executeStatement(UpsertSql::for($platform, count($chunk)), $params);
            }
        });
    }

    public function purge(int $now): void
    {
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
