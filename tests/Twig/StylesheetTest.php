<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Twig;

use PHPUnit\Framework\TestCase;

final class StylesheetTest extends TestCase
{
    private const DARK = '.jm';
    private const LIGHT = '.jm[data-jm-theme="light"]';
    private const COLOUR = '/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?)\(/i';

    public function testTheParserReadsTopLevelMediaAndKeyframeRules(): void
    {
        $rules = self::rules();

        static::assertContains('.jm-live__switch:checked', self::selectorsIn($rules, null));
        static::assertContains('.jm-cards', self::selectorsIn($rules, '@media (max-width: 720px)'));
        static::assertContains('0%', self::selectorsIn($rules, '@keyframes jm-pulse'));
    }

    public function testEverySelectorLivesUnderJm(): void
    {
        $selectors = [];
        foreach (self::rules() as $rule) {
            if (!$rule['keyframes']) {
                array_push($selectors, ...$rule['selectors']);
            }
        }

        static::assertNotEmpty($selectors);
        foreach ($selectors as $selector) {
            static::assertMatchesRegularExpression('/^(?:body)?\.jm(?![a-z0-9_])/i', $selector);
        }
    }

    public function testCustomPropertiesAreOnlySetOnJm(): void
    {
        $count = 0;
        foreach (self::rules() as $rule) {
            foreach ($rule['declarations'] as [$property]) {
                if (!str_starts_with($property, '--')) {
                    continue;
                }

                ++$count;
                foreach ($rule['selectors'] as $selector) {
                    static::assertMatchesRegularExpression('/^(?:\.jm|\.jm\[[^\]]+\]|body\.jm)$/', $selector, $property);
                }
            }
        }

        static::assertGreaterThan(0, $count);
    }

    public function testNoColourIsWrittenOutsideTheTwoThemes(): void
    {
        foreach (self::rules() as $rule) {
            $theme = $rule['at'] === null && \in_array($rule['selectors'], [[self::DARK], [self::LIGHT]], true);

            foreach ($rule['declarations'] as [$property, $value]) {
                if ($theme && str_starts_with($property, '--jm-')) {
                    continue;
                }

                static::assertDoesNotMatchRegularExpression(self::COLOUR, $value, implode(', ', $rule['selectors']) . ' { ' . $property . ' }');
            }
        }
    }

    public function testTheLightThemeRedefinesEveryColourOfTheDarkOne(): void
    {
        $dark = array_keys(array_filter(self::theme(self::DARK), static fn(string $value): bool => preg_match(self::COLOUR, $value) === 1));
        $light = array_keys(self::theme(self::LIGHT));
        sort($dark);
        sort($light);

        static::assertContains('--jm-bg', $dark);
        static::assertSame($dark, $light);
    }

    public function testTheLightThemeKeepsTextReadable(): void
    {
        $light = self::theme(self::LIGHT);
        $background = self::colour($light['--jm-bg'] ?? '');

        static::assertSame(1.0, $background[3], 'The light background is opaque');
        foreach (['--jm-text', '--jm-muted', '--jm-faint'] as $name) {
            $contrast = self::contrast(self::over(self::colour($light[$name] ?? ''), $background), $background);
            static::assertGreaterThanOrEqual(4.5, $contrast, $name);
        }
    }

    public function testForcedColorsCoverTheLiveSwitch(): void
    {
        $selectors = self::selectorsIn(self::rules(), '@media (forced-colors: active)');

        static::assertNotEmpty(array_filter($selectors, static fn(string $selector): bool => str_contains($selector, '.jm-live__switch')));
    }

    /**
     * @param list<array{at: string|null, keyframes: bool, selectors: list<string>, declarations: list<array{string, string}>}> $rules
     *
     * @return list<string>
     */
    private static function selectorsIn(array $rules, ?string $at): array
    {
        $selectors = [];
        foreach ($rules as $rule) {
            if ($rule['at'] === $at) {
                array_push($selectors, ...$rule['selectors']);
            }
        }

        return $selectors;
    }

    /**
     * @return array<string, string>
     */
    private static function theme(string $selector): array
    {
        $variables = [];
        foreach (self::rules() as $rule) {
            if ($rule['at'] === null && $rule['selectors'] === [$selector]) {
                foreach ($rule['declarations'] as [$property, $value]) {
                    if (str_starts_with($property, '--jm-')) {
                        $variables[$property] = $value;
                    }
                }
            }
        }

        return $variables;
    }

    /**
     * @return list<array{at: string|null, keyframes: bool, selectors: list<string>, declarations: list<array{string, string}>}>
     */
    private static function rules(): array
    {
        $css = file_get_contents(__DIR__ . '/../../public/jmonitor.css');
        static::assertIsString($css);
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? '';

        $rules = [];
        foreach (self::blocks($css) as [$prelude, $body]) {
            if (!str_starts_with($prelude, '@')) {
                $rules[] = self::rule($prelude, $body, null);
                continue;
            }

            if (!str_starts_with($prelude, '@media ') && !str_starts_with($prelude, '@keyframes ')) {
                throw new \RuntimeException('Unsupported at-rule: ' . $prelude);
            }

            foreach (self::blocks($body) as [$inner, $innerBody]) {
                if (str_starts_with($inner, '@')) {
                    throw new \RuntimeException('Nested at-rule: ' . $inner);
                }

                $rules[] = self::rule($inner, $innerBody, $prelude);
            }
        }

        return $rules;
    }

