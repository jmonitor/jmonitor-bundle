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
 * keeps the comments, and the values of a statement whose entry was evicted while it ran. The statements that
 * only differ by the size of a list have the same text once normalized: they are merged.
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

        $merged = [];
        $hidden = 0;

        foreach ($rows as $row) {
            $text = \is_array($row) && \is_string($row['query_sample'] ?? null) ? $row['query_sample'] : null;

            if ($text !== null && trim($text) === self::HIDDEN) {
                ++$hidden;

                continue;
            }

            $text = $text === null || stripos($text, self::HIDDEN) !== false ? null : SqlNormalizer::normalize($text, SqlDialect::PostgreSql);

            if ($text === null || !self::isShown($text)) {
                continue;
            }

            $statement = $merged[$text] ?? ['executions' => 0.0, 'total' => 0.0, 'max' => 0.0];
            $merged[$text] = [
                'executions' => $statement['executions'] + self::number($row['exec_count'] ?? null),
                'total' => $statement['total'] + self::number($row['total_time_ms'] ?? null),
                'max' => max($statement['max'], self::number($row['max_time_ms'] ?? null)),
            ];
        }

        $queries = [];

        foreach ($merged as $text => $statement) {
            $queries[] = [
                'text' => (string) $text,
                'executions' => (int) round($statement['executions']),
                'average_ms' => $statement['executions'] > 0 ? $statement['total'] / $statement['executions'] : 0.0,
                'max_ms' => (int) round($statement['max']),
                'total_ms' => (int) round($statement['total']),
            ];
        }

        usort($queries, static fn(array $a, array $b): int => $b['average_ms'] <=> $a['average_ms']);
        $queries = array_map(static fn(array $query): array => array_replace($query, ['average_ms' => (int) round($query['average_ms'])]), \array_slice($queries, 0, self::KEPT));

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

        foreach (self::OWN as $fragment) {
            if (stripos($text, $fragment) !== false) {
                return false;
            }
        }

        return true;
    }

    private static function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
