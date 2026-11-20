<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording\Sql;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * SELECT statements whose every value is known, written with the lexical rules of one server under one session
 * setting: the strings hold a marker among bytes that a misreading would take for quotes or comments.
 */
final class LiteralStatements
{
    public const MODES = ['sqlite', 'mysql', 'mysql-no-backslash', 'mysql-ansi', 'pgsql', 'pgsql-escapes'];
    public const MARKER = 'S3CR3T';
    public const NUMBER = '73571';

    private const FRAGMENTS = ["'", '"', '\\', '#', '--', '-- ', '/*', '*/', '$$', '$t$', '`', '[', ']', ';', '(', ')', "E'", ' ', "\n", 'é'];
    private const NUMBERS = [['73571', 73571.0], ['-73571', -73571.0], ['73571.5', 73571.5], ['.73571', 0.73571], ['73571e2', 7357100.0], ['73571.', 73571.0]];
    /** MySQL reads a hexadecimal literal as a binary string, its odd digit count padded with a leading zero. */
    private const HEXADECIMAL = ['mysql' => ['0x73571', "\x07\x35\x71"], 'sqlite' => ['0x73571', 472433.0], 'pgsql' => ['0x73571', 472433.0]];
    private const COMMENTS = ['sqlite' => ['-- ', '/*'], 'mysql' => ['#', '-- ', '/*'], 'pgsql' => ['-- ', '/*']];

    private function __construct(
        private readonly string $mode,
        private readonly Randomizer $random,
    ) {}

    /**
     * @return iterable<array{string, list<string|float>, bool}> the statement, its values in order, and whether it
     *                                                           holds a form the normalizer leaves undecided by design
     */
    public static function generate(string $mode, int $count, int $seed): iterable
    {
        $statements = new self($mode, new Randomizer(new Mt19937($seed)));

        for ($i = 0; $i < $count; ++$i) {
            yield $statements->statement();
        }
    }

    /**
     * @return array{string, list<string|float>, bool}
     */
    private function statement(): array
    {
        $elements = [];
        $values = [];
        $undecided = false;

        for ($n = $this->random->getInt(1, 6); $n > 0; --$n) {
            [$element, $value, $undecidable] = match ($this->random->getInt(0, 2)) {
                0 => $this->literal($this->value()),
                1 => $this->comment(),
                default => $this->number(),
            };
            $elements[] = $element;
            $values[] = $value;
            $undecided = $undecided || $undecidable;
        }

        return ['SELECT ' . implode(', ', $elements), $values, $undecided];
    }

    private function value(): string
    {
        $value = self::MARKER;
        for ($n = $this->random->getInt(0, 4); $n > 0; --$n) {
            $fragment = $this->pick(self::FRAGMENTS);
            $value = $this->random->getInt(0, 1) === 0 ? $fragment . $value : $value . $fragment;
        }

        return $value;
    }

    /**
     * @return array{string, string, bool}
     */
    private function literal(string $value): array
    {
        $segments = $this->segments($value);

        $literal = match ($this->mode) {
            'sqlite' => $this->quoted($value, "'", false),
            'mysql' => $this->adjacent($segments, $this->pick(["'", '"']), true),
            'mysql-no-backslash' => $this->adjacent($segments, $this->pick(["'", '"']), false),
            'mysql-ansi' => $this->adjacent($segments, "'", true),
            'pgsql' => match ($this->random->getInt(0, 2)) {
                0 => $this->continued($segments, '', false),
                1 => $this->continued($segments, 'E', true),
                default => $this->dollarQuoted($value) ?? $this->continued($segments, '', false),
            },
            'pgsql-escapes' => $this->continued($segments, '', true),
        };

        return [$literal, $value, str_starts_with($literal, 'E') && \count($segments) > 1];
    }

