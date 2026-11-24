<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Mysql\MysqlSlowQueriesCollector as LibraryCollector;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

/**
 * The statements of one database that take the longest on average, as performance_schema digests them:
 * their values are replaced by placeholders.
 */
final readonly class MysqlSlowQueriesCollector implements CollectorInterface
{
    private const TABLE = 'performance_schema.events_statements_summary_by_digest';
    private const ASKED = 20;
    private const KEPT = 10;

    /** The queries of the collectors of the stack, which would otherwise rank among the slowest. */
    private const OWN_SCHEMAS = ['performance_schema', 'information_schema'];

    /** The statements of the stack itself, as their digest without backticks nor spaces. */
    private const OWN_QUERIES = ['selectunix_timestamp()asts'];

    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $database,
    ) {}

    /**
     * A maximum above the total, which performance_schema gives for some digests, is unknown.
     *
     * @return array{queries: list<array{text: string, executions: int, average_ms: int, max_ms: ?int, total_ms: int}>}
     *
     * @throws UnreachableServerException
     * @throws MissingPrerequisiteException performance_schema is off, or the user may not read it
     */
    public function collect(): array
    {
        $slowQueries = new LibraryCollector($this->db, $this->database, self::ASKED, 1, 0, LibraryCollector::ORDER_BY_AVG_TIME);

        try {
            $enabled = $this->db->fetchAllAssociative('SELECT @@performance_schema AS enabled')[0]['enabled'] ?? null;
        } catch (\Throwable $e) {
            throw MysqlFailure::of($e);
        }

        if (\in_array($enabled, [0, '0'], true)) {
            throw new MissingPrerequisiteException('performance_schema is off on this server, so its slowest queries cannot be read.');
        }

        try {
            $slowQueries->boot();
            $rows = $slowQueries->collect()['slow_queries'];
        } catch (\Throwable $e) {
            throw MysqlFailure::of($e, self::TABLE);
        }

        $queries = [];

        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row) || !\is_string($row['query_sample'] ?? null) || self::isOwn($row['query_sample'])) {
                continue;
            }

            $max = self::int($row['max_time_ms'] ?? null);
            $total = self::int($row['total_time_ms'] ?? null);

            $queries[] = [
                'text' => $row['query_sample'],
                'executions' => self::int($row['exec_count'] ?? null),
                'average_ms' => self::int($row['avg_time_ms'] ?? null),
                'max_ms' => $max > $total ? null : $max,
                'total_ms' => $total,
            ];

            if (\count($queries) === self::KEPT) {
                break;
            }
        }

        return ['queries' => $queries];
    }

    public function getVersion(): int
    {
        return (new LibraryCollector($this->db, $this->database))->getVersion();
    }

    public function getName(): string
    {
        return 'mysql.slow_queries';
    }

    private static function isOwn(string $text): bool
    {
        foreach (self::OWN_SCHEMAS as $schema) {
            if (stripos($text, $schema) !== false) {
                return true;
            }
        }

        return \in_array(strtolower(str_replace(['`', ' '], '', $text)), self::OWN_QUERIES, true);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
