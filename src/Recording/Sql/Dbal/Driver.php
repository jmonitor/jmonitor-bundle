<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql\Dbal;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlDialect;

final class Driver extends AbstractDriverMiddleware
{
    /**
     * Client character sets in which a backslash or a backtick can be the second byte of a character, written
     * without the signs PostgreSQL ignores in such a name.
     */
    private const UNREADABLE_CHARSETS = [
        'big5', 'gbk', 'gb18030', 'sjis', 'cp932', 'shiftjis', 'shiftjis2004', 'mskanji', 'uhc', 'johab',
        'win932', 'windows932', 'win936', 'windows936', 'win949', 'windows949', 'win950', 'windows950',
    ];

    private static ?bool $dbal3 = null;

    private ?SqlDialect $platformDialect = null;

    public function __construct(DriverInterface $driver, private readonly Recorder $recorder)
    {
        parent::__construct($driver);
    }

    public function connect(#[\SensitiveParameter] array $params): ConnectionInterface
    {
        self::$dbal3 ??= !(new \ReflectionMethod(AbstractConnectionMiddleware::class, 'commit'))->hasReturnType();

        $connection = parent::connect($params);
        $dialect = self::isReadable($params)
            ? ($this->platformDialect ??= $this->platformDialect($connection)) ?? SqlDialect::Unknown
            : SqlDialect::Unknown;

        return self::$dbal3
            ? new Dbal3Connection($connection, $this->recorder, $dialect)
            : new Connection($connection, $this->recorder, $dialect);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return bool false when the charset option, or a word of a driver option such as an init command, names an
     *              unreadable character set
     */
    private static function isReadable(#[\SensitiveParameter] array $params): bool
    {
        $names = [$params['charset'] ?? null];
        foreach (\is_array($params['driverOptions'] ?? null) ? $params['driverOptions'] : [] as $option) {
            $words = \is_string($option) ? preg_split('/[^a-z0-9_-]++/', strtolower($option), -1, \PREG_SPLIT_NO_EMPTY) : [];
            if ($words === false) {
                return false;
            }
            array_push($names, ...$words);
        }

        foreach ($names as $name) {
            $name = \is_string($name) ? preg_replace('/[^a-z0-9]/', '', strtolower($name)) : '';
            if ($name === null || \in_array($name, self::UNREADABLE_CHARSETS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return SqlDialect|null null when the platform could not be read, so that the next connection asks again
     */
    private function platformDialect(ConnectionInterface $connection): ?SqlDialect
    {
        try {
            $platform = $this->getDatabasePlatform($connection);
        } catch (\Throwable) {
            return null;
        }

        return match (true) {
            $platform instanceof AbstractMySQLPlatform => SqlDialect::MySql,
            $platform instanceof PostgreSQLPlatform => SqlDialect::PostgreSql,
            $platform instanceof SQLitePlatform => SqlDialect::Sqlite,
            default => SqlDialect::Unknown,
        };
    }
}
