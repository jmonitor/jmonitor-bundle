<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCard;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Vital;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Screen\CardBuilder;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CardBuilderTest extends TestCase
{
    private const NBSP = "\u{00A0}";

    public function testTheServerCardShowsItsSystemLoadMemoryAndDisk(): void
    {
        $card = $this->card(Snapshots::of(Component::System, ['system' => Snapshots::system()]), 'stack/system');

        self::assertSame('system', $card->id);
        self::assertSame('Server', $card->name);
        self::assertSame('Debian GNU/Linux 12 (bookworm)', $card->version);
        self::assertEquals(new Mark('ok', 'Healthy'), $card->mark);
        self::assertSame(['Load' => '62 %', 'Memory' => '75 %', 'Disk' => '75 %'], self::vitals($card));
        self::assertSame('stack/system', $card->path);
        self::assertSame(Snapshots::NOW, $card->readAt);
        self::assertFalse($card->reading);
        self::assertNull($card->reason);
        self::assertNull($card->note);
        self::assertSame([], $card->badges);

        $system = Snapshots::system();
        $system['cgroup']['memory_limit'] = 2_000_000_000;

        self::assertSame('Load is measured on the host node', $this->card(Snapshots::of(Component::System, ['system' => $system]))->note);
    }

    public function testThePhpCardShowsOpcacheAndFpmWorkersWithItsEndOfLife(): void
    {
        $php = ['version' => '8.2.29'] + Snapshots::php();
        $card = $this->card(Snapshots::of(Component::Php, ['php' => $php, 'eol.php' => Snapshots::cycles()]), 'stack/php');

        self::assertSame('php', $card->id);
        self::assertSame('PHP', $card->name);
        self::assertSame('8.2.29', $card->version);
        self::assertEquals(new Mark('warn', 'Warning'), $card->mark);
        self::assertEquals([new Mark('warn', 'Security fixes only')], $card->badges);
        self::assertEquals([
            new Vital('OPcache memory', '70 %'),
            new Vital('OPcache hit rate', '90.8 %', 'since server start'),
            new Vital('FPM workers', '2 / 8'),
        ], array_map(self::readable(...), $card->vitals));

        $delta = Snapshots::delta(
            ['opcache' => ['status' => ['opcache_statistics' => ['hits' => 190, 'misses' => 10]]]],
            ['opcache' => ['status' => ['opcache_statistics' => ['hits' => 1090, 'misses' => 110]]]],
        );
        $card = $this->card(Snapshots::of(Component::Php, ['php' => Snapshots::php()], deltas: ['php' => $delta]));

        self::assertSame(['OPcache memory' => '70 %', 'OPcache hit rate' => '90 %', 'FPM workers' => '2 / 8'], self::vitals($card));
        self::assertSame('over the last 60' . self::NBSP . 's', $card->vitals[1]->hint);

        $ended = $this->card(Snapshots::of(Component::Php, ['php' => ['version' => '8.1.31'] + Snapshots::php(), 'eol.php' => Snapshots::cycles()]));
        self::assertEquals([new Mark('bad', 'End of life')], $ended->badges);
        self::assertSame([], $this->card(Snapshots::of(Component::Php, ['php' => Snapshots::php(), 'eol.php' => Snapshots::cycles()]))->badges);
    }

    public function testAVitalNeverShowsAThresholdItDidNotReach(): void
    {
        $php = Snapshots::php();
        $php['opcache']['status']['memory_usage'] = ['used_memory' => 84_960, 'free_memory' => 15_040, 'wasted_memory' => 0];

        self::assertSame('84.9' . self::NBSP . '%', $this->card(Snapshots::of(Component::Php, ['php' => $php]))->vitals[0]->value);
    }

    public function testTheSymfonyCardShowsItsEnvironmentDebugAndFlexRecipes(): void
    {
        $outdated = ['up_to_date' => false, 'outdated_recipes' => ['doctrine/doctrine-bundle', 'symfony/framework-bundle']];
        $card = $this->card(Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony(), 'symfony.flex' => $outdated]), 'stack/symfony');

        self::assertSame('symfony', $card->id);
        self::assertSame('Symfony', $card->name);
        self::assertSame('7.4.2', $card->version);
        self::assertEquals(new Mark('ok', 'Healthy'), $card->mark);
        self::assertSame(['Environment' => 'prod', 'Debug' => 'off', 'Flex recipes' => '2 outdated'], self::vitals($card));

        $upToDate = ['up_to_date' => true, 'outdated_recipes' => []];
        $card = $this->card(Snapshots::of(Component::Symfony, ['symfony' => ['debug' => true, 'env' => 'dev'] + Snapshots::symfony(), 'symfony.flex' => $upToDate, 'eol.symfony' => Snapshots::cycles()]));

        self::assertSame(['Environment' => 'dev', 'Debug' => 'on', 'Flex recipes' => 'up to date'], self::vitals($card));
        self::assertSame([], $card->badges);

        $card = $this->card(Snapshots::of(Component::Symfony, ['symfony' => ['version' => '6.4.20'] + Snapshots::symfony(), 'eol.symfony' => Snapshots::cycles()]));
        self::assertEquals([new Mark('warn', 'Security fixes only')], $card->badges);
    }

    public function testAMissingValueIsADash(): void
    {
        $card = $this->card(Snapshots::of(Component::System, ['system' => ['os' => []]]));

        self::assertNull($card->version);
        self::assertSame(['Load' => '—', 'Memory' => '—', 'Disk' => '—'], self::vitals($card));

        $card = $this->card(Snapshots::of(Component::Php, ['php' => ['opcache' => false, 'fpm' => ['pool' => 'www']]]));

        self::assertSame(['OPcache memory' => '—', 'OPcache hit rate' => '—', 'FPM workers' => '—'], self::vitals($card));
        self::assertNull($card->vitals[1]->hint);
    }

    public function testAVitalSaysReadingWhileItsCollectorWasNeverRead(): void
    {
        foreach ([ResultState::Pending, ResultState::Busy] as $state) {
            $card = $this->card(Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => $state]));

            self::assertSame(['Environment' => 'prod', 'Debug' => 'off', 'Flex recipes' => 'Reading…'], self::vitals($card), $state->value);
            self::assertFalse($card->reading, $state->value);
        }

        $card = $this->card(Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Failed]));
        self::assertSame('—', self::vitals($card)['Flex recipes']);
    }

    public function testWhatWillNotBeReadForNowShowsAsMissing(): void
    {
        $instance = new InstanceState(null, null, null, Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()], ['symfony.flex' => ResultState::Pending]));
        $card = $this->cardOf(Component::Symfony, $instance, reading: false);

        self::assertSame(['Environment' => 'prod', 'Debug' => 'off', 'Flex recipes' => '—'], self::vitals($card));
        self::assertNull($card->vitals[2]->hint);
        self::assertFalse($card->reading);

        foreach ([ResultState::Pending, ResultState::Busy] as $state) {
            $card = $this->cardOf(Component::Php, new InstanceState(null, null, null, Snapshots::of(Component::Php, [], ['php' => $state])), reading: false);

            self::assertFalse($card->reading, $state->value);
            self::assertSame(['OPcache memory' => '—', 'OPcache hit rate' => '—'], self::vitals($card), $state->value);
            self::assertSame([null, null], array_map(static fn(Vital $vital): ?string => $vital->hint, $card->vitals), $state->value);
        }
    }

    /**
     * @return iterable<string, array{ResultState, bool}>
     */
    public static function mainStates(): iterable
    {
        yield 'pending' => [ResultState::Pending, true];
        yield 'busy' => [ResultState::Busy, true];
        yield 'fresh' => [ResultState::Fresh, false];
        yield 'stale' => [ResultState::Stale, false];
        yield 'failed' => [ResultState::Failed, false];
    }

    #[DataProvider('mainStates')]
    public function testACardIsReadingUntilItsMainCollectorWasRead(ResultState $state, bool $reading): void
    {
        $card = $this->card(Snapshots::of(Component::Php, ['php' => Snapshots::php()], ['php' => $state]));

        self::assertSame($reading, $card->reading);
        self::assertSame($state === ResultState::Fresh ? Snapshots::NOW : ($state === ResultState::Stale ? Snapshots::NOW - Snapshots::STALE_AGE : null), $card->readAt);

        if ($reading) {
            self::assertSame(['OPcache memory' => 'Reading…', 'OPcache hit rate' => 'Reading…'], self::vitals($card), 'FPM is not known yet');
        }
    }

    public function testAFailedMainCollectorGivesItsReason(): void
    {
        $card = $this->card(Snapshots::of(Component::Php, [], ['php' => ResultState::Failed]));

        self::assertSame(Snapshots::FAILURE, $card->reason);
        self::assertEquals(new Mark('unknown', 'Unknown'), $card->mark);
        self::assertFalse($card->reading);

        $unavailable = new InstanceState(null, null, 'The FPM status page did not answer.', new ComponentSnapshot(Component::Php, [], 'The FPM status page did not answer.'));
        self::assertSame('The FPM status page did not answer.', $this->cardOf(Component::Php, $unavailable)->reason);

        $failedSnapshot = new InstanceState(null, null, null, new ComponentSnapshot(Component::Php, [], 'No collector could start.'));
        self::assertSame('No collector could start.', $this->cardOf(Component::Php, $failedSnapshot)->reason);

        $stale = Snapshots::of(Component::Php, ['php' => Snapshots::php()], ['php' => ResultState::Stale]);
        self::assertNull($this->card($stale)->reason);
    }

    public function testAStaleCardSaysHowOldItsFiguresAre(): void
    {
        $stale = static fn(Component $component, array $data, ?string $error = null): ComponentSnapshot => new ComponentSnapshot($component, [
            $component->value => new CollectorResult($component->value, $data, ResultState::Stale, $error, Snapshots::NOW - 180, expiresAt: Snapshots::NOW - 120),
        ]);
        $container = Snapshots::system();
        $container['cgroup']['memory_limit'] = 2_000_000_000;

        self::assertNull($this->card(Snapshots::of(Component::Php, ['php' => Snapshots::php()]))->note);
        self::assertSame('Read 3' . self::NBSP . 'min ago', $this->card($stale(Component::Php, Snapshots::php()))->note);
        self::assertSame('Read 3' . self::NBSP . 'min ago, could not be read again', $this->card($stale(Component::Php, Snapshots::php(), 'Timed out'))->note);
        self::assertSame('Load is measured on the host node · Read 3' . self::NBSP . 'min ago, could not be read again', $this->card($stale(Component::System, $container, 'Timed out'))->note);
    }

    public function testFlexRecipesTellTheOutdatedOnesFirst(): void
    {
        $contradicting = ['up_to_date' => true, 'outdated_recipes' => ['symfony/twig-bundle']];
        $card = $this->card(Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony(), 'symfony.flex' => $contradicting]));

        self::assertSame('1 outdated', self::vitals($card)['Flex recipes']);
    }

    public function testFpmWorkersOnlyShowUnderFpm(): void
    {
        $php = Snapshots::php();
        unset($php['fpm']);
        $php['sapi_name'] = 'apache2handler';

        self::assertSame(['OPcache memory', 'OPcache hit rate'], array_keys(self::vitals($this->card(Snapshots::of(Component::Php, ['php' => $php])))));
    }

    public function testFlexRecipesAreLeftOutWhenTurnedOff(): void
    {
        self::assertSame(['Environment', 'Debug'], array_keys(self::vitals($this->card(Snapshots::of(Component::Symfony, ['symfony' => Snapshots::symfony()])))));
    }

    public function testAnInstanceWithAnIdIsNamedAfterIt(): void
    {
        $card = $this->cardOf(Component::Php, new InstanceState('www', null, null, Snapshots::of(Component::Php, ['php' => Snapshots::php()])));

        self::assertSame('php.www', $card->id);
        self::assertSame('PHP · www', $card->name);
    }

    private function card(ComponentSnapshot $snapshot, ?string $path = null): ComponentCard
    {
        return $this->cardOf($snapshot->component, new InstanceState(null, null, null, $snapshot), $path);
    }

    private function cardOf(Component $component, InstanceState $instance, ?string $path = null, bool $reading = true): ComponentCard
    {
        $clock = new MockClock('@' . Snapshots::NOW);
        $health = (new StackChecks([], false, $clock))->health(Snapshots::state([$component->value => [$instance]]));

        return (new CardBuilder($clock))->card($component, $instance, $health, $path, $reading);
    }

    /**
     * @return array<string, string> the values with ordinary spaces
     */
    private static function vitals(ComponentCard $card): array
    {
        $vitals = [];

        foreach ($card->vitals as $vital) {
            $vitals[$vital->label] = self::readable($vital)->value;
        }

        return $vitals;
    }

    private static function readable(Vital $vital): Vital
    {
        self::assertStringNotContainsString(' ', $vital->value, $vital->label . ': a short value never breaks');

        return new Vital($vital->label, str_replace(self::NBSP, ' ', $vital->value), $vital->hint);
    }
}
