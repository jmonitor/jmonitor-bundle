<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class StylesheetTest extends TestCase
{
    private const DARK = ['.jm'];
    private const LIGHT = ['.jm[data-jm-theme="light"]', '[data-bs-theme="light"] .jm[data-jm-theme="auto"]'];
    private const HOST_ANCESTORS = ['[data-bs-theme="light"] '];
    private const UNDER_JM = '/^(?:body)?\.jm(?![a-z0-9_])/i';
    private const ON_JM = '/^(?:\.jm|\.jm\[[^\]]+\]|body\.jm)$/';
    private const COLOUR = '/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?)\(/i';
    private const STANDALONE_LAYOUT_TAGS = ['html', 'head', 'meta', 'title', 'link', 'script', 'body'];
    private const DRAWING_TAGS = ['svg', 'defs', 'linearGradient', 'stop', 'rect', 'line', 'polyline', 'circle'];
    private const TAGS_WITHOUT_BOX_DEFAULTS = ['template', 'section', 'article', 'nav', 'form', 'label', 'strong'];

    public function testTheParserReadsTopLevelMediaAndKeyframeRules(): void
    {
        $rules = self::rules();

        static::assertContains('.jm-live[data-jm-live-state="live"]', self::selectorsIn($rules, null));
        static::assertContains('.jm-cards', self::selectorsIn($rules, '@media (max-width: 720px)'));
        static::assertContains('to', self::selectorsIn($rules, '@keyframes jm-live-progress'));
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
            static::assertMatchesRegularExpression(self::UNDER_JM, self::withoutHostAncestor($selector));
        }
    }

    #[TestWith(['[data-bs-theme="light"] .jm[data-jm-theme="auto"]', true])]
    #[TestWith(['[data-bs-theme="dark"] .jm[data-jm-theme="auto"]', false])]
    #[TestWith(['.sidebar .jm', false])]
    #[TestWith(['.sidebar .jm[data-jm-theme="auto"]', false])]
    #[TestWith(['[data-bs-theme="light"] .sidebar .jm', false])]
    public function testOnlyTheBootstrapLightModeMayBeAnAncestorOfJm(string $selector, bool $accepted): void
    {
        $selector = self::withoutHostAncestor($selector);

        static::assertSame($accepted, preg_match(self::UNDER_JM, $selector) === 1);
        static::assertSame($accepted, preg_match(self::ON_JM, $selector) === 1);
    }

    public function testTheLightThemeAlsoAppliesWhenTheHostIsInLightMode(): void
    {
        $rules = array_filter(self::rules(), static fn(array $rule): bool => \in_array(['color-scheme', 'light'], $rule['declarations'], true));

        static::assertSame([self::LIGHT], array_column(array_values($rules), 'selectors'));
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
                    static::assertMatchesRegularExpression(self::ON_JM, self::withoutHostAncestor($selector), $property);
                }
            }
        }

        static::assertGreaterThan(0, $count);
    }

    public function testNoColourIsWrittenOutsideTheTwoThemes(): void
    {
        foreach (self::rules() as $rule) {
            $theme = $rule['at'] === null && \in_array($rule['selectors'], [self::DARK, self::LIGHT], true);

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

    public function testEveryLiveStateIsDrawnWithColoursOfBothThemes(): void
    {
        $dark = self::theme(self::DARK);
        $light = self::theme(self::LIGHT);

        foreach (['live', 'paused', 'failed'] as $state) {
            $declarations = self::declarationsOf('.jm-live[data-jm-live-state="' . $state . '"]', null);
            $properties = array_column($declarations, 0);

            static::assertContains('border-color', $properties, $state);
            static::assertContains('background', $properties, $state);
            foreach ($declarations as [$property, $value]) {
                preg_match_all('/var\((--jm-[a-z0-9-]+)\)/', $value, $variables);
                foreach ($variables[1] as $variable) {
                    static::assertArrayHasKey($variable, $dark, $state . ' ' . $property);
                    static::assertArrayHasKey($variable, $light, $state . ' ' . $property);
                }
            }
        }

        static::assertContains(['background', 'transparent'], self::declarationsOf('.jm-live[data-jm-live-state="paused"]', null));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function liveStates(): iterable
    {
        yield 'live' => ['live'];
        yield 'paused' => ['paused'];
        yield 'failed' => ['failed'];
    }

    #[DataProvider('liveStates')]
    public function testNoRuleHidesTheWordOfTheLiveButton(string $state): void
    {
        $chain = array_map(self::compound(...), ['div.jm', 'div.jm-header', 'div.jm-header__inner', 'div.jm-toolbar', 'button.jm-live[data-jm-live-toggle][data-jm-live-state="' . $state . '"]', 'span.jm-live__word[data-jm-live-word]']);
        $hiding = [['display', 'none'], ['visibility', 'hidden'], ['font-size', '0'], ['font-size', '0px']];

        foreach (self::rules() as $rule) {
            if ($rule['keyframes'] || array_filter($rule['declarations'], static fn(array $declaration): bool => \in_array($declaration, $hiding, true)) === []) {
                continue;
            }

            foreach ($rule['selectors'] as $selector) {
                $parts = self::split((string) preg_replace('/\s*([>+~])\s*/', ' $1 ', $selector), ' ');
                static::assertFalse(self::selectorMatches($parts, $chain), $selector . ' hides the word in ' . ($rule['at'] ?? 'every case'));
            }
        }
    }

    public function testTheLiveButtonKeepsItsLookUnderAHost(): void
    {
        $properties = array_column(self::declarationsOf('.jm-live', null), 0);

        foreach (['font', 'margin', 'border', 'border-radius', 'background', 'color', 'cursor', 'appearance', 'line-height', 'text-transform', 'min-height'] as $property) {
            static::assertContains($property, $properties);
        }
    }

    public function testTheLiveProgressDoesNotMoveUnderReducedMotion(): void
    {
        static::assertContains(['display', 'none'], self::declarationsOf('.jm-live__progress', '@media (prefers-reduced-motion: reduce)'));
        static::assertContains(['animation', 'jm-live-progress 10s linear forwards'], self::declarationsOf('.jm-live[data-jm-live-state="live"] .jm-live__progress', null));
    }

    public function testForcedColorsKeepABorderOnTheLiveButton(): void
    {
        static::assertContains(['border-color', 'ButtonText'], self::declarationsOf('.jm-live', '@media (forced-colors: active)'));
    }

    public function testForcedColorsCoverTheMarksAndTheGauges(): void
    {
        $rules = array_filter(self::rules(), static fn(array $rule): bool => $rule['at'] === '@media (forced-colors: active)');

        foreach (['.jm-mark' => ['CanvasText'], '.jm-gauge' => ['CanvasText', 'Highlight']] as $block => $colours) {
            foreach ($colours as $colour) {
                $found = array_filter($rules, static fn(array $rule): bool => array_filter($rule['selectors'], static fn(string $selector): bool => str_contains($selector, $block)) !== []
                    && array_filter($rule['declarations'], static fn(array $declaration): bool => str_contains($declaration[1], $colour)) !== []);

                static::assertNotEmpty($found, $block . ' ' . $colour);
            }
        }
    }

    public function testTheTwoTonesOfTheNavigationBadgeDifferByMoreThanColour(): void
    {
        $declarations = static function (string $selector, ?string $at): array {
            $found = [];
            foreach (self::rules() as $rule) {
                if ($rule['at'] === $at && \in_array($selector, $rule['selectors'], true)) {
                    array_push($found, ...$rule['declarations']);
                }
            }

            return $found;
        };

        static::assertContains(['background', 'transparent'], $declarations('.jm-nav__badge--warn', null));
        static::assertContains(['background', 'var(--jm-bad-strong)'], $declarations('.jm-nav__badge--bad', null));
        static::assertContains(['background', 'Highlight'], $declarations('.jm-nav__badge--bad', '@media (forced-colors: active)'));
        static::assertContains(['border-color', 'CanvasText'], $declarations('.jm-nav__badge', '@media (forced-colors: active)'));
    }

    public function testForcedColorsTellAFailedLiveButtonFromAnActiveOne(): void
    {
        $rules = array_filter(self::rules(), static fn(array $rule): bool => $rule['at'] === '@media (forced-colors: active)'
            && \in_array(['border-style', 'dashed'], $rule['declarations'], true));
        $selectors = array_merge(...array_column(array_values($rules), 'selectors'));

        static::assertContains('.jm-live[data-jm-live-state="failed"]', $selectors);
        static::assertNotContains('.jm-live', $selectors);
        static::assertNotContains('.jm-live[data-jm-live-state="live"]', $selectors);
    }

    public function testAStateIsSpacedOnlyFromABlockThatFollowsIt(): void
    {
        $margins = static function (string $selector): array {
            $found = [];
            foreach (self::rules() as $rule) {
                if (\in_array($selector, $rule['selectors'], true)) {
                    array_push($found, ...array_filter($rule['declarations'], static fn(array $declaration): bool => str_starts_with($declaration[0], 'margin')));
                }
            }

            return $found;
        };

        static::assertSame([], $margins('.jm-state'));
        static::assertSame([['margin-bottom', '20px']], $margins('.jm-state:has(+ :not(.jm-rest):not(template))'));
        static::assertContains(['margin-top', '20px'], $margins('.jm .jm-rest'));
    }

    public function testASectionReachedByItsAnchorKeepsItsTitleBelowTheStickyHeader(): void
    {
        foreach ([null, '@media (max-width: 1080px)', '@media (max-width: 720px)'] as $at) {
            $margins = [];
            foreach (self::rules() as $rule) {
                if ($rule['at'] === $at && \in_array('body.jm .jm-section', $rule['selectors'], true)) {
                    array_push($margins, ...array_filter($rule['declarations'], static fn(array $declaration): bool => $declaration[0] === 'scroll-margin-top'));
                }
            }

            static::assertCount(1, $margins, (string) $at);
            static::assertMatchesRegularExpression('/^[1-9][0-9]*px$/', $margins[0][1], (string) $at);
        }
    }

    public function testASectionStacksItsHeaderOnANarrowScreen(): void
    {
        $found = array_filter(self::rules(), static fn(array $rule): bool => $rule['at'] === '@media (max-width: 720px)'
            && \in_array('.jm-section__header', $rule['selectors'], true)
            && \in_array(['flex-direction', 'column'], $rule['declarations'], true));

        static::assertNotEmpty($found);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function navigationLinks(): iterable
    {
        $header = ['div.jm', 'div.jm-header', 'div.jm-header__inner'];
        $tabs = ['div.jm', 'div.jm-main', 'div', 'div.jm-screen', 'div.jm-subbar', 'nav.jm-subnav'];

        yield 'space' => [[...$header, 'nav.jm-nav', 'a.jm-nav__item'], 'var(--jm-muted)'];
        yield 'current space' => [[...$header, 'nav.jm-nav', 'a.jm-nav__item[aria-current="page"]'], 'var(--jm-text-strong)'];
        yield 'tab' => [[...$tabs, 'a.jm-subnav__item'], 'var(--jm-muted)'];
        yield 'current tab' => [[...$tabs, 'a.jm-subnav__item[aria-current="page"]'], 'var(--jm-text-strong)'];
        yield 'brand' => [[...$header, 'a.jm-brand'], 'var(--jm-text-strong)'];
    }

    /**
     * @param list<string> $chain the element and its ancestors, outermost first
     */
    #[DataProvider('navigationLinks')]
    public function testALinkOfTheNavigationIsMutedUnlessItIsCurrent(array $chain, string $colour): void
    {
        static::assertSame($colour, self::colourOf(array_map(self::compound(...), $chain)));
    }

    public function testEveryTagOfTheTemplatesHasItsBrowserDefaultsRestatedForHostPages(): void
    {
        $restated = [];
        foreach (self::rules() as $rule) {
            if ($rule['at'] !== null) {
                continue;
            }

            foreach ($rule['selectors'] as $selector) {
                if (preg_match('/^\.jm :where\(([a-z0-9, ]+)\)$/', $selector, $match) === 1) {
                    array_push($restated, ...array_map(trim(...), explode(',', $match[1])));
                } elseif (preg_match('/^\.jm ([a-z0-9]+)$/', $selector, $match) === 1 && \in_array(['margin', '0'], $rule['declarations'], true)) {
                    $restated[] = $match[1];
                }
            }
        }

        $used = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../templates/dashboard', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.html.twig')) {
                preg_match_all('/<([a-zA-Z][a-zA-Z0-9]*)/', (string) file_get_contents($file->getPathname()), $matches);
                array_push($used, ...$matches[1]);
            }
        }

        static::assertNotEmpty($used);
        $needNothing = [...self::STANDALONE_LAYOUT_TAGS, ...self::DRAWING_TAGS, ...self::TAGS_WITHOUT_BOX_DEFAULTS];
        foreach (array_unique($used) as $tag) {
            static::assertTrue(\in_array($tag, $restated, true) || \in_array($tag, $needNothing, true), sprintf('<%s> is used by a template but its browser defaults are not restated under .jm.', $tag));
        }
    }

    private static function withoutHostAncestor(string $selector): string
    {
        foreach (self::HOST_ANCESTORS as $ancestor) {
            if (str_starts_with($selector, $ancestor)) {
                return substr($selector, \strlen($ancestor));
            }
        }

        return $selector;
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
     * @return list<array{string, string}> the declarations of every rule that lists $selector, in $at
     */
    private static function declarationsOf(string $selector, ?string $at): array
    {
        $found = [];
        foreach (self::rules() as $rule) {
            if ($rule['at'] === $at && \in_array($selector, $rule['selectors'], true)) {
                array_push($found, ...$rule['declarations']);
            }
        }

        return $found;
    }

    /**
     * @param list<string> $selectors
     *
     * @return array<string, string>
     */
    private static function theme(array $selectors): array
    {
        $variables = [];
        foreach (self::rules() as $rule) {
            if ($rule['at'] === null && $rule['selectors'] === $selectors) {
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
     * The colour the cascade gives to the last element of the chain, outside any at-rule and any user state.
     *
     * @param non-empty-list<array{tag: ?string, classes: list<string>, attributes: array<string, ?string>, where: list<string>, not: list<string>, other: bool}> $chain
     */
    private static function colourOf(array $chain): ?string
    {
        $winner = null;
        $best = [-1, -1, -1];

        foreach (self::rules() as $rule) {
            $colour = null;
            foreach ($rule['declarations'] as [$property, $value]) {
                if ($property === 'color') {
                    $colour = $value;
                }
            }

            if ($rule['at'] !== null || $colour === null) {
                continue;
            }

            foreach ($rule['selectors'] as $selector) {
                $parts = self::split((string) preg_replace('/\s*([>+~])\s*/', ' $1 ', $selector), ' ');
                $specificity = self::specificity($parts);
                if ($specificity >= $best && self::selectorMatches($parts, $chain)) {
                    $best = $specificity;
                    $winner = $colour;
                }
            }
        }

        return $winner;
    }

    /**
     * @param list<string>                                                                                                                    $parts compounds and combinators
     * @param list<array{tag: ?string, classes: list<string>, attributes: array<string, ?string>, where: list<string>, not: list<string>, other: bool}> $chain
     */
    private static function selectorMatches(array $parts, array $chain): bool
    {
        $compound = array_pop($parts);
        $element = array_pop($chain);

        if ($compound === null || $element === null || !self::compoundMatches(self::compound($compound), $element)) {
            return false;
        }

        if ($parts === []) {
            return true;
        }

        $combinator = end($parts);
        if ($combinator === '>') {
            array_pop($parts);

            return self::selectorMatches($parts, $chain);
        }

        if (\in_array($combinator, ['+', '~'], true)) {
            return false;
        }

        while ($chain !== []) {
            if (self::selectorMatches($parts, $chain)) {
                return true;
            }
            array_pop($chain);
        }

        return false;
    }

    /**
     * @param array{tag: ?string, classes: list<string>, attributes: array<string, ?string>, where: list<string>, not: list<string>, other: bool} $compound
     * @param array{tag: ?string, classes: list<string>, attributes: array<string, ?string>, where: list<string>, not: list<string>, other: bool} $element
     */
    private static function compoundMatches(array $compound, array $element): bool
    {
        if ($compound['other'] || ($compound['tag'] !== null && $compound['tag'] !== '*' && $compound['tag'] !== $element['tag'])) {
            return false;
        }

        foreach ($compound['classes'] as $class) {
            if (!\in_array($class, $element['classes'], true)) {
                return false;
            }
        }

        foreach ($compound['attributes'] as $name => $value) {
            if (!\array_key_exists($name, $element['attributes']) || ($value !== null && $element['attributes'][$name] !== $value)) {
                return false;
            }
        }

        foreach ($compound['where'] as $list) {
            if (array_filter(self::split($list, ','), static fn(string $alternative): bool => self::compoundMatches(self::compound($alternative), $element)) === []) {
                return false;
            }
        }

        foreach ($compound['not'] as $list) {
            if (array_filter(self::split($list, ','), static fn(string $alternative): bool => self::compoundMatches(self::compound($alternative), $element)) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{tag: ?string, classes: list<string>, attributes: array<string, ?string>, where: list<string>, not: list<string>, other: bool} other: a pseudo-class or a pseudo-element this test does not read
     */
    private static function compound(string $text): array
    {
        $compound = ['tag' => null, 'classes' => [], 'attributes' => [], 'where' => [], 'not' => [], 'other' => false];

        preg_match_all('/\G(?:(?<tag>[a-z][a-z0-9]*|\*)|\.(?<class>[a-zA-Z0-9_-]+)|\[(?<attribute>[a-z-]+)(?:="(?<value>[^"]*)")?\]|:(?<function>where|is|not|has)\((?<inner>(?:[^()]|\((?&inner)\))*)\)|(?<pseudo>::?[a-z-]+(?:\([^)]*\))?))/', $text, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);
        static::assertSame($text, implode('', array_map(static fn(array $match): string => (string) $match[0], $matches)), 'Unread selector: ' . $text);

        foreach ($matches as $match) {
            match (true) {
                $match['tag'] !== null => $compound['tag'] = $match['tag'],
                $match['class'] !== null => $compound['classes'][] = $match['class'],
                $match['attribute'] !== null => $compound['attributes'][$match['attribute']] = $match['value'],
                $match['function'] === 'not' => $compound['not'][] = (string) $match['inner'],
                $match['function'] === 'where', $match['function'] === 'is' => $compound['where'][] = (string) $match['inner'],
                default => $compound['other'] = true,
            };
        }

        return $compound;
    }

    /**
     * @param list<string> $parts compounds and combinators
     *
     * @return array{int, int, int}
     */
    private static function specificity(array $parts): array
    {
        $specificity = [0, 0, 0];

        foreach ($parts as $part) {
            if (\in_array($part, ['>', '+', '~'], true)) {
                continue;
            }

            $compound = self::compound($part);
            $specificity[2] += $compound['tag'] !== null && $compound['tag'] !== '*' ? 1 : 0;
            $specificity[1] += \count($compound['classes']) + \count($compound['attributes']);
            preg_match_all('/(?<!:):(?!where\(|is\(|not\(|has\()[a-z-]+/', $part, $pseudo);
            $specificity[1] += \count($pseudo[0]);
            foreach ($compound['not'] as $list) {
                $inner = array_map(static fn(string $alternative): array => self::specificity([$alternative]), self::split($list, ','));
                $specificity = array_map(static fn(int $a, int $b): int => $a + $b, $specificity, max($inner));
            }
        }

        return $specificity;
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
