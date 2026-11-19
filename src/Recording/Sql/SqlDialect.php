<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql;

/**
 * Each value is a single byte.
 */
enum SqlDialect: string
{
    case MySql = 'm';
    case PostgreSql = 'p';
    case Sqlite = 's';
    case Unknown = 'u';
}
