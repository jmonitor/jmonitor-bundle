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
    private const DRAWING_TAGS = ['svg', 'defs', 'linearGradient', 'stop', 'rect', 'line', 'polyline', 'circle', 'path'];
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
        static::assertSame([['border-color', 'var(--jm-bad-border)'], ['background', 'var(--jm-bad-bg)'], ['color', 'var(--jm-bad)']], $declarations('.jm-nav__badge--bad', null));
        static::assertContains(['background', 'Highlight'], $declarations('.jm-nav__badge--bad', '@media (forced-colors: active)'));
        static::assertContains(['border-color', 'CanvasText'], $declarations('.jm-nav__badge', '@media (forced-colors: active)'));
        static::assertSame([['display', 'inline-flex'], ['gap', '4px']], $declarations('.jm-nav__badges', null));
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
        $previous = 0;
        foreach ([null, '@media (max-width: 1080px)', '@media (max-width: 720px)', '@media (max-width: 560px)'] as $at) {
            $margins = [];
            foreach (self::rules() as $rule) {
                if ($rule['at'] === $at && \in_array('body.jm .jm-section', $rule['selectors'], true)) {
                    array_push($margins, ...array_filter($rule['declarations'], static fn(array $declaration): bool => $declaration[0] === 'scroll-margin-top'));
                }
            }

            static::assertCount(1, $margins, (string) $at);
            static::assertMatchesRegularExpression('/^[1-9][0-9]*px$/', $margins[0][1], (string) $at);
            static::assertGreaterThan($previous, (int) $margins[0][1], (string) $at);
            $previous = (int) $margins[0][1];
        }
    }

    public function testNothingInTheToolbarTakesABlockMarginFromTheHost(): void
    {
        static::assertContains(['margin-block', '0'], self::declarationsOf('.jm-toolbar > *', null));
    }

    public function testATabIsACardAndTheCurrentOneTakesThePrimaryTone(): void
    {
        $tab = array_column(self::declarationsOf('.jm-subnav__item', null), 1, 0);

        static::assertSame(['display' => 'inline-flex', 'align-items' => 'center', 'gap' => '8px', 'min-height' => '40px', 'padding' => '8px 14px', 'border' => '1px solid var(--jm-border-subtle)', 'border-radius' => '10px', 'background' => 'var(--jm-surface-hover)', 'color' => 'var(--jm-muted)', 'font-size' => '13px', 'font-weight' => '600', 'text-decoration' => 'none', 'white-space' => 'nowrap'], $tab);
        static::assertSame([['border-color', 'var(--jm-segment-active-border)'], ['background', 'var(--jm-segment-active-bg)'], ['color', 'var(--jm-text-strong)']], self::declarationsOf('.jm-subnav__item[aria-current="page"]', null));
        static::assertSame([['border-radius', '10px']], self::declarationsOf('.jm-subnav__item:focus-visible', null));
        static::assertSame([['color', 'var(--jm-faint)']], self::declarationsOf('.jm-subnav__item .jm-icon', null));
        static::assertSame([['color', 'var(--jm-primary-light)']], self::declarationsOf('.jm-subnav__item[aria-current="page"] .jm-icon', null));
        static::assertSame([['display', 'flex'], ['flex-wrap', 'wrap'], ['gap', '8px'], ['min-width', '0']], self::declarationsOf('.jm-subnav', null));
    }

    public function testAnIconKeepsItsSizeInItsCard(): void
    {
        static::assertSame([['flex', 'none']], self::declarationsOf('.jm-icon', null));
        static::assertSame([['width', '16px'], ['height', '16px']], self::declarationsOf('.jm-icon--line', null));
        static::assertSame([['width', '18px'], ['height', '18px']], self::declarationsOf('.jm-icon--logo', null));
    }

    public function testNoRuleDrawsALineUnderTheTabs(): void
    {
        foreach ([null, '@media (max-width: 720px)', '@media (forced-colors: active)'] as $at) {
            foreach (['.jm-subbar', '.jm-subnav', '.jm-subnav__item', '.jm-subnav__item[aria-current="page"]'] as $selector) {
                $properties = array_column(self::declarationsOf($selector, $at), 0);

                static::assertSame([], array_values(array_filter($properties, static fn(string $property): bool => str_starts_with($property, 'border-bottom') || ($property === 'margin-bottom' && $selector !== '.jm-subbar'))), $selector . ' ' . $at);
            }
        }
    }

    public function testOnANarrowScreenTheCardsScrollOnOneLine(): void
    {
        $nav = array_column(self::declarationsOf('.jm-subnav', '@media (max-width: 720px)'), 1, 0);

        static::assertSame('nowrap', $nav['flex-wrap'] ?? null);
        static::assertSame('auto', $nav['overflow-x'] ?? null);
        static::assertSame('calc(100% + 2 * var(--jm-gutter))', $nav['width'] ?? null);
        static::assertSame('-4px calc(-1 * var(--jm-gutter))', $nav['margin'] ?? null);
        static::assertSame('4px var(--jm-gutter)', $nav['padding'] ?? null);
        static::assertSame([['display', 'none']], self::declarationsOf('.jm-subnav::-webkit-scrollbar', '@media (max-width: 720px)'));
    }

    public function testTheTabsThatOpenAScreenSitUnderTheHeader(): void
    {
        foreach ([[null, 28], ['@media (max-width: 720px)', 20]] as [$at, $padding]) {
            $main = array_column(self::declarationsOf('.jm-main', $at), 1, 0);
            $top = $main['padding-top'] ?? explode(' ', $main['padding'] ?? '')[0];

            static::assertSame($padding . 'px', $top, (string) $at);
            static::assertContains(['margin-top', '-' . ($padding - 12) . 'px'], self::declarationsOf('.jm-screen > .jm-subbar:first-child', $at), (string) $at);
        }
    }

    public function testForcedColorsOutlineOnlyTheCurrentTab(): void
    {
        $at = '@media (forced-colors: active)';

        static::assertSame([['outline', '1px solid CanvasText']], self::declarationsOf('.jm-subnav__item[aria-current="page"]', $at));
        static::assertSame([], self::declarationsOf('.jm-subnav__item', $at));
    }

    public function testForcedColorsKeepTheFullFocusRingOnTheCurrentEntryAndTab(): void
    {
        $at = '@media (forced-colors: active)';

        static::assertSame([['outline', '2px solid var(--jm-focus)'], ['outline-offset', '2px'], ['border-radius', '4px']], self::declarationsOf('.jm :focus-visible', null));
        foreach (['.jm-nav__item[aria-current="page"]', '.jm-subnav__item[aria-current="page"]'] as $current) {
            static::assertSame([['outline', '1px solid CanvasText']], self::declarationsOf($current, $at), $current);
            static::assertSame([['outline-width', '2px']], self::declarationsOf($current . ':focus-visible', $at), $current);
        }
    }

    public function testForcedColorsDrawTheIconOfACardInTheColourOfItsText(): void
    {
        $at = '@media (forced-colors: active)';

        static::assertSame([['color', 'inherit']], self::declarationsOf('.jm-subnav__item .jm-icon', $at));
        static::assertSame([['color', 'inherit']], self::declarationsOf('.jm-subnav__item[aria-current="page"] .jm-icon', $at));
    }

    public function testTheCardsKeepTheirWordsReadable(): void
    {
        foreach ([self::DARK, self::LIGHT] as $selectors) {
            $theme = self::theme($selectors);
            $page = self::colour($theme['--jm-bg'] ?? '');
            $rest = self::over(self::colour($theme['--jm-surface-hover'] ?? ''), $page);
            $current = self::over(self::colour($theme['--jm-segment-active-bg'] ?? ''), $page);

            static::assertGreaterThanOrEqual(4.5, self::contrast(self::colour($theme['--jm-muted'] ?? ''), $rest), implode(', ', $selectors));
            static::assertGreaterThanOrEqual(4.5, self::contrast(self::colour($theme['--jm-text-strong'] ?? ''), $current), implode(', ', $selectors));
            static::assertGreaterThanOrEqual(3.0, self::contrast(self::colour($theme['--jm-faint'] ?? ''), $rest), implode(', ', $selectors));
            static::assertGreaterThanOrEqual(3.0, self::contrast(self::colour($theme['--jm-primary-light'] ?? ''), $current), implode(', ', $selectors));
        }
    }

    public function testTheWordLiveIsReadableOnItsButton(): void
    {
        static::assertContains(['color', 'var(--jm-ok-text)'], self::declarationsOf('.jm-live[data-jm-live-state="live"] .jm-live__word', null));

        foreach ([self::DARK, self::LIGHT] as $selectors) {
            $theme = self::theme($selectors);
            $background = self::over(self::colour($theme['--jm-ok-bg'] ?? ''), self::colour($theme['--jm-bg'] ?? ''));

            static::assertGreaterThanOrEqual(4.5, self::contrast(self::colour($theme['--jm-ok-text'] ?? ''), $background), implode(', ', $selectors));
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

    public function testTheBlocksOfAGuidedReadingAreStyledUnderJm(): void
    {
        $selectors = [];
        foreach (self::rules() as $rule) {
            array_push($selectors, ...$rule['selectors']);
        }

        foreach (['.jm-lead', '.jm-lead__title', '.jm-lead__detail', '.jm-lead__axis', '.jm-guide', '.jm-guide__title', '.jm-guide__list', '.jm-check__code', '.jm-check__advice', '.jm-checks--warn', '.jm-checks--bad', '.jm-bar', '.jm-bar__fill', '.jm-bar__value', '.jm-split', '.jm-split__text', '.jm-split__part--php', '.jm-split__part--sql', '.jm-split__part--http', '.jm-tag', '.jm-tag--warn', '.jm-tag--bad', '.jm-legend', '.jm-legend__swatch--php', '.jm-panel__footnote'] as $class) {
            static::assertNotEmpty(array_filter($selectors, static fn(string $selector): bool => preg_match('/' . preg_quote($class, '/') . '(?![a-z0-9_-])/', $selector) === 1), $class);
        }
    }

    /**
     * @return iterable<string, array{list<string>, float}>
     */
    public static function themes(): iterable
    {
        yield 'dark' => [self::DARK, 2.5];
        yield 'light' => [self::LIGHT, 1.5];
    }

    /**
     * @param list<string> $selectors
     */
    #[DataProvider('themes')]
    public function testThePartsOfASplitAreToldApartByTheirLightness(array $selectors, float $sqlAndHttp): void
    {
        $theme = self::theme($selectors);
        $background = self::over(self::colour($theme['--jm-surface'] ?? ''), self::over(self::colour($theme['--jm-bg'] ?? ''), [255.0, 255.0, 255.0, 1.0]));
        $parts = [];

        foreach (['php', 'sql', 'http'] as $part) {
            $parts[$part] = self::colour($theme['--jm-part-' . $part] ?? '');
            static::assertSame(1.0, $parts[$part][3], $part);
            static::assertGreaterThanOrEqual(3.0, self::contrast($parts[$part], $background), $part . ' on the table');
        }

        static::assertGreaterThanOrEqual(1.5, self::contrast($parts['php'], $parts['sql']), 'PHP and SQL');
        static::assertGreaterThanOrEqual($sqlAndHttp, self::contrast($parts['sql'], $parts['http']), 'SQL and HTTP');
    }

    public function testForcedColorsKeepTheOutlineOfTheNewCells(): void
    {
        $at = '@media (forced-colors: active)';

        static::assertContains(['border', '1px solid CanvasText'], self::declarationsOf('.jm-bar svg', $at));
        static::assertContains(['border', '1px solid CanvasText'], self::declarationsOf('.jm-split svg', $at));
        static::assertContains(['stroke', 'Canvas'], self::declarationsOf('.jm-split__part', $at));
        static::assertContains(['border-color', 'CanvasText'], self::declarationsOf('.jm-tag', $at));
    }

    public function testForcedColorsKeepEachSwatchOfTheLegendInTheColourOfItsPart(): void
    {
        $at = '@media (forced-colors: active)';

        static::assertContains(['forced-color-adjust', 'none'], self::declarationsOf('.jm-legend__swatch', $at));
        foreach (['php', 'sql', 'http'] as $part) {
            $fill = array_column(self::declarationsOf('.jm-split__part--' . $part, $at), 1, 0)['fill'] ?? null;

            static::assertNotNull($fill, $part);
            static::assertContains(['background', $fill], self::declarationsOf('.jm-legend__swatch--' . $part, $at), $part);
        }
    }

    public function testANarrowScreenStacksTheAdviceOfACheckAndTheItemsOfTheGuide(): void
    {
        foreach (['.jm-check__advice', '.jm-guide__list'] as $selector) {
            static::assertContains(['flex-direction', 'column'], self::declarationsOf($selector, '@media (max-width: 720px)'), $selector);
        }
    }

    public function testARowOfCardsWithoutAWideCardSharesTheWidthEqually(): void
    {
        $equal = '.jm-screen > .jm-cards:not(:has(> .jm-card--wide))';
        $four = $equal . ':has(> .jm-card:nth-child(4):last-child)';

        static::assertContains(['grid-template-columns', 'minmax(0, 1.7fr) repeat(3, minmax(0, 1fr))'], self::declarationsOf('.jm-cards', null));
        static::assertSame([['grid-template-columns', 'none'], ['grid-auto-columns', 'minmax(0, 1fr)'], ['grid-auto-flow', 'column']], self::declarationsOf($equal, null));
        static::assertContains(['grid-template-columns', 'repeat(3, minmax(0, 1fr))'], self::declarationsOf($equal, '@media (max-width: 1080px)'));
        static::assertContains(['grid-auto-flow', 'row'], self::declarationsOf($equal, '@media (max-width: 1080px)'));
        static::assertContains(['grid-template-columns', 'repeat(2, minmax(0, 1fr))'], self::declarationsOf($four, '@media (max-width: 1080px)'));
        static::assertContains(['grid-template-columns', 'repeat(2, minmax(0, 1fr))'], self::declarationsOf($equal, '@media (max-width: 720px)'));
        foreach (['.jm-cards', $equal, $four] as $selector) {
            static::assertContains(['grid-template-columns', 'minmax(0, 1fr)'], self::declarationsOf($selector, '@media (max-width: 380px)'), $selector);
        }
    }

    public function testTheCardsOfASectionShareTheWidthLikeTheCardsOfTheScreen(): void
    {
        $equal = ':not(:has(> .jm-card--wide))';
        $four = $equal . ':has(> .jm-card:nth-child(4):last-child)';
        $five = $equal . ':has(> .jm-card:nth-child(5):last-child)';

        foreach ([
            [$equal, null],
            [$equal, '@media (max-width: 1080px)'],
            [$four, '@media (max-width: 1080px)'],
            [$five, '@media (min-width: 721px) and (max-width: 1080px)'],
            [$five . ' > .jm-card', '@media (min-width: 721px) and (max-width: 1080px)'],
            [$five . ' > .jm-card:nth-child(n + 4)', '@media (min-width: 721px) and (max-width: 1080px)'],
            [$equal, '@media (max-width: 720px)'],
            [$equal, '@media (max-width: 380px)'],
            [$four, '@media (max-width: 380px)'],
        ] as [$rest, $at]) {
            $screen = self::declarationsOf('.jm-screen > .jm-cards' . $rest, $at);

            static::assertNotSame([], $screen, $rest . ' ' . $at);
            static::assertSame($screen, self::declarationsOf('.jm-section > .jm-cards' . $rest, $at), $rest . ' ' . $at);
        }
    }

    public function testOneOrTwoCardsFillTheirRowBetweenTheBreakpoints(): void
    {
        $at = '@media (min-width: 721px) and (max-width: 1080px)';

        foreach (['.jm-screen', '.jm-section'] as $parent) {
            static::assertSame([['grid-template-columns', 'none'], ['grid-auto-flow', 'column']], self::declarationsOf($parent . ' > .jm-cards:not(:has(> .jm-card--wide)):has(> .jm-card:nth-child(-n + 2):last-child)', $at), $parent);
        }
        static::assertContains(['grid-auto-columns', 'minmax(0, 1fr)'], self::declarationsOf('.jm-screen > .jm-cards:not(:has(> .jm-card--wide))', null));
    }

    public function testTheGuideIsSpacedFromTheSectionAboveItLikeTwoSections(): void
    {
        static::assertSame([['margin-top', '20px']], self::declarationsOf('.jm-section + .jm-section', null));
        static::assertSame([['margin-top', '20px']], self::declarationsOf('.jm-section + .jm-guide', null));
    }

    public function testAnAnchorCardKeepsTheColourOfItsLabelAndIsUnderlinedOnHoverAndFocus(): void
    {
        static::assertSame([['color', 'inherit'], ['text-decoration', 'none']], self::declarationsOf('.jm-card__link', null));
        foreach (['.jm-card__link:hover', '.jm-card__link:focus-visible'] as $selector) {
            static::assertSame([['color', 'inherit'], ['text-decoration', 'underline']], self::declarationsOf($selector, null), $selector);
        }
        static::assertSame([['grid-template-columns', 'minmax(0, 1fr)']], self::declarationsOf('.jm-screen > .jm-cards:not(:has(> .jm-card--wide)):has(> .jm-card > .jm-card__label > .jm-card__link)', '@media (max-width: 400px)'));
    }

    public function testANestedRowIsIndentedOnAFainterBackground(): void
    {
        static::assertSame([['background', 'var(--jm-surface-strong)']], self::declarationsOf('.jm-table tbody .jm-row--nested > *', null));
        static::assertSame([['background', 'var(--jm-surface-hover)']], self::declarationsOf('.jm-table tbody tr:hover > *', null), 'A hovered nested row changes its background.');
        static::assertSame([['padding-left', '38px']], self::declarationsOf('.jm-table tbody .jm-row--nested > :first-child', null));
    }

    public function testALongNameOfASectionTableWrapsInsteadOfWideningItsColumn(): void
    {
        static::assertSame([['overflow-wrap', 'anywhere']], self::declarationsOf('.jm-table .jm-code', null));
        static::assertSame([['overflow-wrap', 'anywhere'], ['min-width', '140px']], self::declarationsOf('.jm-table .jm-col-user', null));
        static::assertSame([['min-width', '140px']], self::declarationsOf('.jm-table tbody th:has(> .jm-code)', null), 'A name that wraps keeps a column wide enough to be read.');
        static::assertSame([['white-space', 'normal']], self::declarationsOf('.jm-section .jm-table:has(.jm-split) td.jm-col-problems', null));
        static::assertSame([['white-space', 'nowrap']], self::declarationsOf('.jm-table:has(.jm-split) td:has(> .jm-tag)', null), 'The problems of a route stay on one line.');
    }

    public function testOnlyTheTitleOfAGuidedCheckIsBold(): void
    {
        static::assertContains(['font-weight', '600'], self::declarationsOf('.jm-check__sentence', null));
        static::assertContains(['font-weight', '400'], self::declarationsOf('.jm-check--guided .jm-check__sentence', null));
        static::assertContains(['color', 'var(--jm-text)'], self::declarationsOf('.jm-check--guided .jm-check__sentence', null));
        static::assertSame([['color', 'var(--jm-text-strong)'], ['font-weight', '600']], self::declarationsOf('.jm-check--guided .jm-check__sentence strong', null));
        static::assertSame([], self::declarationsOf('.jm-check__sentence strong', null));
    }

    public function testOnlyATableWithASplitTightensItsCells(): void
    {
        static::assertSame([['padding-left', '10px']], self::declarationsOf('.jm-table:has(.jm-split) :is(th, td):not(:first-child)', null));
        static::assertSame([['padding-right', '10px']], self::declarationsOf('.jm-table:has(.jm-split) :is(th, td):not(:last-child)', null));
        static::assertSame([['min-width', '220px'], ['white-space', 'nowrap']], self::declarationsOf('.jm-table:has(.jm-split) .jm-col-route', null));
        static::assertContains(['padding', '10px 12px'], self::declarationsOf('.jm-table td', null));
        static::assertContains(['min-width', '280px'], self::declarationsOf('.jm-col-route', null));
    }

    public function testARouteOfTheRoutesTableStaysOnOneLine(): void
    {
        $route = self::declarationsOf('.jm-table:has(.jm-split) .jm-route', null);

        foreach ([['display', 'inline-block'], ['overflow', 'hidden'], ['text-overflow', 'ellipsis'], ['overflow-wrap', 'normal']] as $declaration) {
            static::assertContains($declaration, $route);
        }
        static::assertContains(['white-space', 'nowrap'], self::declarationsOf('.jm-table:has(.jm-split) td:has(> .jm-tag)', null));
    }

    public function testTheTextOfASplitGetsMoreRoomOnAWideScreen(): void
    {
        static::assertContains(['max-width', '160px'], self::declarationsOf('.jm-split__text', null));
        static::assertSame([['max-width', '200px']], self::declarationsOf('.jm-split__text', '@media (min-width: 1440px)'));
    }

    public function testTheAdviceOfAGuidedCheckSpansTheWidthOfItsPlace(): void
    {
        static::assertSame([['display', 'contents']], self::declarationsOf('.jm-check--guided .jm-check__body', null));
        static::assertSame([['grid-row', '1'], ['grid-column', '3']], self::declarationsOf('.jm-check--guided .jm-check__where', null));
        static::assertSame([['grid-column', '2 / -1']], self::declarationsOf('.jm-check--guided .jm-check__advice', null));
        static::assertSame([['grid-column', '1 / -1']], self::declarationsOf('.jm-check--guided .jm-check__advice', '@media (max-width: 720px)'));
    }

    public function testFiveCardsFillTwoRowsBetweenTheBreakpoints(): void
    {
        $five = '.jm-screen > .jm-cards:not(:has(> .jm-card--wide)):has(> .jm-card:nth-child(5):last-child)';
        $at = '@media (min-width: 721px) and (max-width: 1080px)';

        static::assertSame([['grid-template-columns', 'repeat(6, minmax(0, 1fr))']], self::declarationsOf($five, $at));
        static::assertSame([['grid-column', 'span 2']], self::declarationsOf($five . ' > .jm-card', $at));
        static::assertSame([['grid-column', 'span 3']], self::declarationsOf($five . ' > .jm-card:nth-child(n + 4)', $at));
    }

    public function testTheQueryColumnBesideABarIsNarrower(): void
    {
        static::assertSame([['min-width', '320px']], self::declarationsOf('.jm-table:has(.jm-bar) .jm-col-query', null));
        static::assertSame([['min-width', '280px']], self::declarationsOf('.jm-table:has(.jm-bar) .jm-col-query', '@media (max-width: 720px)'));
    }

    public function testTheWordsOfASplitStayInTheScrollingBoxOfItsTable(): void
    {
        static::assertContains(['position', 'relative'], self::declarationsOf('.jm-split', null));
    }

    public function testACodeOfACheckScrollsInItsOwnBox(): void
    {
        $code = self::declarationsOf('.jm-check__code', null);

        static::assertContains(['overflow-x', 'auto'], $code);
        static::assertContains(['white-space', 'pre'], $code);
        static::assertContains(['margin', '8px 0 0'], $code);
    }

    public function testTheMapAndThePlacesAreStyledUnderJm(): void
    {
        $selectors = [];
        foreach (self::rules() as $rule) {
            array_push($selectors, ...$rule['selectors']);
        }

        foreach (['.jm-map', '.jm-map__columns', '.jm-map__column', '.jm-map__title', '.jm-map__arrow', '.jm-map__boxes', '.jm-map__boxes--inside', '.jm-map__item', '.jm-box', '.jm-box__line', '.jm-box__name', '.jm-box__label', '.jm-box__value', '.jm-box__tags', '.jm-box--zero', '.jm-box--warn', '.jm-box--bad', '.jm-runs', '.jm-runs__title', '.jm-runs__now', '.jm-runs__list', '.jm-runs__item', '.jm-runs__line', '.jm-runs__name', '.jm-runs__version', '.jm-runs__reason', '.jm-runs__more', '.jm-runs__empty', '.jm-places__list', '.jm-place', '.jm-place__label', '.jm-place__question'] as $class) {
            static::assertNotEmpty(array_filter($selectors, static fn(string $selector): bool => preg_match('/' . preg_quote($class, '/') . '(?![a-z0-9_-])/', $selector) === 1), $class);
        }
    }

    public function testAToneOnlyColoursTheFrameOfABox(): void
    {
        static::assertSame([['border-color', 'var(--jm-warn-border)']], self::declarationsOf('.jm-box--warn', null));
        static::assertSame([['border-color', 'var(--jm-bad-border)']], self::declarationsOf('.jm-box--bad', null));
    }

    public function testABoxHoldsItsNameAndItsTagsBesideItsValue(): void
    {
        $line = self::declarationsOf('.jm-box__line', null);
        $name = self::declarationsOf('.jm-box__name', null);
        $value = self::declarationsOf('.jm-box__value', null);

        static::assertContains(['display', 'flex'], $line);
        static::assertContains(['align-items', 'baseline'], $line);
        static::assertContains(['flex-wrap', 'wrap'], $line, 'a value wider than what is left of the box goes under its name rather than out of the box');
        static::assertContains(['display', 'flex'], $name);
        static::assertContains(['flex', '1 1 5em'], $name);
        static::assertContains(['flex-wrap', 'wrap'], $name, 'the tags wrap beside the name, not the value');
        static::assertContains(['flex-wrap', 'wrap'], self::declarationsOf('.jm-box__tags', null));
        static::assertContains(['flex', 'none'], $value);
        static::assertContains(['white-space', 'nowrap'], $value);
        static::assertContains(['margin-left', 'auto'], $value);
        static::assertContains(['font-size', '20px'], $value);
        static::assertContains(['font-weight', '700'], $value);
        static::assertContains(['font-variant-numeric', 'tabular-nums'], $value);
        static::assertContains(['padding', '11px 16px'], self::declarationsOf('.jm-box', null));
    }

    public function testAValueAtZeroIsMuted(): void
    {
        static::assertSame([['color', 'var(--jm-muted)']], self::declarationsOf('.jm-box--zero .jm-box__value', null));
    }

    public function testTheMapIsStackedUnlessItIsWideEnoughForItsColumns(): void
    {
        $wide = '@container jm-map (width >= 800px)';

        static::assertContains(['container-type', 'inline-size'], self::declarationsOf('.jm-map', null));
        static::assertContains(['container-name', 'jm-map'], self::declarationsOf('.jm-map', null));
        static::assertContains(['display', 'flex'], self::declarationsOf('.jm-map__columns', null));
        static::assertContains(['flex-direction', 'column'], self::declarationsOf('.jm-map__columns', null));
        static::assertContains(['display', 'none'], self::declarationsOf('.jm-map__arrow', null));
        static::assertContains(['flex-direction', 'column'], self::declarationsOf('.jm-map__boxes', null));
        static::assertSame([['flex-flow', 'row wrap']], self::declarationsOf('.jm-map__boxes--inside', null));
        static::assertSame([['flex', '1 1 200px']], self::declarationsOf('.jm-map__boxes--inside > .jm-map__item', null), 'the two boxes of the middle stand side by side from a column of about 410 px');
        static::assertContains(['flex-direction', 'row'], self::declarationsOf('.jm-map__columns', $wide));
        static::assertContains(['flex', '1 1 0'], self::declarationsOf('.jm-map__column', $wide));
        static::assertSame([], self::declarationsOf('.jm-map__column--app', $wide), 'the three columns share the width');
        static::assertSame([], self::selectorsIn(self::rules(), '@container jm-map (width >= 960px)'));
        static::assertContains(['display', 'block'], self::declarationsOf('.jm-map__arrow', $wide));
        static::assertSame([], self::declarationsOf('.jm-map__boxes--inside', $wide));
        static::assertSame([], self::selectorsIn(self::rules(), '@container jm-map (width >= 480px)'));
    }

    public function testTheMapIsLaidOutByItsOwnWidthNotByTheWindow(): void
    {
        foreach (self::rules() as $rule) {
            if ($rule['at'] !== null && str_starts_with($rule['at'], '@media ')) {
                foreach ($rule['selectors'] as $selector) {
                    static::assertDoesNotMatchRegularExpression('/\.jm-map(?![a-z0-9_-])|\.jm-map__(?:columns|column|arrow|boxes)/', $selector, $rule['at']);
                }
            }
        }
    }

    public function testTheParserReadsContainerRules(): void
    {
        static::assertContains('.jm-map__columns', self::selectorsIn(self::rules(), '@container jm-map (width >= 800px)'));
    }

    public function testABoxThatIsALinkKeepsTheColoursOfItsText(): void
    {
        static::assertContains(['display', 'flex'], self::declarationsOf('.jm-box', null));
        static::assertContains(['color', 'inherit'], self::declarationsOf('.jm-box', null));
        static::assertContains(['text-decoration', 'none'], self::declarationsOf('.jm-box', null));
        static::assertContains(['display', 'grid'], self::declarationsOf('.jm-runs__line', null));
        static::assertContains(['align-items', 'start'], self::declarationsOf('.jm-runs__line', null));
    }

    public function testALineOfRunsOnKeepsItsVersionOnOneLineAndPutsItsReasonUnderItsName(): void
    {
        $wide = '@container jm-runs (width >= 340px)';

        static::assertContains(['container-type', 'inline-size'], self::declarationsOf('.jm-runs', null));
        static::assertContains(['container-name', 'jm-runs'], self::declarationsOf('.jm-runs', null));
        static::assertContains(['grid-template-columns', 'fit-content(50%) minmax(0, 1fr) auto'], self::declarationsOf('.jm-runs__line', null), 'in a narrow box the name only takes what it needs');
        static::assertSame([['grid-template-columns', '108px minmax(0, 1fr) auto']], self::declarationsOf('.jm-runs__line', $wide), 'the versions line up when the box has the room');
        static::assertSame(
            [['min-width', '0'], ['overflow', 'hidden'], ['color', 'var(--jm-muted)'], ['text-overflow', 'ellipsis'], ['white-space', 'nowrap']],
            self::declarationsOf('.jm-runs__version', null),
        );
        static::assertSame([['grid-column', '1 / -1'], ['color', 'var(--jm-muted)'], ['overflow-wrap', 'break-word']], self::declarationsOf('.jm-runs__reason', null));
    }

    public function testTheWordPeriodShowsOnlyFrom1500Pixels(): void
    {
        $hidden = [];
        foreach (self::rules() as $rule) {
            if (\in_array('.jm-period__label', $rule['selectors'], true) && \in_array('display', array_column($rule['declarations'], 0), true)) {
                $hidden[] = $rule['at'];
            }
        }

        static::assertSame(['@media (max-width: 1499px)'], $hidden);
        static::assertSame([['display', 'none']], self::declarationsOf('.jm-period__label', '@media (max-width: 1499px)'));
    }

    public function testRunsOnStandsInItsOwnBoxWithANeutralNowTag(): void
    {
        $box = self::declarationsOf('.jm-runs', null);
        $now = self::declarationsOf('.jm-runs__now', null);

        static::assertContains(['border', '1px solid var(--jm-border)'], $box);
        static::assertContains(['border-radius', 'var(--jm-radius)'], $box);
        static::assertContains(['background', 'var(--jm-surface)'], $box);
        static::assertContains(['background', 'var(--jm-surface-strong)'], $now);
        static::assertContains(['color', 'var(--jm-text)'], $now);
        static::assertArrayHasKey('--jm-text', self::theme(self::DARK));
        static::assertArrayHasKey('--jm-text', self::theme(self::LIGHT));
        static::assertSame([], array_filter($now, static fn(array $declaration): bool => str_starts_with($declaration[0], 'border') && $declaration[0] !== 'border-radius'));
        static::assertSame([['border-top', '1px solid var(--jm-border-subtle)']], self::declarationsOf('.jm-runs__item + .jm-runs__item', null));
    }

    public function testTheStatusAndTheAlertsAreStyledUnderJm(): void
    {
        $selectors = [];
        foreach (self::rules() as $rule) {
            array_push($selectors, ...$rule['selectors']);
        }

        foreach (['.jm-status', '.jm-status__pill', '.jm-status__dot', '.jm-status__pill--ok', '.jm-status__pill--warn', '.jm-status__pill--bad', '.jm-status__pill--unknown', '.jm-alerts', '.jm-alerts__title', '.jm-alerts__list', '.jm-alerts__item', '.jm-alert', '.jm-alert--warn', '.jm-alert--bad', '.jm-alert__top', '.jm-alert__where', '.jm-alert__title'] as $class) {
            static::assertNotEmpty(array_filter($selectors, static fn(string $selector): bool => preg_match('/' . preg_quote($class, '/') . '(?![a-z0-9_-])/', $selector) === 1), $class);
        }
    }

    public function testTheTitleOfTheAlertsKeepsItsMarginAgainstTheResetOfTheHeadings(): void
    {
        static::assertSame([['margin', '0']], self::declarationsOf('.jm h2', null));
        static::assertContains(['margin-bottom', '10px'], self::declarationsOf('.jm .jm-alerts__title', null));
    }

    public function testNoClassOfAHeadingOrAParagraphOfTheOverviewSetsAMarginTheResetWouldOverride(): void
    {
        foreach (['.jm-heading__title', '.jm-alerts__title', '.jm-map__title', '.jm-runs__title', '.jm-runs__more', '.jm-runs__empty'] as $class) {
            static::assertSame([], array_filter(self::declarationsOf($class, null), static fn(array $declaration): bool => str_starts_with($declaration[0], 'margin')), $class);
        }
    }

    public function testACardOfTheAlertsTakesTheHeightOfItsOwnContent(): void
    {
        static::assertContains(['align-items', 'flex-start'], self::declarationsOf('.jm-alerts__list', null));
    }

    public function testEachToneOfTheStatusIsDrawnWithItsColours(): void
    {
        foreach (['ok' => ['--jm-ok-border', '--jm-ok-bg', '--jm-ok-text'], 'warn' => ['--jm-warn-border', '--jm-warn-bg', '--jm-warn'], 'bad' => ['--jm-bad-border', '--jm-bad-bg', '--jm-bad'], 'unknown' => ['--jm-border', '--jm-surface-strong', '--jm-muted']] as $tone => [$border, $background, $colour]) {
            static::assertSame([['border-color', 'var(' . $border . ')'], ['background', 'var(' . $background . ')'], ['color', 'var(' . $colour . ')']], self::declarationsOf('.jm-status__pill--' . $tone, null), $tone);
        }

        static::assertContains(['width', '8px'], self::declarationsOf('.jm-status__dot', null));
        static::assertContains(['height', '8px'], self::declarationsOf('.jm-status__dot', null));
        static::assertContains(['font-size', '13px'], self::declarationsOf('.jm-status__pill', null));
    }

    public function testTheAlertsWrapAndFillTheWidthOfANarrowBox(): void
    {
        $wide = '@container jm-alerts (width >= 480px)';

        static::assertContains(['container-type', 'inline-size'], self::declarationsOf('.jm-alerts', null));
        static::assertContains(['container-name', 'jm-alerts'], self::declarationsOf('.jm-alerts', null));
        static::assertContains(['display', 'flex'], self::declarationsOf('.jm-alerts__list', null));
        static::assertContains(['flex-wrap', 'wrap'], self::declarationsOf('.jm-alerts__list', null));
        static::assertContains(['gap', '12px'], self::declarationsOf('.jm-alerts__list', null));
        static::assertContains(['flex', '1 1 100%'], self::declarationsOf('.jm-alerts__item', null));
        static::assertSame([['flex', '0 1 290px']], self::declarationsOf('.jm-alerts__item', $wide), 'four cards a row in a list of 1232 px, three in one of 917 px');
        static::assertContains(['min-width', '0'], self::declarationsOf('.jm-alert', null));
        static::assertContains(['overflow-wrap', 'anywhere'], self::declarationsOf('.jm-alert', null));
        static::assertContains(['color', 'inherit'], self::declarationsOf('.jm-alert', null));
        static::assertContains(['text-decoration', 'none'], self::declarationsOf('.jm-alert', null));
    }

    public function testTheFrameOfAnAlertFollowsItsTone(): void
    {
        static::assertSame([['border-color', 'var(--jm-warn-border)']], self::declarationsOf('.jm-alert--warn', null));
        static::assertSame([['border-color', 'var(--jm-bad-border)']], self::declarationsOf('.jm-alert--bad', null));
    }

    public function testForcedColorsKeepTheFrameAndTheDotOfTheStatus(): void
    {
        $forced = '@media (forced-colors: active)';

        static::assertContains(['border-color', 'CanvasText'], self::declarationsOf('.jm-status__pill', $forced));
        static::assertContains(['background', 'CanvasText'], self::declarationsOf('.jm-status__dot', $forced));
        static::assertContains(['forced-color-adjust', 'none'], self::declarationsOf('.jm-status__dot', $forced));
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

            if (!str_starts_with($prelude, '@media ') && !str_starts_with($prelude, '@container ') && !str_starts_with($prelude, '@keyframes ')) {
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
