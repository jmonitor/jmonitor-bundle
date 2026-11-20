<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Dbal;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Jmonitor\JmonitorBundle\Aggregation\Family;

final class AggregatesTable
{
    public const NAME = 'jmonitor_aggregates';

    /** Metric types are stored in byte order on every platform: familyBounds() relies on it. */
    public static function definition(AbstractPlatform $platform): Table
    {
        $table = new Table(self::NAME);
        $table->addColumn('period', Types::INTEGER);
        $metricType = $table->addColumn('metric_type', Types::STRING, ['length' => 64]);
        if ($platform instanceof PostgreSQLPlatform) {
            $metricType->setPlatformOption('collation', 'C');
        }
        $table->addColumn('bucket', Types::BIGINT);
        $table->addColumn('key_hash', Types::STRING, ['length' => 32, 'fixed' => true]);
        $table->addColumn('entry_count', Types::BIGINT);
        $table->addColumn('value_sum', Types::FLOAT);
        $table->addColumn('value_max', Types::FLOAT);
        $table->setPrimaryKey(['period', 'metric_type', 'bucket', 'key_hash']);
        $table->addOption('charset', 'utf8mb4');
        $table->addOption('collation', 'utf8mb4_bin');

        return $table;
    }

    /** @return array{string, string} the first metric type a family could hold, and the first one past it */
    public static function familyBounds(Family $family): array
    {
        return [$family->value . '.', $family->value . '/'];
    }

    public static function keyHash(string $key): string
    {
        return hash('xxh128', $key);
    }
}
