<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Mysql\MysqlVariablesCollector as LibraryCollector;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

/**
 * The global variables, without the path of the slow query log on the server.
 */
final readonly class MysqlVariablesCollector implements CollectorInterface
{
    public function __construct(
        private DatabaseAdapterInterface $db,
    ) {}

    /**
     * @return array<string, mixed> the variables by name, and "timestamp", the clock of the server
     *
     * @throws UnreachableServerException
     */
    public function collect(): array
    {
        $variables = new LibraryCollector($this->db);

        try {
            $variables->boot();
            $data = $variables->collect();
        } catch (\Throwable $e) {
            throw MysqlFailure::of($e);
        }

        unset($data['slow_query_log_file']);

        return $data;
    }

    public function getVersion(): int
    {
        return (new LibraryCollector($this->db))->getVersion();
    }

    public function getName(): string
    {
        return 'mysql.variables';
    }
}
