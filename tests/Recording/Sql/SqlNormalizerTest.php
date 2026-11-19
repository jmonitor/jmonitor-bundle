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
        yield ["SELECT * FROM users WHERE name = 'O''Brien' AND note = 'a\\b'", 'SELECT * FROM users WHERE name = ? AND note = ?'];
        yield ['SELECT t0.id AS id_1, t0.col2 FROM table2 t0 WHERE t0.x = 3', 'SELECT t0.id AS id_1, t0.col2 FROM table2 t0 WHERE t0.x = ?'];
        yield ['SELECT * FROM t WHERE id IN (?, ?, ?)', 'SELECT * FROM t WHERE id IN (?)'];
        yield ['SELECT * FROM t WHERE id IN (1, 2, 3) AND s IN (:a, :b)', 'SELECT * FROM t WHERE id IN (?) AND s IN (?)'];
        yield ['SELECT * FROM t WHERE id IN ($1, $2)', 'SELECT * FROM t WHERE id IN (?)'];
        yield ['INSERT INTO t (a, b) VALUES (?, ?), (?, ?), (?, ?)', 'INSERT INTO t (a, b) VALUES (?, ?)'];
        yield ["SELECT 1 /* trace 8f3a */ FROM t -- note\n WHERE a = -1.5e3 OR b = 0xFF;", 'SELECT ? FROM t WHERE a = ? OR b = ?'];
        yield ["  SELECT\n\t*  FROM   t\n LIMIT 10 OFFSET 20 ", 'SELECT * FROM t LIMIT ? OFFSET ?'];
        yield ['SELECT "col 1", `col2` FROM "My Table"', 'SELECT "col 1", `col2` FROM "My Table"'];
        yield ['SELECT * FROM u WHERE email = "secret@example.com"', 'SELECT * FROM u WHERE email = ?'];
        yield ['SELECT * FROM u WHERE name LIKE "%smith%" AND age >= "18"', 'SELECT * FROM u WHERE name LIKE ? AND age >= ?'];
        yield ['SELECT * FROM u WHERE code IN ("a", "b", "c")', 'SELECT * FROM u WHERE code IN (?)'];
        yield ['INSERT INTO u (a, b) VALUES ("x", "y"), ("z", "w")', 'INSERT INTO u (a, b) VALUES (?, ?)'];
        yield ['INSERT INTO "u" ("a", "b") VALUES (?, ?)', 'INSERT INTO "u" ("a", "b") VALUES (?, ?)'];
        yield ['SELECT "t"."col 1" FROM "My Table" "t" WHERE "t"."id" = ?', 'SELECT "t"."col 1" FROM "My Table" "t" WHERE "t"."id" = ?'];
        yield ['SELECT * FROM u WHERE a <> "x" OR b != "y" OR c < "z" OR d > "w" OR e <= "v"', 'SELECT * FROM u WHERE a <> ? OR b != ? OR c < ? OR d > ? OR e <= ?'];
        yield ['INSERT INTO u (a) VALUES (CONCAT("a(", "b"))', 'INSERT INTO u (a) VALUES (CONCAT(?, ?))'];
        yield ["SELECT * FROM u WHERE a = 'unterminated", 'SELECT * FROM u WHERE a = ?'];
        yield ['SELECT "IN (?, ?)", "VALUES (?), (?)" FROM t', 'SELECT "IN (?, ?)", "VALUES (?), (?)" FROM t'];
        yield ['INSERT INTO t VALUES ("a"), ("b")); SELECT * FROM u WHERE id IN ("x", "y")', 'INSERT INTO t VALUES (?)); SELECT * FROM u WHERE id IN (?)'];
        yield ['SELECT 1 /* unterminated', 'SELECT ?'];
        yield ['SELECT a$b$c, price_$ FROM t WHERE price_$ = $1 AND id IN ($2, $3)', 'SELECT a$b$c, price_$ FROM t WHERE price_$ = $1 AND id IN (?)'];
        yield ['SELECT * FROM t WHERE a = $10 AND b = $2', 'SELECT * FROM t WHERE a = $10 AND b = $2'];
        yield ["SELECT 1 FROM t WHERE a = 'a--b' AND c = \"x--y\" AND `d--e` = 2 --", 'SELECT ? FROM t WHERE a = ? AND c = ? AND `d--e` = ?'];
        yield ["SELECT 1 -- note\tand 'quotes'\nFROM t --\n WHERE a = 'x'", 'SELECT ? FROM t WHERE a = ?'];
        yield ["SELECT * FROM t WHERE color = '#fff' AND `col#1` = \"#x\" AND \"a#b\" = 2", 'SELECT * FROM t WHERE color = ? AND `col#1` = ? AND "a#b" = ?'];
    }

    /**
     * @return iterable<array{string}>
     */
    public static function valuesThatCannotBeDelimited(): iterable
    {
        yield ['SELECT * FROM t WHERE a = "it\\"s" AND b = "secret"'];
        yield ['INSERT INTO t VALUES ("it\\"s (", "secret")'];
        yield ["SELECT * FROM t WHERE path = 'C:\\dir\\' AND name = 'secret'"];
        yield ["INSERT INTO t (p, n) VALUES ('C:\\', 'secret')"];
        yield ["SELECT * FROM users WHERE name = 'O''Brien' AND note = 'a\\'b'"];
        yield ['SELECT $$secret$$ FROM t'];
        yield ['INSERT INTO t (a) VALUES ($body$it\'s secret$body$)'];
        yield ['SELECT * FROM t WHERE a = $_1$secret$_1$'];
        yield ['SELECT 1$$secret$$'];
        yield ['SELECT * FROM t WHERE a = $1$$secret$$'];
        yield ['SELECT * FROM t WHERE a = $1$tag$secret$tag$'];
        yield ["SELECT id FROM users # don't use the cache\nWHERE email = 'john@example.com' AND token = 'abc'"];
        yield ["SELECT 1 /* /* */ it's */ FROM t WHERE a = 'secret' AND b = 'x'"];
        yield ["SELECT a#b FROM t WHERE a = 'secret'"];
        yield ["SELECT a #> don't\nWHERE b = 'x'"];
        yield ["SELECT a FROM t #---- don't touch\nWHERE b = 'x'"];
        yield ["SELECT data #>> '{a,b}' FROM t WHERE id = 3"];
        yield ["SELECT data #> '{a}', a #- '{b}' FROM t"];
        yield ["SELECT 1 /* see #12 */ FROM t WHERE a = 'x'"];
        yield ["SELECT 1 FROM t -- or #13\n WHERE a = 'x'"];
        yield ["SELECT a FROM t WHERE b = 1--'\n' AND c = 'secret' AND d = 'x'"];
        yield ["SELECT 1 -- c\r'\n' AND a = 'secret' AND b = 'x'"];
        yield ["SELECT 1 ---------- banner\nFROM t WHERE a = 'x'"];
        yield ["SELECT 1 -- note\r\nFROM t WHERE a = 'x'"];
        yield ["SELECT 1 /*/*/ it's */ FROM t WHERE a = 'secret'"];
    }

    #[DataProvider('valuesThatCannotBeDelimited')]
    public function testATextWhoseValuesCannotBeDelimitedGivesNothingBack(string $sql): void
    {
        static::assertNull(SqlNormalizer::normalize($sql));
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

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function hostileQueries(): iterable
    {
        yield 'unterminated escaped quotes' => ['SELECT * FROM t WHERE a = ' . str_repeat("'\\", 200_000), null];
        yield 'unterminated backslashes' => ["SELECT * FROM t WHERE a = '" . str_repeat('\\', 200_000), 'SELECT * FROM t WHERE a = ?'];
        yield 'unterminated comments' => ['SELECT 1 ' . str_repeat('/* ', 200_000), 'SELECT ?'];
        yield 'a megabyte of hashes' => ['SELECT 1 ' . str_repeat('#', 1_048_576), null];
        yield 'a megabyte of hash operators' => ['SELECT 1 ' . str_repeat('#>', 524_288), null];
        yield 'a megabyte of dashes before a letter' => ['SELECT 1 ' . str_repeat('--x', 349_525), null];
        yield 'a megabyte of line comments' => ['SELECT 1 ' . str_repeat("-- c\n", 209_715) . 'FROM t', 'SELECT ? FROM t'];
        yield 'a megabyte of comments ending with a hash' => ['SELECT 1 ' . str_repeat('/**/ x', 174_762) . '/* # */', null];
        yield 'a megabyte of nested comment openings' => ['SELECT 1 ' . str_repeat('/*', 524_288) . '*/', null];
        yield 'a megabyte of comments' => ['SELECT 1 ' . str_repeat('/**/ x', 174_763), mb_substr('SELECT ?' . str_repeat(' x', 174_763), 0, SqlNormalizer::MAX_LENGTH)];
        yield 'a megabyte of hashes in a string' => ["SELECT 1 FROM t WHERE a = '" . str_repeat('#', 1_048_576) . "'", 'SELECT ? FROM t WHERE a = ?'];
        yield 'unterminated identifiers' => ['SELECT ' . str_repeat('"a""', 100_000), 'SELECT ' . mb_substr(str_repeat('"a""', 100_000), 0, SqlNormalizer::MAX_LENGTH - 7)];
        yield 'unterminated values groups' => ['INSERT INTO t VALUES ' . str_repeat('VALUES (', 50_000), 'INSERT INTO t VALUES ' . mb_substr(str_repeat('VALUES (', 50_000), 0, SqlNormalizer::MAX_LENGTH - 21)];

        $sql = 'INSERT INTO t ' . str_repeat('VALUES (' . str_repeat('x', 250) . ' ', 4000) . ')';
        yield 'nested values groups closed once' => [$sql, mb_substr($sql, 0, SqlNormalizer::MAX_LENGTH)];

        $sql = 'INSERT INTO t VALUES ' . str_repeat('VALUES (', 20_000) . ')';
        yield 'adjacent values groups closed once' => [$sql, mb_substr($sql, 0, SqlNormalizer::MAX_LENGTH)];

        $sql = 'INSERT INTO t VALUES ' . str_repeat('VALUES ("', 20_000) . ')';
        yield 'quoted values groups closed once' => [$sql, mb_substr($sql, 0, SqlNormalizer::MAX_LENGTH)];

        yield 'five thousand groups of markers' => ['INSERT INTO t (a, b) VALUES ' . implode(', ', array_fill(0, 5000, '(?, ?)')), 'INSERT INTO t (a, b) VALUES (?, ?)'];
        yield 'five thousand groups of literals' => [
            'INSERT INTO t (a, b) VALUES ' . implode(', ', array_map(static fn(int $i): string => \sprintf("(%d, 'x%d')", $i, $i), range(1, 5000))),
            'INSERT INTO t (a, b) VALUES (?, ?)',
        ];
    }

    #[DataProvider('hostileQueries')]
    public function testAHostileQueryIsNormalizedInLinearTime(string $sql, ?string $expected): void
    {
        $start = microtime(true);
        $normalized = SqlNormalizer::normalize($sql);

        static::assertLessThan(1.0, microtime(true) - $start);
        static::assertSame($expected, $normalized);
    }

    public function testAFailingPatternNeverGivesTheTextBack(): void
    {
        $limit = \ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            static::assertNull(SqlNormalizer::normalize("SELECT * FROM t WHERE a = 'secret' AND b IN (1, 2) AND c = \"x\""));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        static::assertSame('SELECT ?', SqlNormalizer::normalize('SELECT 1'));
    }

    public function testInvalidUtf8IsScrubbed(): void
    {
        static::assertTrue(mb_check_encoding((string) SqlNormalizer::normalize("SELECT '\xC3\x28'"), 'UTF-8'));
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

    /**
     * @return iterable<array{string, bool}>
     */
    public static function selects(): iterable
    {
        yield ['select * FROM t', true];
        yield ['WITH x AS (SELECT ?) SELECT * FROM x', true];
        yield ['UPDATE t SET a = ?', false];
        yield ['SELECTED', false];
        yield ['(SELECT a FROM t) UNION (SELECT a FROM u)', true];
        yield ['((SELECT a FROM t))', true];
        yield ['( SELECT a FROM t)', true];
        yield ['(UPDATE t SET a = ?)', false];
        yield ['WITH x AS (SELECT ?) UPDATE t SET a = ?', false];
        yield ['with x AS (SELECT ?) insert INTO t SELECT * FROM x', false];
        yield ['WITH x AS (DELETE FROM t RETURNING *) SELECT * FROM x', false];
        yield ['WITH x AS (SELECT ?) MERGE INTO t USING x ON t.id = x.id', false];
        yield ['WITH x AS (SELECT last_update, deleted_at FROM t) SELECT * FROM x', true];
    }

    #[DataProvider('selects')]
    public function testIsSelect(string $normalized, bool $expected): void
    {
        static::assertSame($expected, SqlNormalizer::isSelect($normalized));
    }
}
