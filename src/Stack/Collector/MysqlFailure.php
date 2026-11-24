<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

/**
 * What a MySQL collector throws when a query of the library fails.
 */
final class MysqlFailure
{
    /** The codes of MySQL and MariaDB for a privilege the user lacks. */
    private const DENIED = [1044, 1142, 1143, 1227];

    /**
     * The UnreachableServerException of the link untouched; a refused privilege on $table as a missing prerequisite,
     * whose message leaves out the server's, which names the database user; anything else as it is.
     */
    public static function of(\Throwable $e, ?string $table = null): \Throwable
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof UnreachableServerException) {
                return $cause;
            }
        }

        if ($table === null) {
            return $e;
        }

        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (\in_array($cause->getCode(), self::DENIED, true)) {
                return new MissingPrerequisiteException(sprintf('The database user of this connection may not read %s.', $table));
            }
        }

        return $e;
    }
}
