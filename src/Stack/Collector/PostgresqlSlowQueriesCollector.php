<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Postgresql\PostgresqlSlowQueriesCollector as LibraryCollector;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlDialect;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlNormalizer;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

/**
 * The statements of one database that take the longest on average, as pg_stat_statements keeps them.
 * Only the statements that read or write rows are kept, and their text is normalized again: pg_stat_statements
 * keeps the comments, and the values of a statement whose entry was evicted while it ran.
 */
final readonly class PostgresqlSlowQueriesCollector implements CollectorInterface
{
    private const ASKED = 30;
    private const KEPT = 10;

    private const NORMALIZED = ['select', 'insert', 'update', 'delete', 'with'];

    private const HIDDEN = '<insufficient privilege>';

    /** The queries of the collectors of the stack; a text cut short may only show the name of a column. */
    private const OWN = ['pg_stat_', 'pg_database_size', 'pg_total_relation_size', 'pg_indexes_size', 'pg_settings', 'pg_blocking_pids', 'blocked_query_sample'];

    public function __construct(
        private DatabaseAdapterInterface $db,
    ) {}

    /**
     * @return array{queries: list<array{text: string, executions: int, average_ms: int, max_ms: int, total_ms: int}>, hidden: int}
     *               hidden: the statements of other roles among those read, whose text this role may not see
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
        $hidden = 0;

        foreach ($rows as $row) {
            $text = \is_array($row) && \is_string($row['query_sample'] ?? null) ? $row['query_sample'] : null;

            if ($text !== null && trim($text) === self::HIDDEN) {
                ++$hidden;

                continue;
            }

            if ($text === null || \count($queries) === self::KEPT || !self::isShown($text) || ($text = SqlNormalizer::normalize($text, SqlDialect::PostgreSql)) === null) {
                continue;
            }

            $queries[] = [
                'text' => $text,
                'executions' => self::rounded($row['exec_count'] ?? null),
                'average_ms' => self::rounded($row['avg_time_ms'] ?? null),
                'max_ms' => self::rounded($row['max_time_ms'] ?? null),
                'total_ms' => self::rounded($row['total_time_ms'] ?? null),
            ];
        }

        return ['queries' => $queries, 'hidden' => $hidden];
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
