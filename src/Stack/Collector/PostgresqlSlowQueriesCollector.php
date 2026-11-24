<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Postgresql\PostgresqlSlowQueriesCollector as LibraryCollector;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

/**
 * The statements of one database that take the longest on average, as pg_stat_statements keeps them.
 * It only replaces the values of the statements that read or write rows: any other one may carry a password.
 */
final readonly class PostgresqlSlowQueriesCollector implements CollectorInterface
{
    private const ASKED = 30;
    private const KEPT = 10;

    private const NORMALIZED = ['select', 'insert', 'update', 'delete', 'with'];

    private const HIDDEN = '<insufficient privilege>';

    /**
     * The queries of the collectors of the stack, which would otherwise rank among the slowest:
     * the text kept is cut, so the one of the blocked sessions is told by its column names.
     */
    private const OWN = ['pg_stat_', 'pg_database_size', 'pg_total_relation_size', 'pg_indexes_size', 'pg_settings', 'pg_blocking_pids', 'blocked_query_sample'];

    public function __construct(
        private DatabaseAdapterInterface $db,
    ) {}

    /**
     * @return array{queries: list<array{text: string, executions: int, average_ms: int, max_ms: int, total_ms: int}>}
     *
     * @throws UnreachableServerException
     * @throws MissingPrerequisiteException pg_stat_statements is not installed, or the role may not read it
     */
    public function collect(): array
    {
        $slowQueries = new LibraryCollector($this->db, self::ASKED, 1, 0.0, LibraryCollector::ORDER_BY_AVG_TIME);

        try {
            $rows = $slowQueries->collect()['slow_queries'];
        } catch (\Throwable $e) {
            throw PostgresqlFailure::of($e, PostgresqlFailure::STATEMENTS);
        }

        $queries = [];

        foreach ($rows as $row) {
            if (!\is_array($row) || !\is_string($row['query_sample'] ?? null) || !self::isShown($row['query_sample'])) {
                continue;
            }

            $queries[] = [
                'text' => $row['query_sample'],
                'executions' => self::rounded($row['exec_count'] ?? null),
                'average_ms' => self::rounded($row['avg_time_ms'] ?? null),
                'max_ms' => self::rounded($row['max_time_ms'] ?? null),
                'total_ms' => self::rounded($row['total_time_ms'] ?? null),
            ];

            if (\count($queries) === self::KEPT) {
                break;
            }
        }

        return ['queries' => $queries];
    }

    public function getVersion(): int
    {
        return (new LibraryCollector($this->db))->getVersion();
    }

    public function getName(): string
    {
        return 'postgresql.slow_queries';
    }

    private static function isShown(string $text): bool
    {
        if (!preg_match('/^\s*(\w+)/', $text, $word) || !\in_array(strtolower($word[1]), self::NORMALIZED, true)) {
            return false;
        }

        foreach ([self::HIDDEN, ...self::OWN] as $fragment) {
            if (stripos($text, $fragment) !== false) {
                return false;
            }
        }

        return true;
    }

    private static function rounded(mixed $value): int
    {
        return is_numeric($value) ? (int) round((float) $value) : 0;
    }
}
