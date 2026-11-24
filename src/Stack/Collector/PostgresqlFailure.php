<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Doctrine\DBAL\Driver\Exception as DriverException;

/**
 * What a PostgreSQL collector throws when a query of the library fails.
 */
final class PostgresqlFailure
{
    public const STATEMENTS = 'pg_stat_statements';

    private const UNDEFINED_TABLE = '42P01';
    private const NOT_IN_PREREQUISITE_STATE = '55000';
    private const INSUFFICIENT_PRIVILEGE = '42501';

    /**
     * The UnreachableServerException of the link untouched; a missing pg_stat_statements, or a refused read of $what,
     * as a missing prerequisite whose message leaves out the server's, which names the role; anything else as it is.
     */
    public static function of(\Throwable $e, ?string $what = null): \Throwable
    {
        $state = null;

        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof UnreachableServerException) {
                return $cause;
            }

            if ($state === null && $cause instanceof DriverException) {
                $state = $cause->getSQLState();
            }
        }

        return match (true) {
            $what === self::STATEMENTS && $state === self::UNDEFINED_TABLE => new MissingPrerequisiteException('pg_stat_statements is not installed in this database: run CREATE EXTENSION pg_stat_statements.'),
            $what === self::STATEMENTS && $state === self::NOT_IN_PREREQUISITE_STATE => new MissingPrerequisiteException('pg_stat_statements is not loaded: add it to shared_preload_libraries and restart PostgreSQL.'),
            $what !== null && $state === self::INSUFFICIENT_PRIVILEGE => new MissingPrerequisiteException(sprintf('The role of this connection may not read %s.', $what)),
            default => $e,
        };
    }
}
