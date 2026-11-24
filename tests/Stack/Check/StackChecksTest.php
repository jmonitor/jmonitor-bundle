<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Check;

use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Check\Severity;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Check\StackHealth;
use Jmonitor\JmonitorBundle\Stack\Check\Verdict;
use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\RedisPayloads;
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
        self::assertStringStartsWith('The FrankenPHP version could not be read: ', (string) self::find($health, 'frankenphp.version.failed')?->sentence);
        self::assertStringStartsWith('The Nginx settings could not be read: ', (string) self::find($health, 'nginx.config.failed')?->sentence);
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
        $apacheSecurityOnly = self::checks()->health(Snapshots::stack(
            Snapshots::of(Component::Apache, ['apache' => WebServerPayloads::apache(), 'eol.apache-http-server' => ['cycles' => ['2.4' => ['eol' => false, 'support' => false]]]]),
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
        self::assertSame(Severity::Warning, self::find($apacheSecurityOnly, 'apache.security_only')?->severity);
        self::assertSame('Apache 2.4.62 only receives security fixes', self::find($apacheSecurityOnly, 'apache.security_only')->sentence);
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
     * @return iterable<string, array{array<string, array<string, mixed>>, array<string, Delta>, string, ?Severity}>
     */
    public static function mysqlThresholds(): iterable
    {
        yield 'connections 79.9 %' => [self::mysqlConnections(799), [], 'mysql.connections', null];
        yield 'connections 80 %' => [self::mysqlConnections(800), [], 'mysql.connections', Severity::Warning];
        yield 'connections 94.9 %' => [self::mysqlConnections(949), [], 'mysql.connections', Severity::Warning];
        yield 'connections 95 %' => [self::mysqlConnections(950), [], 'mysql.connections', Severity::Critical];

        yield 'hit rate 95 % over 1000 requests' => [...self::bufferPool(1_000, 50), 'mysql.buffer_pool_hit_rate', null];
        yield 'hit rate 94.9 % over 1000 requests' => [...self::bufferPool(1_000, 51), 'mysql.buffer_pool_hit_rate', Severity::Warning];
        yield 'hit rate 94.9 % over 999 requests, server up for 59 min' => [...self::bufferPool(999, 51, 3_540), 'mysql.buffer_pool_hit_rate', null];
        yield 'hit rate 94.9 % over 999 requests, server up for 60 min' => [...self::bufferPool(999, 51, 3_600), 'mysql.buffer_pool_hit_rate', Severity::Warning];
        yield 'hit rate 94.9 % without second sample, server up for 59 min' => [...self::bufferPool(999, 51, 3_540, false), 'mysql.buffer_pool_hit_rate', null];
        yield 'hit rate 94.9 % without second sample, server up for 60 min' => [...self::bufferPool(999, 51, 3_600, false), 'mysql.buffer_pool_hit_rate', Severity::Warning];
        yield 'hit rate 40 % over 500 requests, 99.6 % since the server started' => [...self::mysqlWindow(['Innodb_buffer_pool_read_requests' => 100_000, 'Innodb_buffer_pool_reads' => 100], ['Innodb_buffer_pool_read_requests' => 100_500, 'Innodb_buffer_pool_reads' => 400]), 'mysql.buffer_pool_hit_rate', null];
        yield 'hit rate 100 % over 500 requests, 80.9 % since the server started' => [...self::mysqlWindow(['Innodb_buffer_pool_read_requests' => 10_000, 'Innodb_buffer_pool_reads' => 2_000], ['Innodb_buffer_pool_read_requests' => 10_500, 'Innodb_buffer_pool_reads' => 2_000]), 'mysql.buffer_pool_hit_rate', Severity::Warning];

        yield 'temporary tables 24.9 % on disk' => [...self::temporaryTables(1_000, 249), 'mysql.disk_temporary_tables', null];
        yield 'temporary tables 25 % on disk of 100' => [...self::temporaryTables(100, 25), 'mysql.disk_temporary_tables', Severity::Warning];
        yield 'temporary tables 25 % on disk of 99' => [...self::temporaryTables(99, 25), 'mysql.disk_temporary_tables', null];
        yield 'temporary tables 25 % on disk without second sample, server up for 59 min' => [...self::temporaryTables(100, 25, 3_540, false), 'mysql.disk_temporary_tables', null];
        yield 'temporary tables 25 % on disk without second sample, server up for 60 min' => [...self::temporaryTables(100, 25, 3_600, false), 'mysql.disk_temporary_tables', Severity::Warning];
        yield 'temporary tables 80 % on disk of 50, 13.3 % since the server started' => [...self::mysqlWindow(['Created_tmp_tables' => 1_000, 'Created_tmp_disk_tables' => 100], ['Created_tmp_tables' => 1_050, 'Created_tmp_disk_tables' => 140]), 'mysql.disk_temporary_tables', null];
        yield 'temporary tables none on disk of 50, 47.6 % since the server started' => [...self::mysqlWindow(['Created_tmp_tables' => 1_000, 'Created_tmp_disk_tables' => 500], ['Created_tmp_tables' => 1_050, 'Created_tmp_disk_tables' => 500]), 'mysql.disk_temporary_tables', Severity::Warning];

        yield 'table locks 4.9 % waited' => [...self::tableLocks(1_000, 49), 'mysql.table_lock_waits', null];
        yield 'table locks 5 % waited of 100' => [...self::tableLocks(100, 5), 'mysql.table_lock_waits', Severity::Warning];
        yield 'table locks 5 % waited of 99' => [...self::tableLocks(99, 5), 'mysql.table_lock_waits', null];
        yield 'table locks 50 % waited since the server started' => [...self::tableLocks(100, 50, 86_400, false), 'mysql.table_lock_waits', null];
    }

    /**
     * @param array<string, array<string, mixed>> $data
     * @param array<string, Delta>                $deltas
     */
    #[DataProvider('mysqlThresholds')]
    public function testEachMysqlCheckRisesAtItsThresholdAndNotJustBelow(array $data, array $deltas, string $id, ?Severity $expected): void
    {
        $this->testEachCheckRisesAtItsThresholdAndNotJustBelow(Component::Mysql, $data, $deltas, $id, $expected);
    }

    public function testAMysqlSentenceSaysWhatItCovers(): void
    {
        $connections = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Mysql, self::mysqlConnections(905))));
        [$data, $deltas] = self::bufferPool(1_000, 51);
        $recent = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Mysql, $data, deltas: $deltas)));
        [$data] = self::bufferPool(1_000, 51, 3_600, false);
        $sinceStart = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Mysql, $data)));
        [$data, $deltas] = self::mysqlWindow(
            ['Innodb_buffer_pool_read_requests' => 10_000, 'Innodb_buffer_pool_reads' => 2_000, 'Created_tmp_tables' => 1_000, 'Created_tmp_disk_tables' => 500],
            ['Innodb_buffer_pool_read_requests' => 10_500, 'Innodb_buffer_pool_reads' => 2_000, 'Created_tmp_tables' => 1_050, 'Created_tmp_disk_tables' => 500],
        );
        $smallWindow = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Mysql, $data, deltas: $deltas)));
        [$data, $deltas] = self::temporaryTables(200, 70);
        $temporary = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Mysql, $data, deltas: $deltas)));
        [$data, $deltas] = self::tableLocks(300, 20);
        $locks = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Mysql, $data, deltas: $deltas)));

        self::assertSame("MySQL holds 90.5\u{00A0}% of its connections (905 of 1000)", self::find($connections, 'mysql.connections')?->sentence);
        self::assertStringContainsString('max_connections', self::find($connections, 'mysql.connections')->explanation);
        self::assertSame("The buffer pool hit rate is 94.9\u{00A0}% over the last 60\u{00A0}s", self::find($recent, 'mysql.buffer_pool_hit_rate')?->sentence);
        self::assertSame("The buffer pool hit rate is 94.9\u{00A0}% since the server started", self::find($sinceStart, 'mysql.buffer_pool_hit_rate')?->sentence);
        self::assertStringContainsString('innodb_buffer_pool_size', self::find($recent, 'mysql.buffer_pool_hit_rate')->explanation);
        self::assertSame("The buffer pool hit rate is 80.9\u{00A0}% since the server started", self::find($smallWindow, 'mysql.buffer_pool_hit_rate')?->sentence, 'the window holds too few requests');
        self::assertSame("47.6\u{00A0}% of the temporary tables were written to disk since the server started", self::find($smallWindow, 'mysql.disk_temporary_tables')?->sentence, 'the window holds too few tables');
        self::assertSame("35\u{00A0}% of the temporary tables were written to disk over the last 60\u{00A0}s", self::find($temporary, 'mysql.disk_temporary_tables')?->sentence);
        self::assertStringContainsString('tmp_table_size', self::find($temporary, 'mysql.disk_temporary_tables')->explanation);
        self::assertSame("6.6\u{00A0}% of the table locks had to wait over the last 60\u{00A0}s", self::find($locks, 'mysql.table_lock_waits')?->sentence);
        self::assertStringContainsString('InnoDB', self::find($locks, 'mysql.table_lock_waits')->explanation);
    }

    public function testAHealthyDatabaseRaisesNothing(): void
    {
        $health = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Mysql, [
            'mysql' => DatabasePayloads::mysqlStatus(),
            'mysql.variables' => DatabasePayloads::mysqlVariables(),
            'mysql.sizes' => DatabasePayloads::mysqlSizes(),
            'mysql.slow_queries' => DatabasePayloads::mysqlSlowQueries(),
            'eol.mysql' => Snapshots::cycles(),
        ])));

        self::assertSame([], $health->findings);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Mysql));
    }

    public function testAnUnreachableDatabaseIsCritical(): void
    {
        $health = self::checks()->health(Snapshots::stack(new ComponentSnapshot(Component::Mysql, [
            'mysql' => new CollectorResult('mysql', [], ResultState::Failed, 'Nothing answers on 127.0.0.1:3306.', expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
        ])));

        $finding = self::find($health, 'mysql.failed');
        self::assertSame(Severity::Critical, $finding?->severity);
        self::assertSame('MySQL cannot be reached: Nothing answers on 127.0.0.1:3306.', $finding->sentence);
        self::assertSame('The application reaches this server through the same connection: when it cannot be reached from here, the application cannot use it either. Fix the cause given, then refresh.', $finding->explanation);
        self::assertNull($finding->age);
        self::assertSame(Verdict::Critical, $health->verdict(Component::Mysql));
        self::assertSame(Verdict::Critical, $health->componentVerdict(Component::Mysql));
    }

    public function testADatabaseThatAnsweredAnErrorIsAWarning(): void
    {
        $health = self::checks()->health(Snapshots::stack(new ComponentSnapshot(Component::Mysql, [
            'mysql' => new CollectorResult('mysql', [], ResultState::Failed, 'Unknown database "app".', expiresAt: Snapshots::NOW + 60, failure: FailureKind::Other),
        ])));

        $finding = self::find($health, 'mysql.failed');
        self::assertSame(Severity::Warning, $finding?->severity);
        self::assertSame('MySQL could not be read: Unknown database "app".', $finding->sentence);
        self::assertSame(Verdict::Unknown, $health->verdict(Component::Mysql));
    }

    public function testAnOutdatedDatabaseThatCannotBeReachedAgainIsCritical(): void
    {
        $health = self::checks()->health(Snapshots::stack(new ComponentSnapshot(Component::Mysql, [
            'mysql' => new CollectorResult('mysql', ['Threads_connected' => '85'] + DatabasePayloads::mysqlStatus(), ResultState::Stale, 'Nothing answers.', Snapshots::NOW - 120, expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
            'mysql.variables' => new CollectorResult('mysql.variables', DatabasePayloads::mysqlVariables(), ResultState::Fresh, collectedAt: Snapshots::NOW - 600, expiresAt: Snapshots::NOW + 3_000),
        ])));

        $failed = self::find($health, 'mysql.failed');
        self::assertSame(Severity::Critical, $failed?->severity);
        self::assertSame('MySQL cannot be reached: Nothing answers.', $failed->sentence);
        self::assertSame(120, $failed->age);

        $connections = self::find($health, 'mysql.connections');
        self::assertSame(Severity::Warning, $connections?->severity, 'the value shown is still judged');
        self::assertSame(120, $connections->age);
        self::assertSame(Verdict::Critical, $health->verdict(Component::Mysql));
    }

    public function testAWebServerWhoseStatusPageIsUnreachableStaysInformative(): void
    {
        $health = self::checks()->health(Snapshots::stack(
            new ComponentSnapshot(Component::Apache, ['apache' => new CollectorResult('apache', [], ResultState::Failed, 'Nothing answers.', expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable)]),
            new ComponentSnapshot(Component::Nginx, ['nginx' => new CollectorResult('nginx', WebServerPayloads::nginxStatus(), ResultState::Stale, 'Nothing answers.', Snapshots::NOW - 90, expiresAt: Snapshots::NOW + 30, failure: FailureKind::Unreachable)]),
        ));

        self::assertSame(Severity::Info, self::find($health, 'apache.failed')?->severity);
        self::assertStringContainsString('could not be read: Nothing answers.', self::find($health, 'apache.failed')->sentence);
        self::assertSame(Verdict::Unknown, $health->verdict(Component::Apache));
        self::assertSame(Severity::Info, self::find($health, 'nginx.failed')?->severity);
        self::assertStringContainsString('could not be read again: Nothing answers.', self::find($health, 'nginx.failed')->sentence);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Nginx));
    }

    public function testAConnectionThatCannotBeReadIsAWarning(): void
    {
        $error = 'The connection "e" cannot be read: Environment variable not found: "LEGACY_DATABASE_URL".';
        $health = self::checks()->health(Snapshots::state([
            'mysql' => [new InstanceState('e', null, $error, null)],
        ]));

        $finding = self::find($health, 'mysql.failed');
        self::assertSame(Severity::Warning, $finding?->severity);
        self::assertSame('MySQL is not available: ' . $error, $finding->sentence);
        self::assertSame('e', $finding->instance);
        self::assertCount(1, $health->findings);
        self::assertSame(Verdict::Unknown, $health->verdict(Component::Mysql, 'e'));
    }

    public function testTheDeniedSlowQueriesAreOnlyInformative(): void
    {
        $denied = 'The database user of this connection may not read performance_schema.events_statements_summary_by_digest.';
        $health = self::checks()->health(Snapshots::stack(new ComponentSnapshot(Component::Mysql, [
            'mysql' => new CollectorResult('mysql', DatabasePayloads::mysqlStatus(), ResultState::Fresh, collectedAt: Snapshots::NOW, expiresAt: Snapshots::NOW + 10),
            'mysql.variables' => new CollectorResult('mysql.variables', DatabasePayloads::mysqlVariables(), ResultState::Fresh, collectedAt: Snapshots::NOW, expiresAt: Snapshots::NOW + 3_600),
            'mysql.slow_queries' => new CollectorResult('mysql.slow_queries', [], ResultState::Failed, $denied, expiresAt: Snapshots::NOW + 300, failure: FailureKind::MissingPrerequisite),
        ])));
        $otherFailures = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Mysql, ['mysql' => DatabasePayloads::mysqlStatus()], ['mysql.variables' => ResultState::Failed, 'mysql.sizes' => ResultState::Failed])));

        $finding = self::find($health, 'mysql.slow_queries.failed');
        self::assertSame(Severity::Info, $finding?->severity);
        self::assertSame('The slowest queries could not be read: ' . $denied, $finding->sentence);
        self::assertCount(1, $health->findings);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Mysql));

        self::assertSame(Severity::Info, self::find($otherFailures, 'mysql.variables.failed')?->severity);
        self::assertStringStartsWith('The MySQL settings could not be read: ', self::find($otherFailures, 'mysql.variables.failed')->sentence);
        self::assertSame(Severity::Info, self::find($otherFailures, 'mysql.sizes.failed')?->severity);
        self::assertStringStartsWith('The size of the database could not be read: ', self::find($otherFailures, 'mysql.sizes.failed')->sentence);
        self::assertSame(Verdict::Healthy, $otherFailures->verdict(Component::Mysql));
    }

    public function testAnUnreachableServerSaysItOnce(): void
    {
        $unreachable = static fn(string $name, array $data = [], ResultState $state = ResultState::Failed): CollectorResult => new CollectorResult($name, $data, $state, 'Nothing answers.', $state === ResultState::Stale ? Snapshots::NOW - 120 : null, expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable);
        $denied = 'The database user of this connection may not read performance_schema.events_statements_summary_by_digest.';
        $dead = self::checks()->health(Snapshots::stack(new ComponentSnapshot(Component::Mysql, [
            'mysql' => $unreachable('mysql'),
            'mysql.variables' => $unreachable('mysql.variables'),
            'mysql.sizes' => $unreachable('mysql.sizes'),
            'mysql.slow_queries' => $unreachable('mysql.slow_queries'),
        ])));
        $outdated = self::checks()->health(Snapshots::stack(new ComponentSnapshot(Component::Mysql, [
            'mysql' => $unreachable('mysql', DatabasePayloads::mysqlStatus(), ResultState::Stale),
            'mysql.sizes' => $unreachable('mysql.sizes'),
            'mysql.slow_queries' => new CollectorResult('mysql.slow_queries', [], ResultState::Failed, $denied, expiresAt: Snapshots::NOW + 3_600, failure: FailureKind::MissingPrerequisite),
        ])));

        self::assertSame(['mysql.failed'], array_map(static fn(Finding $finding): string => $finding->id, $dead->findings));
        self::assertSame(Severity::Critical, self::find($dead, 'mysql.failed')?->severity);
        self::assertSame(Verdict::Critical, $dead->verdict(Component::Mysql));

        self::assertSame(Severity::Critical, self::find($outdated, 'mysql.failed')?->severity);
        self::assertNull(self::find($outdated, 'mysql.sizes.failed'));
        self::assertSame(Severity::Info, self::find($outdated, 'mysql.slow_queries.failed')?->severity, 'a refusal is not said by the main collector');
    }

    public function testASlowDetailReadDoesNotSayTheServerIsDown(): void
    {
        $health = self::checks()->health(Snapshots::stack(new ComponentSnapshot(Component::Mysql, [
            'mysql' => new CollectorResult('mysql', DatabasePayloads::mysqlStatus(), ResultState::Fresh, collectedAt: Snapshots::NOW, expiresAt: Snapshots::NOW + 10),
            'mysql.variables' => new CollectorResult('mysql.variables', DatabasePayloads::mysqlVariables(), ResultState::Fresh, collectedAt: Snapshots::NOW, expiresAt: Snapshots::NOW + 3_600),
            'mysql.sizes' => new CollectorResult('mysql.sizes', [], ResultState::Failed, 'Read timed out.', expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
        ])));

        self::assertNull(self::find($health, 'mysql.failed'));
        self::assertSame(Severity::Info, self::find($health, 'mysql.sizes.failed')?->severity);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Mysql));
    }

    public function testTheSupportOfMysqlAndMariadbIsJudged(): void
    {
        $mysql = static fn(string $version, array $eol): StackHealth => self::checks()->health(Snapshots::stack(Snapshots::of(Component::Mysql, [
            'mysql' => DatabasePayloads::mysqlStatus(),
            'mysql.variables' => ['version' => $version] + DatabasePayloads::mysqlVariables(),
        ] + $eol)));
        $mysqlCycles = ['cycles' => ['8.4' => ['eol' => '2032-04-30', 'support' => '2029-04-30'], '8.0' => ['eol' => '2026-04-30', 'support' => '2023-07-18']]];
        $mariadbCycles = ['cycles' => ['11.4' => ['eol' => '2029-05-29', 'support' => '2029-05-29'], '10.11' => ['eol' => '2028-02-16', 'support' => '2026-02-16'], '10.5' => ['eol' => '2025-06-24', 'support' => '2025-06-24']]];

        $ended = $mysql('8.0.40', ['eol.mysql' => $mysqlCycles]);
        $mariadbEnded = $mysql('10.5.27-MariaDB', ['eol.mariadb' => $mariadbCycles]);
        $securityOnly = $mysql('10.11.10-MariaDB-log', ['eol.mariadb' => $mariadbCycles]);
        $supported = $mysql('8.4.3', ['eol.mysql' => $mysqlCycles]);
        $mariadbSupported = $mysql('11.4.10-MariaDB-ubu2404', ['eol.mariadb' => $mariadbCycles, 'eol.mysql' => ['cycles' => ['11.4' => ['eol' => true, 'support' => false]]]]);

        self::assertSame(Severity::Critical, self::find($ended, 'mysql.end_of_life')?->severity);
        self::assertSame('MySQL 8.0.40 has reached its end of life', self::find($ended, 'mysql.end_of_life')->sentence);
        self::assertSame(Severity::Critical, self::find($mariadbEnded, 'mysql.end_of_life')?->severity);
        self::assertSame('MariaDB 10.5.27 has reached its end of life', self::find($mariadbEnded, 'mysql.end_of_life')->sentence);
        self::assertSame(Severity::Warning, self::find($securityOnly, 'mysql.security_only')?->severity);
        self::assertSame('MariaDB 10.11.10 only receives security fixes', self::find($securityOnly, 'mysql.security_only')->sentence);
        self::assertSame([], $supported->findings);
        self::assertSame([], $mariadbSupported->findings, 'the cycles of MySQL say nothing of MariaDB');
    }

    public function testAnIgnoredMysqlCheckIsDroppedBeforeTheVerdict(): void
    {
        $full = Snapshots::stack(Snapshots::of(Component::Mysql, self::mysqlConnections(960)));
        $unreachable = Snapshots::stack(new ComponentSnapshot(Component::Mysql, [
            'mysql' => new CollectorResult('mysql', [], ResultState::Failed, 'Nothing answers.', expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
        ]));

        self::assertSame(Verdict::Critical, self::checks()->health($full)->verdict(Component::Mysql));

        $health = self::checks(['mysql.connections'])->health($full);
        self::assertSame([], $health->findings);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Mysql));

        $health = self::checks(['mysql.failed'])->health($unreachable);
        self::assertSame([], $health->findings);
        self::assertSame(Verdict::Unknown, $health->verdict(Component::Mysql), 'nothing to judge without a value');
    }

    public function testTwoConnectionsHaveTheirOwnFindings(): void
    {
        $health = self::checks()->health(Snapshots::state(['mysql' => [
            new InstanceState('default', '127.0.0.1:3306/app', null, Snapshots::of(Component::Mysql, self::mysqlConnections(960))),
            new InstanceState('legacy', 'db.internal:3306/legacy', null, new ComponentSnapshot(Component::Mysql, [
                'mysql' => new CollectorResult('mysql', [], ResultState::Failed, 'Nothing answers.', expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
            ])),
            new InstanceState('reports', '127.0.0.1:3306/reports', null, Snapshots::of(Component::Mysql, self::mysqlConnections(100))),
        ]]));

        self::assertSame('default', self::find($health, 'mysql.connections')?->instance);
        self::assertSame('legacy', self::find($health, 'mysql.failed')?->instance);
        self::assertCount(2, $health->findings);
        self::assertSame(Verdict::Critical, $health->verdict(Component::Mysql, 'default'));
        self::assertSame(Verdict::Critical, $health->verdict(Component::Mysql, 'legacy'));
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Mysql, 'reports'));
    }

    /**
     * @return iterable<string, array{array<string, array<string, mixed>>, array<string, Delta>, string, ?Severity}>
     */
    public static function postgresqlThresholds(): iterable
    {
        yield 'connections 79.9 %' => [self::postgresqlConnections(799), [], 'postgresql.connections', null];
        yield 'connections 80 %' => [self::postgresqlConnections(800), [], 'postgresql.connections', Severity::Warning];
        yield 'connections 94.9 %' => [self::postgresqlConnections(949), [], 'postgresql.connections', Severity::Warning];
        yield 'connections 95 %' => [self::postgresqlConnections(950), [], 'postgresql.connections', Severity::Critical];

        yield 'cache hit rate 95 % over 10 000 blocks' => [...self::postgresqlWindow(['blks_hit' => 0, 'blks_read' => 0], ['blks_hit' => 9_500, 'blks_read' => 500]), 'postgresql.cache_hit_rate', null];
        yield 'cache hit rate 94.99 % over 10 000 blocks' => [...self::postgresqlWindow(['blks_hit' => 0, 'blks_read' => 0], ['blks_hit' => 9_499, 'blks_read' => 501]), 'postgresql.cache_hit_rate', Severity::Warning];
        yield 'cache hit rate 94.9 % over 9 999 blocks, the same since the reset' => [...self::postgresqlWindow(['blks_hit' => 0, 'blks_read' => 0], ['blks_hit' => 9_489, 'blks_read' => 510]), 'postgresql.cache_hit_rate', null];
        yield 'cache hit rate 94.9 % over 9 999 blocks, 99 999 since the reset' => [...self::postgresqlWindow(['blks_hit' => 85_410, 'blks_read' => 4_590], ['blks_hit' => 94_899, 'blks_read' => 5_100]), 'postgresql.cache_hit_rate', null];
        yield 'cache hit rate 94.9 % over 9 999 blocks, 100 000 since the reset' => [...self::postgresqlWindow(['blks_hit' => 85_411, 'blks_read' => 4_590], ['blks_hit' => 94_900, 'blks_read' => 5_100]), 'postgresql.cache_hit_rate', Severity::Warning];
        yield 'cache hit rate 94.9 % without second sample, 99 999 blocks' => [self::postgresqlActivity(['database_stats' => ['blks_hit' => 94_899, 'blks_read' => 5_100]]), [], 'postgresql.cache_hit_rate', null];
        yield 'cache hit rate 94.9 % without second sample, 100 000 blocks' => [self::postgresqlActivity(['database_stats' => ['blks_hit' => 94_900, 'blks_read' => 5_100]]), [], 'postgresql.cache_hit_rate', Severity::Warning];
        yield 'cache hit rate 40 % over 500 blocks, 99 % since the reset' => [...self::postgresqlWindow(['blks_hit' => 395_800, 'blks_read' => 3_700], ['blks_hit' => 396_000, 'blks_read' => 4_000]), 'postgresql.cache_hit_rate', null];

        yield 'rollbacks 9.9 % of 1000' => [...self::postgresqlWindow(['xact_commit' => 0, 'xact_rollback' => 0], ['xact_commit' => 901, 'xact_rollback' => 99]), 'postgresql.rollbacks', null];
        yield 'rollbacks 10 % of 100' => [...self::postgresqlWindow(['xact_commit' => 0, 'xact_rollback' => 0], ['xact_commit' => 90, 'xact_rollback' => 10]), 'postgresql.rollbacks', Severity::Warning];
        yield 'rollbacks 10 of 99' => [...self::postgresqlWindow(['xact_commit' => 0, 'xact_rollback' => 0], ['xact_commit' => 89, 'xact_rollback' => 10]), 'postgresql.rollbacks', null];
        yield 'rollbacks 50 % since the reset' => [self::postgresqlActivity(['database_stats' => ['xact_commit' => 100_000, 'xact_rollback' => 100_000]]), [], 'postgresql.rollbacks', null];

        yield 'dead rows 19.9 % of the schema, 19 900 dead' => [self::postgresqlActivity(database: ['dead_tuples' => 19_900, 'live_tuples' => 80_100]), [], 'postgresql.dead_rows', null];
        yield 'dead rows 20 % of the schema, 10 000 dead' => [self::postgresqlActivity(database: ['dead_tuples' => 10_000, 'live_tuples' => 40_000]), [], 'postgresql.dead_rows', Severity::Warning];
        yield 'dead rows 20 % of the schema, 9 999 dead' => [self::postgresqlActivity(database: ['dead_tuples' => 9_999, 'live_tuples' => 39_996]), [], 'postgresql.dead_rows', null];

        yield 'idle in a transaction for 299 s' => [self::postgresqlActivity(['sessions' => ['idle_in_transaction_count' => 1, 'oldest_idle_in_transaction_seconds' => 299]]), [], 'postgresql.idle_in_transaction', null];
        yield 'idle in a transaction for 300 s' => [self::postgresqlActivity(['sessions' => ['idle_in_transaction_count' => 1, 'oldest_idle_in_transaction_seconds' => 300]]), [], 'postgresql.idle_in_transaction', Severity::Warning];

        yield 'blocked for 29 s' => [self::postgresqlActivity(['sessions' => self::blocked(29)]), [], 'postgresql.blocked_sessions', null];
        yield 'blocked for 30 s' => [self::postgresqlActivity(['sessions' => self::blocked(30)]), [], 'postgresql.blocked_sessions', Severity::Warning];

        yield 'autovacuum on' => [self::postgresqlActivity(settings: ['autovacuum' => 'on', 'track_counts' => 'on']), [], 'postgresql.autovacuum_off', null];
        yield 'autovacuum off' => [self::postgresqlActivity(settings: ['autovacuum' => 'off']), [], 'postgresql.autovacuum_off', Severity::Critical];
        yield 'track_counts off' => [self::postgresqlActivity(settings: ['track_counts' => 'off']), [], 'postgresql.autovacuum_off', Severity::Critical];

        yield 'every session of other roles read' => [self::postgresqlActivity(['hidden_sessions' => 0]), [], 'postgresql.limited_role', null];
        yield 'one session of another role hidden' => [self::postgresqlActivity(['connections' => ['unknown' => 1], 'hidden_sessions' => 1]), [], 'postgresql.limited_role', Severity::Info];

        yield 'PostgreSQL supported, not ended' => [self::postgresqlActivity(settings: ['server_version' => '17.2']), [], 'postgresql.end_of_life', null];
        yield 'PostgreSQL ended' => [self::postgresqlActivity(settings: ['server_version' => '13.18']), [], 'postgresql.end_of_life', Severity::Critical];
        yield 'PostgreSQL supported' => [self::postgresqlActivity(settings: ['server_version' => '17.2']), [], 'postgresql.security_only', null];
        yield 'PostgreSQL security fixes only' => [self::postgresqlActivity(settings: ['server_version' => '14.15']), [], 'postgresql.security_only', Severity::Warning];
    }

    /**
     * @param array<string, array<string, mixed>> $data
     * @param array<string, Delta>                $deltas
     */
    #[DataProvider('postgresqlThresholds')]
    public function testEachPostgresqlCheckRisesAtItsThresholdAndNotJustBelow(array $data, array $deltas, string $id, ?Severity $expected): void
    {
        $this->testEachCheckRisesAtItsThresholdAndNotJustBelow(Component::Postgresql, $data, $deltas, $id, $expected);
    }

    public function testAPostgresqlSentenceSaysWhatItCovers(): void
    {
        $health = static fn(array $data, array $deltas = []): StackHealth => self::checks()->health(Snapshots::stack(Snapshots::of(Component::Postgresql, $data, deltas: $deltas)));
        $connections = $health(self::postgresqlConnections(905));
        $recent = $health(...self::postgresqlWindow(['blks_hit' => 0, 'blks_read' => 0, 'xact_commit' => 0, 'xact_rollback' => 0], ['blks_hit' => 18_000, 'blks_read' => 2_000, 'xact_commit' => 170, 'xact_rollback' => 30]));
        $sinceReset = $health(self::postgresqlActivity(['database_stats' => ['blks_hit' => 180_000, 'blks_read' => 20_000]]));
        $deadRows = $health(self::postgresqlActivity(database: ['dead_tuples' => 30_000, 'live_tuples' => 70_000]));
        $idle = $health(self::postgresqlActivity(['sessions' => ['idle_in_transaction_count' => 1, 'oldest_idle_in_transaction_seconds' => 342]]));
        $idleMany = $health(self::postgresqlActivity(['sessions' => ['idle_in_transaction_count' => 3, 'oldest_idle_in_transaction_seconds' => 342]]));
        $blocked = $health(self::postgresqlActivity(['sessions' => self::blocked(45)]));
        $blockedMany = $health(self::postgresqlActivity(['sessions' => ['blocked_count' => 2, 'blocked_queries' => [
            ['blocked_pid' => 4242, 'wait_seconds' => 45, 'blocking_pid' => 4100, 'blocking_state' => 'active'],
            ['blocked_pid' => 4243, 'wait_seconds' => 90, 'blocking_pid' => 4100, 'blocking_state' => 'active'],
        ]]]));
        $waitingForTwo = $health(self::postgresqlActivity(['sessions' => ['blocked_count' => 1, 'blocked_queries' => [
            ['blocked_pid' => 4242, 'wait_seconds' => 45, 'blocking_pid' => 4100, 'blocking_state' => 'active'],
            ['blocked_pid' => 4242, 'wait_seconds' => 45, 'blocking_pid' => 4101, 'blocking_state' => 'active'],
        ]]]));
        $autovacuum = $health(self::postgresqlActivity(settings: ['autovacuum' => 'off']));
        $trackCounts = $health(self::postgresqlActivity(settings: ['track_counts' => 'off']));
        $both = $health(self::postgresqlActivity(settings: ['autovacuum' => 'off', 'track_counts' => 'off']));
        $limited = $health(self::postgresqlActivity(['connections' => ['active' => 1, 'unknown' => 4], 'hidden_sessions' => 4]));
        $limitedOne = $health(self::postgresqlActivity(['connections' => ['active' => 1, 'unknown' => 1], 'hidden_sessions' => 1]));
        $ended = $health(self::postgresqlActivity(settings: ['server_version' => '13.18 (Debian 13.18-1.pgdg120+1)']));
        $securityOnly = $health(self::postgresqlActivity(settings: ['server_version' => '14.15']));

        self::assertSame("The PostgreSQL server holds 90.5\u{00A0}% of its connections (905 of 1,000)", self::find($connections, 'postgresql.connections')?->sentence);
        self::assertStringContainsString('max_connections', self::find($connections, 'postgresql.connections')->explanation);
        self::assertSame("The cache hit rate is 90\u{00A0}% over the last 60\u{00A0}s", self::find($recent, 'postgresql.cache_hit_rate')?->sentence);
        self::assertSame("The cache hit rate is 90\u{00A0}% since the statistics were reset", self::find($sinceReset, 'postgresql.cache_hit_rate')?->sentence);
        self::assertStringContainsString('shared_buffers', self::find($recent, 'postgresql.cache_hit_rate')->explanation);
        self::assertSame("15\u{00A0}% of the transactions were rolled back over the last 60\u{00A0}s", self::find($recent, 'postgresql.rollbacks')?->sentence);
        self::assertSame("30\u{00A0}% of the rows of the schema public are dead (30,000 of 100,000)", self::find($deadRows, 'postgresql.dead_rows')?->sentence);
        self::assertStringContainsString('VACUUM', self::find($deadRows, 'postgresql.dead_rows')->explanation);
        self::assertSame("A session has been idle in a transaction for 5\u{00A0}min\u{00A0}42\u{00A0}s", self::find($idle, 'postgresql.idle_in_transaction')?->sentence);
        self::assertSame("3 sessions are idle in a transaction, the oldest for 5\u{00A0}min\u{00A0}42\u{00A0}s", self::find($idleMany, 'postgresql.idle_in_transaction')?->sentence);
        self::assertStringContainsString('idle_in_transaction_session_timeout', self::find($idle, 'postgresql.idle_in_transaction')->explanation);
        self::assertSame("A session has been waiting for a lock for 45\u{00A0}s", self::find($blocked, 'postgresql.blocked_sessions')?->sentence);
        self::assertSame("2 sessions are waiting for a lock, the longest for 1\u{00A0}min\u{00A0}30\u{00A0}s", self::find($blockedMany, 'postgresql.blocked_sessions')?->sentence);
        self::assertSame(self::find($blocked, 'postgresql.blocked_sessions')?->sentence, self::find($waitingForTwo, 'postgresql.blocked_sessions')?->sentence, 'a session waiting for two others is one session');
        self::assertStringContainsString('pg_stat_activity', self::find($blocked, 'postgresql.blocked_sessions')->explanation);
        self::assertSame('autovacuum is off', self::find($autovacuum, 'postgresql.autovacuum_off')?->sentence);
        self::assertSame('track_counts is off, which stops autovacuum', self::find($trackCounts, 'postgresql.autovacuum_off')?->sentence);
        self::assertSame('autovacuum and track_counts are off', self::find($both, 'postgresql.autovacuum_off')?->sentence);
        self::assertSame('The role of this connection cannot see the state of 4 sessions of other roles', self::find($limited, 'postgresql.limited_role')?->sentence);
        self::assertSame('The role of this connection cannot see the state of 1 session of other roles', self::find($limitedOne, 'postgresql.limited_role')?->sentence);
        self::assertStringContainsString('pg_monitor', self::find($limited, 'postgresql.limited_role')->explanation);
        self::assertSame('PostgreSQL 13.18 has reached its end of life', self::find($ended, 'postgresql.end_of_life')?->sentence);
        self::assertSame('PostgreSQL 14.15 only receives security fixes', self::find($securityOnly, 'postgresql.security_only')?->sentence);
    }

    public function testAHealthyPostgresqlRaisesNothing(): void
    {
        $health = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Postgresql, self::postgresqlActivity())));

        self::assertSame([], $health->findings);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Postgresql));
    }

    public function testAutovacuumOffIsCritical(): void
    {
        foreach (['autovacuum', 'track_counts'] as $setting) {
            $health = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Postgresql, self::postgresqlActivity(settings: [$setting => 'off']))));

            self::assertSame(Severity::Critical, self::find($health, 'postgresql.autovacuum_off')?->severity, $setting);
            self::assertStringContainsString('track_counts', self::find($health, 'postgresql.autovacuum_off')->explanation);
            self::assertSame(Verdict::Critical, $health->verdict(Component::Postgresql));
        }
    }

    public function testALimitedRoleIsToldAndSilencesWhatItCannotSee(): void
    {
        $health = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Postgresql, self::postgresqlActivity([
            'connections' => ['active' => 1, 'unknown' => 4],
            'hidden_sessions' => 4,
            'sessions' => ['idle_in_transaction_count' => 1, 'oldest_idle_in_transaction_seconds' => 3_600] + self::blocked(600),
        ]))));

        self::assertSame(['postgresql.limited_role'], array_map(static fn(Finding $finding): string => $finding->id, $health->findings));
        self::assertSame(Severity::Info, self::find($health, 'postgresql.limited_role')?->severity);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Postgresql));
    }

    public function testAnAutovacuumWorkerNeitherTellsNorSilences(): void
    {
        $health = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Postgresql, self::postgresqlActivity([
            'connections' => ['active' => 1, 'unknown' => 1],
            'hidden_sessions' => 0,
            'sessions' => ['idle_in_transaction_count' => 1, 'oldest_idle_in_transaction_seconds' => 3_600] + self::blocked(600),
        ]))));

        self::assertNull(self::find($health, 'postgresql.limited_role'));
        self::assertSame(Severity::Warning, self::find($health, 'postgresql.idle_in_transaction')?->severity);
        self::assertSame(Severity::Warning, self::find($health, 'postgresql.blocked_sessions')?->severity);
    }

    public function testAnUnknownValueRaisesNothing(): void
    {
        $data = self::postgresqlActivity(['sessions' => ['blocked_count' => 2, 'max_wait_seconds' => 0, 'blocked_queries' => [
            ['blocked_pid' => 4242, 'wait_seconds' => null, 'blocking_pid' => 4100, 'blocking_state' => null],
            ['blocked_pid' => 4243, 'wait_seconds' => null, 'blocking_pid' => 4100, 'blocking_state' => null],
        ]]]);
        $data['postgresql']['server_connections'] = null;
        $data['postgresql']['database_stats']['numbackends'] = 99;
        $data['postgresql.settings']['max_connections'] = '100';

        $health = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Postgresql, $data)));

        self::assertSame([], $health->findings, 'neither the connections of one database nor an unknown wait are judged');
    }

    public function testAnUnreachablePostgresqlIsCritical(): void
    {
        $unreachable = static fn(string $name): CollectorResult => new CollectorResult($name, [], ResultState::Failed, 'Nothing answers on 127.0.0.1:5432.', expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable);
        $health = self::checks()->health(Snapshots::stack(new ComponentSnapshot(Component::Postgresql, [
            'postgresql' => $unreachable('postgresql'),
            'postgresql.settings' => $unreachable('postgresql.settings'),
            'postgresql.database' => $unreachable('postgresql.database'),
            'postgresql.slow_queries' => $unreachable('postgresql.slow_queries'),
        ])));

        self::assertSame(['postgresql.failed'], array_map(static fn(Finding $finding): string => $finding->id, $health->findings), 'said once');
        self::assertSame(Severity::Critical, self::find($health, 'postgresql.failed')?->severity);
        self::assertSame('PostgreSQL cannot be reached: Nothing answers on 127.0.0.1:5432.', self::find($health, 'postgresql.failed')->sentence);
        self::assertSame(Verdict::Critical, $health->verdict(Component::Postgresql));
    }

    public function testAPostgresqlThatAnsweredAnErrorIsAWarning(): void
    {
        $health = self::checks()->health(Snapshots::stack(new ComponentSnapshot(Component::Postgresql, [
            'postgresql' => new CollectorResult('postgresql', [], ResultState::Failed, 'The database "app" does not exist.', expiresAt: Snapshots::NOW + 60, failure: FailureKind::Other),
        ])));

        self::assertSame(Severity::Warning, self::find($health, 'postgresql.failed')?->severity);
        self::assertSame(Verdict::Unknown, $health->verdict(Component::Postgresql));
    }

    public function testTheMissingExtensionIsOnlyInformative(): void
    {
        $missing = 'pg_stat_statements is not installed in this database: run CREATE EXTENSION pg_stat_statements.';
        $data = self::postgresqlActivity();
        $health = self::checks()->health(Snapshots::stack(new ComponentSnapshot(Component::Postgresql, [
            'postgresql' => new CollectorResult('postgresql', $data['postgresql'], ResultState::Fresh, collectedAt: Snapshots::NOW, expiresAt: Snapshots::NOW + 10),
            'postgresql.settings' => new CollectorResult('postgresql.settings', $data['postgresql.settings'], ResultState::Fresh, collectedAt: Snapshots::NOW, expiresAt: Snapshots::NOW + 3_600),
            'postgresql.slow_queries' => new CollectorResult('postgresql.slow_queries', [], ResultState::Failed, $missing, expiresAt: Snapshots::NOW + 300, failure: FailureKind::MissingPrerequisite),
        ])));
        $otherFailures = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Postgresql, ['postgresql' => $data['postgresql']], ['postgresql.settings' => ResultState::Failed, 'postgresql.database' => ResultState::Failed])));

        $finding = self::find($health, 'postgresql.slow_queries.failed');
        self::assertSame(Severity::Info, $finding?->severity);
        self::assertSame('The slowest queries could not be read: ' . $missing, $finding->sentence);
        self::assertCount(1, $health->findings);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Postgresql));

        self::assertSame(Severity::Info, self::find($otherFailures, 'postgresql.settings.failed')?->severity);
        self::assertStringStartsWith('The PostgreSQL settings could not be read: ', self::find($otherFailures, 'postgresql.settings.failed')->sentence);
        self::assertSame(Severity::Info, self::find($otherFailures, 'postgresql.database.failed')?->severity);
        self::assertStringStartsWith('The tables of the database could not be read: ', self::find($otherFailures, 'postgresql.database.failed')->sentence);
        self::assertSame(Verdict::Healthy, $otherFailures->verdict(Component::Postgresql));
    }

    public function testTheSupportOfPostgresqlIsJudged(): void
    {
        $postgresql = static fn(string $version, array $eol = []): StackHealth => self::checks()->health(Snapshots::stack(Snapshots::of(Component::Postgresql, array_replace(
            self::postgresqlActivity(settings: ['server_version' => $version]),
            $eol,
        ))));

        self::assertSame(Severity::Critical, self::find($postgresql('13.18'), 'postgresql.end_of_life')?->severity);
        self::assertSame(Severity::Warning, self::find($postgresql('14.15'), 'postgresql.security_only')?->severity);
        self::assertSame([], $postgresql('17.2')->findings);
        self::assertSame([], $postgresql('13.0.0')->findings, 'CockroachDB has its own cycles');
        self::assertSame([], $postgresql('13.18', ['eol.postgresql' => []])->findings, 'no cycle read');
    }

    public function testAnIgnoredPostgresqlCheckIsDroppedBeforeTheVerdict(): void
    {
        $full = Snapshots::stack(Snapshots::of(Component::Postgresql, self::postgresqlConnections(960)));
        $unreachable = Snapshots::stack(new ComponentSnapshot(Component::Postgresql, [
            'postgresql' => new CollectorResult('postgresql', [], ResultState::Failed, 'Nothing answers.', expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
        ]));

        self::assertSame(Verdict::Critical, self::checks()->health($full)->verdict(Component::Postgresql));

        $health = self::checks(['postgresql.connections'])->health($full);
        self::assertSame([], $health->findings);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Postgresql));

        $health = self::checks(['postgresql.failed'])->health($unreachable);
        self::assertSame([], $health->findings);
        self::assertSame(Verdict::Unknown, $health->verdict(Component::Postgresql), 'nothing to judge without a value');
    }

    public function testAnOutdatedPostgresqlValueCarriesItsAge(): void
    {
        $data = self::postgresqlConnections(900);
        $health = self::checks()->health(Snapshots::stack(new ComponentSnapshot(Component::Postgresql, [
            'postgresql' => new CollectorResult('postgresql', $data['postgresql'], ResultState::Stale, 'Nothing answers.', Snapshots::NOW - 120, expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
            'postgresql.settings' => new CollectorResult('postgresql.settings', $data['postgresql.settings'], ResultState::Fresh, collectedAt: Snapshots::NOW - 600, expiresAt: Snapshots::NOW + 3_000),
        ])));

        self::assertSame(Severity::Critical, self::find($health, 'postgresql.failed')?->severity);
        self::assertSame(120, self::find($health, 'postgresql.connections')?->age, 'the value shown is still judged');
    }

    /**
     * @return iterable<string, array{array<string, array<string, mixed>>, array<string, Delta>, string, ?Severity}>
     */
    public static function redisThresholds(): iterable
    {
        yield 'memory 89.9 % under noeviction' => [self::redisMemory(899, 'noeviction'), [], 'redis.memory', null];
        yield 'memory 90 % under noeviction' => [self::redisMemory(900, 'noeviction'), [], 'redis.memory', Severity::Warning];
        yield 'memory 97.9 % under noeviction' => [self::redisMemory(979, 'noeviction'), [], 'redis.memory', Severity::Warning];
        yield 'memory 98 % under noeviction' => [self::redisMemory(980, 'noeviction'), [], 'redis.memory', Severity::Critical];
        yield 'memory 99 % under allkeys-lru' => [self::redisMemory(990, 'allkeys-lru'), [], 'redis.memory', null];
        yield 'memory 99 % under volatile-ttl' => [self::redisMemory(990, 'volatile-ttl'), [], 'redis.memory', null];

        yield 'hit rate 79.9 % over 1000 lookups' => [...self::redisWindow(['keyspace_hits' => 0, 'keyspace_misses' => 0], ['keyspace_hits' => 799, 'keyspace_misses' => 201]), 'redis.hit_rate', Severity::Info];
        yield 'hit rate 80 % over 1000 lookups' => [...self::redisWindow(['keyspace_hits' => 0, 'keyspace_misses' => 0], ['keyspace_hits' => 800, 'keyspace_misses' => 200]), 'redis.hit_rate', null];
        yield 'hit rate 0 % over 3 lookups' => [...self::redisWindow(['keyspace_hits' => 0, 'keyspace_misses' => 0], ['keyspace_hits' => 0, 'keyspace_misses' => 3]), 'redis.hit_rate', null];
        yield 'hit rate 0 % over 1000 lookups' => [...self::redisWindow(['keyspace_hits' => 0, 'keyspace_misses' => 0], ['keyspace_hits' => 0, 'keyspace_misses' => 1_000]), 'redis.hit_rate', Severity::Info];
        yield 'hit rate 50 % over 999 lookups' => [...self::redisWindow(['keyspace_hits' => 0, 'keyspace_misses' => 0], ['keyspace_hits' => 500, 'keyspace_misses' => 499]), 'redis.hit_rate', null];
        yield 'hit rate 10 % since the server started' => [self::redis(['stats' => ['keyspace_hits' => 10_000, 'keyspace_misses' => 90_000]]), [], 'redis.hit_rate', null];

        yield 'no key evicted' => [...self::redisWindow(['evicted_keys' => 7], ['evicted_keys' => 7]), 'redis.evictions', null];
        yield 'one key evicted' => [...self::redisWindow(['evicted_keys' => 6], ['evicted_keys' => 7]), 'redis.evictions', Severity::Info];
        yield 'keys evicted since the server started only' => [self::redis(['stats' => ['evicted_keys' => 5_000]]), [], 'redis.evictions', null];

        yield 'no connection refused' => [...self::redisWindow(['rejected_connections' => 3], ['rejected_connections' => 3]), 'redis.rejected_connections', null];
        yield 'one connection refused' => [...self::redisWindow(['rejected_connections' => 3], ['rejected_connections' => 4]), 'redis.rejected_connections', Severity::Warning];
        yield 'connections refused since the server started only' => [self::redis(['stats' => ['rejected_connections' => 50]]), [], 'redis.rejected_connections', null];

        yield 'last snapshot ok' => [self::redis(['persistence' => ['rdb_last_bgsave_status' => 'ok']]), [], 'redis.rdb_failed', null];
        yield 'last snapshot failed' => [self::redis(['persistence' => ['rdb_last_bgsave_status' => 'err']]), [], 'redis.rdb_failed', Severity::Critical];

        yield 'AOF on, last rewrite ok' => [self::redis(['persistence' => ['aof_enabled' => 1, 'aof_last_bgrewrite_status' => 'ok']]), [], 'redis.aof_failed', null];
        yield 'AOF on, last rewrite failed' => [self::redis(['persistence' => ['aof_enabled' => 1, 'aof_last_bgrewrite_status' => 'err']]), [], 'redis.aof_failed', Severity::Critical];

        yield 'Redis supported, not ended' => [self::redis(['server' => ['version' => '7.4.2']]), [], 'redis.end_of_life', null];
        yield 'Redis ended' => [self::redis(['server' => ['version' => '6.2.14']]), [], 'redis.end_of_life', Severity::Critical];
        yield 'Redis supported' => [self::redis(['server' => ['version' => '7.4.2']]), [], 'redis.security_only', null];
        yield 'Redis security fixes only' => [self::redis(['server' => ['version' => '7.2.4']]), [], 'redis.security_only', Severity::Warning];
    }

    /**
     * @param array<string, array<string, mixed>> $data
     * @param array<string, Delta>                $deltas
     */
    #[DataProvider('redisThresholds')]
    public function testEachRedisCheckRisesAtItsThresholdAndNotJustBelow(array $data, array $deltas, string $id, ?Severity $expected): void
    {
        $this->testEachCheckRisesAtItsThresholdAndNotJustBelow(Component::Redis, $data, $deltas, $id, $expected);
    }

    public function testARedisSentenceSaysWhatItCovers(): void
    {
        $health = static fn(array $data, array $deltas = []): StackHealth => self::checks()->health(Snapshots::stack(Snapshots::of(Component::Redis, $data, deltas: $deltas)));
        $full = $health(self::redis(['memory' => ['used' => 1_932_735_284, 'max_memory' => 2_147_483_648, 'max_memory_policy' => 'noeviction']]));
        $window = $health(...self::redisWindow(
            ['keyspace_hits' => 0, 'keyspace_misses' => 0, 'evicted_keys' => 0, 'rejected_connections' => 0],
            ['keyspace_hits' => 1_400, 'keyspace_misses' => 600, 'evicted_keys' => 12, 'rejected_connections' => 3],
        ));
        $once = $health(...self::redisWindow(['evicted_keys' => 0, 'rejected_connections' => 0], ['evicted_keys' => 1, 'rejected_connections' => 1]));
        $many = $health(...self::redisWindow(['evicted_keys' => 0, 'rejected_connections' => 0], ['evicted_keys' => 1_793, 'rejected_connections' => 2_500]));
        $snapshot = $health(self::redis(['persistence' => ['rdb_last_bgsave_status' => 'err']]));
        $rewrite = $health(self::redis(['persistence' => ['aof_enabled' => 1, 'aof_last_bgrewrite_status' => 'err']]));
        $ended = $health(self::redis(['server' => ['version' => '6.2.14']]));
        $securityOnly = $health(self::redis(['server' => ['version' => '7.2.4']]));
        $valkey = $health(self::redis(['server' => ['name' => 'valkey', 'valkey_version' => '7.2.5'], 'memory' => ['used' => 1_932_735_284, 'max_memory' => 2_147_483_648, 'max_memory_policy' => 'noeviction']]));

        self::assertSame("Redis uses 90\u{00A0}% of its memory limit (1.8\u{00A0}GB of 2\u{00A0}GB)", self::find($full, 'redis.memory')?->sentence);
        self::assertStringContainsString('noeviction', self::find($full, 'redis.memory')->explanation);
        self::assertStringContainsString('maxmemory', self::find($full, 'redis.memory')->explanation);
        self::assertSame("The hit rate is 70\u{00A0}% over the last 60\u{00A0}s", self::find($window, 'redis.hit_rate')?->sentence);
        self::assertSame("12 keys were evicted over the last 60\u{00A0}s", self::find($window, 'redis.evictions')?->sentence);
        self::assertSame("1,793 keys were evicted over the last 60\u{00A0}s", self::find($many, 'redis.evictions')?->sentence);
        self::assertSame("2,500 connections were refused over the last 60\u{00A0}s", self::find($many, 'redis.rejected_connections')?->sentence);
        self::assertSame("1 key was evicted over the last 60\u{00A0}s", self::find($once, 'redis.evictions')?->sentence);
        self::assertStringContainsString('allkeys-lru', self::find($window, 'redis.evictions')->explanation, 'the policy of the server');
        self::assertSame("3 connections were refused over the last 60\u{00A0}s", self::find($window, 'redis.rejected_connections')?->sentence);
        self::assertSame("1 connection was refused over the last 60\u{00A0}s", self::find($once, 'redis.rejected_connections')?->sentence);
        self::assertStringContainsString('maxclients', self::find($window, 'redis.rejected_connections')->explanation);
        self::assertSame('The last RDB snapshot failed', self::find($snapshot, 'redis.rdb_failed')?->sentence);
        self::assertSame('The last rewrite of the AOF failed', self::find($rewrite, 'redis.aof_failed')?->sentence);
        self::assertSame('Redis 6.2.14 has reached its end of life', self::find($ended, 'redis.end_of_life')?->sentence);
        self::assertSame('Redis 7.2.4 only receives security fixes', self::find($securityOnly, 'redis.security_only')?->sentence);
        self::assertSame("Valkey uses 90\u{00A0}% of its memory limit (1.8\u{00A0}GB of 2\u{00A0}GB)", self::find($valkey, 'redis.memory')?->sentence);
        self::assertSame('Valkey 7.2.5 has reached its end of life', self::find($valkey, 'redis.end_of_life')?->sentence);
    }

    public function testAHealthyRedisRaisesNothing(): void
    {
        $health = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Redis, self::redis())));

        self::assertSame([], $health->findings);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Redis));
    }

    public function testAFailedSnapshotIsCritical(): void
    {
        $health = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Redis, self::redis(['persistence' => ['rdb_last_bgsave_status' => 'err']]))));

        self::assertSame(Severity::Critical, self::find($health, 'redis.rdb_failed')?->severity);
        self::assertStringContainsString('disk', self::find($health, 'redis.rdb_failed')->explanation);
        self::assertSame(Verdict::Critical, $health->verdict(Component::Redis));
    }

    public function testASnapshotInProgressIsNotAFailure(): void
    {
        $health = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Redis, self::redis(['persistence' => [
            'rdb_bgsave_in_progress' => 1,
            'rdb_last_bgsave_status' => 'err',
            'aof_enabled' => 1,
            'aof_rewrite_in_progress' => 1,
            'aof_last_bgrewrite_status' => 'err',
        ]]))));

        self::assertSame([], $health->findings, 'the one under way may succeed');
    }

    public function testAFailedRewriteOnlyCountsWithAofOn(): void
    {
        $off = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Redis, self::redis(['persistence' => ['aof_enabled' => 0, 'aof_last_bgrewrite_status' => 'err']]))));
        $on = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Redis, self::redis(['persistence' => ['aof_enabled' => 1, 'aof_last_bgrewrite_status' => 'err']]))));

        self::assertNull(self::find($off, 'redis.aof_failed'));
        self::assertSame(Severity::Critical, self::find($on, 'redis.aof_failed')?->severity);
        self::assertSame(Verdict::Critical, $on->verdict(Component::Redis));
    }

    public function testAnUnreachableRedisIsCritical(): void
    {
        $health = self::checks()->health(Snapshots::stack(new ComponentSnapshot(Component::Redis, [
            'redis' => new CollectorResult('redis', [], ResultState::Failed, 'The connection is refused, cannot be opened, or gets no answer in time.', expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
        ])));
        $denied = self::checks()->health(Snapshots::stack(new ComponentSnapshot(Component::Redis, [
            'redis' => new CollectorResult('redis', [], ResultState::Failed, 'The Redis user of this connection may not run INFO.', expiresAt: Snapshots::NOW + 300, failure: FailureKind::MissingPrerequisite),
        ])));

        self::assertSame(['redis.failed'], array_map(static fn(Finding $finding): string => $finding->id, $health->findings));
        self::assertSame(Severity::Critical, self::find($health, 'redis.failed')?->severity);
        self::assertSame('Redis cannot be reached: The connection is refused, cannot be opened, or gets no answer in time.', self::find($health, 'redis.failed')->sentence);
        self::assertSame(Verdict::Critical, $health->verdict(Component::Redis));

        self::assertSame(Severity::Warning, self::find($denied, 'redis.failed')?->severity, 'the server answered');
        self::assertSame(Verdict::Unknown, $denied->verdict(Component::Redis));
    }

    public function testAnUnreachableRedisIsToldFromTheConnectionOfTheStack(): void
    {
        $health = self::checks()->health(Snapshots::stack(new ComponentSnapshot(Component::Redis, [
            'redis' => new CollectorResult('redis', [], ResultState::Failed, 'The server asks for a password, or refuses the one given.', expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
        ])));

        self::assertSame(
            'The Stack screen reads this server through a connection of its own, opened with the DSN it was given or found: the application cannot use the server either when it reaches it with the same DSN. Fix the cause given, then refresh.',
            self::find($health, 'redis.failed')?->explanation,
        );
    }

    public function testTheSupportOfRedisAndValkeyIsJudged(): void
    {
        $redis = static fn(array $server, array $eol = []): StackHealth => self::checks()->health(Snapshots::stack(Snapshots::of(Component::Redis, array_replace(self::redis(['server' => $server]), $eol))));

        self::assertSame(Severity::Critical, self::find($redis(['version' => '6.2.14']), 'redis.end_of_life')?->severity);
        self::assertSame(Severity::Warning, self::find($redis(['version' => '7.2.4']), 'redis.security_only')?->severity);
        self::assertSame([], $redis(['version' => '7.4.2'])->findings);
        self::assertSame([], $redis(['version' => '6.2.14'], ['eol.redis' => []])->findings, 'no cycle read');

        $valkey = $redis(['name' => 'valkey', 'valkey_version' => '7.2.5']);
        self::assertSame(Severity::Critical, self::find($valkey, 'redis.end_of_life')?->severity, 'the cycles of Valkey, not those of the Redis 7.2.4 it tells');
        self::assertSame([], $redis(['name' => 'valkey', 'valkey_version' => '8.0.1'])->findings);
        self::assertSame([], $redis(['name' => 'valkey', 'valkey_version' => '7.2.5'], ['eol.valkey' => []])->findings);
    }

    public function testTheSupportOfValkeyIsJudgedOnTheCyclesOfValkey(): void
    {
        $judged = static fn(array $server, string $stale): ?Finding => self::find(
            self::checks()->health(Snapshots::stack(Snapshots::of(Component::Redis, self::redis(['server' => $server]), [$stale => ResultState::Stale]))),
            'redis.end_of_life',
        );

        self::assertSame(Snapshots::STALE_AGE, $judged(['name' => 'valkey', 'valkey_version' => '7.2.5'], 'eol.valkey')?->age, 'its age is the one of the cycles of Valkey');
        self::assertNull($judged(['name' => 'valkey', 'valkey_version' => '7.2.5'], 'eol.redis')?->age);
        self::assertSame(Snapshots::STALE_AGE, $judged(['version' => '6.2.14'], 'eol.redis')?->age);
        self::assertNull($judged(['version' => '6.2.14'], 'eol.valkey')?->age);
    }

    public function testAnIgnoredRedisCheckIsDroppedBeforeTheVerdict(): void
    {
        $full = Snapshots::stack(Snapshots::of(Component::Redis, self::redisMemory(990, 'noeviction')));
        $unreachable = Snapshots::stack(new ComponentSnapshot(Component::Redis, [
            'redis' => new CollectorResult('redis', [], ResultState::Failed, 'Nothing answers.', expiresAt: Snapshots::NOW + 300, failure: FailureKind::Unreachable),
        ]));

        self::assertSame(Verdict::Critical, self::checks()->health($full)->verdict(Component::Redis));

        $health = self::checks(['redis.memory'])->health($full);
        self::assertSame([], $health->findings);
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Redis));

        $health = self::checks(['redis.failed'])->health($unreachable);
        self::assertSame([], $health->findings);
        self::assertSame(Verdict::Unknown, $health->verdict(Component::Redis));
    }

    public function testInformationOnRedisDoesNotChangeTheVerdict(): void
    {
        [$data, $deltas] = self::redisWindow(
            ['keyspace_hits' => 0, 'keyspace_misses' => 0, 'evicted_keys' => 0],
            ['keyspace_hits' => 100, 'keyspace_misses' => 900, 'evicted_keys' => 50],
        );
        $health = self::checks()->health(Snapshots::stack(Snapshots::of(Component::Redis, $data, deltas: $deltas)));

        self::assertSame(['redis.evictions', 'redis.hit_rate'], array_map(static fn(Finding $finding): string => $finding->id, $health->findings));
        self::assertSame(Verdict::Healthy, $health->verdict(Component::Redis));
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
     * @return array<string, array<string, mixed>> the status and the settings of a MySQL that allows 1000 connections
     */
    private static function mysqlConnections(int $connected): array
    {
        return [
            'mysql' => ['Threads_connected' => (string) $connected] + DatabasePayloads::mysqlStatus(),
            'mysql.variables' => ['max_connections' => '1000'] + DatabasePayloads::mysqlVariables(),
        ];
    }

    /**
     * @return array{array<string, array<string, mixed>>, array<string, Delta>}
     */
    private static function bufferPool(int $requests, int $reads, int $uptime = 600, bool $twoSamples = true): array
    {
        return self::mysqlCounters(['Innodb_buffer_pool_read_requests' => $requests, 'Innodb_buffer_pool_reads' => $reads], $uptime, $twoSamples);
    }

    /**
     * @return array{array<string, array<string, mixed>>, array<string, Delta>}
     */
    private static function temporaryTables(int $created, int $onDisk, int $uptime = 600, bool $twoSamples = true): array
    {
        return self::mysqlCounters(['Created_tmp_tables' => $created, 'Created_tmp_disk_tables' => $onDisk], $uptime, $twoSamples);
    }

    /**
     * @return array{array<string, array<string, mixed>>, array<string, Delta>}
     */
    private static function tableLocks(int $locks, int $waited, int $uptime = 600, bool $twoSamples = true): array
    {
        return self::mysqlCounters(['Table_locks_waited' => $waited, 'Table_locks_immediate' => $locks - $waited], $uptime, $twoSamples);
    }

    /**
     * A MySQL whose given counters started from zero, between two samples a minute apart or since the server started.
     *
     * @param array<string, int> $counters
     *
     * @return array{array<string, array<string, mixed>>, array<string, Delta>}
     */
    private static function mysqlCounters(array $counters, int $uptime, bool $twoSamples): array
    {
        [$data, $deltas] = self::mysqlWindow(array_map(static fn(): int => 0, $counters), $counters, $uptime);

        return [$data, $twoSamples ? $deltas : []];
    }

    /**
     * A MySQL whose given counters went from $previous to $current between two samples a minute apart.
     *
     * @param array<string, int> $previous
     * @param array<string, int> $current
     *
     * @return array{array<string, array<string, mixed>>, array<string, Delta>}
     */
    private static function mysqlWindow(array $previous, array $current, int $uptime = 3_600): array
    {
        $status = array_map(strval(...), $current) + ['Uptime' => (string) $uptime] + DatabasePayloads::mysqlStatus();

        return [
            ['mysql' => $status, 'mysql.variables' => DatabasePayloads::mysqlVariables()],
            ['mysql' => Snapshots::delta(array_map(strval(...), $previous), $status)],
        ];
    }

    /**
     * A PostgreSQL 17 database with nothing to tell, the values given replaced.
     *
     * @param array<string, mixed> $activity replacing those of the main collector, recursively
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $database replacing the figures of the tables
     *
     * @return array<string, array<string, mixed>>
     */
    private static function postgresqlActivity(array $activity = [], array $settings = [], array $database = []): array
    {
        $healthy = DatabasePayloads::postgresqlActivity();
        $healthy['sessions'] = [
            'oldest_transaction_seconds' => 2,
            'idle_in_transaction_count' => 0,
            'oldest_idle_in_transaction_seconds' => null,
            'blocked_count' => 0,
            'max_wait_seconds' => null,
            'blocked_queries' => [],
        ];
        $tables = DatabasePayloads::postgresqlDatabase();
        $tables['tables'] = array_replace($tables['tables'], $database);

        return [
            'postgresql' => array_replace_recursive($healthy, $activity),
            'postgresql.settings' => array_replace(DatabasePayloads::postgresqlSettings(), $settings),
            'postgresql.database' => $tables,
            'eol.postgresql' => ['cycles' => [
                '17' => ['eol' => '2029-11-08', 'support' => '2029-11-08'],
                '14' => ['eol' => '2026-11-12', 'support' => '2025-11-12'],
                '13' => ['eol' => '2025-11-13', 'support' => '2025-11-13'],
            ]],
        ];
    }

    /**
     * @return array<string, array<string, mixed>> a PostgreSQL server that allows 1000 connections
     */
    private static function postgresqlConnections(int $connected): array
    {
        return self::postgresqlActivity(['server_connections' => $connected], ['max_connections' => '1000']);
    }

    /**
     * A PostgreSQL database whose given counters went from $previous to $current between two samples a minute apart.
     *
     * @param array<string, int> $previous
     * @param array<string, int> $current
     *
     * @return array{array<string, array<string, mixed>>, array<string, Delta>}
     */
    private static function postgresqlWindow(array $previous, array $current): array
    {
        $data = self::postgresqlActivity(['database_stats' => $current]);

        return [$data, ['postgresql' => Snapshots::delta(['database_stats' => $previous], $data['postgresql'])]];
    }

    /**
     * @return array<string, mixed> the sessions of a database where one session waits for a lock
     */
    private static function blocked(int $seconds): array
    {
        return ['blocked_count' => 1, 'max_wait_seconds' => $seconds, 'blocked_queries' => [
            ['blocked_pid' => 4242, 'wait_seconds' => $seconds, 'blocking_pid' => 4100, 'blocking_state' => 'idle in transaction'],
        ]];
    }

    /**
     * A Redis 7.4 with nothing to tell, the values given replaced.
     *
     * @param array<string, mixed> $replaced replacing what the stack keeps of the server, recursively
     *
     * @return array<string, array<string, mixed>>
     */
    private static function redis(array $replaced = []): array
    {
        $kept = RedisPayloads::kept();
        $kept['server']['version'] = '7.4.2';

        return [
            'redis' => array_replace_recursive($kept, $replaced),
            'eol.redis' => RedisPayloads::redisCycles(),
            'eol.valkey' => RedisPayloads::valkeyCycles(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>> a Redis whose memory limit is 1 000 000 000 bytes
     */
    private static function redisMemory(int $usedPerThousand, string $policy): array
    {
        return self::redis(['memory' => ['used' => $usedPerThousand * 1_000_000, 'max_memory' => 1_000_000_000, 'max_memory_policy' => $policy]]);
    }

    /**
     * A Redis whose given counters went from $previous to $current between two samples a minute apart.
     *
     * @param array<string, int> $previous
     * @param array<string, int> $current
     *
     * @return array{array<string, array<string, mixed>>, array<string, Delta>}
     */
    private static function redisWindow(array $previous, array $current): array
    {
        $data = self::redis(['stats' => $current]);

        return [$data, ['redis' => Snapshots::delta(['stats' => $previous], $data['redis'])]];
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
