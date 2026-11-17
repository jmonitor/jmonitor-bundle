<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Jmonitor\JmonitorBundle\Aggregation\Aggregate;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;

final class DbalStorage implements StorageInterface
{
    private bool $tableReady = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $retention,
    ) {}

    public function prepare(): void
    {
        $this->assertWritable();
        $this->ensureTable();
    }

    /**
     * @return bool whether the table was created
     */
    public function setUp(): bool
    {
        $this->assertWritable();
        $this->tableReady = false;

        return $this->ensureTable();
    }

    public function store(array $aggregates): void
    {
        if ($aggregates === []) {
            return;
        }

        $this->prepare();
        $rows = self::rows($aggregates);

        try {
            $this->write($rows);
        } catch (TableNotFoundException) {
            $this->tableReady = false;
            $this->ensureTable();
            $this->write($rows);
        }
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

    /** Writes made inside a transaction of the application would be committed or rolled back with it. */
    private function assertNoOpenTransaction(): void
    {
        if ($this->connection->isTransactionActive() || self::nativeTransactionActive($this->connection)) {
            throw new \RuntimeException('The connection has an open transaction.');
        }
    }

    private static function nativeTransactionActive(Connection $connection): bool
    {
        try {
            $native = $connection->getNativeConnection();
        } catch (\LogicException) {
            return false;
        }

        return $native instanceof \PDO && $native->inTransaction();
    }

    /**
     * @param list<list<int|float|string>> $rows
     */
    private function write(array $rows): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->connection->transactional(function (Connection $connection) use ($rows, $platform): void {
            foreach (array_chunk($rows, UpsertSql::maxRows($platform)) as $chunk) {
                $connection->executeStatement(UpsertSql::for($platform, count($chunk)), array_merge(...$chunk));
            }
        });
    }

    private function assertWritable(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (!UpsertSql::supports($platform)) {
            throw new \LogicException(sprintf('Unsupported database platform "%s".', $platform::class));
        }

        $this->assertNoOpenTransaction();
    }

    private function ensureTable(): bool
    {
        if ($this->tableReady) {
            return false;
        }

        $created = false;

        if (!$this->tableExists()) {
            try {
                foreach ($this->connection->getDatabasePlatform()->getCreateTableSQL(AggregatesTable::definition()) as $sql) {
                    $this->connection->executeStatement($sql);
                }
                $created = true;
            } catch (\Throwable $e) {
                if (!$this->tableExists()) {
                    throw $e;
                }
            }
        }

        $this->tableReady = true;

        return $created;
    }

    /** @phpstan-impure */
    private function tableExists(): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1 FROM ' . AggregatesTable::NAME . ' WHERE 1 = 0');
        } catch (TableNotFoundException) {
            return false;
        }

        return true;
    }
}
