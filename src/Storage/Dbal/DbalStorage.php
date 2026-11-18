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
    private const KEY_GRACE_SECONDS = 86400;

    private bool $schemaReady = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $retention,
    ) {}

    public function prepare(): void
    {
        $this->assertWritable();
        $this->ensureSchema();
    }

    public function setUp(): SetupResult
    {
        $this->assertWritable();
        $this->schemaReady = false;

        return $this->ensureSchema();
    }

    public function store(array $aggregates, int $now, array $labels = []): void
    {
        if ($aggregates === []) {
            return;
        }

        $this->prepare();
        $rows = self::rows($aggregates);
        $keyBatches = self::keyBatches($aggregates, $labels);

        try {
            $this->write($rows, $keyBatches, $now);
        } catch (TableNotFoundException) {
            $this->schemaReady = false;
            $this->ensureSchema();
            $this->write($rows, $keyBatches, $now);
        }
    }

    public function purge(int $now): void
    {
        $this->assertNoOpenTransaction();

        try {
            foreach (Period::cases() as $period) {
                $this->connection->executeStatement(
                    'DELETE FROM ' . AggregatesTable::NAME . ' WHERE period = ? AND bucket < ?',
                    [$period->value, $now - min($period->value, $this->retention) - $period->bucketSeconds()],
                );
            }

            $this->connection->executeStatement(
                'DELETE FROM ' . KeysTable::NAME . ' WHERE last_seen < ? AND key_hash <> ?',
                [$now - $this->retention - self::KEY_GRACE_SECONDS, KeysTable::VERSION_ROW],
            );
        } catch (TableNotFoundException) {
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
     * Batches follow the hash order across inserts and refreshes alike, for the same reason as the rows.
     *
     * @param list<Aggregate>        $aggregates
     * @param array<string, ?string> $labels
     *
     * @return list<array<string, ?string>> hash => label to insert, or null to only refresh its last sight
     */
    private static function keyBatches(array $aggregates, array $labels): array
    {
        $keys = [];
        foreach ($aggregates as $aggregate) {
            $hash = AggregatesTable::keyHash($aggregate->key);
            if (\array_key_exists($hash, $keys)) {
                continue;
            }

            $label = \array_key_exists($aggregate->key, $labels) ? $labels[$aggregate->key] : $aggregate->key;
            $keys[$hash] = $label === null ? null : mb_substr(mb_scrub($label, 'UTF-8'), 0, KeysTable::LABEL_MAX_LENGTH, 'UTF-8');
        }
        ksort($keys, \SORT_STRING);

        $batches = [];
        $batch = [];
        foreach ($keys as $hash => $label) {
            if ($batch !== [] && (\count($batch) === UpsertSql::MAX_KEY_ROWS || (end($batch) === null) !== ($label === null))) {
                $batches[] = $batch;
                $batch = [];
            }
            $batch[(string) $hash] = $label;
        }
        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
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
     * @param list<list<int|float|string>>  $rows
     * @param list<array<string, ?string>> $keyBatches
     */
    private function write(array $rows, array $keyBatches, int $now): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->connection->transactional(function (Connection $connection) use ($rows, $keyBatches, $now, $platform): void {
            foreach (array_chunk($rows, UpsertSql::maxRows($platform)) as $chunk) {
                $connection->executeStatement(UpsertSql::for($platform, \count($chunk)), array_merge(...$chunk));
            }

            foreach ($keyBatches as $batch) {
                if (end($batch) === null) {
                    $connection->executeStatement(UpsertSql::touchKeys(\count($batch)), [$now, ...array_map('strval', array_keys($batch))]);

                    continue;
                }

                $params = [];
                foreach ($batch as $hash => $label) {
                    array_push($params, (string) $hash, $label, $now);
                }
                $connection->executeStatement(UpsertSql::forKeys($platform, \count($batch)), $params);
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

    private function ensureSchema(): SetupResult
    {
        if ($this->schemaReady) {
            return SetupResult::Unchanged;
        }

        $version = $this->version();
        self::assertNotNewer($version);

        if ($version === KeysTable::VERSION) {
            $this->schemaReady = true;

            return SetupResult::Unchanged;
        }

        $keysExist = $version !== null;
        $aggregatesExist = $this->tableExists(AggregatesTable::NAME);

        try {
            $this->install($aggregatesExist, $keysExist);
        } catch (\Throwable $e) {
            if (!$this->installedByAnotherServer()) {
                throw $e;
            }

            $this->schemaReady = true;

            return SetupResult::Unchanged;
        }

        $this->schemaReady = true;

        return $aggregatesExist || $keysExist ? SetupResult::Upgraded : SetupResult::Created;
    }

    private function install(bool $aggregatesExist, bool $keysExist): void
    {
        foreach ([AggregatesTable::NAME => $aggregatesExist, KeysTable::NAME => $keysExist] as $name => $exists) {
            if ($exists) {
                $this->connection->executeStatement('DROP TABLE ' . $name);
            }
        }

        $platform = $this->connection->getDatabasePlatform();
        foreach ([AggregatesTable::definition(), KeysTable::definition()] as $table) {
            foreach ($platform->getCreateTableSQL($table) as $sql) {
                $this->connection->executeStatement($sql);
            }
        }

        $this->connection->insert(KeysTable::NAME, [
            'key_hash' => KeysTable::VERSION_ROW,
            'metric_key' => (string) KeysTable::VERSION,
            'last_seen' => 0,
        ]);
    }

    /** Both tables present without a newer version: another server is installing them or just did. */
    private function installedByAnotherServer(): bool
    {
        try {
            $version = $this->version();
            $aggregatesExist = $this->tableExists(AggregatesTable::NAME);
        } catch (\Throwable) {
            return false;
        }

        self::assertNotNewer($version);

        return $version !== null && $aggregatesExist;
    }

    /**
     * @return int|null null without the keys table, 0 without its version row
     *
     * @phpstan-impure
     */
    private function version(): ?int
    {
        try {
            $version = $this->connection->fetchOne(
                'SELECT metric_key FROM ' . KeysTable::NAME . " WHERE key_hash = '" . KeysTable::VERSION_ROW . "'",
            );
        } catch (TableNotFoundException) {
            return null;
        }

        return \is_scalar($version) && ctype_digit((string) $version) ? (int) $version : 0;
    }

    private static function assertNotNewer(?int $version): void
    {
        if ($version > KeysTable::VERSION) {
            throw new \RuntimeException('The JMonitor tables were created by a newer version of the bundle.');
        }
    }

    /** @phpstan-impure */
    private function tableExists(string $name): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1 FROM ' . $name . ' WHERE 1 = 0');
        } catch (TableNotFoundException) {
            return false;
        }

        return true;
    }
}