    /**
     * @return array{at: string|null, keyframes: bool, selectors: list<string>, declarations: list<array{string, string}>}
     */
    private static function rule(string $prelude, string $body, ?string $at): array
    {
        if (str_contains($body, '{')) {
            throw new \RuntimeException('Nested block in ' . $prelude);
        }

        $declarations = [];
        foreach (self::split($body, ';') as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false) {
                throw new \RuntimeException('Malformed declaration in ' . $prelude . ': ' . $declaration);
            }

            $declarations[] = [trim(substr($declaration, 0, $colon)), trim(substr($declaration, $colon + 1))];
        }

        return [
            'at' => $at,
            'keyframes' => $at !== null && str_starts_with($at, '@keyframes '),
            'selectors' => self::split($prelude, ','),
            'declarations' => $declarations,
        ];
    }

    /**
     * @return list<array{string, string}> prelude and body of each block, in order
     */
    private static function blocks(string $css): array
    {
        $blocks = [];
        $start = 0;
        $length = \strlen($css);

        for ($i = 0; $i < $length; ++$i) {
            $char = $css[$i];
            if ($char === '"' || $char === "'") {
                $i = self::endOfString($css, $i);
            } elseif ($char === '}' || $char === ';') {
                throw new \RuntimeException(\sprintf('Unexpected "%s" at offset %d', $char, $i));
            } elseif ($char === '{') {
                $end = self::closingBrace($css, $i);
                $blocks[] = [self::normalize(substr($css, $start, $i - $start)), substr($css, $i + 1, $end - $i - 1)];
                $i = $end;
                $start = $end + 1;
            }
        }

        if (trim(substr($css, $start)) !== '') {
            throw new \RuntimeException('Trailing content: ' . trim(substr($css, $start)));
        }

        return $blocks;
    }

    private static function closingBrace(string $css, int $open): int
    {
        $depth = 0;
        $length = \strlen($css);

        for ($i = $open; $i < $length; ++$i) {
            $char = $css[$i];
            if ($char === '"' || $char === "'") {
                $i = self::endOfString($css, $i);
            } elseif ($char === '{') {
                ++$depth;
            } elseif ($char === '}' && --$depth === 0) {
                return $i;
            }
        }

        throw new \RuntimeException(\sprintf('Unclosed "{" at offset %d', $open));
    }

    private static function endOfString(string $css, int $open): int
    {
        $end = strpos($css, $css[$open], $open + 1);
        if ($end === false) {
            throw new \RuntimeException(\sprintf('Unclosed string at offset %d', $open));
        }

        return $end;
    }

    /**
     * @return list<string> the non-empty parts of $text cut at each $separator outside strings and parentheses
     */
    private static function split(string $text, string $separator): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $length = \strlen($text);

        for ($i = 0; $i < $length; ++$i) {
            $char = $text[$i];
            if ($char === '"' || $char === "'") {
                $i = self::endOfString($text, $i);
            } elseif ($char === '(' || $char === '[') {
                ++$depth;
            } elseif ($char === ')' || $char === ']') {
                --$depth;
            } elseif ($char === $separator && $depth === 0) {
                $parts[] = substr($text, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $parts[] = substr($text, $start);

        return array_values(array_filter(array_map(self::normalize(...), $parts), static fn(string $part): bool => $part !== ''));
    }

    private static function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /**
     * @return array{float, float, float, float} red, green and blue from 0 to 255, alpha from 0 to 1
     */
    private static function colour(string $value): array
    {
        if (preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value, $match) === 1) {
            $hex = \strlen($match[1]) <= 4 ? preg_replace('/./', '$0$0', $match[1]) ?? '' : $match[1];
            $channels = array_map(hexdec(...), str_split($hex . (\strlen($hex) === 6 ? 'ff' : ''), 2));

            return [(float) $channels[0], (float) $channels[1], (float) $channels[2], (float) $channels[3] / 255];
        }

        if (preg_match('/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)\s*(?:[,\/]\s*([\d.]+)\s*)?\)$/i', $value, $match) === 1) {
            return [(float) $match[1], (float) $match[2], (float) $match[3], isset($match[4]) ? (float) $match[4] : 1.0];
        }

        static::fail('Not a colour this test reads: "' . $value . '"');
    }

    /**
     * @param array{float, float, float, float} $colour
     * @param array{float, float, float, float} $background
     *
     * @return array{float, float, float, float}
     */
    private static function over(array $colour, array $background): array
    {
        $alpha = $colour[3];

        return [
            $colour[0] * $alpha + $background[0] * (1 - $alpha),
            $colour[1] * $alpha + $background[1] * (1 - $alpha),
            $colour[2] * $alpha + $background[2] * (1 - $alpha),
            1.0,
        ];
    }

    /**
     * @param array{float, float, float, float} $a
     * @param array{float, float, float, float} $b
     */
    private static function contrast(array $a, array $b): float
    {
        $lighter = max(self::luminance($a), self::luminance($b));
        $darker = min(self::luminance($a), self::luminance($b));

        return ($lighter + .05) / ($darker + .05);
    }

    /**
     * @param array{float, float, float, float} $colour
     */
    private static function luminance(array $colour): float
    {
        $linear = array_map(
            static fn(float $channel): float => $channel / 255 <= .04045 ? $channel / 255 / 12.92 : (($channel / 255 + .055) / 1.055) ** 2.4,
            \array_slice($colour, 0, 3),
        );

        return .2126 * $linear[0] + .7152 * $linear[1] + .0722 * $linear[2];
    }
}
