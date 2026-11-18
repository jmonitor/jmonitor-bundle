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

    private const IN_LIST = '~\b(IN)\s*+\(\s*+' . self::MARKER . '(?:\s*+,\s*+' . self::MARKER . ')*+\s*+\)~iu';
    private const VALUES_GROUPS = '~\b(VALUES)\s*+(\((?:[^()"`]++|' . self::DOUBLE_QUOTED . '|' . self::BACKTICKED . '|(?2))*+\))(?:\s*+,\s*+(?2))*+~iu';

    /**
     * @return string|null null when a pattern fails, the text then possibly still holding values
     */
    public static function normalize(string $sql): ?string
    {
        $sql = preg_replace_callback(self::TOKENS, self::token(...), mb_scrub($sql, 'UTF-8'), flags: \PREG_UNMATCHED_AS_NULL);
        if ($sql === null) {
            return null;
        }

        $sql = preg_replace(self::IN_LIST, '$1 (?)', $sql);
        if ($sql === null) {
            return null;
        }

        $failed = false;
        $sql = preg_replace_callback(self::VALUES_GROUPS, static function (array $groups) use (&$failed): string {
            $first = preg_replace('~' . self::DOUBLE_QUOTED . '~u', '?', $groups[2]);
            $failed = $failed || $first === null;

            return $groups[1] . ' ' . $first;
        }, $sql);
        if ($sql === null || $failed) {
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
