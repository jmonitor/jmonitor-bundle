<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Dbal;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

final class UpsertSql
{
    /** A label weighs up to 16 KB: a hundred of them stay below the smallest default MySQL packet size. */
    public const MAX_KEY_ROWS = 100;

    private const INSERT = 'INSERT INTO ' . AggregatesTable::NAME
        . ' (period, metric_type, bucket, key_hash, entry_count, value_sum, value_max) VALUES ';

    private const INSERT_KEYS = 'INSERT INTO ' . KeysTable::NAME . ' (key_hash, metric_key, last_seen) VALUES ';

    public static function supports(AbstractPlatform $platform): bool
    {
        return $platform instanceof AbstractMySQLPlatform
            || $platform instanceof PostgreSQLPlatform
            || $platform instanceof SQLitePlatform;
    }

    /** SQLite before 3.32 accepts at most 999 parameters per statement. */
    public static function maxRows(AbstractPlatform $platform): int
    {
        return $platform instanceof SQLitePlatform ? 100 : 500;
    }

    /** One positional parameter per column and per row, in column order. */
    public static function for(AbstractPlatform $platform, int $rows): string
    {
        $values = implode(', ', array_fill(0, $rows, '(?, ?, ?, ?, ?, ?, ?)'));
        $table = AggregatesTable::NAME;

        if ($platform instanceof AbstractMySQLPlatform) {
            return self::INSERT . $values . ' ON DUPLICATE KEY UPDATE'
                . ' entry_count = entry_count + VALUES(entry_count),'
                . ' value_sum = value_sum + VALUES(value_sum),'
                . ' value_max = GREATEST(value_max, VALUES(value_max))';
        }

        $conflict = ' ON CONFLICT (period, bucket, metric_type, key_hash) DO UPDATE SET'
            . ' entry_count = ' . $table . '.entry_count + excluded.entry_count,'
            . ' value_sum = ' . $table . '.value_sum + excluded.value_sum,';

        if ($platform instanceof PostgreSQLPlatform) {
            return self::INSERT . $values . $conflict
                . ' value_max = GREATEST(' . $table . '.value_max, excluded.value_max)';
        }

        if ($platform instanceof SQLitePlatform) {
            return self::INSERT . $values . $conflict
                . ' value_max = MAX(' . $table . '.value_max, excluded.value_max)';
        }

        throw new \LogicException(sprintf('Unsupported database platform "%s".', $platform::class));
    }

    /** Parameters: key_hash, metric_key, last_seen per row. A known key keeps its label. */
    public static function forKeys(AbstractPlatform $platform, int $rows): string
    {
        $values = implode(', ', array_fill(0, $rows, '(?, ?, ?)'));

        if ($platform instanceof AbstractMySQLPlatform) {
            return self::INSERT_KEYS . $values . ' ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)';
        }

        if ($platform instanceof PostgreSQLPlatform || $platform instanceof SQLitePlatform) {
            return self::INSERT_KEYS . $values . ' ON CONFLICT (key_hash) DO UPDATE SET last_seen = excluded.last_seen';
        }

        throw new \LogicException(sprintf('Unsupported database platform "%s".', $platform::class));
    }

    /** Parameters: last_seen, then the hash of each key. */
    public static function touchKeys(int $keys): string
    {
        return 'UPDATE ' . KeysTable::NAME . ' SET last_seen = ? WHERE key_hash IN ('
            . implode(', ', array_fill(0, $keys, '?')) . ')';
    }
}
