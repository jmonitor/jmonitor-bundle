<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording\Sql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlDialect;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlNormalizer;
use Jmonitor\JmonitorBundle\Tests\Storage\Dbal\ConnectionProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LiteralLeakTest extends TestCase
{
    private const COUNT = 400;
    private const SEEDS = ['sqlite' => 11, 'mysql' => 23, 'mysql-no-backslash' => 37, 'mysql-ansi' => 41, 'pgsql' => 53, 'pgsql-escapes' => 67];
    private const SESSIONS = [
        'mysql-no-backslash' => ["SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'"],
        'mysql-ansi' => ["SET SESSION sql_mode = 'ANSI_QUOTES'"],
        'pgsql-escapes' => ['SET standard_conforming_strings = off', 'SET escape_string_warning = off'],
    ];
    private const URLS = ['mysql' => 'JMONITOR_TEST_MYSQL_URL', 'pgsql' => 'JMONITOR_TEST_PGSQL_URL'];
    private const DIALECTS = ['sqlite' => SqlDialect::Sqlite, 'mysql' => SqlDialect::MySql, 'pgsql' => SqlDialect::PostgreSql];

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        foreach (LiteralStatements::MODES as $mode) {
            yield $mode => [$mode];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function defaultModes(): iterable
    {
        foreach (array_keys(self::DIALECTS) as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('modes')]
    public function testTheServerReadsTheGeneratedLiteralsAsValues(string $mode): void
    {
        $connection = self::connect($mode);

        foreach (self::statements($mode) as [$sql, $expected]) {
            $row = $connection->fetchNumeric($sql);
            static::assertIsArray($row, $sql);

            $read = [];
            foreach ($row as $i => $value) {
                if (\is_float($expected[$i] ?? null)) {
                    static::assertIsNumeric($value, $sql);
                    $value = (float) $value;
                }
                $read[] = $value;
            }
            static::assertSame($expected, $read, $sql);
        }

        $connection->close();
    }

    #[DataProvider('modes')]
    public function testNoLiteralSurvivesNormalization(string $mode): void
    {
        foreach (self::statements($mode) as [$sql]) {
            $normalized = (string) SqlNormalizer::normalize($sql, self::dialect($mode));

            static::assertStringNotContainsString(LiteralStatements::MARKER, $normalized, $sql);
            static::assertStringNotContainsString(LiteralStatements::NUMBER, $normalized, $sql);
        }
    }

    #[DataProvider('modes')]
    public function testAStatementWithoutBackslashIsAlwaysKept(string $mode): void
    {
        foreach (self::statements($mode) as [$sql, , $undecidable]) {
            if (!$undecidable && !str_contains($sql, '\\')) {
                static::assertNotNull(SqlNormalizer::normalize($sql, self::dialect($mode)), $sql);
            }
        }
    }

    #[DataProvider('defaultModes')]
    public function testMostStatementsOfTheDefaultModeAreKept(string $mode): void
    {
        $kept = 0;
        foreach (self::statements($mode) as [$sql]) {
            $kept += (int) (SqlNormalizer::normalize($sql, self::dialect($mode)) !== null);
        }

        static::assertGreaterThanOrEqual(self::COUNT / 2, $kept);
    }

    /**
     * @return iterable<array{string, list<string|float>, bool}>
     */
    private static function statements(string $mode): iterable
    {
        self::url($mode);

        return LiteralStatements::generate($mode, self::COUNT, self::SEEDS[$mode]);
    }

    private static function dialect(string $mode): SqlDialect
    {
        return self::DIALECTS[explode('-', $mode)[0]];
    }

    /**
     * @return string|null the URL of the server of the mode, null for SQLite
     */
    private static function url(string $mode): ?string
    {
        $server = explode('-', $mode)[0];
        if (!isset(self::URLS[$server])) {
            return null;
        }

        $url = getenv(self::URLS[$server]);
        if (!\is_string($url) || $url === '') {
            static::markTestSkipped(self::URLS[$server] . ' is not set');
        }

        return $url;
    }

    private static function connect(string $mode): Connection
    {
        $url = self::url($mode);
        $connection = DriverManager::getConnection($url === null ? ['driver' => 'pdo_sqlite', 'memory' => true] : ConnectionProvider::parse($url));

        foreach (self::SESSIONS[$mode] ?? [] as $statement) {
            $connection->executeStatement($statement);
        }

        return $connection;
    }
}
