<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Mysql\MysqlInformationSchemaCollector;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

/**
 * The size of the data and of the indexes of one database, as information_schema tells it: MySQL 8 caches it up to a day.
 */
final readonly class MysqlSizesCollector implements CollectorInterface
{
    private const TABLE = 'information_schema.TABLES';

    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $database,
    ) {}

    /**
     * @return array{data_length: ?int, index_length: ?int} in bytes, null for a database the user cannot see
     *
     * @throws UnreachableServerException
     * @throws MissingPrerequisiteException the user may not read information_schema.TABLES
     */
    public function collect(): array
    {
        $sizes = new MysqlInformationSchemaCollector($this->db, $this->database);

        try {
            $sizes->boot();
            $weight = $sizes->collect()['data_weight'] ?? [];
        } catch (\Throwable $e) {
            throw MysqlFailure::of($e, self::TABLE);
        }

        return [
            'data_length' => \is_int($weight['data_length'] ?? null) ? $weight['data_length'] : null,
            'index_length' => \is_int($weight['index_length'] ?? null) ? $weight['index_length'] : null,
        ];
    }

    public function getVersion(): int
    {
        return (new MysqlInformationSchemaCollector($this->db, $this->database))->getVersion();
    }

    public function getName(): string
    {
        return 'mysql.sizes';
    }
}
