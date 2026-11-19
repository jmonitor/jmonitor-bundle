<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql;

/**
 * Each value is one byte, prefixed to a raw text in the accumulation of a unit of work.
 */
enum SqlDialect: string
{
    case MySql = 'm';
    case PostgreSql = 'p';
    case Sqlite = 's';
    case Unknown = 'u';
}
