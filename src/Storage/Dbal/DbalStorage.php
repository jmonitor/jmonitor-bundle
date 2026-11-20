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
    private bool $installed = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $retention,
    ) {}

    public function prepare(): bool
    {
        $this->assertWritable();
        $this->ensureSchema();
        $installed = $this->installed;
        $this->installed = false;

        return $installed;
    }

    public function setUp(): SetupResult
    {
        $this->assertWritable();
        $this->schemaReady = false;

        return $this->ensureSchema(checkAggregates: true);
    }

    public function store(array $aggregates, int $now, array $labels = []): void
    {
        if ($aggregates === []) {
            return;
        }

        $this->assertWritable();
        $this->ensureSchema();
        $rows = self::rows($aggregates);
        $keys = self::keys($aggregates, $labels);

        try {
            $this->write($rows, $keys, $now);
        } catch (TableNotFoundException) {
            $this->schemaReady = false;
            $this->ensureSchema(checkAggregates: true);
            $this->write($rows, $keys, $now);
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
            => $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]) ?: $a[2] <=> $b[2] ?: strcmp($a[3], $b[3]));

        return $rows;
    }

    /**
     * Keys follow the hash order, for the same reason as the rows.
     *
     * @param list<Aggregate>        $aggregates
     * @param array<string, ?string> $labels
     *
     * @return array<string, string> hash => label, empty when there is no text to store
     */
    private static function keys(array $aggregates, array $labels): array
    {
        $keys = [];
        foreach ($aggregates as $aggregate) {
            $hash = AggregatesTable::keyHash($aggregate->key);
            if (\array_key_exists($hash, $keys)) {
                continue;
            }

            $label = \array_key_exists($aggregate->key, $labels) ? $labels[$aggregate->key] : $aggregate->key;
            $keys[$hash] = $label === null ? '' : self::fit($label);
        }
        foreach ($labels as $key => $label) {
            $hash = AggregatesTable::keyHash((string) $key);
            if ($label !== null && !\array_key_exists($hash, $keys)) {
                $keys[$hash] = self::fit($label);
            }
        }
        ksort($keys, \SORT_STRING);

        return $keys;
    }

    private static function fit(string $label): string
    {
        return mb_substr(mb_scrub($label, 'UTF-8'), 0, KeysTable::LABEL_MAX_LENGTH, 'UTF-8');
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
     * @param array<string, string>        $keys
     */
    private function write(array $rows, array $keys, int $now): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->connection->transactional(function (Connection $connection) use ($rows, $keys, $now, $platform): void {
            foreach (array_chunk($rows, UpsertSql::maxRows($platform)) as $chunk) {
                $connection->executeStatement(UpsertSql::for($platform, \count($chunk)), array_merge(...$chunk));
            }

            foreach (array_chunk($keys, UpsertSql::MAX_KEY_ROWS, true) as $chunk) {
                $params = [];
                foreach ($chunk as $hash => $label) {
                    array_push($params, (string) $hash, $label, $now);
                }
                $connection->executeStatement(UpsertSql::forKeys($platform, \count($chunk)), $params);
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

    /** @param bool $checkAggregates also verify the aggregates table behind a current version row, at the cost of one query */
    private function ensureSchema(bool $checkAggregates = false): SetupResult
    {
        if ($this->schemaReady) {
            return SetupResult::Unchanged;
        }

        $version = $this->version();
        self::assertNotNewer($version);
        $current = $version === KeysTable::VERSION;

        if ($current && !$checkAggregates) {
            $this->schemaReady = true;

            return SetupResult::Unchanged;
        }

        $keysExist = $version !== null;
        $aggregatesExist = $this->tableExists(AggregatesTable::NAME);

        if ($current && $aggregatesExist) {
            $this->schemaReady = true;

            return SetupResult::Unchanged;
        }

        try {
            $this->install($aggregatesExist, $keysExist);
        } catch (\Throwable $e) {
            if (!$this->installedByAnotherServer()) {
                throw $e;
            }

            $this->schemaReady = true;
            $this->installed = true;

            return SetupResult::Unchanged;
        }

        $this->schemaReady = true;
        $this->installed = true;

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

    /** Another server installed both tables, or created them and has yet to insert the version row. */
    private function installedByAnotherServer(): bool
    {
        try {
            $version = $this->version();
            $aggregatesExist = $this->tableExists(AggregatesTable::NAME);
        } catch (\Throwable) {
            return false;
        }

        self::assertNotNewer($version);

        return $aggregatesExist && ($version === KeysTable::VERSION || $version === 0);
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
