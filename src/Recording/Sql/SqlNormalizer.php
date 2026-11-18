<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql;

final class SqlNormalizer
{
    public const MAX_LENGTH = 2000;

    private const DOUBLE_QUOTED = '"(?:[^"]++|"")*+"';
    private const BACKTICKED = '`(?:[^`]++|``)*+`';
    private const MARKER = '(?:\?|:\w++|\$\d++|' . self::DOUBLE_QUOTED . ')';

    /**
     * A double-quoted token right after a comparison is a MySQL or SQLite string, elsewhere an identifier;
     * an unterminated token runs to the end of the text, which keeps the pass linear.
     */
    private const TOKENS = '~'
        . '(?<value>(?:[=<>]|\bLIKE)\s*+)"(?:[^"]++|"")*+(?:"|\z)'
        . '|(?<quoted>"(?:[^"]++|"")*+(?:"|\z)|`(?:[^`]++|``)*+(?:`|\z))'
        . '|(?<blank>(?:\s++|/\*(?:[^*]++|\*(?!/))*+(?:\*/|\z)|--[^\n]*+)++)'
        . '|\'(?:[^\'\\\\]++|\\\\.?|\'\')*+(?:\'|\z)'
        . '|(?<![\w$.])[-+]?(?:0x[0-9a-f]++|(?:\d++(?:\.\d*+)?|\.\d++)(?:e[-+]?\d++)?)(?![\w$])'
        . '~isu';

    /** Skips quoted tokens whole, so that no search starts inside one. */
    private const SKIP_QUOTED = '(?:"(?:[^"]++|"")*+(?:"|\z)|`(?:[^`]++|``)*+(?:`|\z))(*SKIP)(*FAIL)';
    private const IN_LIST = '~' . self::SKIP_QUOTED . '|\b(IN)\s*+\(\s*+' . self::MARKER . '(?:\s*+,\s*+' . self::MARKER . ')*+\s*+\)~i';
    private const VALUES_START = '~' . self::SKIP_QUOTED . '|\bVALUES(?=\s*+\()~i';
    private const VALUE_IN_GROUP = '~' . self::BACKTICKED . '(*SKIP)(*FAIL)|' . self::DOUBLE_QUOTED . '~';
    private const BLANKS = " \t\n\r\f\v";
    private const DOLLAR_QUOTE = '~(?:(?<![\w$])|(?<=\d))\$(?:[^\W\d]\w*+)?\$~u';

    /**
     * Whether a backslash escapes a quote depends on the dialect and its settings, which are not known here: a text
     * holding one, or a PostgreSQL dollar quote, gives null rather than risk reading a value as SQL.
     *
     * @return string|null null as well when a pattern fails, the text then possibly still holding values
     */
    public static function normalize(string $sql): ?string
    {
        $sql = mb_scrub($sql, 'UTF-8');
        if (str_contains($sql, '\\\'') || str_contains($sql, '\\"') || preg_match(self::DOLLAR_QUOTE, $sql) !== 0) {
            return null;
        }

        $sql = preg_replace_callback(self::TOKENS, self::token(...), $sql, flags: \PREG_UNMATCHED_AS_NULL);
        if ($sql === null) {
            return null;
        }

        $sql = preg_replace(self::IN_LIST, '$1 (?)', $sql);
        if ($sql === null) {
            return null;
        }

        $sql = self::foldValues($sql);
        if ($sql === null) {
            return null;
        }

        return mb_substr(rtrim(trim($sql), '; '), 0, self::MAX_LENGTH);
    }

    public static function id(string $normalized): string
    {
        return hash('xxh3', $normalized);
    }

    public static function isSelect(string $normalized): bool
    {
        return preg_match('~^(?:SELECT|WITH)\b~i', $normalized) === 1;
    }

    /**
     * Keeps the first group of each VALUES list; a scan that reaches the end of the text without closing its
     * group stops the fold, since every later start lies inside that group.
     */
    private static function foldValues(string $sql): ?string
    {
        if (preg_match_all(self::VALUES_START, $sql, $starts, \PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        $folded = '';
        $copied = 0;
        foreach ($starts[0] as [$keyword, $offset]) {
            if ($offset < $copied) {
                continue;
            }

            $open = $offset + \strlen($keyword);
            $open += strspn($sql, self::BLANKS, $open);
            $end = self::groupEnd($sql, $open);
            if ($end === null) {
                break;
            }

            $first = preg_replace(self::VALUE_IN_GROUP, '?', substr($sql, $open, $end - $open));
            if ($first === null) {
                return null;
            }

            $folded .= substr($sql, $copied, $offset - $copied) . $keyword . ' ' . $first;
            $copied = $end;

            while (true) {
                $comma = $end + strspn($sql, self::BLANKS, $end);
                if (($sql[$comma] ?? '') !== ',') {
                    break;
                }

                $next = $comma + 1 + strspn($sql, self::BLANKS, $comma + 1);
                if (($sql[$next] ?? '') !== '(') {
                    break;
                }

                $end = self::groupEnd($sql, $next);
                if ($end === null) {
                    return $folded . substr($sql, $copied);
                }
                $copied = $end;
            }
        }

        return $folded . substr($sql, $copied);
    }

    /**
     * @return int|null the offset after the parenthesis closing the one at $open, null when the text ends first
     */
    private static function groupEnd(string $sql, int $open): ?int
    {
        $length = \strlen($sql);
        $depth = 0;

        for ($i = $open; $i < $length; ++$i) {
            $i += strcspn($sql, '()"`', $i);
            $char = $sql[$i] ?? null;

            if ($char === '(') {
                ++$depth;
            } elseif ($char === ')') {
                if (--$depth === 0) {
                    return $i + 1;
                }
            } elseif ($char !== null) {
                $i = self::quoteEnd($sql, $i);
                if ($i === null) {
                    return null;
                }
            }
        }

        return null;
    }

    private static function quoteEnd(string $sql, int $open): ?int
    {
        $quote = $sql[$open];
        $i = $open;

        while (true) {
            $i = strpos($sql, $quote, $i + 1);
            if ($i === false) {
                return null;
            }
            if (($sql[$i + 1] ?? '') !== $quote) {
                return $i;
            }
            ++$i;
        }
    }

    /**
     * @param array<int|string, string|null> $token
     */
    private static function token(array $token): string
    {
        if ($token['value'] !== null) {
            $operator = rtrim($token['value']);

            return $operator . ($operator === $token['value'] ? '' : ' ') . '?';
        }

        return $token['quoted'] ?? ($token['blank'] !== null ? ' ' : '?');
    }
}
