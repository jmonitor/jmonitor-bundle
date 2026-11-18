<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording\Sql;

use Jmonitor\JmonitorBundle\Recording\Sql\SqlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlNormalizerTest extends TestCase
{
    /**
     * @return iterable<array{string, string}>
     */
    public static function queries(): iterable
    {
        yield ['SELECT * FROM users WHERE id = 42', 'SELECT * FROM users WHERE id = ?'];
        yield ["SELECT * FROM users WHERE name = 'O''Brien' AND note = 'a\\'b'", 'SELECT * FROM users WHERE name = ? AND note = ?'];
        yield ['SELECT t0.id AS id_1, t0.col2 FROM table2 t0 WHERE t0.x = 3', 'SELECT t0.id AS id_1, t0.col2 FROM table2 t0 WHERE t0.x = ?'];
        yield ['SELECT * FROM t WHERE id IN (?, ?, ?)', 'SELECT * FROM t WHERE id IN (?)'];
        yield ['SELECT * FROM t WHERE id IN (1, 2, 3) AND s IN (:a, :b)', 'SELECT * FROM t WHERE id IN (?) AND s IN (?)'];
        yield ['SELECT * FROM t WHERE id IN ($1, $2)', 'SELECT * FROM t WHERE id IN (?)'];
        yield ['INSERT INTO t (a, b) VALUES (?, ?), (?, ?), (?, ?)', 'INSERT INTO t (a, b) VALUES (?, ?)'];
        yield ["SELECT 1 /* trace 8f3a */ FROM t -- note\n WHERE a = -1.5e3 OR b = 0xFF;", 'SELECT ? FROM t WHERE a = ? OR b = ?'];
        yield ["  SELECT\n\t*  FROM   t\n LIMIT 10 OFFSET 20 ", 'SELECT * FROM t LIMIT ? OFFSET ?'];
        yield ['SELECT "col 1", `col2` FROM "My Table"', 'SELECT "col 1", `col2` FROM "My Table"'];
    }

    #[DataProvider('queries')]
    public function testNormalize(string $sql, string $expected): void
    {
        static::assertSame($expected, SqlNormalizer::normalize($sql));
    }

    public function testALongQueryIsCutAtTwoThousandCharacters(): void
    {
        $sql = 'SELECT ' . str_repeat('a + ', 262_144) . 'b FROM t';

        $start = microtime(true);
        $normalized = SqlNormalizer::normalize($sql);

        static::assertLessThan(1.0, microtime(true) - $start);
        static::assertSame(SqlNormalizer::MAX_LENGTH, mb_strlen($normalized));
    }

    public function testAHugeInListIsFoldedQuickly(): void
    {
        $sql = 'SELECT * FROM t WHERE id IN (' . implode(', ', range(1, 150_000)) . ')';

        $start = microtime(true);
        $normalized = SqlNormalizer::normalize($sql);

        static::assertLessThan(1.0, microtime(true) - $start);
        static::assertSame('SELECT * FROM t WHERE id IN (?)', $normalized);
    }

    public function testInvalidUtf8IsScrubbed(): void
    {
        static::assertTrue(mb_check_encoding(SqlNormalizer::normalize("SELECT '\xC3\x28'"), 'UTF-8'));
    }

    public function testMarkupIsKeptAsText(): void
    {
        static::assertSame('SELECT ? AS "<b>"', SqlNormalizer::normalize('SELECT \'<script>\' AS "<b>"'));
    }

    public function testCommentMarkersInsideStringsAreValues(): void
    {
        static::assertSame('SELECT ?, ? FROM t', SqlNormalizer::normalize("SELECT '-- x', '/* y */' FROM t"));
    }

    public function testIdIsSixteenHexadecimalCharacters(): void
    {
        $id = SqlNormalizer::id('SELECT ?');

        static::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
        static::assertSame($id, SqlNormalizer::id('SELECT ?'));
        static::assertNotSame($id, SqlNormalizer::id('SELECT ? FROM t'));
    }

    public function testIsSelect(): void
    {
        static::assertTrue(SqlNormalizer::isSelect('select * FROM t'));
        static::assertTrue(SqlNormalizer::isSelect('WITH x AS (SELECT ?) SELECT * FROM x'));
        static::assertFalse(SqlNormalizer::isSelect('UPDATE t SET a = ?'));
        static::assertFalse(SqlNormalizer::isSelect('SELECTED'));
    }
}