    /**
     * @return list<string> the value, or now and then the two parts of it a server joins back, cut outside the marker
     */
    private function segments(string $value): array
    {
        if ($this->random->getInt(0, 3) > 0) {
            return [$value];
        }

        $characters = mb_str_split($value);
        $marker = (int) mb_strpos($value, self::MARKER);
        $cuts = array_values(array_diff(range(0, \count($characters)), range($marker + 1, $marker + \strlen(self::MARKER) - 1)));
        $cut = $this->pick($cuts);

        return [implode('', \array_slice($characters, 0, $cut)), implode('', \array_slice($characters, $cut))];
    }

    /**
     * @param list<string> $segments MySQL concatenates adjacent strings
     */
    private function adjacent(array $segments, string $quote, bool $backslashEscapes): string
    {
        return implode(' ', array_map(fn(string $segment): string => $this->quoted($segment, $quote, $backslashEscapes), $segments));
    }

    /**
     * @param list<string> $segments PostgreSQL continues a string with a quote on a later line, its prefix included
     */
    private function continued(array $segments, string $prefix, bool $backslashEscapes): string
    {
        return $prefix . implode("\n", array_map(fn(string $segment): string => $this->quoted($segment, "'", $backslashEscapes), $segments));
    }

    private function quoted(string $value, string $quote, bool $backslashEscapes): string
    {
        $escaped = '';
        foreach (mb_str_split($backslashEscapes ? str_replace('\\', '\\\\', $value) : $value) as $character) {
            if ($character === $quote) {
                $character = $backslashEscapes && $this->random->getInt(0, 1) === 0 ? '\\' . $quote : $quote . $quote;
            }
            $escaped .= $character;
        }

        return $quote . $escaped . $quote;
    }

    private function dollarQuoted(string $value): ?string
    {
        $delimiter = '$' . $this->pick(['', 't', 'q']) . '$';

        return strpos($value . $delimiter, $delimiter) === \strlen($value) ? $delimiter . $value . $delimiter : null;
    }

    /**
     * @return array{string, float, false}
     */
    private function comment(): array
    {
        $opening = $this->pick(self::COMMENTS[$this->server()]);
        $fragments = array_values(array_diff(self::FRAGMENTS, $opening === '/*' ? ['/*', '*/'] : ["\n"]));

        $body = '';
        for ($n = $this->random->getInt(0, 4); $n > 0; --$n) {
            $body .= $this->pick($fragments);
        }

        return [$opening === '/*' ? '/* ' . $body . ' */ 1' : $opening . $body . "\n1", 1.0, false];
    }

    /**
     * @return array{string, string|float, false}
     */
    private function number(): array
    {
        [$number, $value] = $this->pick([...self::NUMBERS, self::HEXADECIMAL[$this->server()]]);

        $quotes = match ($this->mode) {
            'sqlite' => $this->pick(['""', '``', '[]']),
            'mysql', 'mysql-no-backslash' => '``',
            'mysql-ansi' => $this->pick(['""', '``']),
            default => '""',
        };

        return [$number . ' AS ' . $this->identifier($quotes[0], $quotes[1]), $value, false];
    }

    /**
     * A name of hostile fragments, never empty nor ending with a blank, which MySQL refuses.
     */
    private function identifier(string $open, string $close): string
    {
        $fragments = array_values(array_diff(self::FRAGMENTS, [$close === ']' ? ']' : '']));

        $name = '';
        for ($n = $this->random->getInt(1, 4); $n > 0; --$n) {
            $name .= $this->pick($fragments);
        }
        if (ctype_space($name[-1])) {
            $name .= 'n';
        }

        return $open . ($close === ']' ? $name : str_replace($close, $close . $close, $name)) . $close;
    }

    private function server(): string
    {
        return explode('-', $this->mode)[0];
    }

    /**
     * @template T
     *
     * @param list<T> $list
     *
     * @return T
     */
    private function pick(array $list): mixed
    {
        return $list[$this->random->getInt(0, \count($list) - 1)];
    }
}
