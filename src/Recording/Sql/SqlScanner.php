<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql;

/**
 * Reads a SQL text the way one dialect does under one reading of the backslash: a string becomes `?`, a comment a
 * space, and a quoted identifier a NUL-delimited index into the kept tokens, so that no later search looks inside one.
 */
final class SqlScanner
{
    /** What stands for a kept token in the text, as a pattern capturing its index. */
    public const KEPT = '\x00"?(\d++)\x00';
    /** The same for a double-quoted token of SQLite, which may be a value. */
    public const MAYBE_VALUE = '\x00"\d++\x00';
    /** The server rejects the text under this reading, which is then not the one it applies. */
    public const INVALID = 'invalid';
    /** What the server reads cannot be told. */
    public const UNDECIDABLE = 'undecidable';

    private const STOPS = [
        SqlDialect::MySql->value => "'\"`#-/\\$[",
        SqlDialect::PostgreSql->value => "'\"$-/\\",
        SqlDialect::Sqlite->value => "'\"`[(-/\\",
    ];
    private const DIGITS = '0123456789';
    private const TAG = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_' . self::DIGITS;
    private const PLAIN_IN_SPECIAL_COMMENT = self::TAG . " \t\n\r\f\v(),.=@";

    private static ?string $highBytes = null;

    private readonly int $length;
    private int $offset = 0;
    private int $tokenEnd = 0;
    private bool $undecidable = false;
    private string $text = '';
    /** @var list<string> */
    private array $kept = [];

    private function __construct(
        private readonly string $sql,
        private readonly SqlDialect $dialect,
        private readonly string $escaping,
    ) {
        $this->length = \strlen($sql);
    }

    /**
     * @param string $sql      a text without NUL byte
     * @param string $escaping the quotes whose strings read a backslash as an escape; a PostgreSQL E string always does
     *
     * @return array{string, list<string>}|self::INVALID|self::UNDECIDABLE the text and its kept tokens
     */
    public static function scan(string $sql, SqlDialect $dialect, string $escaping): array|string
    {
        if (!isset(self::STOPS[$dialect->value])) {
            return self::UNDECIDABLE;
        }

        $scanner = new self($sql, $dialect, $escaping);
        if ($scanner->read()) {
            return [$scanner->text, $scanner->kept];
        }

        return $scanner->undecidable ? self::UNDECIDABLE : self::INVALID;
    }

    private function read(): bool
    {
        $stops = self::STOPS[$this->dialect->value];

        while (true) {
            $this->bare(strcspn($this->sql, $stops, $this->offset));
            if ($this->offset >= $this->length) {
                return true;
            }
            if (!$this->token($this->sql[$this->offset], $this->sql[$this->offset + 1] ?? '')) {
                return false;
            }
            $this->tokenEnd = $this->offset;
        }
    }

    private function token(string $byte, string $next): bool
    {
        return match ($byte) {
            "'" => $this->string(),
            '"' => match ($this->dialect) {
                SqlDialect::MySql => $this->string(),
                SqlDialect::PostgreSql => $this->keep($this->quoteEnd(false)),
                default => $this->keep($this->quoteEnd(false), true),
            },
            '`' => $this->keep($this->quoteEnd(false)),
            // MariaDB quotes identifiers with brackets under sql_mode=MSSQL.
            '[' => $this->dialect === SqlDialect::MySql ? $this->undecidable() : $this->keep($this->after(']', 1)),
            '$' => $this->dialect === SqlDialect::MySql ? $this->mysqlDollar() : $this->postgresqlDollar(),
            '(' => $this->followsSqliteVariable() ? $this->undecidable() : $this->bare(1),
            '#' => $this->dialect === SqlDialect::MySql ? $this->lineComment() : $this->bare(1),
            '-' => $next === '-' ? $this->dashes() : $this->bare(1),
            '/' => $next === '*' ? $this->blockComment() : $this->bare(1),
            // A backslash outside a string: only `\N`, the NULL of MySQL, is SQL.
            default => $this->dialect === SqlDialect::MySql && $next === 'N' && $this->bare(2),
        };
    }

    private function bare(int $length): bool
    {
        $this->text .= substr($this->sql, $this->offset, $length);
        $this->offset += $length;

        return true;
    }

    private function undecidable(): bool
    {
        $this->undecidable = true;

        return false;
    }

    private function replace(?int $end, string $by): bool
    {
        if ($end === null) {
            return false;
        }

        $this->text .= $by;
        $this->offset = $end;

        return true;
    }

