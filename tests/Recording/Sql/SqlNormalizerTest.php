<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording\Sql;

use Jmonitor\JmonitorBundle\Recording\Sql\SqlDialect;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlNormalizerTest extends TestCase
{
    private const DIALECTS = ['MySQL' => SqlDialect::MySql, 'PostgreSQL' => SqlDialect::PostgreSql, 'SQLite' => SqlDialect::Sqlite];
    private const MEGABYTE = 1_048_576;

    /**
     * @return iterable<string, array{SqlDialect}>
     */
    public static function dialects(): iterable
    {
        foreach (self::DIALECTS as $name => $dialect) {
            yield $name => [$dialect];
        }
    }

    /**
     * @return iterable<array{string, SqlDialect, string|null}>
     */
    public static function anyDialect(): iterable
    {
        $queries = [
            ['SELECT * FROM users WHERE id = 42', 'SELECT * FROM users WHERE id = ?'],
            ["SELECT * FROM users WHERE name = 'O''Brien' AND note = 'a\\b'", 'SELECT * FROM users WHERE name = ? AND note = ?'],
            ['SELECT t0.id AS id_1, t0.col2 FROM table2 t0 WHERE t0.x = 3', 'SELECT t0.id AS id_1, t0.col2 FROM table2 t0 WHERE t0.x = ?'],
            ['SELECT * FROM t WHERE id IN (?, ?, ?)', 'SELECT * FROM t WHERE id IN (?)'],
            ['SELECT * FROM t WHERE id IN (1, 2, 3) AND s IN (:a, :b)', 'SELECT * FROM t WHERE id IN (?) AND s IN (?)'],
            ['SELECT * FROM t WHERE id IN ($1, $2)', 'SELECT * FROM t WHERE id IN (?)'],
            ['INSERT INTO t (a, b) VALUES (?, ?), (?, ?), (?, ?)', 'INSERT INTO t (a, b) VALUES (?, ?)'],
            ["SELECT 1 /* trace 8f3a */ FROM t -- note\n WHERE a = -1.5e3 OR b = 0xFF;", 'SELECT ? FROM t WHERE a = ? OR b = ?'],
            ["  SELECT\n\t*  FROM   t\n LIMIT 10 OFFSET 20 ", 'SELECT * FROM t LIMIT ? OFFSET ?'],
            ['SELECT a$b$c, price_$ FROM t WHERE price_$ = $1 AND id IN ($2, $3)', 'SELECT a$b$c, price_$ FROM t WHERE price_$ = $1 AND id IN (?)'],
            ['SELECT * FROM t WHERE a = $10 AND b = $2', 'SELECT * FROM t WHERE a = $10 AND b = $2'],
            ["SELECT '-- x', '/* y */' FROM t", 'SELECT ?, ? FROM t'],
            ["SELECT * FROM u WHERE a = 'unterminated", null],
            ['SELECT 1 /* unterminated', null],
            ["SELECT 1\0, 'x'", null],
            ["SELECT 1 -- note\tand 'quotes'\nFROM t --\n WHERE a = 'x'", 'SELECT ? FROM t WHERE a = ?'],
            ["SELECT 1 -- note\r\nFROM t WHERE a = 'x' -- end\r\n", 'SELECT ? FROM t WHERE a = ?'],
            ["SELECT 1 -- c\r\r\nFROM t WHERE a = 'x'", 'SELECT ? FROM t WHERE a = ?'],
            ["SELECT 1 FROM t WHERE a = 'x' -- c\r", 'SELECT ? FROM t WHERE a = ?'],
            ["SELECT 1 /* see #12 */ FROM t WHERE a = 'x'", 'SELECT ? FROM t WHERE a = ?'],
            ["SELECT 1 FROM t -- or #13\n WHERE a = 'x'", 'SELECT ? FROM t WHERE a = ?'],
            ['SELECT abs(:a), :b FROM t WHERE c IN (:d) AND e = lower(@f)', 'SELECT abs(:a), :b FROM t WHERE c IN (?) AND e = lower(@f)'],
            ['SELECT 1_000, 0b101, 0o17, 0x1_F, 1_000.5, 1_0e1_0, .5_0 FROM t', 'SELECT ?, ?, ?, ?, ?, ?, ? FROM t'],
            ['SELECT * FROM t WHERE a = -0b11 AND b IN (1_000, 2_000)', 'SELECT * FROM t WHERE a = ? AND b IN (?)'],
            ['SELECT id_1, t0.col_2, a_1_000 FROM t0 WHERE x_0b1 = $1', 'SELECT id_1, t0.col_2, a_1_000 FROM t0 WHERE x_0b1 = $1'],
            ['SELECT 0B101, 0O17, 0X1F, 1., 1.e5, 1_000e-1_0, +.5 FROM t', 'SELECT ?, ?, ?, ?, ?, ?, ? FROM t'],
            ['INSERT INTO t (a, b) VALUES ' . implode(', ', array_fill(0, 5000, '(?, ?)')), 'INSERT INTO t (a, b) VALUES (?, ?)'],
            [
                'INSERT INTO t (a, b) VALUES ' . implode(', ', array_map(static fn(int $i): string => \sprintf("(%d, 'x%d')", $i, $i), range(1, 5000))),
                'INSERT INTO t (a, b) VALUES (?, ?)',
            ],
            ['SELECT * FROM t WHERE id IN (' . implode(', ', range(1, 150_000)) . ')', 'SELECT * FROM t WHERE id IN (?)'],
        ];

        foreach ($queries as [$sql, $expected]) {
            foreach (self::DIALECTS as $dialect) {
                yield [$sql, $dialect, $expected];
            }
        }
    }

    #[DataProvider('anyDialect')]
    public function testAQueryEveryDialectReadsTheSameWay(string $sql, SqlDialect $dialect, ?string $expected): void
    {
        $start = microtime(true);
        $normalized = SqlNormalizer::normalize($sql, $dialect);

        static::assertLessThan(1.0, microtime(true) - $start);
        static::assertSame($expected, $normalized);
    }

    /**
     * @return iterable<array{string, string|null}>
     */
    public static function mysqlQueries(): iterable
    {
        yield ['SELECT "col 1", `col2` FROM "My Table"', 'SELECT ?, `col2` FROM ?'];
        yield ['SELECT * FROM t ORDER BY FIELD(status, "new", "open")', 'SELECT * FROM t ORDER BY FIELD(status, ?, ?)'];
        yield ['SELECT * FROM u WHERE code IN ("a", "b", "c")', 'SELECT * FROM u WHERE code IN (?)'];
        yield ["SELECT * FROM users WHERE name = 'O\\'Brien' AND active = 1", 'SELECT * FROM users WHERE name = ? AND active = ?'];
        yield ["SELECT * FROM t WHERE a = 'it\\'s' AND b = 'it\\'s secret'", 'SELECT * FROM t WHERE a = ? AND b = ?'];
        yield ['SELECT * FROM t WHERE a = "it\\"s" AND b = "secret"', 'SELECT * FROM t WHERE a = ? AND b = ?'];
        yield ["SELECT * FROM t WHERE path = 'C:\\dir\\' AND name = 'secret'", 'SELECT * FROM t WHERE path = ? AND name = ?'];
        yield ["INSERT INTO t (p, n) VALUES ('C:\\', 'secret')", 'INSERT INTO t (p, n) VALUES (?, ?)'];
        yield ["SELECT 'a\\' /* ' */ , 1", null];
        yield ["SELECT \\N, 'a'", 'SELECT \N, ?'];
        yield ["SELECT id FROM users # don't use the cache\nWHERE email = 'john@example.com' AND token = 'abc'", 'SELECT id FROM users WHERE email = ? AND token = ?'];
        yield ["SELECT a#b FROM t WHERE a = 'secret'", 'SELECT a'];
        yield ["SELECT a FROM t WHERE b = 1--'\n' AND c = 'secret' AND d = 'x'", 'SELECT a FROM t WHERE b = ?--? AND c = ? AND d = ?'];
        yield ["SELECT 1 ---------- banner\nFROM t WHERE a = 'x'", 'SELECT ? -------- FROM t WHERE a = ?'];
        yield ["SELECT 1 -- c\r'\n' AND a = 'secret' AND b = 'x'", null];
        yield ["SELECT 1 /* /* */ it's */ FROM t WHERE a = 'secret' AND b = 'x'", null];
        yield ['SELECT /*+ MAX_EXECUTION_TIME(1000) */ * FROM t', 'SELECT * FROM t'];
        yield ['SELECT 1 /*!50000 , 2 */', 'SELECT ?'];
        yield ["SELECT 1 /*! , 'exec' */", null];
        yield ["SELECT /*+ SET_VAR(optimizer_switch = 'mrr=off') */ 1", null];
        yield ['SELECT /*+ QB_NAME(`*/`) */ \'secret\', `c` FROM t', null];
        yield ["SELECT `a'b`, 'x' FROM `t#1`", "SELECT `a'b`, ? FROM `t#1`"];
        yield ["SELECT 'é' AS \"ñ\", `ü` FROM t WHERE n = 'ß'", 'SELECT ? AS ?, `ü` FROM t WHERE n = ?'];

        yield ['INSERT INTO t VALUES ("it\\"s (", "secret")', 'INSERT INTO t VALUES (?, ?)'];
        yield ["SELECT * FROM users WHERE name = 'O''Brien' AND note = 'a\\'b'", 'SELECT * FROM users WHERE name = ? AND note = ?'];
        yield ["SELECT a #> don't\nWHERE b = 'x'", 'SELECT a WHERE b = ?'];
        yield ["SELECT a FROM t #---- don't touch\nWHERE b = 'x'", 'SELECT a FROM t WHERE b = ?'];
        yield ["SELECT * FROM t WHERE color = '#fff' AND `col#1` = \"#x\" AND \"a#b\" = 2", 'SELECT * FROM t WHERE color = ? AND `col#1` = ? AND ? = ?'];
        yield ["SELECT 1 --\x7F'\n, 'x'", 'SELECT ? , ?'];
        yield ['SELECT `IN (1, 2)`, `VALUES (1), (2)`, `a  b` FROM t', 'SELECT `IN (1, 2)`, `VALUES (1), (2)`, `a  b` FROM t'];
        yield ['INSERT INTO t (`a 1`) VALUES (DEFAULT(`a 1`)), (DEFAULT(`a 1`))', 'INSERT INTO t (`a 1`) VALUES (DEFAULT(`a 1`))'];
        yield ["SELECT 1 /*M! , 'exec' */", null];
        yield ["SELECT 1 /*! /*/ , 'exec' */ */", null];
        yield ["SELECT 1 /*! # */ '\n*/ , 'secret' /*! # */ '\n*/", null];
        yield ["SELECT 1 /*! -- */ '\n*/ , 'secret' /*! -- */ '\n*/", null];
        yield ["SELECT \\n, 'a'", null];
        yield ["SELECT 'a\\' /*! , 'secret/* ' */", null];
        yield ["CREATE PROCEDURE jm_probe3() LANGUAGE JAVASCRIPT AS \$\$ // don't\n let token = 'secret'; // won't\n \$\$", null];
        yield ['SELECT $$secret$$', null];
        yield ['SELECT $tag$secret$tag$', null];
        yield ["SELECT 'a\\' , \$\$secret\$\$ /* ' */", null];
        yield ['SELECT 1e5$$secret$$, 1.5$$secret$$', null];
        yield ['SELECT 1.e5$$secret$$', null];
        yield ['SELECT 1.E5$tag$secret$tag$', null];
        yield ['SELECT t0.price$ FROM t t0', 'SELECT t0.price$ FROM t t0'];
        yield ['SELECT t0.a$b$c FROM t t0', 'SELECT t0.a$b$c FROM t t0'];
        yield ["SELECT 1 /*! [*/ , `] */ , 'secret' /*! [`] */", null];
        yield ['SELECT /*+ INDEX(t idx_a, idx_b) NO_ICP(t@qb1) */ a FROM t', 'SELECT a FROM t'];
        yield ["SELECT 1 /*! \$\$ */ , 'secret' /*! \$\$ */", null];
        yield ['SELECT /*+ SET_VAR(sort_buffer_size = 16M) */ 1', 'SELECT ?'];
        yield ["SELECT 1 /*! [*/ , ] */ , 'secret'", null];
        yield ['SELECT 1 /*!80000 , 2 * 3 */', null];
        yield ['SELECT 1 AS 12$$, abc$$x$$, $a FROM $t', 'SELECT ? AS 12$$, abc$$x$$, $a FROM $t'];
        yield ['SELECT 1e5abc', 'SELECT ?abc'];
        yield ['SELECT .5abc', 'SELECT ?abc'];
        yield ['SELECT 1.5abc', 'SELECT ?abc'];
        yield ['SELECT 1 FROM DUAL WHERE 1=1e0AND 2=2', 'SELECT ? FROM DUAL WHERE ?=?AND ?=?'];
        yield ['SELECT * FROM 2fa_tokens WHERE 123abc = 0x1Fz', 'SELECT * FROM 2fa_tokens WHERE 123abc = 0x1Fz'];
        yield ["SELECT 1 --\xA0'\n, 'secret' --\xA0'\n", null];
        yield ["SELECT [a'b], 'secret', [c'd]", null];
        yield ["SELECT 'a\\' , [secret] /* ' */", null];
        yield ["CREATE PROCEDURE jm_fr3() LANGUAGE JAVASCRIPT AS\xA0\$\$ let a = `ZQSECRETQZ`; return a\$\$", null];
        yield ["CREATE PROCEDURE jm_fr3() LANGUAGE JAVASCRIPT AS\xA0\$\$ let a = ZQSECRETQZ_token_abc; return a\$\$", null];
        yield ["SELECT caf\xC3\xA9\$a FROM t", 'SELECT café$a FROM t'];
        yield ["SELECT caf\xC3\xA9\$\$ FROM t", null];
    }

    #[DataProvider('mysqlQueries')]
    public function testAMySqlQueryIsReadWithTheRulesOfMySql(string $sql, ?string $expected): void
    {
        self::assertReadAs($expected, SqlNormalizer::normalize($sql, SqlDialect::MySql));
    }

    /**
     * @return iterable<array{string, string|null}>
     */
    public static function postgresqlQueries(): iterable
    {
        yield ["SELECT data #>> '{a,b}' FROM t WHERE id = 3", 'SELECT data #>> ? FROM t WHERE id = ?'];
        yield ["SELECT data #> '{a}', a #- '{b}' FROM t", 'SELECT data #> ?, a #- ? FROM t'];
        yield ["SELECT a#b FROM t WHERE a = 'secret'", 'SELECT a#b FROM t WHERE a = ?'];
        yield ['SELECT $$secret$$ FROM t', 'SELECT ? FROM t'];
        yield ['INSERT INTO t (a) VALUES ($body$it\'s secret$body$)', 'INSERT INTO t (a) VALUES (?)'];
        yield ['SELECT * FROM t WHERE a = $_1$secret$_1$', 'SELECT * FROM t WHERE a = ?'];
        yield ['SELECT $a$ x $b$ y $a$', 'SELECT ?'];
        yield ['SELECT * FROM t WHERE a = $1$$secret$$', 'SELECT * FROM t WHERE a = $1?'];
        yield ['SELECT * FROM t WHERE a = $1$tag$secret$tag$', 'SELECT * FROM t WHERE a = $1?'];
        yield ['SELECT 1$$secret$$', 'SELECT ??'];
        yield ["SELECT a1$$, 'x $$ secret' FROM t", 'SELECT a1$$, ? FROM t'];
        yield ['SELECT $$secret', null];
        yield ['SELECT * FROM a JOIN b ON "a"."id" = "b"."a_id"', 'SELECT * FROM a JOIN b ON "a"."id" = "b"."a_id"'];
        yield ['SELECT * FROM u WHERE email = "secret@example.com"', 'SELECT * FROM u WHERE email = "secret@example.com"'];
        yield ["SELECT \"it's\", 'x' FROM t", 'SELECT "it\'s", ? FROM t'];
        yield ["SELECT 1 ` 'secret' ` 2", 'SELECT ? ` ? ` ?'];
        yield ["SELECT 1 /* /* */ it's */ FROM t WHERE a = 'secret' AND b = 'x'", 'SELECT ? FROM t WHERE a = ? AND b = ?'];
        yield ["SELECT 1 /*/*/ it's */ FROM t WHERE a = 'secret'", null];
        yield ["SELECT 1 -- c\r'\n' AND a = 'secret' AND b = 'x'", 'SELECT ? ? AND a = ? AND b = ?'];
        yield ["SELECT a FROM t WHERE b = 1--'\n' AND c = 'secret' AND d = 'x'", null];
        yield ["SELECT 1 ---------- banner\nFROM t WHERE a = 'x'", 'SELECT ? FROM t WHERE a = ?'];
        yield ["SELECT E'it\\'s', 'x'", 'SELECT E?, ?'];
        yield ["SELECT 'a\\' , 'secret'", 'SELECT ? , ?'];
        yield ["SELECT 'it\\'s' AS a", 'SELECT ? AS a'];
        yield ["SELECT 1E'a'", null];
        yield ["SELECT U&'d\\0061t'", 'SELECT U&?'];
        yield ["SELECT '1'::int, :id", 'SELECT ?::int, :id'];
        yield ["SELECT 'é' AS \"ñ\" FROM t WHERE n = 'ß'", 'SELECT ? AS "ñ" FROM t WHERE n = ?'];

        yield ["SELECT a #> don't\nWHERE b = 'x'", null];
        yield ["SELECT a FROM t #---- don't touch\nWHERE b = 'x'", 'SELECT a FROM t # WHERE b = ?'];
        yield ["SELECT e'a\\'b', 'x'", 'SELECT e?, ?'];
        yield ["SELECT typE'a\\' , 'secret'", 'SELECT typE? , ?'];
        yield ['SELECT "a 1", "IN (1, 2)", "VALUES (1), (2)" FROM "t  2" WHERE "b" IN ("c", "d")', 'SELECT "a 1", "IN (1, 2)", "VALUES (1), (2)" FROM "t  2" WHERE "b" IN ("c", "d")'];
        yield ['SELECT 1 $ 2', null];
        yield ['SELECT $tag secret', null];
        yield ['SELECT 0x1F$$secret$$', null];
        yield ['SELECT 1_000$$secret$$', null];
        yield ['SELECT 0b1$$secret$$', null];
        yield ["SELECT '\\', \$\$secret\$\$ FROM t", 'SELECT ?, ? FROM t'];
        yield ["SELECT 1 \\ 'a'", null];
        yield ["SELECT 'a\\' , 1E'secret' /* ' */", null];
        yield ["SELECT E'a'\n'b\\'' , 'secret' /* ' */ , '\\'", null];
        yield ["SELECT E'a' -- c\n  'b\\'' , 'secret' /* ' */ , '\\'", null];
        yield ["SELECT E'a'\r'b\\'' , 'secret' /* ' */ , '\\'", null];
        yield ["SELECT 'a\\' , E'secret'\n'x' /* ' */", null];
        yield ["SELECT E'a'\n, 'b'\n'c', E'd' 'e'", 'SELECT E? , ? ?, E? ?'];
        yield ['SELECT 123abc, 1e5abc, .5abc', 'SELECT ?abc, ?abc, ?abc'];
        yield ['SELECT 1.e5$$secret$$', null];
        yield ["SELECT 'a\\' , 0x1F\$\$secret\$\$ /* ' */", null];
        yield ['SELECT t0.price$ FROM t t0', 'SELECT t0.price$ FROM t t0'];
        yield ['SELECT t0.a$b$c FROM t t0', 'SELECT t0.a$b$c FROM t t0'];
        yield ["SELECT caf\xC3\xA9\$\$ FROM t", 'SELECT café$$ FROM t'];
    }

    #[DataProvider('postgresqlQueries')]
    public function testAPostgreSqlQueryIsReadWithTheRulesOfPostgreSql(string $sql, ?string $expected): void
    {
        self::assertReadAs($expected, SqlNormalizer::normalize($sql, SqlDialect::PostgreSql));
    }

    /**
     * @return iterable<array{string, string|null}>
     */
    public static function sqliteQueries(): iterable
    {
        yield ['SELECT "col 1", `col2` FROM "My Table"', 'SELECT "col 1", `col2` FROM "My Table"'];
        yield ['SELECT * FROM u WHERE email = "secret@example.com"', 'SELECT * FROM u WHERE email = ?'];
        yield ['SELECT * FROM u WHERE name LIKE "%smith%" AND age >= "18"', 'SELECT * FROM u WHERE name LIKE ? AND age >= ?'];
        yield ['SELECT * FROM u WHERE code IN ("a", "b", "c")', 'SELECT * FROM u WHERE code IN (?)'];
        yield ['INSERT INTO u (a, b) VALUES ("x", "y"), ("z", "w")', 'INSERT INTO u (a, b) VALUES (?, ?)'];
        yield ['INSERT INTO "u" ("a", "b") VALUES (?, ?)', 'INSERT INTO "u" ("a", "b") VALUES (?, ?)'];
        yield ['SELECT "t"."col 1" FROM "My Table" "t" WHERE "t"."id" = ?', 'SELECT "t"."col 1" FROM "My Table" "t" WHERE "t"."id" = ?'];
        yield ['SELECT * FROM u WHERE a <> "x" OR b != "y" OR c < "z" OR d > "w" OR e <= "v"', 'SELECT * FROM u WHERE a <> ? OR b != ? OR c < ? OR d > ? OR e <= ?'];
        yield ['INSERT INTO u (a) VALUES (CONCAT("a(", "b"))', 'INSERT INTO u (a) VALUES (CONCAT(?, ?))'];
        yield ['SELECT "IN (?, ?)", "VALUES (?), (?)" FROM t', 'SELECT "IN (?, ?)", "VALUES (?), (?)" FROM t'];
        yield ['INSERT INTO t VALUES ("a"), ("b")); SELECT * FROM u WHERE id IN ("x", "y")', 'INSERT INTO t VALUES (?)); SELECT * FROM u WHERE id IN (?)'];
        yield ["SELECT [it's], 'x' FROM [my table]", "SELECT [it's], ? FROM [my table]"];
        yield ['SELECT [a FROM t', null];
        yield ["SELECT a#b FROM t WHERE a = 'secret'", 'SELECT a#b FROM t WHERE a = ?'];
        yield ["SELECT 'C:\\dir\\', 'secret'", 'SELECT ?, ?'];
        yield ["SELECT 'it\\'s'", null];
        yield ["SELECT 1 FROM t WHERE a = 'a--b' AND c = \"x--y\" AND `d--e` = 2 --", 'SELECT ? FROM t WHERE a = ? AND c = ? AND `d--e` = ?'];

        yield ["SELECT * FROM t WHERE color = '#fff' AND `col#1` = \"#x\" AND \"a#b\" = 2", 'SELECT * FROM t WHERE color = ? AND `col#1` = ? AND "a#b" = ?'];
        yield ['SELECT * FROM t WHERE a = "it\\"s" AND b = "secret"', null];
        yield ['SELECT [a 1], [IN (1, 2)], `VALUES (1), (2)` FROM t', 'SELECT [a 1], [IN (1, 2)], `VALUES (1), (2)` FROM t'];
        yield ["SELECT :a('), 'secret', :b(')", null];
        yield ["SELECT \$a('), 'secret', \$b(')", null];
        yield ["SELECT @a::b('), 'secret', #c(')", null];
        yield ["SELECT \$a::('), 'secret', \$a::(')", null];
        yield ['SELECT a$b(1), "x"(2)', 'SELECT a$b(?), "x"(?)'];
        yield ['SELECT 123abc, 1e5abc, .5abc', 'SELECT ?abc, ?abc, ?abc'];
    }

    #[DataProvider('sqliteQueries')]
    public function testASqliteQueryIsReadWithTheRulesOfSqlite(string $sql, ?string $expected): void
    {
        self::assertReadAs($expected, SqlNormalizer::normalize($sql, SqlDialect::Sqlite));
    }

    /**
     * An expected text holds the word only where it is an identifier.
     */
    private static function assertReadAs(?string $expected, ?string $normalized): void
    {
        static::assertSame($expected, $normalized);
        if (!str_contains((string) $expected, 'secret')) {
            static::assertStringNotContainsString('secret', (string) $normalized);
        }
    }

    /**
     * @return iterable<string, array{string, SqlDialect, 2?: string|null}>
     */
    public static function hostileQueries(): iterable
    {
        $cut = static fn(string $text): string => mb_substr($text, 0, SqlNormalizer::MAX_LENGTH);
        $everywhere = static fn(?string $expected): array => [$expected, $expected, $expected];

        $nested = 'INSERT INTO t ' . str_repeat('VALUES (' . str_repeat('x', 250) . ' ', 4000) . ')';
        $adjacent = 'INSERT INTO t VALUES ' . str_repeat('VALUES (', 20_000) . ')';
        $quoted = 'INSERT INTO t VALUES ' . str_repeat('VALUES ("', 20_000) . ')';
        $doubled = 'SELECT ' . str_repeat('"a""', 100_000);

        $pinned = [
            'unterminated escaped quotes' => ['SELECT * FROM t WHERE a = ' . str_repeat("'\\", 200_000), $everywhere(null)],
            'unterminated backslashes' => ["SELECT * FROM t WHERE a = '" . str_repeat('\\', 200_000), $everywhere(null)],
            'unterminated comments' => ['SELECT 1 ' . str_repeat('/* ', 200_000), $everywhere(null)],
            'a megabyte of hashes' => ['SELECT 1 ' . str_repeat('#', self::MEGABYTE), [
                'SELECT ?',
                $cut('SELECT ? ' . str_repeat('#', self::MEGABYTE)),
                $cut('SELECT ? ' . str_repeat('#', self::MEGABYTE)),
            ]],
            'a megabyte of hash operators' => ['SELECT 1 ' . str_repeat('#>', 524_288), [
                'SELECT ?',
                $cut('SELECT ? ' . str_repeat('#>', 524_288)),
                $cut('SELECT ? ' . str_repeat('#>', 524_288)),
            ]],
            'a megabyte of dashes before a letter' => ['SELECT 1 ' . str_repeat('--x', 349_525), [
                $cut('SELECT ? ' . str_repeat('--x', 349_525)),
                'SELECT ?',
                'SELECT ?',
            ]],
            'a megabyte of line comments' => ['SELECT 1 ' . str_repeat("-- c\n", 209_715) . 'FROM t', $everywhere('SELECT ? FROM t')],
            'a megabyte of comments ending with a hash' => [
                'SELECT 1 ' . str_repeat('/**/ x', 174_762) . '/* # */',
                $everywhere($cut('SELECT ?' . str_repeat(' x', 174_762))),
            ],
            'a megabyte of nested comment openings' => ['SELECT 1 ' . str_repeat('/*', 524_288) . '*/', [
                $cut('SELECT ?' . str_repeat(' *', 174_762)),
                null,
                $cut('SELECT ?' . str_repeat(' *', 174_762)),
            ]],
            'a megabyte of comments' => ['SELECT 1 ' . str_repeat('/**/ x', 174_763), $everywhere($cut('SELECT ?' . str_repeat(' x', 174_763)))],
            'a megabyte of hashes in a string' => ["SELECT 1 FROM t WHERE a = '" . str_repeat('#', self::MEGABYTE) . "'", $everywhere('SELECT ? FROM t WHERE a = ?')],
            'a hundred thousand doubled quotes' => [$doubled, [$cut('SELECT ' . str_repeat('?a', 50_000) . '?'), $cut($doubled), $cut($doubled)]],
            'unterminated values groups' => [
                'INSERT INTO t VALUES ' . str_repeat('VALUES (', 50_000),
                $everywhere($cut('INSERT INTO t VALUES ' . str_repeat('VALUES (', 50_000))),
            ],
            'nested values groups closed once' => [$nested, $everywhere($cut($nested))],
            'adjacent values groups closed once' => [$adjacent, $everywhere($cut($adjacent))],
            'quoted values groups closed once' => [$quoted, [
                $cut('INSERT INTO t VALUES ' . str_repeat('VALUES (?', 10_000) . ')'),
                $cut($quoted),
                $cut($quoted),
            ]],
        ];

        foreach ($pinned as $name => [$sql, $expected]) {
            foreach (array_values(self::DIALECTS) as $i => $dialect) {
                yield $name . ', ' . $dialect->name => [$sql, $dialect, $expected[$i]];
            }
        }

        $repeated = ['$', '$a', '\\', '\\\'', 'E\'', '[', '"', '/*!', '$a$', 'a$', "E'a'\n", "E'a' -- c\n", '1e', '1.', '--é'];
        foreach ($repeated as $bytes) {
            $megabyte = str_repeat($bytes, intdiv(self::MEGABYTE, \strlen($bytes)));
            foreach (self::DIALECTS as $dialect) {
                yield 'a megabyte of ' . $bytes . ', ' . $dialect->name => ['SELECT ' . $megabyte, $dialect];
                yield 'a value before a megabyte of ' . $bytes . ', ' . $dialect->name => ["SELECT 'secret' " . $megabyte, $dialect];
            }
        }
    }

    #[DataProvider('hostileQueries')]
    public function testAHostileQueryIsReadInLinearTime(string $sql, SqlDialect $dialect, string|false|null $expected = false): void
    {
        $start = microtime(true);
        $normalized = SqlNormalizer::normalize($sql, $dialect);

        static::assertLessThan(1.0, microtime(true) - $start);
        static::assertStringNotContainsString('secret', (string) $normalized);
        if ($expected !== false) {
            static::assertSame($expected, $normalized);
        }
    }

    #[DataProvider('dialects')]
    public function testALongQueryIsCutAtTwoThousandCharacters(SqlDialect $dialect): void
    {
        $sql = 'SELECT ' . str_repeat('a + ', 262_144) . 'b FROM t';

        $start = microtime(true);
        $normalized = SqlNormalizer::normalize($sql, $dialect);

        static::assertLessThan(1.0, microtime(true) - $start);
        static::assertSame(SqlNormalizer::MAX_LENGTH, mb_strlen((string) $normalized));
    }

    #[DataProvider('dialects')]
    public function testTheCutNeverSplitsACharacter(SqlDialect $dialect): void
    {
        $normalized = SqlNormalizer::normalize('SELECT ' . str_repeat('é', 3000) . ' FROM t', $dialect);

        static::assertSame('SELECT ' . str_repeat('é', SqlNormalizer::MAX_LENGTH - 7), $normalized);
        static::assertTrue(mb_check_encoding((string) $normalized, 'UTF-8'));
    }

    #[DataProvider('dialects')]
    public function testAFailingPatternNeverGivesTheTextBack(SqlDialect $dialect): void
    {
        $limit = \ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            static::assertNull(SqlNormalizer::normalize("SELECT * FROM t WHERE a = 'secret' AND b IN (1, 2) AND c = \"x\"", $dialect));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        static::assertSame('SELECT ?', SqlNormalizer::normalize('SELECT 1', $dialect));
    }

    #[DataProvider('dialects')]
    public function testInvalidUtf8IsScrubbed(SqlDialect $dialect): void
    {
        $normalized = SqlNormalizer::normalize("SELECT '\xC3\x28', a\xC3 FROM `t\xFF` WHERE b = 1", $dialect);

        static::assertNotNull($normalized);
        static::assertTrue(mb_check_encoding($normalized, 'UTF-8'));
        static::assertStringStartsWith('SELECT ?, a', $normalized);
        static::assertStringEndsWith(' WHERE b = ?', $normalized);
    }

    public function testMarkupIsKeptAsText(): void
    {
        static::assertSame('SELECT ? AS "<b>"', SqlNormalizer::normalize('SELECT \'<script>\' AS "<b>"', SqlDialect::Sqlite));
    }

    public function testAnUnknownDialectGivesNothingBack(): void
    {
        static::assertNull(SqlNormalizer::normalize('SELECT 1', SqlDialect::Unknown));
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
