<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Dbal;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

final class KeysTable
{
    public const NAME = 'jmonitor_keys';
    public const VERSION = 2;
    public const VERSION_ROW = 'schema';
    public const LABEL_MAX_LENGTH = 4000;

    public static function definition(): Table
    {
        $table = new Table(self::NAME);
        $table->addColumn('key_hash', Types::STRING, ['length' => 32, 'fixed' => true]);
        $table->addColumn('metric_key', Types::TEXT);
        $table->addColumn('last_seen', Types::BIGINT);
        $table->setPrimaryKey(['key_hash']);
        $table->addOption('charset', 'utf8mb4');
        $table->addOption('collation', 'utf8mb4_unicode_ci');

        return $table;
    }
}
