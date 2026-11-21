<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Snapshot;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectBudget;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\DeltaCalculator;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Cache\RecordingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeCollector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\ThrowingCachePool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;

final class SnapshotProviderTest extends TestCase
{
    private const START = 1_791_799_200;

    private RecordingCachePool $recording;
    private CountingCachePool $cache;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->recording = new RecordingCachePool();
        $this->cache = new CountingCachePool($this->recording);
        $this->clock = new MockClock('@' . self::START);
    }

    public function testCollectsOnceAndKeepsEachCollectorForItsLifetime(): void
    {
        $status = new FakeCollector('php.status', ['requests' => 100]);
        $version = new FakeCollector('php.version', ['version' => '8.4']);
        $instance = self::instance([self::spec($status), self::spec($version, Lifetime::Static)]);
        $provider = $this->provider();

        $first = $this->read($provider, $instance);
        $this->clock->sleep(9);
        $second = $this->read($provider, $instance);

        self::assertSame(1, $status->collects);
        self::assertSame(ResultState::Fresh, $second->result('php.status')?->state);
        self::assertSame(['requests' => 100], $second->data('php.status'));
        self::assertSame(['version' => '8.4'], $second->data('php.version'));
        self::assertSame(self::START, $first->result('php.status')?->collectedAt);
        self::assertSame(self::START + 10, $first->result('php.status')?->expiresAt);
        self::assertSame(300, $this->recording->ttls['jmonitor.stack.result.%node.web-1.php.php.status'], 'kept while it may still show');
        self::assertSame(604800, $this->recording->ttls['jmonitor.stack.result.%node.web-1.php.php.version']);

        $this->clock->sleep(1);
        $this->read($provider, $instance);

        self::assertSame(2, $status->collects);

        $this->clock->sleep(3600 - 11);
        $this->read($provider, $instance);

        self::assertSame(1, $version->collects);

        $this->clock->sleep(1);
        $this->read($provider, $instance);

        self::assertSame(2, $version->collects);
    }

    public function testTheNextCollectionOfADynamicCollectorCarriesADelta(): void
    {
        $status = new FakeCollector('php.status', ['requests' => 100]);
        $version = new FakeCollector('php.version', ['version' => '8.4']);
        $instance = self::instance([self::spec($status, trackDelta: true), self::spec($version, Lifetime::Static)]);
        $provider = $this->provider();

        $first = $this->read($provider, $instance);
        $status->setData(['requests' => 250]);
        $this->clock->sleep(11);
        $snapshot = $this->read($provider, $instance);

        self::assertNull($first->delta('php.status'), 'first sample: no previous one');
        self::assertSame(2, $status->collects);
        self::assertSame(1, $version->collects, 'still kept');
        self::assertSame(150, $snapshot->delta('php.status')?->getValue('requests'));
        self::assertSame(11, $snapshot->delta('php.status')->elapsed);
        self::assertTrue($this->recording->hasItem('jmonitor.stack.previous.%node.web-1.php.php.status'), 'under the suffix of its result');
    }

    public function testAForgetRightAfterACollectKeepsItsRates(): void
    {
        $status = new FakeCollector('php.status', ['requests' => 100]);
        $instance = self::instance([self::spec($status, trackDelta: true)]);
        $provider = $this->provider();

        $this->read($provider, $instance);
        $this->clock->sleep(2);
        $status->setData(['requests' => 120]);
        $provider->forget([$instance]);
        $forgotten = $this->read($provider, $instance);

        self::assertSame(2, $status->collects);
        self::assertSame(10.0, $forgotten->delta('php.status')?->getPerSec('requests'));

        $this->clock->sleep(10);
        $status->setData(['requests' => 220]);

        self::assertSame(12, $this->read($provider, $instance)->delta('php.status')?->elapsed, 'the next read spans a full interval');
    }

    public function testAnInstanceIsCollectedByOneRequestAtATime(): void
    {
        $witness = 'jmonitor.stack.collecting.%node.web-1.symfony.main';
        $status = new FakeCollector('symfony.status', ['env' => 'prod']);
        $variables = new class ($this->recording, $witness) implements CollectorInterface {
            public ?bool $witnessed = null;

            public function __construct(
                private readonly RecordingCachePool $cache,
                private readonly string $witness,
            ) {}

            public function collect(): array
            {
                $this->witnessed = $this->cache->hasItem($this->witness);

                return ['debug' => false];
            }

            public function getVersion(): int
            {
                return 1;
            }

            public function getName(): string
            {
                return 'symfony.variables';
            }
        };
        $instance = self::instance([self::spec($status), new CollectorSpec($variables, Lifetime::Static, CostClass::Immediate)], 'main', Component::Symfony);

        $this->read($this->provider(), $instance);

        self::assertTrue($variables->witnessed, 'a witness is set while collecting');
        self::assertFalse($this->recording->hasItem($witness), 'and lifted afterwards');
        self::assertSame(300, $this->recording->ttls[$witness], 'the witness of a request that died expires on its own');
    }

    public function testAWitnessTakenOverByAnotherRequestIsLeftToIt(): void
    {
        $witness = 'jmonitor.stack.collecting.%node.web-1.php';
        $status = new class ($this->recording, $witness) implements CollectorInterface {
            public function __construct(
                private readonly RecordingCachePool $cache,
                private readonly string $witness,
            ) {}

            public function collect(): array
            {
                $this->cache->save($this->cache->getItem($this->witness)->set('token of another request'));

                return ['requests' => 100];
            }

            public function getVersion(): int
            {
                return 1;
            }

            public function getName(): string
            {
                return 'php.status';
            }
        };

        $this->read($this->provider(), self::instance([new CollectorSpec($status, Lifetime::Dynamic, CostClass::Immediate)]));

        self::assertSame('token of another request', $this->recording->getItem($witness)->get());
    }

    public function testForgetLiftsTheWitnessOfARequestThatDied(): void
    {
        $status = new FakeCollector('php.status', ['requests' => 100]);
        $instance = self::instance([self::spec($status)]);
        $provider = $this->provider();
        $this->recording->save($this->recording->getItem('jmonitor.stack.collecting.%node.web-1.php')->set('token of a dead request'));

        $busy = $this->read($provider, $instance);

        self::assertSame(0, $status->collects);
        self::assertSame(ResultState::Busy, $busy->result('php.status')?->state);
        self::assertTrue($busy->isReading());

        $provider->forget([$instance]);
        $snapshot = $this->read($provider, $instance);

        self::assertSame(1, $status->collects);
        self::assertFalse($snapshot->isReading());
    }

    public function testAnotherRequestSeesTheKeptValuesAndLeavesTheOthersToTheWitness(): void
    {
        $status = new FakeCollector('php.status', ['requests' => 100]);
        $version = new FakeCollector('php.version', ['version' => '8.4']);
        $instance = self::instance([self::spec($status), self::spec($version, Lifetime::Static)]);
        $provider = $this->provider();
        $this->read($provider, $instance);
        $this->recording->deleteItem('jmonitor.stack.result.%node.web-1.php.php.status');
        $this->recording->save($this->recording->getItem('jmonitor.stack.collecting.%node.web-1.php')->set('token of another request'));

        $snapshot = $this->read($provider, $instance);

        self::assertSame(1, $status->collects, 'not collected while another request does');
        self::assertSame(ResultState::Busy, $snapshot->result('php.status')?->state);
        self::assertSame(ResultState::Fresh, $snapshot->result('php.version')?->state);
        self::assertSame([], $snapshot->errors());
        self::assertFalse($this->recording->hasItem('jmonitor.stack.result.%node.web-1.php.php.status'), 'nothing is written for it');
        self::assertSame('token of another request', $this->recording->getItem('jmonitor.stack.collecting.%node.web-1.php')->get());
    }

    public function testTheImplicitInstancesOfTwoComponentsHaveTheirOwnWitness(): void
    {
        $this->recording->save($this->recording->getItem('jmonitor.stack.collecting.%node.web-1.system')->set('token of another request'));
        $php = new FakeCollector('php', ['version' => '8.4']);

        $snapshot = $this->read($this->provider(), self::instance([self::spec($php)]));

        self::assertSame(1, $php->collects);
        self::assertSame(ResultState::Fresh, $snapshot->result('php')?->state);
    }

    public function testTheCauseOfAWrappedFailureIsPartOfTheMessage(): void
    {
        $status = new FakeCollector('php.status', bootFailure: new BootFailedException('The status is not readable', new \RuntimeException('Permission denied')));

        $snapshot = $this->read($this->provider(), self::instance([self::spec($status)]));

        self::assertSame(ResultState::Failed, $snapshot->result('php.status')?->state);
        self::assertSame('The status is not readable (Permission denied)', $snapshot->result('php.status')->error);
        self::assertSame(['php.status: The status is not readable (Permission denied)'], $snapshot->errors());
    }

    public function testAKeptPayloadOfUnknownShapeIsReadAgain(): void
    {
        $status = new FakeCollector('php.status', ['requests' => 100]);
        $this->recording->save($this->recording->getItem('jmonitor.stack.result.%node.web-1.php.php.status')->set(['payload' => 'from another version of the bundle']));
        $this->recording->save($this->recording->getItem('jmonitor.stack.failure.%node.web-1.php.php.status')->set(['error' => 42]));
        $this->recording->save($this->recording->getItem('jmonitor.stack.forgotten.php')->set('yesterday'));

        $snapshot = $this->read($this->provider(), self::instance([self::spec($status)]));

        self::assertSame(1, $status->collects);
        self::assertSame(ResultState::Fresh, $snapshot->result('php.status')?->state);
        self::assertSame(['requests' => 100], $snapshot->data('php.status'));
    }

    public function testAnUnavailableInstanceGivesAnEmptySnapshotWithItsReason(): void
    {
        $snapshot = $this->read($this->provider(), Instance::unavailable(Component::System, 'Server metrics are only read on Linux.'));

        self::assertSame(Component::System, $snapshot->component);
        self::assertSame([], $snapshot->results);
        self::assertSame('Server metrics are only read on Linux.', $snapshot->error);
        self::assertSame('web-1', $snapshot->node, 'the web server that answered');
        self::assertSame(0, $this->cache->roundTrips());
    }

    public function testTwoInstancesOfAComponentHaveSeparateEntriesAndDeltas(): void
    {
        $main = new FakeCollector('symfony.status', ['messages' => 100]);
        $legacy = new FakeCollector('symfony.status', ['messages' => 5000]);
        $mainInstance = self::instance([self::spec($main, trackDelta: true)], 'main', Component::Symfony);
        $legacyInstance = self::instance([self::spec($legacy, trackDelta: true)], 'legacy', Component::Symfony);
        $provider = $this->provider();

        $this->read($provider, $mainInstance);
        $this->clock->sleep(11);
        $snapshot = $this->read($provider, $legacyInstance);

        self::assertSame(['messages' => 5000], $snapshot->data('symfony.status'));
        self::assertNull($snapshot->delta('symfony.status'), 'the sample of the other instance is not its reference');
        self::assertArrayHasKey('jmonitor.stack.result.%node.web-1.symfony.main.symfony.status', $this->recording->ttls);
        self::assertArrayHasKey('jmonitor.stack.result.%node.web-1.symfony.legacy.symfony.status', $this->recording->ttls);
        self::assertSame(1, $main->collects);
        self::assertSame(1, $legacy->collects);
    }

    public function testForgettingAnInstanceLeavesTheOthers(): void
    {
        $main = new FakeCollector('symfony.status', ['messages' => 100]);
        $legacy = new FakeCollector('symfony.status', ['messages' => 5000]);
        $mainInstance = self::instance([self::spec($main)], 'main', Component::Symfony);
        $legacyInstance = self::instance([self::spec($legacy)], 'legacy', Component::Symfony);
        $provider = $this->provider();

        $provider->read([$mainInstance, $legacyInstance], CollectBudget::page($this->clock), CacheState::Working);
        $provider->forget([$legacyInstance]);
        $provider->read([$mainInstance, $legacyInstance], CollectBudget::page($this->clock), CacheState::Working);

        self::assertSame(1, $main->collects);
        self::assertSame(2, $legacy->collects);
    }

    public function testEachWebServerKeepsItsOwnValuesOfALocalComponent(): void
    {
        $php = new FakeCollector('php', ['requests' => 100]);
        $instance = self::instance([self::spec($php, trackDelta: true)]);
        $web1 = $this->provider('web-1');
        $web2 = $this->provider('web-2');

        $this->read($web1, $instance);
        $php->setData(['requests' => 5000]);
        $fromWeb2 = $this->read($web2, $instance);
        $this->clock->sleep(11);
        $php->setData(['requests' => 160]);
        $fromWeb1 = $this->read($web1, $instance);

        self::assertSame(3, $php->collects, 'each server collects its own values');
        self::assertSame(['requests' => 5000], $fromWeb2->data('php'));
        self::assertSame('web-2', $fromWeb2->node);
        self::assertSame(60, $fromWeb1->delta('php')?->getValue('requests'), 'compared with the previous sample of the same server');
        self::assertTrue($this->recording->hasItem('jmonitor.stack.previous.%node.web-2.php.php'));
    }

    public function testAWebServerCollectingALocalComponentLeavesTheOthersFree(): void
    {
        $this->recording->save($this->recording->getItem('jmonitor.stack.collecting.%node.web-1.php')->set('token of web-1'));
        $php = new FakeCollector('php', ['version' => '8.4']);

        $snapshot = $this->read($this->provider('web-2'), self::instance([self::spec($php)]));

        self::assertSame(1, $php->collects);
        self::assertFalse($snapshot->isReading());
    }

    public function testAServerWithoutNameKeepsTheUnprefixedKeys(): void
    {
        $snapshot = $this->read($this->provider(''), self::instance([self::spec(new FakeCollector('php', ['version' => '8.4']))]));

        self::assertArrayHasKey('jmonitor.stack.result.php.php', $this->recording->ttls);
        self::assertArrayHasKey('jmonitor.stack.collecting.php', $this->recording->ttls);
        self::assertNull($snapshot->node);
    }

    public function testAForgetOnOneWebServerOutdatesTheValuesOfTheOthers(): void
    {
        $php = new FakeCollector('php', ['version' => '8.4']);
        $endOfLife = new FakeCollector('eol.php', ['cycles' => []]);
        $instance = self::instance([self::spec($php, Lifetime::Static), self::spec($endOfLife, Lifetime::Day)]);
        $web1 = $this->provider('web-1');
        $web2 = $this->provider('web-2');
        $this->read($web1, $instance);
        $this->read($web2, $instance);
        $this->recording->save($this->recording->getItem('jmonitor.stack.collecting.%node.web-1.php')->set('token of a dead request'));
        $this->clock->sleep(5);

        $web1->forget([$instance]);
        $php->setData(['version' => '8.5']);
        $fromWeb2 = $this->read($web2, $instance);
        $this->read($web2, $instance);

        self::assertFalse($this->recording->hasItem('jmonitor.stack.result.%node.web-1.php.php'));
        self::assertFalse($this->recording->hasItem('jmonitor.stack.collecting.%node.web-1.php'));
        self::assertSame(self::START + 5, $this->recording->getItem('jmonitor.stack.forgotten.php')->get());
        self::assertSame(604800, $this->recording->ttls['jmonitor.stack.forgotten.php']);
        self::assertSame(['version' => '8.5'], $fromWeb2->data('php'), 'collected again, the value of web-2 predates the forget');
        self::assertSame(3, $php->collects, 'and kept once collected after it');
        self::assertSame(2, $endOfLife->collects, 'a day-long value is not forgotten');
    }

    public function testTheSetIsReleasedOnceCollected(): void
    {
        $releases = 0;
        $release = static function () use (&$releases): void {
            ++$releases;
        };
        $instance = Instance::found(Component::Php, new CollectorSet(Component::Php, [self::spec(new FakeCollector('php.status'))], $release));
        $provider = $this->provider();

        $this->read($provider, $instance);

        self::assertSame(1, $releases);

        $this->read($provider, $instance);

        self::assertSame(1, $releases, 'nothing ran: nothing to release');
    }

    public function testASetThatCannotBeBuiltGivesItsErrorForAMinute(): void
    {
        $builds = 0;
        $build = static function () use (&$builds): CollectorSet {
            ++$builds;

            throw new \RuntimeException('Cannot reach redis://app:s3cret@cache');
        };
        $instance = static fn(): Instance => Instance::found(Component::Symfony, CollectorSet::lazy(Component::Symfony, $build), 'main');
        $provider = $this->provider();

        $snapshot = $this->read($provider, $instance());
        $this->clock->sleep(59);
        $again = $this->read($provider, $instance());

        self::assertSame('Cannot reach redis://cache', $snapshot->error);
        self::assertSame([], $snapshot->results);
        self::assertSame('Cannot reach redis://cache', $again->error);
        self::assertSame(1, $builds, 'building may wait for a server');
        self::assertSame(60, $this->recording->ttls['jmonitor.stack.build.%node.web-1.symfony.main']);

        $this->clock->sleep(1);
        $this->read($provider, $instance());

        self::assertSame(2, $builds);

        $provider->forget([$instance()]);
        $this->read($provider, $instance());

        self::assertSame(3, $builds, 'a forget forgets the failed build too');
    }

    public function testASetThatCannotBeBuiltLeavesTheOtherComponentsAlone(): void
    {
        $provider = $this->provider();
        $php = Instance::found(Component::Php, CollectorSet::lazy(Component::Php, static fn(): CollectorSet => throw new \RuntimeException('Cannot build')));
        $system = Instance::found(Component::System, CollectorSet::lazy(Component::System, static fn(): CollectorSet => new CollectorSet(Component::System, [self::spec(new FakeCollector('system', ['load' => 1]))])));

        $snapshots = $provider->read([$php, $system], CollectBudget::page($this->clock), CacheState::Working);

        self::assertSame(['php', 'system'], array_keys($snapshots));
        self::assertSame('Cannot build', $snapshots['php']->error);
        self::assertNull($snapshots['system']->error);
        self::assertSame(['load' => 1], $snapshots['system']->data('system'));
    }

    public function testAnOutdatedValueStillShowsWhileAnotherRequestRenewsIt(): void
    {
        $status = new FakeCollector('php.status', ['requests' => 100]);
        $instance = self::instance([self::spec($status)]);
        $provider = $this->provider();
        $this->read($provider, $instance);
        $this->clock->sleep(15);
        $this->recording->save($this->recording->getItem('jmonitor.stack.collecting.%node.web-1.php')->set('token of another request'));

        $snapshot = $this->read($provider, $instance);

        self::assertSame(1, $status->collects);
        self::assertSame(ResultState::Stale, $snapshot->result('php.status')?->state);
        self::assertSame(self::START, $snapshot->result('php.status')->collectedAt);
        self::assertNull($snapshot->result('php.status')->error);
        self::assertSame(['requests' => 100], $snapshot->data('php.status'));
        self::assertFalse($snapshot->isReading());
    }

    public function testAnOutdatedDynamicValueShowsForFiveMinutesThenDisappears(): void
    {
        $provider = $this->provider();
        $this->read($provider, self::instance([self::spec(new FakeCollector('php.status', ['requests' => 100]))]));
        $failing = new FakeCollector('php.status', collectFailure: new \RuntimeException('Connection refused'));
        $instance = self::instance([self::spec($failing)]);

        $this->clock->sleep(299);
        $outdated = $this->read($provider, $instance);
        $this->clock->sleep(1);
        $gone = $this->read($provider, $instance);

        self::assertSame(ResultState::Stale, $outdated->result('php.status')?->state);
        self::assertSame('Connection refused', $outdated->result('php.status')->error);
        self::assertSame(['requests' => 100], $outdated->data('php.status'));
        self::assertSame(['php.status: Connection refused'], $outdated->errors());
        self::assertSame(ResultState::Failed, $gone->result('php.status')?->state);
        self::assertSame('Connection refused', $gone->result('php.status')->error);
        self::assertSame([], $gone->data('php.status'));
        self::assertSame(1, $failing->collects, 'the failure is kept');
    }

    public function testAnOutdatedStaticValueShowsUntilItIsReplaced(): void
    {
        $provider = $this->provider();
        $this->read($provider, self::instance([self::spec(new FakeCollector('php.version', ['version' => '8.4']), Lifetime::Static)]));
        $failing = new FakeCollector('php.version', collectFailure: new \RuntimeException('Permission denied'));

        $this->clock->sleep(2 * 86400);
        $snapshot = $this->read($provider, self::instance([self::spec($failing, Lifetime::Static)]));
        $this->clock->sleep(1);
        $kept = $this->read($provider, self::instance([self::spec($failing, Lifetime::Static)]));

        self::assertSame(1, $failing->collects);

        foreach ([$snapshot, $kept] as $shown) {
            self::assertSame(ResultState::Stale, $shown->result('php.version')?->state);
            self::assertSame('Permission denied', $shown->result('php.version')->error);
            self::assertSame(['version' => '8.4'], $shown->data('php.version'));
        }
    }

    /**
     * @return iterable<string, array{CostClass, \Throwable, int}>
     */
    public static function failures(): iterable
    {
        yield 'immediate' => [CostClass::Immediate, new \RuntimeException('Permission denied'), 10];
        yield 'network' => [CostClass::Network, new \RuntimeException('Access denied'), 10];
        yield 'unreachable server' => [CostClass::Network, new BootFailedException('Not readable', new UnreachableServerException('Connection timed out')), 60];
        yield 'slow' => [CostClass::Slow, new \RuntimeException('Timed out'), 600];
        yield 'missing prerequisite' => [CostClass::Network, new MissingPrerequisiteException('No status page here'), 3600];
        yield 'missing prerequisite behind an unreachable server' => [CostClass::Slow, new UnreachableServerException('Not readable', 0, new MissingPrerequisiteException('composer: not found')), 3600];
    }

    #[DataProvider('failures')]
    public function testAFailureIsKeptAsLongAsRetryingCosts(CostClass $cost, \Throwable $failure, int $kept): void
    {
        $collector = new FakeCollector('php.status', collectFailure: $failure);
        $instance = self::instance([self::spec($collector, cost: $cost)]);
        $provider = $this->provider();

        $this->read($provider, $instance);
        $this->clock->sleep($kept - 1);
        $kept1 = $this->read($provider, $instance);

        self::assertSame(1, $collector->collects);
        self::assertSame(ResultState::Failed, $kept1->result('php.status')?->state);
        self::assertSame($kept, $this->recording->ttls['jmonitor.stack.failure.%node.web-1.php.php.status']);

        $this->clock->sleep(1);
        $this->read($provider, $instance);

        self::assertSame(2, $collector->collects);
    }

    public function testAForgetForgetsFailuresAndKeepsDayLongValues(): void
    {
        $failing = new FakeCollector('php.opcache', collectFailure: new MissingPrerequisiteException('OPcache is not loaded'));
        $version = new FakeCollector('php.version', ['version' => '8.4']);
        $endOfLife = new FakeCollector('eol.php', ['cycles' => []]);
        $endOfLifeFailing = new FakeCollector('eol.symfony', collectFailure: new \RuntimeException('Timed out'));
        $instance = self::instance([
            self::spec($failing),
            self::spec($version, Lifetime::Static),
            self::spec($endOfLife, Lifetime::Day),
            self::spec($endOfLifeFailing, Lifetime::Day),
        ]);
        $provider = $this->provider();
        $this->read($provider, $instance);
        $this->clock->sleep(1);

        $provider->forget([$instance]);
        $snapshot = $this->read($provider, $instance);

        self::assertSame(2, $failing->collects);
        self::assertSame(2, $version->collects);
        self::assertSame(1, $endOfLife->collects);
        self::assertSame(1, $endOfLifeFailing->collects, 'its failure is kept too');
        self::assertSame(ResultState::Fresh, $snapshot->result('eol.php')?->state);
        self::assertSame(ResultState::Failed, $snapshot->result('eol.symfony')?->state);
    }

    public function testASharedCollectorIsReadOnceForEveryWebServer(): void
    {
        $endOfLife = new FakeCollector('eol.php', ['cycles' => []]);
        $php = new FakeCollector('php', ['version' => '8.4']);
        $instance = self::instance([self::spec($endOfLife, Lifetime::Day, shared: true), self::spec($php)]);

        $this->read($this->provider('web-1'), $instance);
        $snapshot = $this->read($this->provider('web-2'), $instance);

        self::assertSame(1, $endOfLife->collects);
        self::assertSame(2, $php->collects);
        self::assertSame(ResultState::Fresh, $snapshot->result('eol.php')?->state);
        self::assertArrayHasKey('jmonitor.stack.result.php.eol.php', $this->recording->ttls);
    }

    public function testImmediateCollectorsOfEveryInstanceRunBeforeAnyNetworkOne(): void
    {
        /** @var \ArrayObject<int, string> $journal */
        $journal = new \ArrayObject();
        $main = self::instance([
            self::spec(new FakeCollector('main.slow', journal: $journal), cost: CostClass::Slow),
            self::spec(new FakeCollector('main.network', journal: $journal), cost: CostClass::Network),
            self::spec(new FakeCollector('main.immediate', journal: $journal)),
            self::spec(new FakeCollector('main.network2', journal: $journal), cost: CostClass::Network),
        ], 'main', Component::Symfony);
        $legacy = self::instance([
            self::spec(new FakeCollector('legacy.network', journal: $journal), cost: CostClass::Network),
            self::spec(new FakeCollector('legacy.slow', journal: $journal), cost: CostClass::Slow),
            self::spec(new FakeCollector('legacy.immediate', journal: $journal)),
        ], 'legacy', Component::Symfony);

        $this->provider()->read([$main, $legacy], CollectBudget::fragment($this->clock), CacheState::Working);

        self::assertSame(
            ['main.immediate', 'legacy.immediate', 'main.network', 'main.network2', 'legacy.network', 'main.slow', 'legacy.slow'],
            $journal->getArrayCopy(),
        );
    }

    public function testNoCollectorStartsOnceASecondIsSpent(): void
    {
        $first = new FakeCollector('php.first', ['a' => 1], clock: $this->clock, duration: 0.6);
        $second = new FakeCollector('php.second', ['a' => 1], clock: $this->clock, duration: 0.6);
        $third = new FakeCollector('php.third', ['a' => 1], clock: $this->clock, duration: 0.6);
        $instance = self::instance([
            self::spec($first, cost: CostClass::Network),
            self::spec($second, cost: CostClass::Network),
            self::spec($third, cost: CostClass::Network),
        ]);

        $snapshot = $this->read($this->provider(), $instance, CollectBudget::page($this->clock));

        self::assertSame([1, 1, 0], [$first->collects, $second->collects, $third->collects]);
        self::assertSame(ResultState::Pending, $snapshot->result('php.third')?->state);
        self::assertTrue($snapshot->isReading());
        self::assertFalse($this->recording->hasItem('jmonitor.stack.failure.%node.web-1.php.php.third'), 'no failure is kept for it');
        self::assertFalse($this->recording->hasItem('jmonitor.stack.result.%node.web-1.php.php.third'));
    }

    public function testAPageRunsNoSlowCollector(): void
    {
        $slow = new FakeCollector('php.slow', ['a' => 1]);
        $immediate = new FakeCollector('php', ['a' => 1]);
        $instance = self::instance([self::spec($slow, cost: CostClass::Slow), self::spec($immediate)]);
        $provider = $this->provider();

        $page = $this->read($provider, $instance, CollectBudget::page($this->clock));

        self::assertSame(0, $slow->collects);
        self::assertSame(1, $immediate->collects);
        self::assertSame(ResultState::Pending, $page->result('php.slow')?->state);

        $this->read($provider, $instance, CollectBudget::fragment($this->clock));
        $this->clock->sleep(10);
        $outdated = $this->read($provider, $instance, CollectBudget::page($this->clock));

        self::assertSame(1, $slow->collects);
        self::assertSame(ResultState::Stale, $outdated->result('php.slow')?->state, 'left aside, its last value shows');
        self::assertSame(['a' => 1], $outdated->data('php.slow'));
    }

    public function testAFragmentStartsSlowCollectorsWhileUnderTwoSeconds(): void
    {
        $collectors = [];

        for ($i = 1; $i <= 3; ++$i) {
            $collectors[] = new FakeCollector('php.slow' . $i, ['a' => 1], clock: $this->clock, duration: 1.5);
        }

        $snapshot = $this->read(
            $this->provider(),
            self::instance(array_map(static fn(FakeCollector $collector): CollectorSpec => self::spec($collector, cost: CostClass::Slow), $collectors)),
            CollectBudget::fragment($this->clock),
        );

        self::assertSame([1, 1, 0], array_map(static fn(FakeCollector $collector): int => $collector->collects, $collectors));
        self::assertSame(ResultState::Pending, $snapshot->result('php.slow3')?->state);
    }

    public function testAColdCacheOnlyRunsImmediateCollectors(): void
    {
        $immediate = new FakeCollector('php', ['a' => 1]);
        $network = new FakeCollector('php.network', ['a' => 1]);
        $slow = new FakeCollector('php.slow', ['a' => 1]);
        $instance = self::instance([self::spec($immediate), self::spec($network, cost: CostClass::Network), self::spec($slow, cost: CostClass::Slow)]);

        $snapshot = $this->read($this->provider(), $instance, CollectBudget::fragment($this->clock), CacheState::Cold);

        self::assertSame([1, 0, 0], [$immediate->collects, $network->collects, $slow->collects]);
        self::assertSame(ResultState::Fresh, $snapshot->result('php')?->state);
        self::assertSame(ResultState::Pending, $snapshot->result('php.network')?->state);
        self::assertSame(ResultState::Pending, $snapshot->result('php.slow')?->state);
        self::assertTrue($this->recording->hasItem('jmonitor.stack.result.%node.web-1.php.php'), 'a cold cache is written');
    }

    public function testACacheOutOfOrderOnlyRunsImmediateCollectorsAndWritesNothing(): void
    {
        $immediate = new FakeCollector('php', ['requests' => 1]);
        $network = new FakeCollector('php.network', ['a' => 1]);
        $instance = self::instance([self::spec($immediate, trackDelta: true), self::spec($network, cost: CostClass::Network)]);
        $provider = $this->provider();

        $snapshot = $this->read($provider, $instance, CollectBudget::fragment($this->clock), CacheState::OutOfOrder);
        $this->clock->sleep(1);
        $again = $this->read($provider, $instance, CollectBudget::fragment($this->clock), CacheState::OutOfOrder);

        self::assertSame([2, 0], [$immediate->collects, $network->collects]);
        self::assertSame(ResultState::Fresh, $snapshot->result('php')?->state);
        self::assertSame(['requests' => 1], $snapshot->data('php'));
        self::assertNull($again->delta('php'));
        self::assertSame(ResultState::Pending, $snapshot->result('php.network')?->state);
        self::assertSame(0, $this->cache->roundTrips(), 'neither read, witness nor write');
    }

    public function testAWarmInstanceCostsOneRoundTrip(): void
    {
        $instance = self::instance([
            self::spec(new FakeCollector('php', ['requests' => 1]), trackDelta: true),
            self::spec(new FakeCollector('php.version', ['version' => '8.4']), Lifetime::Static),
            self::spec(new FakeCollector('eol.php', ['cycles' => []]), Lifetime::Day, CostClass::Slow, shared: true),
        ]);
        $provider = $this->provider();
        $this->read($provider, $instance);
        $this->cache->reset();

        $this->read($provider, $instance);

        self::assertSame(['getItems' => 1], $this->cache->calls);
    }

    public function testAWarmLazyInstanceCostsTwoRoundTrips(): void
    {
        $instance = static fn(): Instance => Instance::found(Component::Php, CollectorSet::lazy(
            Component::Php,
            static fn(): CollectorSet => new CollectorSet(Component::Php, [self::spec(new FakeCollector('php', ['requests' => 1]))]),
        ));
        $provider = $this->provider();
        $this->read($provider, $instance());
        $this->cache->reset();

        $this->read($provider, $instance());

        self::assertSame(['getItem' => 1, 'getItems' => 1], $this->cache->calls, 'the failed build it may have left, then its values');
    }

    public function testACollectedInstanceWritesInOneCommit(): void
    {
        $instance = self::instance([
            self::spec(new FakeCollector('php', ['requests' => 1])),
            self::spec(new FakeCollector('php.version', ['version' => '8.4']), Lifetime::Static),
            self::spec(new FakeCollector('php.opcache', collectFailure: new \RuntimeException('OPcache is not loaded'))),
        ]);

        $this->read($this->provider(), $instance);

        self::assertSame(['getItems' => 1, 'save' => 1, 'commit' => 1, 'getItem' => 1, 'deleteItem' => 1], $this->cache->calls, 'read, witness, writes, witness lifted');
        self::assertTrue($this->recording->hasItem('jmonitor.stack.result.%node.web-1.php.php'));
        self::assertTrue($this->recording->hasItem('jmonitor.stack.result.%node.web-1.php.php.version'));
        self::assertTrue($this->recording->hasItem('jmonitor.stack.failure.%node.web-1.php.php.opcache'));
    }

    public function testAValueDatedInTheFutureIsFreshAndZeroSecondsOld(): void
    {
        $endOfLife = new FakeCollector('eol.php', ['cycles' => []]);
        $instance = self::instance([self::spec($endOfLife, shared: true)]);
        $ahead = new MockClock('@' . (self::START + 30));
        $web2 = new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($this->cache, 10), $this->cache, $ahead, new WebNode('web-2'));
        $web2->read([$instance], CollectBudget::page($ahead), CacheState::Working);

        $snapshot = $this->read($this->provider('web-1'), $instance);

        self::assertSame(1, $endOfLife->collects);
        self::assertSame(ResultState::Fresh, $snapshot->result('eol.php')?->state);
        self::assertSame(self::START, $snapshot->result('eol.php')->collectedAt);
        self::assertSame(0, $snapshot->freshness(self::START, 'eol.php')?->age());
    }

    public function testCredentialsOfAFailureAreNeitherKeptNorReturned(): void
    {
        $failure = new \RuntimeException('Cannot reach redis://app:s3cret@cache', 0, new \RuntimeException('Auth failed for mysql://root@db/x'));
        $instance = self::instance([self::spec(new FakeCollector('php.status', collectFailure: $failure))]);

        $snapshot = $this->read($this->provider(), $instance);
        $kept = serialize($this->recording->getItem('jmonitor.stack.failure.%node.web-1.php.php.status')->get());

        self::assertSame('Cannot reach redis://cache (Auth failed for mysql://db/x)', $snapshot->result('php.status')?->error);
        self::assertStringContainsString('Cannot reach redis://cache', $kept);

        foreach (['s3cret', 'app', 'root'] as $secret) {
            self::assertStringNotContainsString($secret, $kept);
        }
    }

    public function testNothingAThrowingCollectorOrPoolDoesGetsOut(): void
    {
        $pool = new ThrowingCachePool();
        $logger = new BufferingLogger();
        $instance = self::instance([
            self::spec(new FakeCollector('php.broken', collectFailure: new \Error('Call to undefined function opcache_get_status()'))),
            self::spec(new FakeCollector('php', ['requests' => 1]), trackDelta: true),
            self::spec(new FakeCollector('php.network', ['a' => 1]), cost: CostClass::Network),
        ]);
        $provider = $this->provider(logger: $logger, cache: $pool);

        $snapshot = $this->read($provider, $instance);
        $logs = $logger->cleanLogs();

        self::assertSame(ResultState::Failed, $snapshot->result('php.broken')?->state);
        self::assertSame('Call to undefined function opcache_get_status()', $snapshot->result('php.broken')->error);
        self::assertSame(ResultState::Fresh, $snapshot->result('php')?->state);
        self::assertSame(['requests' => 1], $snapshot->data('php'));
        self::assertSame(ResultState::Pending, $snapshot->result('php.network')?->state, 'the rest of the read goes on without the cache');
        self::assertCount(1, $logs);
        self::assertSame('error', $logs[0][0]);
        self::assertSame(1, $pool->calls);

        $provider->forget([$instance]);

        self::assertNotSame([], $logger->cleanLogs());
    }

    public function testASetThatCannotBeReleasedGetsNothingOut(): void
    {
        $logger = new BufferingLogger();
        $release = static fn(): never => throw new \RuntimeException('Connection already closed');
        $instance = Instance::found(Component::Php, new CollectorSet(Component::Php, [self::spec(new FakeCollector('php', ['a' => 1]))], $release));

        $snapshot = $this->read($this->provider(logger: $logger), $instance);
        $logs = $logger->cleanLogs();

        self::assertSame(['a' => 1], $snapshot->data('php'));
        self::assertCount(1, $logs);
        self::assertSame('error', $logs[0][0]);
    }

    /**
     * @param list<CollectorSpec> $specs
     */
    private static function instance(array $specs, ?string $id = null, Component $component = Component::Php): Instance
    {
        return Instance::found($component, new CollectorSet($component, $specs), $id);
    }

    private static function spec(
        FakeCollector $collector,
        Lifetime $lifetime = Lifetime::Dynamic,
        CostClass $cost = CostClass::Immediate,
        bool $trackDelta = false,
        bool $shared = false,
    ): CollectorSpec {
        return new CollectorSpec($collector, $lifetime, $cost, $trackDelta, $shared);
    }

    private function provider(string $node = 'web-1', ?LoggerInterface $logger = null, ?CacheItemPoolInterface $cache = null): SnapshotProvider
    {
        $cache ??= $this->cache;

        return new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($cache, 10), $cache, $this->clock, new WebNode($node), $logger);
    }

    private function read(SnapshotProvider $provider, Instance $instance, ?CollectBudget $budget = null, CacheState $cache = CacheState::Working): ComponentSnapshot
    {
        return $provider->read([$instance], $budget ?? CollectBudget::fragment($this->clock), $cache)[$instance->key()];
    }
}
