<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Postgresql\PostgresqlDatabaseCollector as LibraryCollector;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

/**
 * The size of the whole database, and the rows and sizes of the tables of one of its schemas.
 */
final readonly class PostgresqlDatabaseCollector implements CollectorInterface
{
    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $schema,
    ) {}

    /**
     * @return array<string, mixed> "schema", "db_size" in bytes, and "tables"
     *
     * @throws UnreachableServerException
     */
    public function collect(): array
    {
        $database = new LibraryCollector($this->db, $this->schema);

        try {
            $database->boot();

            return $database->collect();
        } catch (\Throwable $e) {
            throw PostgresqlFailure::of($e);
        }
    }

    public function getVersion(): int
    {
        return (new LibraryCollector($this->db, $this->schema))->getVersion();
    }

    public function getName(): string
    {
        return 'postgresql.database';
    }
}
