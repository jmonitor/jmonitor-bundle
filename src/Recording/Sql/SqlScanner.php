<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql;

/**
 * Reads a SQL text the way one dialect does under one reading of the backslash: a string becomes `?`, a comment a
 * space, and a quoted identifier a NUL-delimited index into the kept tokens, so that no later search looks inside one.
 */
final class SqlScanner
{
    private const STOPS = [
        SqlDialect::MySql->value => "'\"`#-/\\",
        SqlDialect::PostgreSql->value => "'\"$-/\\",
        SqlDialect::Sqlite->value => "'\"`[(-/\\",
    ];
    private const DIGITS = '0123456789';
    private const TAG = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_' . self::DIGITS;

    private static ?string $highBytes = null;

    private readonly int $length;
    private int $offset = 0;
    private int $tokenEnd = 0;
    private bool $followed = true;
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
     * @return array{string, list<string>}|false|null the text and its kept tokens, a token SQLite may read as a value
     *                                                being marked by a `"` before its index; null when the server
     *                                                rejects the text under this reading, which is then not the one
     *                                                it applies; false when what the server reads cannot be told
     */
    public static function scan(string $sql, SqlDialect $dialect, string $escaping): array|false|null
    {
        if (!isset(self::STOPS[$dialect->value])) {
            return false;
        }

        $scanner = new self($sql, $dialect, $escaping);
        if ($scanner->read()) {
            return [$scanner->text, $scanner->kept];
        }

        return $scanner->followed ? null : false;
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
                default => $this->keep($this->quoteEnd(false), '"'),
            },
            '`' => $this->keep($this->quoteEnd(false)),
            '[' => $this->keep($this->after(']', 1)),
            '$' => $this->dollar(),
            '(' => $this->followsVariable() ? $this->lost() : $this->bare(1),
            '#' => $this->lineComment(),
            '-' => $next === '-' && $this->dashesOpenComment() ? $this->lineComment() : $this->bare(1),
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

    private function lost(): bool
    {
        $this->followed = false;

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

    private function keep(?int $end, string $mark = ''): bool
    {
        if ($end === null) {
            return false;
        }

        $this->kept[] = substr($this->sql, $this->offset, $end - $this->offset);

        return $this->replace($end, "\0" . $mark . array_key_last($this->kept) . "\0");
    }

    private function string(): bool
    {
        $escapes = str_contains($this->escaping, $this->sql[$this->offset]);

        if ($this->dialect === SqlDialect::PostgreSql) {
            $e = $this->offset - 1;
            if ($e >= 0 && ($this->sql[$e] === 'E' || $this->sql[$e] === 'e')) {
                $before = $e > 0 ? $this->sql[$e - 1] : ' ';
                // PostgreSQL 15 rejects an E glued to a number, earlier versions read it as the prefix of an E string.
                if (ctype_digit($before)) {
                    return $this->lost();
                }
                $escapes = $escapes || $e === $this->tokenEnd || !self::isWord($before);
            }
        }

        return $this->replace($this->quoteEnd($escapes), '?');
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

    private function dashesOpenComment(): bool
    {
        if ($this->dialect !== SqlDialect::MySql) {
            return true;
        }

        $after = \ord($this->sql[$this->offset + 2] ?? ' ');

        return $after <= 0x20 || $after === 0x7F;
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
        if ($end !== null && $this->dialect === SqlDialect::MySql && $this->hidesItsEnd(substr($this->sql, $this->offset + 2, $end - $this->offset - 3))) {
            return $this->lost();
        }

        return $this->replace($end, ' ');
    }

    /**
     * MySQL runs the body of a `/*!` or `/*M!` comment as SQL and reads the one of a `/*+` comment as hints: a quote,
     * a nested comment or a line comment in there can hold the end found here, the comment then ending further.
     *
     * @param string $body up to the `*` of the end, which a slash right before it turns into a nested opening
     */
    private function hidesItsEnd(string $body): bool
    {
        if ($body[0] !== '!' && $body[0] !== '+' && !str_starts_with($body, 'M!')) {
            return false;
        }

        return strpbrk($body, '\'"`#') !== false || str_contains($body, '/*') || str_contains($body, '--');
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
    private function dollar(): bool
    {
        $start = $this->wordStart();
        if ($start < $this->offset) {
            if (!ctype_digit($this->sql[$start])) {
                return $this->bare(strspn($this->sql, self::TAG . '$' . self::highBytes(), $this->offset));
            }
            // Whether `0x1F`, `1_000` or `1e` is one number depends on the version of PostgreSQL.
            if (strspn($this->sql, self::DIGITS, $start) < $this->offset - $start) {
                return $this->lost();
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
     * A parenthesis glued to a variable makes SQLite read everything up to the next `)` as part of its name, quotes
     * included.
     */
    private function followsVariable(): bool
    {
        $start = $this->wordStart(':');
        $name = substr($this->sql, $start, $this->offset - $start);

        if ($name === '') {
            return false;
        }

        return $name[0] === '$' || str_contains($name, ':') || ($start > $this->tokenEnd && str_contains('@#', $this->sql[$start - 1]));
    }

    /**
     * @return int where the run of identifier bytes before the current offset starts, the last token being its limit
     */
    private function wordStart(string $also = ''): int
    {
        $start = $this->offset;
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
