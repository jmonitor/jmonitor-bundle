<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Dbal;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

final class AggregatesTable
{
    public const NAME = 'jmonitor_aggregates';
    public const KEY_MAX_LENGTH = 255;

    public static function definition(): Table
    {
        $table = new Table(self::NAME);
        $table->addColumn('period', Types::INTEGER);
        $table->addColumn('metric_type', Types::STRING, ['length' => 64]);
        $table->addColumn('bucket', Types::BIGINT);
        $table->addColumn('key_hash', Types::STRING, ['length' => 32, 'fixed' => true]);
        $table->addColumn('metric_key', Types::STRING, ['length' => self::KEY_MAX_LENGTH]);
        $table->addColumn('entry_count', Types::BIGINT);
        $table->addColumn('value_sum', Types::FLOAT);
        $table->addColumn('value_max', Types::FLOAT);
        $table->setPrimaryKey(['period', 'metric_type', 'bucket', 'key_hash']);

        return $table;
    }

    public static function keyHash(string $key): string
    {
        return hash('xxh128', $key);
    }
}
