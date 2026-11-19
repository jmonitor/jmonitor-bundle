<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql;

final class SqlNormalizer
{
    public const MAX_LENGTH = 2000;

    private const KEPT = '\x00"?(\d++)\x00';
    private const MAYBE_VALUE = '\x00"\d++\x00';
    private const MARKER = '(?:\?|:\w++|\$\d++|' . self::MAYBE_VALUE . ')';

    private const DIGITS = '\d[\d_]*+';

    /** Numbers, then blanks, then a SQLite double-quoted token right after a comparison, then IN lists. */
    private const PASSES = [
        '~' . self::KEPT . '(*SKIP)(*FAIL)|(?<![\w$.])[-+]?(?:0[xbo][0-9a-f_]++|(?:' . self::DIGITS . '(?:\.(?:' . self::DIGITS . ')?+)?|\.' . self::DIGITS . ')(?:e[-+]?' . self::DIGITS . ')?)(?![\w$])~iu' => '?',
        '~\s++~u' => ' ',
        '~(?:[=<>]|\bLIKE)\s*+\K' . self::MAYBE_VALUE . '~iu' => '?',
        '~\b(IN)\s*+\(\s*+' . self::MARKER . '(?:\s*+,\s*+' . self::MARKER . ')*+\s*+\)~i' => '$1 (?)',
    ];
    private const VALUES_START = '~\bVALUES(?=\s*+\()~i';
    private const BLANKS = " \t\n\r\f\v";

    /**
     * Whether a backslash escapes a quote depends on a session setting that is not known here: the text is read once
     * per setting its dialect allows, and kept only when the readings that delimit it all give the same result.
     *
     * @return string|null null as well when a pattern fails, the text then possibly still holding values
     */
    public static function normalize(string $sql, SqlDialect $dialect): ?string
    {
        if ($dialect === SqlDialect::Unknown || str_contains($sql, "\0")) {
            return null;
        }

        $read = null;
        foreach (self::readings($sql, $dialect) as $escaping) {
            $reading = SqlScanner::scan($sql, $dialect, $escaping);
            if ($reading === false || ($reading !== null && $read !== null && $reading !== $read)) {
                return null;
            }
            $read ??= $reading;
        }
        if ($read === null) {
            return null;
        }

        [$text, $kept] = $read;
        $text = preg_replace(array_keys(self::PASSES), array_values(self::PASSES), mb_scrub($text, 'UTF-8'));
        if ($text === null) {
            return null;
        }

        $text = self::foldValues($text);
        if ($text === null) {
            return null;
        }

        $text = preg_replace_callback('~' . self::KEPT . '~', static fn(array $marker): string => $kept[(int) $marker[1]], $text);
        if ($text === null) {
            return null;
        }

        return mb_substr(rtrim(trim(mb_scrub($text, 'UTF-8')), '; '), 0, self::MAX_LENGTH);
    }

    public static function id(string $normalized): string
    {
        return hash('xxh3', $normalized);
    }

    public static function isSelect(string $normalized): bool
    {
        if (preg_match('~^(?:\(\s*+)*+(SELECT|WITH)\b~i', $normalized, $match) !== 1) {
            return false;
        }

        return strtoupper($match[1]) === 'SELECT' || preg_match('~\b(?:INSERT|UPDATE|DELETE|MERGE)\b~i', $normalized) === 0;
    }

    /**
     * @return list<string> per reading, the quotes whose strings take a backslash as an escape
     */
    private static function readings(string $sql, SqlDialect $dialect): array
    {
        if ($dialect === SqlDialect::Sqlite || !str_contains($sql, '\\')) {
            return [''];
        }
        if ($dialect === SqlDialect::PostgreSql) {
            return ['', "'"];
        }

        return str_contains($sql, '"') ? ['\'"', "'", ''] : ["'", ''];
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

            $first = preg_replace('~' . self::MAYBE_VALUE . '~', '?', substr($sql, $open, $end - $open));
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
            $i += strcspn($sql, '()', $i);
            if ($i >= $length) {
                break;
            }
            if ($sql[$i] === '(') {
                ++$depth;
            } elseif (--$depth === 0) {
                return $i + 1;
            }
        }

        return null;
    }
}
