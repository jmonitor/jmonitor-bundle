<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Check;

use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Check\Severity;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Check\StackHealth;
use Jmonitor\JmonitorBundle\Stack\Check\Verdict;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\WebServerPayloads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class StackChecksTest extends TestCase
{
    /**
     * @return iterable<string, array{Component, array<string, array<string, mixed>>, array<string, Delta>, string, ?Severity, 5?: bool}>
     */
    public static function thresholds(): iterable
    {
        yield 'memory 84.9 %' => [Component::System, ['system' => self::memory(849)], [], 'system.memory', null];
        yield 'memory 85 %' => [Component::System, ['system' => self::memory(850)], [], 'system.memory', Severity::Warning];
        yield 'memory 94.9 %' => [Component::System, ['system' => self::memory(949)], [], 'system.memory', Severity::Warning];
        yield 'memory 95 %' => [Component::System, ['system' => self::memory(950)], [], 'system.memory', Severity::Critical];

        yield 'disk 84.9 %' => [Component::System, ['system' => self::disk(849)], [], 'system.disk', null];
        yield 'disk 85 %' => [Component::System, ['system' => self::disk(850)], [], 'system.disk', Severity::Warning];
        yield 'disk 94.9 %' => [Component::System, ['system' => self::disk(949)], [], 'system.disk', Severity::Warning];
        yield 'disk 95 %' => [Component::System, ['system' => self::disk(950)], [], 'system.disk', Severity::Critical];

        yield 'load 99.9 %' => [Component::System, ['system' => self::load(99.9)], [], 'system.load', null];
        yield 'load 100 %' => [Component::System, ['system' => self::load(100)], [], 'system.load', Severity::Warning];
        yield 'load 199.9 %' => [Component::System, ['system' => self::load(199.9)], [], 'system.load', Severity::Warning];
        yield 'load 200 %' => [Component::System, ['system' => self::load(200)], [], 'system.load', Severity::Critical];

        yield 'OPcache running' => [Component::Php, ['php' => self::php()], [], 'php.opcache_disabled', null];
        yield 'OPcache turned off at runtime' => [Component::Php, ['php' => self::php(['opcache' => ['status' => ['opcache_enabled' => false]]])], [], 'php.opcache_disabled', Severity::Warning];
        yield 'OPcache not loaded' => [Component::Php, ['php' => ['opcache' => []] + self::php()], [], 'php.opcache_disabled', Severity::Warning];
        yield 'OPcache told off' => [Component::Php, ['php' => ['opcache' => false] + self::php()], [], 'php.opcache_disabled', Severity::Warning];
        yield 'OPcache not told' => [Component::Php, ['php' => array_diff_key(self::php(), ['opcache' => true])], [], 'php.opcache_disabled', null];
        yield 'OPcache null' => [Component::Php, ['php' => ['opcache' => null] + self::php()], [], 'php.opcache_disabled', null];
        yield 'OPcache as a string' => [Component::Php, ['php' => ['opcache' => 'on'] + self::php()], [], 'php.opcache_disabled', null];
        yield 'OPcache status and config of unknown shape' => [Component::Php, ['php' => ['opcache' => ['status' => 'x', 'config' => 5]] + self::php()], [], 'php.opcache_disabled', null];
        yield 'OPcache flag that cannot be read' => [Component::Php, ['php' => ['opcache' => ['status' => ['opcache_enabled' => 'maybe']]] + self::php()], [], 'php.opcache_disabled', null];
        yield 'OPcache turned off at runtime in debug' => [Component::Php, ['php' => self::php(['opcache' => ['status' => ['opcache_enabled' => false]]])], [], 'php.opcache_disabled', Severity::Info, true];
        yield 'OPcache not loaded in debug' => [Component::Php, ['php' => ['opcache' => []] + self::php()], [], 'php.opcache_disabled', Severity::Info, true];

        yield 'OPcache memory 89.9 %' => [Component::Php, ['php' => self::opcacheMemory(899)], [], 'php.opcache_memory', null];
        yield 'OPcache memory 90 %' => [Component::Php, ['php' => self::opcacheMemory(900)], [], 'php.opcache_memory', Severity::Warning];
        yield 'OPcache full at 50 %' => [Component::Php, ['php' => self::opcacheMemory(500, true)], [], 'php.opcache_memory', Severity::Critical];
        yield 'OPcache memory 89.9 % with wasted memory' => [Component::Php, ['php' => self::opcacheMemory(799, wasted: 100)], [], 'php.opcache_memory', null];
        yield 'OPcache memory 90 % with wasted memory' => [Component::Php, ['php' => self::opcacheMemory(800, wasted: 100)], [], 'php.opcache_memory', Severity::Warning];

        yield 'OPcache hit rate 84.9 % of 1,000' => [Component::Php, ['php' => self::hitRate(849, 151)], [], 'php.opcache_hit_rate', Severity::Warning];
        yield 'OPcache hit rate 84.9 % of 999' => [Component::Php, ['php' => self::hitRate(848, 151)], [], 'php.opcache_hit_rate', null];
        yield 'OPcache hit rate 85 %' => [Component::Php, ['php' => self::hitRate(850, 150)], [], 'php.opcache_hit_rate', null];
        yield 'OPcache hit rate 84.9 % of 1,000 in debug' => [Component::Php, ['php' => self::hitRate(849, 151)], [], 'php.opcache_hit_rate', null, true];
        yield 'OPcache hit rate 10 % in debug' => [Component::Php, ['php' => self::hitRate(100_000, 900_000)], [], 'php.opcache_hit_rate', null, true];

        yield 'APCu memory 89.9 %' => [Component::Php, ['php' => self::apcuMemory(899)], [], 'php.apcu_memory', null];
        yield 'APCu memory 90 %' => [Component::Php, ['php' => self::apcuMemory(900)], [], 'php.apcu_memory', Severity::Warning];
        yield 'APCu turned off at 95 %' => [Component::Php, ['php' => self::apcuMemory(950, false)], [], 'php.apcu_memory', null];

        yield 'FPM queue empty' => [Component::Php, ['php' => self::fpm(['listen-queue' => 0])], [], 'php.fpm_queue', null];
        yield 'FPM queue of 1' => [Component::Php, ['php' => self::fpm(['listen-queue' => 1])], [], 'php.fpm_queue', Severity::Warning];

        yield 'max_children never reached' => [Component::Php, ['php' => self::fpm(['max-children-reached' => 0])], [], 'php.fpm_max_children', null];
        yield 'max_children reached since start only' => [Component::Php, ['php' => self::fpm(['max-children-reached' => 1])], ['php' => Snapshots::delta(['fpm' => ['max-children-reached' => 1]], ['fpm' => ['max-children-reached' => 1]])], 'php.fpm_max_children', Severity::Info];
        yield 'max_children reached between the samples' => [Component::Php, ['php' => self::fpm(['max-children-reached' => 1])], ['php' => Snapshots::delta(['fpm' => ['max-children-reached' => 0]], ['fpm' => ['max-children-reached' => 1]])], 'php.fpm_max_children', Severity::Warning];

        yield 'display_errors off' => [Component::Php, ['php' => self::php(['display_errors' => '0'])], [], 'php.display_errors', null];
        yield 'display_errors on' => [Component::Php, ['php' => self::php(['display_errors' => '1'])], [], 'php.display_errors', Severity::Warning];
        yield 'display_errors to stderr' => [Component::Php, ['php' => self::php(['display_errors' => 'stderr'])], [], 'php.display_errors', Severity::Warning];
        yield 'display_errors on in debug' => [Component::Php, ['php' => self::php(['display_errors' => '1'])], [], 'php.display_errors', null, true];

        yield 'realpath_cache_size 4,095,999' => [Component::Php, ['php' => self::php(['realpath_cache_size' => '4095999'])], [], 'php.realpath_cache_size', Severity::Info];
        yield 'realpath_cache_size 4,096,000' => [Component::Php, ['php' => self::php(['realpath_cache_size' => '4096000'])], [], 'php.realpath_cache_size', null];

        yield 'PHP supported' => [Component::Php, ['php' => self::php(['version' => '8.4.1']), 'eol.php' => Snapshots::cycles()], [], 'php.security_only', null];
        yield 'PHP security fixes only' => [Component::Php, ['php' => self::php(['version' => '8.2.1']), 'eol.php' => Snapshots::cycles()], [], 'php.security_only', Severity::Warning];
        yield 'PHP supported, not ended' => [Component::Php, ['php' => self::php(['version' => '8.4.1']), 'eol.php' => Snapshots::cycles()], [], 'php.end_of_life', null];
        yield 'PHP ended' => [Component::Php, ['php' => self::php(['version' => '8.1.5']), 'eol.php' => Snapshots::cycles()], [], 'php.end_of_life', Severity::Critical];

        yield 'Symfony supported' => [Component::Symfony, ['symfony' => Snapshots::symfony(), 'eol.symfony' => Snapshots::cycles()], [], 'symfony.security_only', null];
        yield 'Symfony security fixes only' => [Component::Symfony, ['symfony' => ['version' => '6.4.1'] + Snapshots::symfony(), 'eol.symfony' => Snapshots::cycles()], [], 'symfony.security_only', Severity::Warning];
        yield 'Symfony supported, not ended' => [Component::Symfony, ['symfony' => Snapshots::symfony(), 'eol.symfony' => Snapshots::cycles()], [], 'symfony.end_of_life', null];
        yield 'Symfony ended' => [Component::Symfony, ['symfony' => ['version' => '5.4.1'] + Snapshots::symfony(), 'eol.symfony' => Snapshots::cycles()], [], 'symfony.end_of_life', Severity::Critical];

        yield 'prod without debug' => [Component::Symfony, ['symfony' => Snapshots::symfony()], [], 'symfony.debug_in_prod', null];
        yield 'prod with debug' => [Component::Symfony, ['symfony' => ['debug' => true] + Snapshots::symfony()], [], 'symfony.debug_in_prod', Severity::Critical];
        yield 'dev with debug' => [Component::Symfony, ['symfony' => ['env' => 'dev', 'debug' => true] + Snapshots::symfony()], [], 'symfony.debug_in_prod', null];

        yield 'recipes up to date' => [Component::Symfony, ['symfony' => Snapshots::symfony(), 'symfony.flex' => ['up_to_date' => true, 'outdated_recipes' => []]], [], 'symfony.flex_recipes', null];
        yield 'one outdated recipe' => [Component::Symfony, ['symfony' => Snapshots::symfony(), 'symfony.flex' => ['up_to_date' => false, 'outdated_recipes' => ['symfony/twig-bundle']]], [], 'symfony.flex_recipes', Severity::Info];
    }

    /**
     * @param array<string, array<string, mixed>> $data
     * @param array<string, Delta>                $deltas
     */
    #[DataProvider('thresholds')]
    public function testEachCheckRisesAtItsThresholdAndNotJustBelow(Component $component, array $data, array $deltas, string $id, ?Severity $expected, bool $debug = false): void
    {
        $health = self::checks(debug: $debug)->health(Snapshots::stack(Snapshots::of($component, $data, deltas: $deltas)));

        self::assertSame($expected, self::find($health, $id)?->severity);

        foreach ($health->findings as $finding) {
            self::assertSame($component, $finding->component);
            self::assertNotSame('', $finding->sentence);
            self::assertNotSame('', $finding->explanation);
        }
    }

    public function testTheSentenceCarriesTheValue(): void
    {
        $health = self::checks()->health(Snapshots::stack(
            Snapshots::of(Component::System, ['system' => self::memory(930)]),
            Snapshots::of(Component::Php, ['php' => self::opcacheMemory(935)]),
        ));

        self::assertSame("Memory is 93\u{00A0}% used", self::find($health, 'system.memory')?->sentence);
        self::assertSame("OPcache memory is 93.5\u{00A0}% full", self::find($health, 'php.opcache_memory')?->sentence);
    }

    public function testAFullOpcacheMayLackMemoryOrKeys(): void
    {
        $finding = self::find(self::checks()->health(Snapshots::stack(Snapshots::of(Component::Php, ['php' => self::opcacheMemory(500, true)]))), 'php.opcache_memory');

        self::assertSame('OPcache is full', $finding?->sentence);
        self::assertStringContainsString('opcache.memory_consumption', $finding->explanation);
        self::assertStringContainsString('opcache.max_accelerated_files', $finding->explanation);
    }

    public function testAnOpcacheStatusThatCannotBeReadIsNotSaidToBeOff(): void
    {
        $unread = self::find(self::checks()->health(Snapshots::stack(Snapshots::of(Component::Php, ['php' => ['opcache' => []] + self::php()]))), 'php.opcache_disabled');
        $off = self::find(self::checks()->health(Snapshots::stack(Snapshots::of(Component::Php, ['php' => self::php(['opcache' => ['status' => ['opcache_enabled' => false]]])]))), 'php.opcache_disabled');

        self::assertSame('OPcache is not running, or its status cannot be read', $unread?->sentence);
        self::assertStringContainsString('opcache.enable', $unread->explanation);
        self::assertStringContainsString('opcache.restrict_api', $unread->explanation);
        self::assertSame('OPcache is not running', $off?->sentence);
    }

    /**
     * @return iterable<string, array{int, int, ?array{int, int}, ?string}>
     */
    public static function hitRateSpans(): iterable
    {
        yield 'no lookup between the samples' => [500_000, 500_000, [500_000, 500_000], "OPcache hit rate is 50\u{00A0}% since server start"];
        yield 'too few lookups between the samples' => [500_000, 500_000, [499_800, 499_800], "OPcache hit rate is 50\u{00A0}% since server start"];
        yield 'enough lookups between the samples' => [500_000, 500_000, [499_500, 499_500], "OPcache hit rate is 50\u{00A0}% over the last 60\u{00A0}s"];
        yield 'a bad variation beats a good cumulated rate' => [990_500, 10_500, [990_000, 10_000], "OPcache hit rate is 50\u{00A0}% over the last 60\u{00A0}s"];
        yield 'a good variation beats a bad cumulated rate' => [500_990, 500_010, [500_000, 500_000], null];
        yield 'too few lookups either way' => [500, 499, [450, 449], null];
        yield 'too few lookups and no second sample' => [500, 499, null, null];
    }

    /**
     * @param ?array{int, int} $previous hits and misses of the previous sample
     */
    #[DataProvider('hitRateSpans')]
    public function testTheHitRateJudgesTheVariationWhenItHoldsEnoughLookups(int $hits, int $misses, ?array $previous, ?string $expected): void
    {
        $deltas = $previous === null ? [] : ['php' => Snapshots::delta(
            ['opcache' => ['status' => ['opcache_statistics' => ['hits' => $previous[0], 'misses' => $previous[1]]]]],
            ['opcache' => ['status' => ['opcache_statistics' => ['hits' => $hits, 'misses' => $misses]]]],
        )];

        $finding = self::find(self::checks()->health(Snapshots::stack(Snapshots::of(Component::Php, ['php' => self::hitRate($hits, $misses)], deltas: $deltas))), 'php.opcache_hit_rate');

        self::assertSame($expected, $finding?->sentence);
    }

    public function testTheLoadIsOnlyInformativeInAContainer(): void
    {
        foreach ([150.0, 250.0] as $load) {
            $data = self::load($load);
            $data['cgroup'] = ['memory_limit' => 2_147_483_648, 'cpu_quota' => null];

            $finding = self::find(self::checks()->health(Snapshots::stack(Snapshots::of(Component::System, ['system' => $data]))), 'system.load');

            self::assertSame(Severity::Info, $finding?->severity);
            self::assertStringContainsString('host', $finding->explanation);
        }
    }

    public function testAFailedMainCollectorIsAWarningAndASecondaryOneAnInfo(): void
    {
        $health = self::checks()->health(Snapshots::stack(
            Snapshots::of(Component::System, [], ['system' => ResultState::Failed]),
            Snapshots::of(Component::Php, [], ['php' => ResultState::Failed]),
            Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Failed]),
        ));

        self::assertSame(Severity::Warning, self::find($health, 'system.failed')?->severity);
        self::assertSame(Severity::Warning, self::find($health, 'php.failed')?->severity);
        self::assertSame(Severity::Info, self::find($health, 'symfony.flex.failed')?->severity);
        self::assertStringContainsString(Snapshots::FAILURE, self::find($health, 'php.failed')->sentence, 'the sentence gives the reason');
        self::assertNull(self::find($health, 'php.opcache_disabled'), 'nothing is judged without a value');
        self::assertSame(Verdict::Unknown, $health->verdict(Component::Php));
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Symfony), 'a failed secondary collector is only informative');
    }

    public function testAnUnavailableInstanceIsAWarning(): void
    {
        $health = self::checks()->health(Snapshots::state([
            'php' => [new InstanceState(null, null, 'The PHP endpoint answered 502.', new ComponentSnapshot(Component::Php, [], 'The PHP endpoint answered 502.'))],
        ]));

        self::assertSame(Severity::Warning, self::find($health, 'php.failed')?->severity);
        self::assertStringContainsString('502', self::find($health, 'php.failed')->sentence);
        self::assertCount(1, $health->findings);
        self::assertSame(Verdict::Unknown, $health->verdict(Component::Php));
    }

    public function testAFailedEndOfLifeLookupRaisesNothing(): void
    {
        $health = self::checks()->health(Snapshots::stack(
            Snapshots::of(Component::Php, ['php' => self::php()], ['eol.php' => ResultState::Failed]),
            Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['eol.symfony' => ResultState::Failed]),
        ));

        self::assertSame([], $health->findings);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Php));
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Symfony));
    }

    public function testARateWithoutSecondSampleRaisesNothing(): void
    {
        $withoutSample = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Php, ['php' => self::php()])));
        $afterARestart = self::checks()->health(Snapshots::stack(Snapshots::of(
            Component::Php,
            ['php' => self::php()],
            deltas: ['php' => Snapshots::delta(['fpm' => ['max-children-reached' => 5]], ['fpm' => ['max-children-reached' => 0]])],
        )));
        $reachedBefore = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Php, ['php' => self::fpm(['max-children-reached' => 2])])));

        self::assertSame([], $withoutSample->findings);
        self::assertSame([], $afterARestart->findings, 'a counter that went back is no variation');
        self::assertSame(Severity::Info, self::find($reachedBefore, 'php.fpm_max_children')?->severity, 'only what the cumulated counter says');
    }

    public function testARatioSaysWhatItCovers(): void
    {
        $recent = self::checks()->health(Snapshots::stack(Snapshots::of(
            Component::Php,
            ['php' => self::fpm(['max-children-reached' => 4], self::hitRate(5_000, 5_000))],
            deltas: ['php' => Snapshots::delta(
                ['opcache' => ['status' => ['opcache_statistics' => ['hits' => 4_200, 'misses' => 4_800]]], 'fpm' => ['max-children-reached' => 1]],
                ['opcache' => ['status' => ['opcache_statistics' => ['hits' => 5_000, 'misses' => 5_000]]], 'fpm' => ['max-children-reached' => 4]],
                60,
            )],
        )));
        $sinceStart = self::checks()->health(Snapshots::stack(Snapshots::of(
            Component::Php,
            ['php' => self::fpm(['max-children-reached' => 4], self::hitRate(5_000, 5_000))],
        )));

        self::assertSame("OPcache hit rate is 80\u{00A0}% over the last 60\u{00A0}s", self::find($recent, 'php.opcache_hit_rate')?->sentence);
        self::assertSame("PHP-FPM reached pm.max_children 3 times over the last 60\u{00A0}s", self::find($recent, 'php.fpm_max_children')?->sentence);
        self::assertSame("OPcache hit rate is 50\u{00A0}% since server start", self::find($sinceStart, 'php.opcache_hit_rate')?->sentence);
        self::assertSame("PHP-FPM reached pm.max_children 4 times since PHP-FPM started", self::find($sinceStart, 'php.fpm_max_children')?->sentence);
    }

    public function testAFindingOnAnOutdatedValueCarriesItsAge(): void
    {
        $stale = self::checks()->health(Snapshots::stack(Snapshots::of(Component::System, ['system' => self::memory(960)], ['system' => ResultState::Stale])));
        $fresh = self::checks()->health(Snapshots::stack(Snapshots::of(Component::System, ['system' => self::memory(960)])));
        $renewalFailed = self::checks()->health(Snapshots::stack(new ComponentSnapshot(Component::System, [
            'system' => new CollectorResult('system', self::memory(960), ResultState::Stale, 'It could not be read again.', Snapshots::NOW - 120, expiresAt: Snapshots::NOW + 300),
        ])));

        self::assertSame(Snapshots::STALE_AGE, self::find($stale, 'system.memory')?->age);
        self::assertSame(Verdict::Critical, $stale->verdict(Component::System), 'an outdated value is still judged');
        self::assertNull(self::find($fresh, 'system.memory')?->age);
        self::assertSame(120, self::find($renewalFailed, 'system.memory')?->age);
        self::assertSame(120, self::find($renewalFailed, 'system.failed')?->age, 'the value is still there, its renewal failed');
    }

    public function testNoFindingOnAMissingValue(): void
    {
        $health = self::checks()->health(Snapshots::stack(
            Snapshots::of(Component::System, ['system' => []]),
            Snapshots::of(Component::Php, ['php' => ['opcache' => ['status' => ['opcache_enabled' => true]]], 'eol.php' => Snapshots::cycles()]),
            Snapshots::of(Component::Symfony, ['symfony' => [], 'symfony.flex' => [], 'eol.symfony' => Snapshots::cycles()]),
        ));

        self::assertSame([], $health->findings);
    }

    public function testDataOfUnknownShapeRaisesNothing(): void
    {
        $health = self::checks()->health(Snapshots::stack(
            Snapshots::of(Component::System, ['system' => [
                'disk' => 'full',
                'cpu' => ['cores' => 'four', 'load' => [250], 'load1' => INF],
                'ram' => ['total' => 'lots', 'available' => []],
                'cgroup' => 'none',
            ]]),
            Snapshots::of(Component::Php, [
                'php' => [
                    'version' => ['8.1'],
                    'display_errors' => ['On'],
                    'realpath_cache_size' => '16Q',
                    'apcu' => 'on',
                    'opcache' => ['status' => ['opcache_enabled' => 'maybe', 'cache_full' => 'yes please'], 'config' => 5],
                    'fpm' => ['listen-queue' => 'many', 'max-children-reached' => NAN],
                ],
                'eol.php' => ['cycles' => 'soon'],
            ], deltas: ['php' => Snapshots::delta(['fpm' => 'x'], ['fpm' => ['max-children-reached' => 'many']])]),
            Snapshots::of(Component::Symfony, [
                'symfony' => ['env' => ['prod'], 'debug' => 'maybe', 'version' => ['5.4']],
                'symfony.flex' => ['up_to_date' => 'perhaps', 'outdated_recipes' => 'symfony/framework-bundle'],
                'eol.symfony' => ['cycles' => ['5.4' => 'ended']],
            ]),
        ));

        self::assertSame([], $health->findings);
    }

    public function testAnIgnoredCheckIsDroppedBeforeTheVerdict(): void
    {
        $state = Snapshots::stack(Snapshots::of(Component::System, ['system' => self::memory(960)]));

        self::assertSame(Verdict::Critical, self::checks()->health($state)->verdict(Component::System));

        $health = self::checks(['system.memory'])->health($state);

        self::assertSame([], $health->findings);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::System));
    }

    public function testInfoDoesNotChangeTheVerdict(): void
    {
        $health = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Php, ['php' => self::php(['realpath_cache_size' => '16K'])])));

        self::assertSame(Severity::Info, self::find($health, 'php.realpath_cache_size')?->severity);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Php));
    }

    public function testAnInstanceThatWasNotReadIsUnknown(): void
    {
        $health = self::checks()->health(Snapshots::state([
            'system' => [new InstanceState(null, null, null, null)],
            'php' => [new InstanceState(null, null, null, Snapshots::of(Component::Php, ['eol.php' => Snapshots::cycles()], ['php' => ResultState::Pending]))],
            'symfony' => [new InstanceState(null, null, null, Snapshots::of(Component::Symfony, [], ['symfony' => ResultState::Busy]))],
        ]));

        self::assertSame([], $health->findings, 'nothing to tell while it is being read');
        self::assertSame(Verdict::Unknown, $health->verdict(Component::System));
        self::assertSame(Verdict::Unknown, $health->verdict(Component::Php));
        self::assertSame(Verdict::Unknown, $health->verdict(Component::Symfony));
    }

    public function testAnEngineFailureGivesNothingToCheck(): void
    {
        $health = self::checks()->health(new StackState(null, Snapshots::NOW, CacheState::Working, [], [], 'The stack could not be read.'));

        self::assertSame([], $health->findings);
        self::assertSame(Verdict::Unknown, $health->componentVerdict(Component::System));
    }

    public function testEachInstanceIsJudgedOnItsOwn(): void
    {
        $health = self::checks()->health(Snapshots::state(['system' => [
            new InstanceState('a', null, null, Snapshots::of(Component::System, ['system' => self::memory(960)])),
            new InstanceState('b', null, null, Snapshots::of(Component::System, ['system' => self::memory(500)])),
        ]]));

        self::assertSame('a', self::find($health, 'system.memory')?->instance);
        self::assertSame(Verdict::Critical, $health->verdict(Component::System, 'a'));
        self::assertSame(Verdict::Healthy, $health->verdict(Component::System, 'b'));
        self::assertSame(Verdict::Critical, $health->componentVerdict(Component::System));
    }

    /**
     * @return iterable<string, array{Component, array<string, array<string, mixed>>, array<string, Delta>, string, ?Severity}>
     */
    public static function webServerThresholds(): iterable
    {
        yield 'Apache slots 89.9 %' => [Component::Apache, ['apache' => self::apacheSlots(899)], [], 'apache.workers', null];
        yield 'Apache slots 90 %' => [Component::Apache, ['apache' => self::apacheSlots(900)], [], 'apache.workers', Severity::Warning];
        yield 'Apache slots 99.9 %' => [Component::Apache, ['apache' => self::apacheSlots(999)], [], 'apache.workers', Severity::Warning];
        yield 'Apache slots 100 %' => [Component::Apache, ['apache' => self::apacheSlots(1_000)], [], 'apache.workers', Severity::Critical];

        yield 'Apache with 1 idle worker' => [Component::Apache, ['apache' => self::apacheWorkers(3, 1)], [], 'apache.idle_workers', null];
        yield 'Apache without idle worker' => [Component::Apache, ['apache' => self::apacheWorkers(3, 0)], [], 'apache.idle_workers', Severity::Info];
        yield 'Apache without any worker' => [Component::Apache, ['apache' => self::apacheWorkers(0, 0)], [], 'apache.idle_workers', null];
        yield 'Apache idle workers not told' => [Component::Apache, ['apache' => self::apacheWorkers(3, null)], [], 'apache.idle_workers', null];
        yield 'Apache busy workers not told' => [Component::Apache, ['apache' => self::apacheWorkers(null, 0)], [], 'apache.idle_workers', null];

        yield 'Nginx connections 84.9 %' => [Component::Nginx, self::nginxConnections(849), [], 'nginx.connections', null];
        yield 'Nginx connections 85 %' => [Component::Nginx, self::nginxConnections(850), [], 'nginx.connections', Severity::Warning];
        yield 'Nginx connections 94.9 %' => [Component::Nginx, self::nginxConnections(949), [], 'nginx.connections', Severity::Warning];
        yield 'Nginx connections 95 %' => [Component::Nginx, self::nginxConnections(950), [], 'nginx.connections', Severity::Critical];

        yield 'Nginx never refused' => [Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus(101, 101)], ['nginx' => Snapshots::delta(WebServerPayloads::nginxStatus(100, 100), WebServerPayloads::nginxStatus(101, 101))], 'nginx.refused', null];
        yield 'Nginx refused 1 between the samples' => [Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus(101, 100)], ['nginx' => Snapshots::delta(WebServerPayloads::nginxStatus(100, 100), WebServerPayloads::nginxStatus(101, 100))], 'nginx.refused', Severity::Warning];
        yield 'Nginx refused since start only' => [Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus(101, 100)], ['nginx' => Snapshots::delta(WebServerPayloads::nginxStatus(100, 99), WebServerPayloads::nginxStatus(101, 100))], 'nginx.refused', Severity::Info];
        yield 'Nginx refused, no second sample' => [Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus(101, 100)], [], 'nginx.refused', Severity::Info];

        yield 'FrankenPHP queue empty' => [Component::FrankenPhp, ['frankenphp' => self::frankenphp()], [], 'frankenphp.queue', null];
        yield 'FrankenPHP queue of 1' => [Component::FrankenPhp, ['frankenphp' => self::frankenphp(queue: 1)], [], 'frankenphp.queue', Severity::Warning];

        yield 'FrankenPHP worker queue empty' => [Component::FrankenPhp, ['frankenphp' => self::frankenphp()], [], 'frankenphp.worker_queue', null];
        yield 'FrankenPHP worker queue of 1' => [Component::FrankenPhp, ['frankenphp' => self::frankenphp(['worker_queue_depth' => 1])], [], 'frankenphp.worker_queue', Severity::Warning];

        yield 'FrankenPHP workers never crashed' => [Component::FrankenPhp, ['frankenphp' => self::frankenphp()], ['frankenphp' => Snapshots::delta(self::frankenphp(), self::frankenphp())], 'frankenphp.worker_crashes', null];
        yield 'FrankenPHP worker crash not exported yet' => [Component::FrankenPhp, ['frankenphp' => ['workers' => [['name' => '/srv/probe/worker.php', 'total_workers' => 2]]] + self::frankenphp()], [], 'frankenphp.worker_crashes', null];
        yield 'FrankenPHP worker crashed between the samples' => [Component::FrankenPhp, ['frankenphp' => self::frankenphp(['worker_crashes' => 1])], ['frankenphp' => Snapshots::delta(self::frankenphp(), self::frankenphp(['worker_crashes' => 1]))], 'frankenphp.worker_crashes', Severity::Warning];
        yield 'FrankenPHP worker crashed since start only' => [Component::FrankenPhp, ['frankenphp' => self::frankenphp(['worker_crashes' => 1])], ['frankenphp' => Snapshots::delta(self::frankenphp(['worker_crashes' => 1]), self::frankenphp(['worker_crashes' => 1]))], 'frankenphp.worker_crashes', Severity::Info];
        yield 'FrankenPHP worker crashed, no second sample' => [Component::FrankenPhp, ['frankenphp' => self::frankenphp(['worker_crashes' => 1])], [], 'frankenphp.worker_crashes', Severity::Info];
    }

    /**
     * @param array<string, array<string, mixed>> $data
     * @param array<string, Delta>                $deltas
     */
    #[DataProvider('webServerThresholds')]
    public function testEachWebServerCheckRisesAtItsThresholdAndNotJustBelow(Component $component, array $data, array $deltas, string $id, ?Severity $expected): void
    {
        $this->testEachCheckRisesAtItsThresholdAndNotJustBelow($component, $data, $deltas, $id, $expected);
    }

    public function testAWebServerSentenceCarriesItsValue(): void
    {
        $health = self::checks()->health(Snapshots::stack(
            Snapshots::of(Component::Apache, ['apache' => self::apacheSlots(935)]),
            Snapshots::of(Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus(110, 100)], deltas: ['nginx' => Snapshots::delta(WebServerPayloads::nginxStatus(100, 98), WebServerPayloads::nginxStatus(110, 100), 30)]),
        ));
        $connections = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Nginx, self::nginxConnections(905))));
        $refusedBefore = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus(101, 100)])));

        self::assertSame("Apache workers take 93.5\u{00A0}% of the slots (935 of 1000)", self::find($health, 'apache.workers')?->sentence);
        self::assertSame("Nginx holds 90.5\u{00A0}% of its connections (905 of 1000)", self::find($connections, 'nginx.connections')?->sentence);
        self::assertSame("Nginx refused 8 connections over the last 30\u{00A0}s", self::find($health, 'nginx.refused')?->sentence);
        self::assertSame('Nginx refused 1 connection since it started', self::find($refusedBefore, 'nginx.refused')?->sentence);
        self::assertStringContainsString('MaxRequestWorkers', self::find($health, 'apache.workers')->explanation, 'the scoreboard may hold more slots');
        self::assertStringContainsString('upstream', self::find($connections, 'nginx.connections')->explanation);
    }

    public function testNoIdleWorkerIsOnlyInformative(): void
    {
        $health = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Apache, ['apache' => self::apacheWorkers(150, 0)])));
        $one = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Apache, ['apache' => self::apacheWorkers(1, 0)])));

        self::assertSame(Severity::Info, self::find($health, 'apache.idle_workers')?->severity);
        self::assertSame('Apache has no idle worker, 150 are busy', self::find($health, 'apache.idle_workers')->sentence);
        self::assertStringContainsString('MaxRequestWorkers', self::find($health, 'apache.idle_workers')->explanation);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Apache));
        self::assertSame('Apache has no idle worker, 1 is busy', self::find($one, 'apache.idle_workers')?->sentence);
    }

    public function testAWorkerCheckNamesItsScripts(): void
    {
        $data = self::frankenphp(['worker_queue_depth' => 3, 'worker_crashes' => 2]);
        $data['workers'][1]['worker_queue_depth'] = 1;
        $data['workers'][1]['worker_crashes'] = 1;
        $data['workers'][] = ['name' => '/app/bin/quiet.php', 'total_workers' => 1, 'worker_crashes' => 0, 'worker_queue_depth' => 0];
        $previous = $data;
        $previous['workers'][0]['worker_crashes'] = 0;

        $health = self::checks()->health(Snapshots::stack(Snapshots::of(Component::FrankenPhp, ['frankenphp' => $data], deltas: ['frankenphp' => Snapshots::delta($previous, $data, 20)])));
        $queue = array_values(array_filter($health->findings, static fn(Finding $finding): bool => $finding->id === 'frankenphp.worker_queue'));

        self::assertCount(1, $queue, 'one finding for every script');
        self::assertSame('4 requests are waiting for a worker of index.php, api', $queue[0]->sentence);
        self::assertSame("Workers of index.php crashed 2 times over the last 20\u{00A0}s", self::find($health, 'frankenphp.worker_crashes')?->sentence, 'api crashed before the previous sample');
        self::assertSame(Severity::Warning, self::find($health, 'frankenphp.worker_crashes')->severity);

        $sinceStart = self::checks()->health(Snapshots::stack(Snapshots::of(Component::FrankenPhp, ['frankenphp' => $data])));

        self::assertSame('Workers of index.php, api crashed 3 times since FrankenPHP started', self::find($sinceStart, 'frankenphp.worker_crashes')?->sentence);
    }

    public function testASingleCrashIsThatOfOneWorker(): void
    {
        $crashed = self::frankenphp(['worker_crashes' => 1]);

        $recent = self::checks()->health(Snapshots::stack(Snapshots::of(Component::FrankenPhp, ['frankenphp' => $crashed], deltas: ['frankenphp' => Snapshots::delta(self::frankenphp(), $crashed, 20)])));
        $sinceStart = self::checks()->health(Snapshots::stack(Snapshots::of(Component::FrankenPhp, ['frankenphp' => $crashed])));

        self::assertSame("A worker of index.php crashed once over the last 20\u{00A0}s", self::find($recent, 'frankenphp.worker_crashes')?->sentence);
        self::assertSame('A worker of index.php crashed once since FrankenPHP started', self::find($sinceStart, 'frankenphp.worker_crashes')?->sentence);
    }

    public function testAStatusPageThatCannotBeReadIsOnlyInformative(): void
    {
        $health = self::checks()->health(Snapshots::stack(
            Snapshots::of(Component::FrankenPhp, ['frankenphp.version' => ['version' => '1.13.0']], ['frankenphp' => ResultState::Failed]),
            Snapshots::of(Component::Apache, ['eol.apache-http-server' => WebServerPayloads::cycles()], ['apache' => ResultState::Failed]),
            Snapshots::of(Component::Nginx, ['nginx.config' => WebServerPayloads::nginxConfig(), 'eol.nginx' => WebServerPayloads::cycles()], ['nginx' => ResultState::Failed]),
        ));

        foreach ([Component::FrankenPhp, Component::Apache, Component::Nginx] as $component) {
            $finding = self::find($health, $component->value . '.failed');

            self::assertSame(Severity::Info, $finding?->severity, $component->value);
            self::assertStringContainsString(Snapshots::FAILURE, $finding->sentence, 'the reason tells how to expose it');
            self::assertStringContainsString('only', $finding->explanation);
            self::assertStringContainsString('live figures', $finding->explanation, 'other checks may still be judged');
            self::assertSame(Verdict::Unknown, $health->verdict($component));
        }

        self::assertSame(Severity::Critical, self::find($health, 'nginx.end_of_life')?->severity, 'the version comes from a command');
    }

    public function testACommandThatCannotRunIsOnlyInformative(): void
    {
        $health = self::checks()->health(Snapshots::stack(
            Snapshots::of(Component::FrankenPhp, ['frankenphp' => self::frankenphp()], ['frankenphp.version' => ResultState::Failed]),
            Snapshots::of(Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus()], ['nginx.config' => ResultState::Failed]),
        ));

        self::assertSame(Severity::Info, self::find($health, 'frankenphp.version.failed')?->severity);
        self::assertSame(Severity::Info, self::find($health, 'nginx.config.failed')?->severity);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::FrankenPhp));
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Nginx));
    }

    public function testNoConnectionCheckWithoutAKnownLimit(): void
    {
        $health = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Nginx, [
            'nginx' => WebServerPayloads::nginxStatus(active: 100_000),
            'nginx.config' => WebServerPayloads::nginxConfigWithoutSettings(),
        ])));

        self::assertSame([], $health->findings);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Nginx));
    }

    public function testTheSupportOfApacheAndNginxIsJudged(): void
    {
        $ended = ['cycles' => ['2.4' => ['eol' => true, 'support' => false]]];
        $health = self::checks()->health(Snapshots::stack(
            Snapshots::of(Component::Apache, ['apache' => WebServerPayloads::apache(), 'eol.apache-http-server' => $ended]),
            Snapshots::of(Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus(), 'nginx.config' => WebServerPayloads::nginxConfig(), 'eol.nginx' => WebServerPayloads::cycles()]),
        ));
        $securityOnly = self::checks()->health(Snapshots::stack(
            Snapshots::of(Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus(), 'nginx.config' => ['version' => '1.27.4'] + WebServerPayloads::nginxConfig(), 'eol.nginx' => WebServerPayloads::cycles()]),
        ));
        $supported = self::checks()->health(Snapshots::stack(
            Snapshots::of(Component::Apache, ['apache' => WebServerPayloads::apache(), 'eol.apache-http-server' => WebServerPayloads::cycles()]),
            Snapshots::of(Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus(), 'nginx.config' => ['version' => '1.28.0'] + WebServerPayloads::nginxConfig(), 'eol.nginx' => WebServerPayloads::cycles()]),
        ));

        self::assertSame(Severity::Critical, self::find($health, 'apache.end_of_life')?->severity);
        self::assertSame('Apache 2.4.62 has reached its end of life', self::find($health, 'apache.end_of_life')->sentence);
        self::assertSame(Severity::Critical, self::find($health, 'nginx.end_of_life')?->severity);
        self::assertSame('Nginx 1.26.2 has reached its end of life', self::find($health, 'nginx.end_of_life')->sentence);
        self::assertSame(Severity::Warning, self::find($securityOnly, 'nginx.security_only')?->severity);
        self::assertSame('Nginx 1.27.4 only receives security fixes', self::find($securityOnly, 'nginx.security_only')->sentence);
        self::assertSame([], $supported->findings);
    }

    public function testAnIgnoredWebServerCheckIsDroppedBeforeTheVerdict(): void
    {
        $state = Snapshots::stack(Snapshots::of(Component::Apache, ['apache' => self::apacheSlots(1_000)]));

        self::assertSame(Verdict::Critical, self::checks()->health($state)->verdict(Component::Apache));

        $health = self::checks(['apache.workers'])->health($state);

        self::assertSame([], $health->findings);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Apache));
    }

    public function testAValueThatCouldNotBeReadAgainRaisesItsFailure(): void
    {
        $health = self::checks()->health(Snapshots::stack(
            new ComponentSnapshot(Component::Php, ['php' => new CollectorResult('php', self::php(), ResultState::Stale, 'The PHP endpoint answered 502.', Snapshots::NOW - 120, expiresAt: Snapshots::NOW + 300)]),
            new ComponentSnapshot(Component::Nginx, ['nginx' => new CollectorResult('nginx', WebServerPayloads::nginxStatus(), ResultState::Stale, 'Nothing answers.', Snapshots::NOW - 90, expiresAt: Snapshots::NOW + 30)]),
        ));

        $php = self::find($health, 'php.failed');
        self::assertSame(Severity::Warning, $php?->severity);
        self::assertSame('PHP could not be read again: The PHP endpoint answered 502.', $php->sentence);
        self::assertSame(120, $php->age);
        self::assertSame(Verdict::Warning, $health->verdict(Component::Php), 'the value is still shown');

        $nginx = self::find($health, 'nginx.failed');
        self::assertSame(Severity::Info, $nginx?->severity);
        self::assertStringContainsString('could not be read again: Nothing answers.', $nginx->sentence);
        self::assertSame(90, $nginx->age);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Nginx));
    }

    public function testAnOutdatedValueStillBeingRenewedRaisesNothing(): void
    {
        $health = self::checks()->health(Snapshots::stack(
            Snapshots::of(Component::Php, ['php' => self::php()], ['php' => ResultState::Stale]),
            Snapshots::of(Component::Nginx, ['nginx' => WebServerPayloads::nginxStatus()], ['nginx' => ResultState::Stale]),
        ));

        self::assertSame([], $health->findings);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Php));
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Nginx));
    }

    /**
     * @param list<string> $ignored
     */
    private static function checks(array $ignored = [], bool $debug = false): StackChecks
    {
        return new StackChecks($ignored, $debug, new MockClock(new \DateTimeImmutable('@' . Snapshots::NOW)));
    }

    private static function find(StackHealth $health, string $id): ?Finding
    {
        foreach ($health->findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function memory(int $usedPerThousand): array
    {
        return ['ram' => ['total' => 1_000, 'available' => 1_000 - $usedPerThousand]] + Snapshots::system();
    }

    /**
     * @return array<string, mixed>
     */
    private static function disk(int $usedPerThousand): array
    {
        return ['disk' => ['total' => 1_000, 'free' => 1_000 - $usedPerThousand]] + Snapshots::system();
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(float $percent): array
    {
        $data = Snapshots::system();
        $data['cpu']['load'] = $percent;

        return $data;
    }

    /**
     * A healthy PHP under FPM, with the given values replaced.
     *
     * @param array<string, mixed> $replaced
     * @param array<string, mixed> $base
     *
     * @return array<string, mixed>
     */
    private static function php(array $replaced = [], ?array $base = null): array
    {
        $base ??= Snapshots::php();
        $base['fpm']['max-children-reached'] = 0;

        return array_replace_recursive($base, $replaced);
    }

    /**
     * @param array<string, mixed>  $fpm
     * @param ?array<string, mixed> $base
     *
     * @return array<string, mixed>
     */
    private static function fpm(array $fpm, ?array $base = null): array
    {
        return array_replace_recursive(self::php([], $base), ['fpm' => $fpm]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function opcacheMemory(int $usedPerThousand, bool $full = false, int $wasted = 0): array
    {
        return self::php(['opcache' => ['status' => [
            'cache_full' => $full,
            'memory_usage' => ['used_memory' => $usedPerThousand, 'free_memory' => 1_000 - $usedPerThousand - $wasted, 'wasted_memory' => $wasted],
        ]]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function hitRate(int $hits, int $misses): array
    {
        return self::php(['opcache' => ['status' => ['opcache_statistics' => ['hits' => $hits, 'misses' => $misses]]]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function apcuMemory(int $usedPerThousand, bool $enabled = true): array
    {
        return self::php(['apcu' => [
            'config' => ['apc.enabled' => $enabled],
            'sma_info' => ['num_seg' => 1, 'seg_size' => 1_000, 'avail_mem' => 1_000 - $usedPerThousand],
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function apacheWorkers(?int $busy, ?int $idle): array
    {
        return ['workers' => array_filter(['busy' => $busy, 'idle' => $idle], static fn(?int $count): bool => $count !== null)] + WebServerPayloads::apache();
    }

    /**
     * @return array<string, mixed>
     */
    private static function apacheSlots(int $usedPerThousand): array
    {
        return ['scoreboard' => ['_' => 1_000 - $usedPerThousand, 'W' => $usedPerThousand]] + WebServerPayloads::apache();
    }

    /**
     * @return array<string, array<string, mixed>> the status page and the settings of an Nginx that holds 1000 connections
     */
    private static function nginxConnections(int $active): array
    {
        $config = WebServerPayloads::nginxConfig();
        $config['config']['worker_processes'] = '1';
        $config['config']['worker_connections'] = 1_000;

        return ['nginx' => WebServerPayloads::nginxStatus(active: $active), 'nginx.config' => $config];
    }

    /**
     * FrankenPHP in worker mode with nothing waiting and no crash, the values of its first worker replaced.
     *
     * @param array<string, mixed> $worker
     *
     * @return array<string, mixed>
     */
    private static function frankenphp(array $worker = [], int $queue = 0): array
    {
        $data = WebServerPayloads::frankenphpWorker();
        $data['queue_depth'] = $queue;
        $data['workers'][0] = array_replace($data['workers'][0], ['worker_crashes' => 0], $worker);
        $data['workers'][1]['worker_queue_depth'] = 0;

        return $data;
    }
}