    private function keep(?int $end, bool $maybeValue = false): bool
    {
        if ($end === null) {
            return false;
        }

        $this->kept[] = substr($this->sql, $this->offset, $end - $this->offset);

        return $this->replace($end, "\0" . ($maybeValue ? '"' : '') . array_key_last($this->kept) . "\0");
    }

    private function string(): bool
    {
        $escapes = str_contains($this->escaping, $this->sql[$this->offset]);
        $e = $this->offset - 1;

        if ($this->dialect !== SqlDialect::PostgreSql || $e < 0 || ($this->sql[$e] !== 'E' && $this->sql[$e] !== 'e')) {
            return $this->replace($this->quoteEnd($escapes), '?');
        }

        $before = $e > 0 ? $this->sql[$e - 1] : ' ';
        // PostgreSQL 15 rejects an E glued to a number, earlier versions read it as the prefix of an E string.
        if (ctype_digit($before)) {
            return $this->undecidable();
        }
        if ($e !== $this->tokenEnd && self::isWord($before)) {
            return $this->replace($this->quoteEnd($escapes), '?');
        }

        $end = $this->quoteEnd(true);
        if ($end !== null && $this->isContinued($end)) {
            return $this->undecidable();
        }

        return $this->replace($end, '?');
    }

    /**
     * PostgreSQL reads a quote on a later line as the rest of the string that just ended, escapes included.
     */
    private function isContinued(int $end): bool
    {
        $newLine = false;

        while (true) {
            $blanks = strspn($this->sql, " \t\n\r\f\v", $end);
            $newLine = $newLine || strcspn($this->sql, "\n\r", $end, $blanks) < $blanks;
            $end += $blanks;
            if (($this->sql[$end] ?? '') !== '-' || ($this->sql[$end + 1] ?? '') !== '-') {
                return $newLine && ($this->sql[$end] ?? '') === "'";
            }
            $end += strcspn($this->sql, "\n\r", $end);
        }
    }

    /**
     * @return int|null the offset after the quote closing the one at the current offset
     */
    private function quoteEnd(bool $escapes): ?int
    {
        $quote = $this->sql[$this->offset];
        $stops = $escapes ? $quote . '\\' : $quote;
        $i = $this->offset + 1;

        while ($i < $this->length) {
            $i += strcspn($this->sql, $stops, $i);
            if ($i >= $this->length) {
                break;
            }
            if ($this->sql[$i] === $quote && ($this->sql[$i + 1] ?? '') !== $quote) {
                return $i + 1;
            }
            $i += 2;
        }

        return null;
    }

    private function after(string $needle, int $from): ?int
    {
        $found = strpos($this->sql, $needle, $this->offset + $from);

        return $found === false ? null : $found + \strlen($needle);
    }

    /**
     * MySQL opens a comment only before a blank or a control byte, and a byte from 0x80 is one or not depending on the
     * character set of the client.
     */
    private function dashes(): bool
    {
        $after = $this->dialect === SqlDialect::MySql ? \ord($this->sql[$this->offset + 2] ?? ' ') : 0x20;
        if ($after >= 0x80) {
            return $this->undecidable();
        }

        return $after <= 0x20 || $after === 0x7F ? $this->lineComment() : $this->bare(1);
    }

    private function lineComment(): bool
    {
        $this->offset += strcspn($this->sql, $this->dialect === SqlDialect::PostgreSql ? "\n\r" : "\n", $this->offset);
        $this->text .= ' ';

        return true;
    }

    private function blockComment(): bool
    {
        if ($this->dialect === SqlDialect::PostgreSql) {
            return $this->replace($this->nestedCommentEnd(), ' ');
        }

        $end = $this->after('*/', 2);
        if ($end !== null && $this->dialect === SqlDialect::MySql && self::mayEndElsewhere(substr($this->sql, $this->offset + 2, $end - $this->offset - 4))) {
            return $this->undecidable();
        }

        return $this->replace($end, ' ');
    }

    /**
     * MySQL runs a `/*!` or `/*M!` body as SQL and reads a `/*+` one as hints: only a body of words, blanks and a few
     * signs is sure to end where a plain comment would.
     */
    private static function mayEndElsewhere(string $body): bool
    {
        $marker = str_starts_with($body, 'M!') ? 2 : (int) ($body !== '' && ($body[0] === '!' || $body[0] === '+'));

        return $marker > 0 && strspn($body, self::PLAIN_IN_SPECIAL_COMMENT, $marker) < \strlen($body) - $marker;
    }

