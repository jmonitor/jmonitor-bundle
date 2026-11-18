<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql;

final class SqlNormalizer
{
    public const MAX_LENGTH = 2000;

    private const TOKENS = <<<'REGEX'
        ~
          (?<quoted>"(?:[^"]++|"")*+"|`(?:[^`]++|``)*+`)
        | (?<blank>(?:\s++|/\*.*?\*/|--[^\n]*+)++)
        | '(?:[^'\\]++|\\.|'')*+'
        | (?<![\w$.])[-+]?(?:0x[0-9a-f]++|(?:\d++(?:\.\d*+)?|\.\d++)(?:e[-+]?\d++)?)(?![\w$])
        ~xisu
        REGEX;

    private const MARKER = '(?:\?|:\w++|\$\d++)';

    public static function normalize(string $sql): string
    {
        $sql = mb_scrub($sql, 'UTF-8');

        $sql = preg_replace_callback(
            self::TOKENS,
            static fn(array $token): string => $token['quoted'] ?? ($token['blank'] !== null ? ' ' : '?'),
            $sql,
            flags: \PREG_UNMATCHED_AS_NULL,
        ) ?? $sql;

        $sql = preg_replace('~\b(IN)\s*+\(\s*+' . self::MARKER . '(?:\s*+,\s*+' . self::MARKER . ')*+\s*+\)~iu', '$1 (?)', $sql) ?? $sql;
        $sql = preg_replace('~\b(VALUES)\s*+(\((?:[^()]++|(?2))*+\))(?:\s*+,\s*+(?2))++~iu', '$1 $2', $sql) ?? $sql;

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
}