    private function nestedCommentEnd(): ?int
    {
        $depth = 0;
        $i = $this->offset;

        while ($i < $this->length - 1) {
            $pair = $this->sql[$i] . $this->sql[$i + 1];
            if ($pair === '/*') {
                ++$depth;
                $i += 2;
            } elseif ($pair !== '*/') {
                ++$i;
            } elseif (--$depth === 0) {
                return $i + 2;
            } else {
                $i += 2;
            }
            $i += strcspn($this->sql, '*/', $i);
        }

        return null;
    }

    /**
     * A dollar inside an identifier is skipped with the rest of it; one that starts a token opens a parameter or a
     * dollar-quoted string, and is valid in nothing else.
     */
    private function postgresqlDollar(): bool
    {
        $start = $this->wordStart($this->offset);
        if ($start < $this->offset) {
            $digits = strspn($this->sql, self::DIGITS, $start);
            // Whether `0x1F`, `1_000`, `1e` or `1.e5` is one number depends on the version of PostgreSQL.
            if ($digits === 0 ? $this->followsDecimalPoint($start) : $digits < $this->offset - $start) {
                return $this->undecidable();
            }
            if ($digits === 0) {
                return $this->skipWord();
            }
        }

        $digits = strspn($this->sql, self::DIGITS, $this->offset + 1);
        if ($digits > 0) {
            return $this->bare(1 + $digits);
        }

        $length = strspn($this->sql, self::TAG . self::highBytes(), $this->offset + 1) + 2;
        $delimiter = substr($this->sql, $this->offset, $length);
        if (\strlen($delimiter) < $length || $delimiter[-1] !== '$') {
            return false;
        }

        return $this->replace($this->after($delimiter, $length), '?');
    }

    /**
     * @return bool whether the word at $start comes right after the decimal point of a number, as `e5` in `1.e5`
     */
    private function followsDecimalPoint(int $start): bool
    {
        if ($start <= $this->tokenEnd || $this->sql[$start - 1] !== '.') {
            return false;
        }

        $number = $this->wordStart($start - 1);

        return $number < $start - 1 && ctype_digit($this->sql[$number]);
    }

    private function skipWord(): bool
    {
        return $this->bare(strspn($this->sql, self::TAG . '$' . self::highBytes(), $this->offset));
    }

    /**
     * MySQL 8 reads `$$` or `$tag$` at the start of a token as the opening of a dollar-quoted string, earlier versions
     * and MariaDB as an identifier. A word of digits is an identifier, but `1e5` or the `5` of `1.5` and `1e-5` ends a
     * number, the dollar then starting a token. So does a byte from 0x80 right before it: some character sets of the
     * client read it as a blank.
     */
    private function mysqlDollar(): bool
    {
        $start = $this->wordStart($this->offset);
        if ($start < $this->offset && $this->sql[$this->offset - 1] < "\x80") {
            $digits = strspn($this->sql, self::DIGITS, $start);
            $endsNumber = $digits === 0
                ? $this->followsDecimalPoint($start)
                : $digits < $this->offset - $start || ($start > 0 && str_contains('.+-', $this->sql[$start - 1]));

            return $endsNumber ? $this->undecidable() : $this->skipWord();
        }

        $tag = strspn($this->sql, self::TAG . self::highBytes(), $this->offset + 1);

        return ($this->sql[$this->offset + 1 + $tag] ?? '') === '$' ? $this->undecidable() : $this->bare(1 + $tag);
    }

    /**
     * A parenthesis glued to a variable makes SQLite read everything up to the next `)` as part of its name, quotes
     * included.
     */
    private function followsSqliteVariable(): bool
    {
        $start = $this->wordStart($this->offset, ':');
        $name = substr($this->sql, $start, $this->offset - $start);

        if ($name === '') {
            return false;
        }

        return $name[0] === '$' || str_contains($name, ':') || ($start > $this->tokenEnd && str_contains('@#', $this->sql[$start - 1]));
    }

    /**
     * @return int where the run of identifier bytes ending at $end starts, the last token being its limit
     */
    private function wordStart(int $end, string $also = ''): int
    {
        $start = $end;
        while ($start > $this->tokenEnd && (self::isWord($this->sql[$start - 1]) || $this->sql[$start - 1] === $also)) {
            --$start;
        }

        return $start;
    }

    private static function isWord(string $byte): bool
    {
        return $byte >= "\x80" || $byte === '_' || $byte === '$' || ctype_alnum($byte);
    }

    private static function highBytes(): string
    {
        return self::$highBytes ??= implode('', array_map(\chr(...), range(0x80, 0xFF)));
    }
}
