<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Snapshot;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Stack\Detection\Server;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectBudget;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\DeltaCalculator;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\BreakingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Cache\RecordingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeCollector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\RefusingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\ThrowingCachePool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Random\Engine;
use Random\RandomException;
use Random\Randomizer;
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

    public function testTheWitnessOfAnInstanceOfSharedCollectorsCarriesNoServerName(): void
    {
        $witness = 'jmonitor.stack.collecting.symfony.queues';
        $queues = new FakeCollector('queues', ['count' => 4]);
        $instance = self::instance([self::spec($queues, Lifetime::HalfMinute, CostClass::Slow, shared: true)], 'queues', Component::Symfony);

        $this->read($this->provider('web-1'), $instance);

        self::assertSame(300, $this->recording->ttls[$witness] ?? null);
        self::assertArrayNotHasKey('jmonitor.stack.collecting.%node.web-1.symfony.queues', $this->recording->ttls);

        $this->recording->save($this->recording->getItem($witness)->set('token of web-1'));
        $this->recording->deleteItem('jmonitor.stack.result.symfony.%shared.queues');
        $busy = $this->read($this->provider('web-2'), $instance);

        self::assertSame(1, $queues->collects, 'one count behind a load balancer');
        self::assertSame(ResultState::Busy, $busy->result('queues')?->state);
    }

    public function testTheWitnessOfAMixedInstanceStillCarriesIt(): void
    {
        $instance = self::instance([
            self::spec(new FakeCollector('eol.php', ['cycles' => []]), Lifetime::Day, CostClass::Slow, shared: true),
            self::spec(new FakeCollector('php', ['version' => '8.4'])),
        ]);

        $this->read($this->provider('web-1'), $instance);

        self::assertSame(300, $this->recording->ttls['jmonitor.stack.collecting.%node.web-1.php'] ?? null);
        self::assertArrayNotHasKey('jmonitor.stack.collecting.php', $this->recording->ttls);
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

    public function testTwoInstancesWhoseNamesOnlyDifferByADotNeverShareAValue(): void
    {
        $first = new FakeCollector('b.c', ['from' => 'a']);
        $second = new FakeCollector('c', ['from' => 'a.b']);
        $instances = [
            self::instance([self::spec($first)], 'a', Component::Symfony),
            self::instance([self::spec($second)], 'a.b', Component::Symfony),
        ];
        $provider = $this->provider();

        $provider->read($instances, CollectBudget::fragment($this->clock), CacheState::Working);
        $this->clock->sleep(1);
        $snapshots = $provider->read($instances, CollectBudget::fragment($this->clock), CacheState::Working);

        self::assertSame([1, 1], [$first->collects, $second->collects], 'both read again from the cache');
        self::assertSame(['from' => 'a'], $snapshots['symfony.a']->data('b.c'));
        self::assertSame(['from' => 'a.b'], $snapshots['symfony.a%2Eb']->data('c'));
    }

    public function testAPerServerValueIsSharedByTheInstancesOfTheServer(): void
    {
        $server = new Server('db.internal:3306', 'db.internal:3306');
        $versions = [new FakeCollector('symfony.version', ['version' => '8.4']), new FakeCollector('symfony.version', ['version' => '8.4'])];
        $statuses = [new FakeCollector('symfony.status', ['n' => 1]), new FakeCollector('symfony.status', ['n' => 2])];
        $main = self::instance([self::spec($versions[0], perServer: true), self::spec($statuses[0])], 'main', Component::Symfony, $server);
        $replica = self::instance([self::spec($versions[1], perServer: true), self::spec($statuses[1])], 'replica', Component::Symfony, $server);
        $provider = $this->provider();

        $snapshots = $provider->read([$main, $replica], CollectBudget::fragment($this->clock), CacheState::Working);

        self::assertSame([1, 0], [$versions[0]->collects, $versions[1]->collects]);
        self::assertSame([1, 1], [$statuses[0]->collects, $statuses[1]->collects]);

        foreach (['symfony.main', 'symfony.replica'] as $key) {
            self::assertSame(ResultState::Fresh, $snapshots[$key]->result('symfony.version')?->state, $key);
            self::assertSame(['version' => '8.4'], $snapshots[$key]->data('symfony.version'), $key);
        }

        self::assertSame(['n' => 2], $snapshots['symfony.replica']->data('symfony.status'));
        self::assertArrayHasKey('jmonitor.stack.result.%node.web-1.symfony.%server.db%2Einternal%3A3306.symfony.version', $this->recording->ttls);

        $this->clock->sleep(1);
        $alone = $this->read($provider, $replica);

        self::assertSame([1, 0], [$versions[0]->collects, $versions[1]->collects], 'read from the cache');
        self::assertSame(['version' => '8.4'], $alone->data('symfony.version'));
    }

    public function testAfterAForgetAPerServerValueIsStillSharedByTheInstancesOfTheServer(): void
    {
        $server = new Server('db.internal:3306', 'db.internal:3306');
        $collectors = [new FakeCollector('symfony.version', ['version' => '8.4']), new FakeCollector('symfony.version', ['version' => '8.4'])];
        $instances = [
            self::instance([self::spec($collectors[0], Lifetime::Static, perServer: true)], 'main', Component::Symfony, $server),
            self::instance([self::spec($collectors[1], Lifetime::Static, perServer: true)], 'replica', Component::Symfony, $server),
        ];
        $provider = $this->provider();

        $provider->forget($instances);

        for ($i = 0; $i < 5; ++$i) {
            $snapshots = $provider->read($instances, CollectBudget::fragment($this->clock), CacheState::Working);

            foreach (['symfony.main', 'symfony.replica'] as $key) {
                self::assertSame(['version' => '8.4'], $snapshots[$key]->data('symfony.version'), $key);
            }

            $this->clock->sleep(1);
        }

        self::assertSame(1, $collectors[0]->collects + $collectors[1]->collects);
    }

    public function testAfterAForgetAnUnreachableServerIsAskedOnceForEachOfItsInstances(): void
    {
        $server = new Server('db.internal:3306', 'db.internal:3306');
        $collectors = [];
        $instances = [];

        foreach (['main', 'replica'] as $id) {
            $collectors[] = $collector = new FakeCollector('symfony.status', collectFailure: new UnreachableServerException('Connection timed out'), clock: $this->clock, duration: 30.0);
            $instances[] = self::instance([self::spec($collector, cost: CostClass::Network, perServer: true)], $id, Component::Symfony, $server);
        }

        $provider = $this->provider();

        $provider->forget($instances);
        $snapshots = [];

        for ($i = 0; $i < 5; ++$i) {
            $snapshots = $provider->read($instances, CollectBudget::fragment($this->clock), CacheState::Working);
            $this->clock->sleep(1);
        }

        foreach (['symfony.main', 'symfony.replica'] as $key) {
            self::assertSame(FailureKind::Unreachable, $snapshots[$key]->result('symfony.status')?->failure, $key);
        }

        self::assertSame([1, 1], [$collectors[0]->collects, $collectors[1]->collects], 'one wait for each instance, each kept 150 s');
    }

    public function testForgettingOneInstanceOfAServerKeepsTheValueShared(): void
    {
        $server = new Server('db.internal:3306', 'db.internal:3306');
        $collectors = [new FakeCollector('symfony.version', ['version' => '8.4']), new FakeCollector('symfony.version', ['version' => '8.5'])];
        $instances = [
            self::instance([self::spec($collectors[0], Lifetime::Static, perServer: true)], 'main', Component::Symfony, $server),
            self::instance([self::spec($collectors[1], Lifetime::Static, perServer: true)], 'replica', Component::Symfony, $server),
        ];
        $provider = $this->provider();
        $provider->read($instances, CollectBudget::fragment($this->clock), CacheState::Working);

        $provider->forget([$instances[1]]);

        for ($i = 0; $i < 5; ++$i) {
            $this->clock->sleep(1);
            $snapshots = $provider->read($instances, CollectBudget::fragment($this->clock), CacheState::Working);
        }

        self::assertSame(2, $collectors[0]->collects + $collectors[1]->collects, 'once before the forget, once after');
        self::assertSame($snapshots['symfony.main']->data('symfony.version'), $snapshots['symfony.replica']->data('symfony.version'));
    }

    public function testAPerServerValueIsNotSharedAcrossServers(): void
    {
        $first = new FakeCollector('symfony.version', ['version' => '8.4']);
        $second = new FakeCollector('symfony.version', ['version' => '5.7']);
        $main = self::instance([self::spec($first, perServer: true)], 'main', Component::Symfony, new Server('db-1:3306', 'db-1:3306'));
        $legacy = self::instance([self::spec($second, perServer: true)], 'legacy', Component::Symfony, new Server('db-2:3306', 'db-2:3306'));
        $provider = $this->provider();

        $provider->read([$main, $legacy], CollectBudget::fragment($this->clock), CacheState::Working);
        $this->clock->sleep(1);
        $snapshots = $provider->read([$main, $legacy], CollectBudget::fragment($this->clock), CacheState::Working);

        self::assertSame([1, 1], [$first->collects, $second->collects]);
        self::assertSame(['version' => '8.4'], $snapshots['symfony.main']->data('symfony.version'));
        self::assertSame(['version' => '5.7'], $snapshots['symfony.legacy']->data('symfony.version'));
    }

    public function testAPerServerRateHasOneReferenceForTheServer(): void
    {
        $server = new Server('db.internal:3306', 'db.internal:3306');
        $first = new FakeCollector('symfony.status', ['queries' => 100]);
        $second = new FakeCollector('symfony.status', ['queries' => 250]);
        $main = self::instance([self::spec($first, trackDelta: true, perServer: true)], 'main', Component::Symfony, $server);
        $replica = self::instance([self::spec($second, trackDelta: true, perServer: true)], 'replica', Component::Symfony, $server);
        $provider = $this->provider();

        $this->read($provider, $main);
        $this->clock->sleep(11);
        $snapshot = $this->read($provider, $replica);

        self::assertSame([1, 1], [$first->collects, $second->collects]);
        self::assertSame(150, $snapshot->delta('symfony.status')?->getValue('queries'), 'compared with the sample read through the other instance');
        self::assertTrue($this->recording->hasItem('jmonitor.stack.previous.%node.web-1.symfony.%server.db%2Einternal%3A3306.symfony.status'));
    }

    public function testADetailOnlyCollectorIsLeftOutOfAnOverviewRead(): void
    {
        $status = new FakeCollector('php.status', ['ok' => true]);
        $tables = new FakeCollector('php.tables', ['count' => 12]);
        $instance = self::instance([self::spec($status), self::spec($tables, cost: CostClass::Slow, detailOnly: true)]);

        $snapshot = $this->read($this->provider(), $instance);

        self::assertSame([1, 0], [$status->collects, $tables->collects]);
        self::assertNull($snapshot->result('php.tables'));
        self::assertSame(['php.status'], array_keys($snapshot->results));
        self::assertFalse($snapshot->isReading());
        self::assertSame([], array_values(array_filter($this->recording->asked, static fn(string $key): bool => str_contains($key, 'php.tables'))));
    }

    public function testADetailReadRunsIt(): void
    {
        $tables = new FakeCollector('php.tables', ['count' => 12]);
        $instance = self::instance([self::spec(new FakeCollector('php.status', ['ok' => true])), self::spec($tables, cost: CostClass::Slow, detailOnly: true)]);
        $provider = $this->provider();

        $detail = $this->read($provider, $instance, detail: true);
        $overview = $this->read($provider, $instance);

        self::assertSame(1, $tables->collects);
        self::assertSame(ResultState::Fresh, $detail->result('php.tables')?->state);
        self::assertSame(['count' => 12], $detail->data('php.tables'));
        self::assertNull($overview->result('php.tables'));
    }

    public function testAForgetDropsItsValueToo(): void
    {
        $tables = new FakeCollector('php.tables', ['count' => 12]);
        $instance = self::instance([self::spec($tables, cost: CostClass::Slow, detailOnly: true)]);
        $provider = $this->provider();
        $this->read($provider, $instance, detail: true);

        $provider->forget([$instance]);

        self::assertFalse($this->recording->hasItem('jmonitor.stack.result.%node.web-1.php.php.tables'));

        $this->read($provider, $instance, detail: true);

        self::assertSame(2, $tables->collects);
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
        self::assertIsString($this->recording->getItem('jmonitor.stack.forgotten.php')->get());
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

    public function testAnOutdatedValueWhoseRenewalFailedSaysWhenItIsRetried(): void
    {
        $provider = $this->provider();
        $this->read($provider, self::instance([self::spec(new FakeCollector('php.version', ['version' => '8.4']), Lifetime::Static, CostClass::Slow)]));
        $failing = new FakeCollector('php.version', collectFailure: new \RuntimeException('Permission denied'));

        $this->clock->sleep(2 * 86400);
        $snapshot = $this->read($provider, self::instance([self::spec($failing, Lifetime::Static, CostClass::Slow)]));

        self::assertSame(ResultState::Stale, $snapshot->result('php.version')?->state);
        self::assertSame(self::START + 2 * 86400 + 600, $snapshot->result('php.version')->expiresAt);
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
     * @return iterable<string, array{0: CostClass, 1: \Throwable, 2: int, 3?: float}> the last one, how long the collector waits before it fails
     */
    public static function failures(): iterable
    {
        yield 'immediate' => [CostClass::Immediate, new \RuntimeException('Permission denied'), 10];
        yield 'network' => [CostClass::Network, new \RuntimeException('Access denied'), 10];
        yield 'unreachable server' => [CostClass::Network, new BootFailedException('Not readable', new UnreachableServerException('Connection timed out')), 60];
        yield 'slow' => [CostClass::Slow, new \RuntimeException('Timed out'), 600];
        yield 'missing prerequisite' => [CostClass::Network, new MissingPrerequisiteException('No status page here'), 3600];
        yield 'missing prerequisite behind an unreachable server' => [CostClass::Slow, new UnreachableServerException('Not readable', 0, new MissingPrerequisiteException('composer: not found')), 3600];
        yield 'network that waits as long as it is kept' => [CostClass::Network, new \RuntimeException('Timed out'), 10, 10.0];
        yield 'unreachable server after its timeout' => [CostClass::Network, new UnreachableServerException('Connection timed out'), 300, 60.0];
    }

    #[DataProvider('failures')]
    public function testAFailureIsKeptAsLongAsRetryingCosts(CostClass $cost, \Throwable $failure, int $kept, float $duration = 0.0): void
    {
        $collector = new FakeCollector('php.status', collectFailure: $failure, clock: $this->clock, duration: $duration);
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

    public function testAFailureKeepsItsKind(): void
    {
        $collector = new FakeCollector('php.status', collectFailure: new BootFailedException('Not readable', new UnreachableServerException('Connection refused')));
        $instance = self::instance([self::spec($collector, cost: CostClass::Network)]);
        $provider = $this->provider();

        $failed = $this->read($provider, $instance);
        $this->clock->sleep(1);
        $kept = $this->read($provider, $instance);

        self::assertSame(1, $collector->collects);

        foreach ([$failed, $kept] as $snapshot) {
            self::assertSame(ResultState::Failed, $snapshot->result('php.status')?->state);
            self::assertSame(FailureKind::Unreachable, $snapshot->result('php.status')->failure);
        }

        self::assertSame('unreachable', $this->recording->getItem('jmonitor.stack.failure.%node.web-1.php.php.status')->get()['kind'] ?? null);
    }

    public function testAnOutdatedValueThatCouldNotBeRenewedTellsTheKind(): void
    {
        $provider = $this->provider();
        $fresh = $this->read($provider, self::instance([self::spec(new FakeCollector('php.status', ['requests' => 100]), cost: CostClass::Network)]));
        $failing = new FakeCollector('php.status', collectFailure: new UnreachableServerException('Connection timed out'));
        $instance = self::instance([self::spec($failing, cost: CostClass::Network)]);

        $this->clock->sleep(15);
        $outdated = $this->read($provider, $instance);
        $this->clock->sleep(1);
        $kept = $this->read($provider, $instance);

        self::assertNull($fresh->result('php.status')?->failure);
        self::assertSame(1, $failing->collects);

        foreach ([$outdated, $kept] as $snapshot) {
            self::assertSame(ResultState::Stale, $snapshot->result('php.status')?->state);
            self::assertSame(['requests' => 100], $snapshot->data('php.status'));
            self::assertSame('Connection timed out', $snapshot->result('php.status')->error);
            self::assertSame(FailureKind::Unreachable, $snapshot->result('php.status')->failure);
        }
    }

    public function testAFailureStoredWithoutAKindIsOther(): void
    {
        $collector = new FakeCollector('php.status', ['requests' => 100]);
        $this->recording->save($this->recording->getItem('jmonitor.stack.failure.%node.web-1.php.php.status')->set(['error' => 'Timed out', 'until' => self::START + 60, 'forgotten' => null]));

        $snapshot = $this->read($this->provider(), self::instance([self::spec($collector, cost: CostClass::Network)]));

        self::assertSame(0, $collector->collects);
        self::assertSame(ResultState::Failed, $snapshot->result('php.status')?->state);
        self::assertSame('Timed out', $snapshot->result('php.status')->error);
        self::assertSame(FailureKind::Other, $snapshot->result('php.status')->failure);
    }

    public function testAnUnreachableServerThatMadeTheReadWaitIsKeptFiveTimesAsLong(): void
    {
        foreach (['quick' => [0.2, 60], 'timed out' => [30.0, 150], 'frozen' => [200.0, 600]] as $id => [$waited, $kept]) {
            $collector = new FakeCollector('symfony.status', collectFailure: new UnreachableServerException('Connection timed out'), clock: $this->clock, duration: $waited);
            $instance = self::instance([self::spec($collector, cost: CostClass::Network)], $id, Component::Symfony);
            $provider = $this->provider();

            $failed = $this->read($provider, $instance);
            $failedAt = $this->clock->now()->getTimestamp();

            self::assertSame($kept, $this->recording->ttls['jmonitor.stack.failure.%node.web-1.' . $instance->key() . '.symfony.status'], $id);
            self::assertSame($failedAt + $kept, $failed->result('symfony.status')?->expiresAt, $id);

            $this->clock->sleep($kept - 1);
            $this->read($provider, $instance);

            self::assertSame(1, $collector->collects, $id . ': not asked again during the wait it made');

            $this->clock->sleep(1);
            $this->read($provider, $instance);

            self::assertSame(2, $collector->collects, $id);
        }
    }

    public function testAnUnreachableRemoteServerIsNotAskedAgainForItsOtherCollectors(): void
    {
        $variables = new FakeCollector('mysql.variables', ['version' => '8.4']);
        $instance = static fn(FakeCollector $status): Instance => self::instance([
            self::spec($status, cost: CostClass::Network),
            self::spec($variables, Lifetime::Static, CostClass::Network),
        ], 'default', Component::Mysql);
        $frozen = new FakeCollector('mysql', collectFailure: new UnreachableServerException('Read timed out'), clock: $this->clock, duration: 10.0);
        $provider = $this->provider();

        $this->read($provider, $instance($frozen), CollectBudget::page($this->clock));
        $failedAt = $this->clock->now()->getTimestamp();
        $this->clock->sleep(1);
        $held = $this->read($provider, $instance($frozen), CollectBudget::page($this->clock));

        self::assertSame([1, 0], [$frozen->collects, $variables->collects]);
        self::assertSame(ResultState::Failed, $held->result('mysql.variables')?->state);
        self::assertSame('Read timed out', $held->result('mysql.variables')->error);
        self::assertSame(FailureKind::Unreachable, $held->result('mysql.variables')->failure);
        self::assertSame($failedAt + 60, $held->result('mysql.variables')->expiresAt);
        self::assertSame($held->result('mysql')?->expiresAt, $held->result('mysql.variables')->expiresAt);
        self::assertFalse($held->isReading());
        self::assertFalse($this->recording->hasItem('jmonitor.stack.failure.mysql.default.mysql.variables'), 'nothing is kept for it');

        $this->clock->sleep($failedAt + 60 - $this->clock->now()->getTimestamp());
        $answering = new FakeCollector('mysql', ['Uptime' => 5]);
        $back = $this->read($provider, $instance($answering), CollectBudget::page($this->clock));

        self::assertSame([1, 1], [$answering->collects, $variables->collects]);
        self::assertSame(ResultState::Fresh, $back->result('mysql.variables')?->state);
    }

    public function testAKeptUnreachableFailureAlsoHoldsTheCollectorsThatHaveAValue(): void
    {
        $variables = new FakeCollector('mysql.variables', ['version' => '8.4']);
        $instance = static fn(FakeCollector $status): Instance => self::instance([
            self::spec($status, cost: CostClass::Network),
            self::spec($variables, cost: CostClass::Network),
        ], 'default', Component::Mysql);
        $frozen = new FakeCollector('mysql', collectFailure: new UnreachableServerException('Read timed out'), clock: $this->clock, duration: 10.0);
        $provider = $this->provider();
        $this->read($provider, $instance(new FakeCollector('mysql', ['Uptime' => 5])));
        $this->clock->sleep(11);

        $this->read($provider, $instance($frozen));
        $this->clock->sleep(1);
        $held = $this->read($provider, $instance($frozen));

        self::assertSame([1, 1], [$frozen->collects, $variables->collects]);
        self::assertSame(ResultState::Stale, $held->result('mysql.variables')?->state);
        self::assertSame(['version' => '8.4'], $held->data('mysql.variables'));
        self::assertSame('Read timed out', $held->result('mysql.variables')->error);
        self::assertSame(FailureKind::Unreachable, $held->result('mysql.variables')->failure);
        self::assertSame($held->result('mysql')?->expiresAt, $held->result('mysql.variables')->expiresAt);
    }

    public function testAFailedDetailCollectorDoesNotHoldTheMainCollector(): void
    {
        $status = new FakeCollector('mysql', ['Uptime' => 5]);
        $sizes = new FakeCollector('mysql.sizes', collectFailure: new UnreachableServerException('Read timed out'));
        $slowQueries = new FakeCollector('mysql.slow_queries', ['queries' => []]);
        $instance = self::instance([
            self::spec($status, cost: CostClass::Network),
            self::spec($sizes, cost: CostClass::Network, detailOnly: true),
            self::spec($slowQueries, cost: CostClass::Slow, detailOnly: true),
        ], 'default', Component::Mysql);
        $provider = $this->provider();
        $this->read($provider, $instance, detail: true);
        $this->clock->sleep(11);

        $detail = $this->read($provider, $instance, detail: true);

        self::assertSame([2, 1, 0], [$status->collects, $sizes->collects, $slowQueries->collects]);
        self::assertSame(ResultState::Fresh, $detail->result('mysql')?->state);
        self::assertNull($detail->result('mysql')->failure);
        self::assertNull($detail->result('mysql')->error);
        self::assertSame(FailureKind::Unreachable, $detail->result('mysql.sizes')?->failure);
        self::assertSame(ResultState::Failed, $detail->result('mysql.slow_queries')?->state, 'another collector of the detail is still held');
        self::assertSame(FailureKind::Unreachable, $detail->result('mysql.slow_queries')->failure);
    }

    public function testAConnectionThatCannotBeOpenedHoldsNeitherShowsForTheOtherConnectionsOfItsServer(): void
    {
        $server = new Server('db.internal:3306', 'db.internal:3306');
        $refused = new FakeCollector('mysql', collectFailure: new UnreachableServerException('SQLSTATE[HY000] [1045] Access denied'));
        $answering = new FakeCollector('mysql', ['Uptime' => 5]);
        $sizes = [new FakeCollector('mysql.sizes', ['tables' => 3]), new FakeCollector('mysql.sizes', ['tables' => 4])];
        $main = self::instance([self::spec($refused, cost: CostClass::Network, perServer: true), self::spec($sizes[0], cost: CostClass::Network)], 'main', Component::Mysql, $server);
        $reporting = self::instance([self::spec($answering, cost: CostClass::Network, perServer: true), self::spec($sizes[1], cost: CostClass::Network)], 'reporting', Component::Mysql, $server);
        $provider = $this->provider();

        $this->read($provider, $main);
        $this->clock->sleep(1);
        $other = $this->read($provider, $reporting);
        $this->clock->sleep(1);
        $again = $this->read($provider, $main);

        self::assertSame([1, 1], [$refused->collects, $answering->collects]);
        self::assertSame([0, 1], [$sizes[0]->collects, $sizes[1]->collects]);
        self::assertSame(ResultState::Fresh, $other->result('mysql')?->state);
        self::assertSame(ResultState::Fresh, $other->result('mysql.sizes')?->state);
        self::assertSame([], $other->errors());
        self::assertSame(ResultState::Fresh, $again->result('mysql')?->state, 'the fresh value of the server wins over the failure of the connection');
        self::assertSame(['Uptime' => 5], $again->data('mysql'));
        self::assertNull($again->result('mysql')->error);
        self::assertSame(ResultState::Failed, $again->result('mysql.sizes')?->state, 'still held by the failure of the connection');
        self::assertSame('SQLSTATE[HY000] [1045] Access denied', $again->result('mysql.sizes')->error);
        self::assertSame(FailureKind::Unreachable, $again->result('mysql.sizes')->failure);
    }

    public function testWithinAReadAValueOfTheServerIsReusedAndAFailureIsNot(): void
    {
        $server = new Server('db.internal:3306', 'db.internal:3306');
        $refused = [new FakeCollector('mysql', collectFailure: new UnreachableServerException('Connection refused')), new FakeCollector('mysql', collectFailure: new UnreachableServerException('Connection refused'))];
        $answering = [new FakeCollector('mysql.variables', ['version' => '8.4']), new FakeCollector('mysql.variables', ['version' => '8.4'])];
        $instances = [];

        foreach (['main', 'reporting'] as $i => $id) {
            $instances[] = self::instance([
                self::spec($answering[$i], Lifetime::Static, CostClass::Network, perServer: true),
                self::spec($refused[$i], cost: CostClass::Network, perServer: true),
            ], $id, Component::Mysql, $server);
        }

        $snapshots = $this->provider()->read($instances, CollectBudget::fragment($this->clock), CacheState::Working);

        self::assertSame([1, 0], [$answering[0]->collects, $answering[1]->collects]);
        self::assertSame([1, 1], [$refused[0]->collects, $refused[1]->collects]);
        self::assertSame(['version' => '8.4'], $snapshots['mysql.reporting']->data('mysql.variables'));
        self::assertSame(FailureKind::Unreachable, $snapshots['mysql.reporting']->result('mysql')?->failure);
    }

    public function testADeadServerMakesEachConnectionShownWaitOnce(): void
    {
        $server = new Server('db.internal:3306', 'db.internal:3306');
        $statuses = [];
        $instances = [];

        foreach (['main', 'reporting'] as $id) {
            $statuses[] = $status = new FakeCollector('mysql', collectFailure: new UnreachableServerException('Read timed out'), clock: $this->clock, duration: 10.0);
            $instances[] = self::instance([
                self::spec($status, cost: CostClass::Network, perServer: true),
                self::spec(new FakeCollector('mysql.variables', ['version' => '8.4']), Lifetime::Static, CostClass::Network, perServer: true),
            ], $id, Component::Mysql, $server);
        }

        $provider = $this->provider();
        $this->read($provider, $instances[0], CollectBudget::page($this->clock));
        $this->read($provider, $instances[1], CollectBudget::page($this->clock));

        for ($i = 0; $i < 5; ++$i) {
            $this->clock->sleep(1);
            $snapshots = $provider->read($instances, CollectBudget::fragment($this->clock), CacheState::Working);

            foreach (['mysql.main', 'mysql.reporting'] as $key) {
                self::assertSame(FailureKind::Unreachable, $snapshots[$key]->result('mysql.variables')?->failure, $key);
                self::assertFalse($snapshots[$key]->isReading(), $key);
            }
        }

        self::assertSame([1, 1], [$statuses[0]->collects, $statuses[1]->collects]);
    }

    public function testASharedCollectorIsNeitherHeldNorHolding(): void
    {
        $frozen = new FakeCollector('mysql', collectFailure: new UnreachableServerException('Connection refused'));
        $endOfLife = new FakeCollector('eol.mysql', ['cycles' => []]);
        $instance = self::instance([
            self::spec($frozen, cost: CostClass::Network),
            self::spec($endOfLife, Lifetime::Day, CostClass::Slow, shared: true),
        ], 'default', Component::Mysql);
        $provider = $this->provider();

        $this->read($provider, $instance, CollectBudget::page($this->clock));
        $this->clock->sleep(1);
        $snapshot = $this->read($provider, $instance);

        self::assertSame([1, 1], [$frozen->collects, $endOfLife->collects]);
        self::assertSame(ResultState::Fresh, $snapshot->result('eol.mysql')?->state);

        $status = new FakeCollector('mysql', ['Uptime' => 5]);
        $unreachableEndOfLife = new FakeCollector('eol.mariadb', collectFailure: new UnreachableServerException('Connection timed out'));
        $other = self::instance([
            self::spec($status, cost: CostClass::Network),
            self::spec($unreachableEndOfLife, Lifetime::Day, CostClass::Slow, shared: true),
        ], 'reporting', Component::Mysql);

        $this->read($provider, $other);
        $this->clock->sleep(11);
        $snapshot = $this->read($provider, $other);

        self::assertSame([2, 1], [$status->collects, $unreachableEndOfLife->collects]);
        self::assertSame(ResultState::Fresh, $snapshot->result('mysql')?->state);
    }

    public function testALocalComponentIsNotHeldByAnUnreachableCollector(): void
    {
        $status = new FakeCollector('nginx.status', collectFailure: new UnreachableServerException('Connection timed out'), clock: $this->clock, duration: 10.0);
        $config = new FakeCollector('nginx.config', ['workers' => 4]);
        $instance = self::instance([self::spec($status, cost: CostClass::Network), self::spec($config, Lifetime::Static, CostClass::Network)], null, Component::Nginx);
        $provider = $this->provider();

        $this->read($provider, $instance, CollectBudget::page($this->clock));
        $this->clock->sleep(1);
        $snapshot = $this->read($provider, $instance, CollectBudget::page($this->clock));

        self::assertSame([1, 1], [$status->collects, $config->collects]);
        self::assertSame(ResultState::Fresh, $snapshot->result('nginx.config')?->state);
    }

    public function testAnotherKindOfFailureHoldsNothing(): void
    {
        $provider = $this->provider();

        foreach (['prerequisite' => new MissingPrerequisiteException('performance_schema is off'), 'other' => new \RuntimeException('Access denied')] as $id => $failure) {
            $status = new FakeCollector('mysql', collectFailure: $failure);
            $variables = new FakeCollector('mysql.variables', ['version' => '8.4']);
            $instance = self::instance([self::spec($status, cost: CostClass::Network), self::spec($variables, Lifetime::Static, CostClass::Network)], $id, Component::Mysql);

            $snapshot = $this->read($provider, $instance);
            $this->clock->sleep(1);
            $this->read($provider, $instance);

            self::assertSame([1, 1], [$status->collects, $variables->collects], $id);
            self::assertSame(ResultState::Fresh, $snapshot->result('mysql.variables')?->state, $id);
        }
    }

    public function testAForgetLiftsTheHold(): void
    {
        $variables = new FakeCollector('mysql.variables', ['version' => '8.4']);
        $instance = static fn(FakeCollector $status): Instance => self::instance([
            self::spec($status, cost: CostClass::Network),
            self::spec($variables, Lifetime::Static, CostClass::Network),
        ], 'default', Component::Mysql);
        $provider = $this->provider();
        $this->read($provider, $instance(new FakeCollector('mysql', collectFailure: new UnreachableServerException('Connection refused'))));
        $answering = new FakeCollector('mysql', ['Uptime' => 5]);
        $this->clock->sleep(1);

        $held = $this->read($provider, $instance($answering));
        $provider->forget([$instance($answering)]);
        $retried = $this->read($provider, $instance($answering));

        self::assertSame(ResultState::Failed, $held->result('mysql.variables')?->state);
        self::assertSame([1, 1], [$answering->collects, $variables->collects]);
        self::assertSame(ResultState::Fresh, $retried->result('mysql')?->state);
        self::assertSame(ResultState::Fresh, $retried->result('mysql.variables')?->state);
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
        self::assertArrayHasKey('jmonitor.stack.result.php.%shared.eol.php', $this->recording->ttls);
    }

    public function testASharedValueIsReadOnceForEveryInstanceOfItsComponent(): void
    {
        $endOfLife = [new FakeCollector('eol.mysql', ['cycles' => []]), new FakeCollector('eol.mysql', ['cycles' => []])];
        $instances = [
            self::instance([self::spec(new FakeCollector('mysql', ['Uptime' => 5])), self::spec($endOfLife[0], Lifetime::Day, CostClass::Slow, shared: true)], 'main', Component::Mysql),
            self::instance([self::spec(new FakeCollector('mysql', ['Uptime' => 5])), self::spec($endOfLife[1], Lifetime::Day, CostClass::Slow, shared: true)], 'reporting', Component::Mysql),
        ];

        $snapshots = $this->provider()->read($instances, CollectBudget::fragment($this->clock), CacheState::Working);
        $this->clock->sleep(1);
        $later = $this->read($this->provider(), self::instance([self::spec($third = new FakeCollector('eol.mysql', ['cycles' => []]), Lifetime::Day, CostClass::Slow, shared: true)], 'legacy', Component::Mysql));

        self::assertSame(1, $endOfLife[0]->collects + $endOfLife[1]->collects + $third->collects);
        foreach ($snapshots as $key => $snapshot) {
            self::assertSame(ResultState::Fresh, $snapshot->result('eol.mysql')?->state, $key);
            self::assertSame(['cycles' => []], $snapshot->data('eol.mysql'), $key);
        }
        self::assertSame(ResultState::Fresh, $later->result('eol.mysql')?->state);
        self::assertArrayHasKey('jmonitor.stack.result.mysql.%shared.eol.mysql', $this->recording->ttls);
    }

    public function testASharedLookupThatFailsBesideAnOlderValueIsTriedOnceInARead(): void
    {
        $instances = static fn(array $endOfLife): array => array_map(static fn(string $name, FakeCollector $collector): Instance => self::instance(
            [self::spec(new FakeCollector('mysql', ['Uptime' => 5])), self::spec($collector, Lifetime::Day, CostClass::Slow, shared: true)],
            $name,
            Component::Mysql,
        ), ['main', 'reporting', 'legacy'], $endOfLife);
        $provider = $this->provider();
        $provider->read($instances(array_map(static fn(): FakeCollector => new FakeCollector('eol.mysql', ['cycles' => []]), range(1, 3))), CollectBudget::fragment($this->clock), CacheState::Working);
        $this->clock->sleep(Lifetime::Day->seconds() + 1);
        $failing = array_map(static fn(): FakeCollector => new FakeCollector('eol.mysql', collectFailure: new \RuntimeException('Lookup failed')), range(1, 3));

        $snapshots = $provider->read($instances($failing), CollectBudget::fragment($this->clock), CacheState::Working);

        self::assertSame(1, array_sum(array_map(static fn(FakeCollector $collector): int => $collector->collects, $failing)));
        foreach ($snapshots as $key => $snapshot) {
            self::assertSame(ResultState::Stale, $snapshot->result('eol.mysql')?->state, $key);
            self::assertSame(['cycles' => []], $snapshot->data('eol.mysql'), $key);
            self::assertSame('Lookup failed', $snapshot->result('eol.mysql')->error, $key);
        }
    }

    public function testAConnectionNamedLikeASharedCollectorSharesNoKeyWithIt(): void
    {
        $instance = static fn(FakeCollector $status, FakeCollector $endOfLife): Instance => self::instance([
            self::spec($status),
            self::spec($endOfLife, Lifetime::Day, CostClass::Slow, shared: true),
        ], 'eol', Component::Mysql);

        $failingStatus = new FakeCollector('mysql', collectFailure: new \RuntimeException('Status failed'));
        $answeringEndOfLife = new FakeCollector('eol.mysql', ['cycles' => []]);
        $provider = $this->provider();
        $this->read($provider, $instance($failingStatus, $answeringEndOfLife));
        $this->clock->sleep(1);
        $snapshot = $this->read($provider, $instance($failingStatus, $answeringEndOfLife));

        self::assertSame(ResultState::Fresh, $snapshot->result('eol.mysql')?->state);
        self::assertNull($snapshot->result('eol.mysql')->error);
        self::assertSame(['cycles' => []], $snapshot->data('eol.mysql'));
        self::assertSame('Status failed', $snapshot->result('mysql')?->error);

        $answeringStatus = new FakeCollector('mysql', ['Uptime' => 5]);
        $failingEndOfLife = new FakeCollector('eol.mysql', collectFailure: new \RuntimeException('Lookup failed'));
        $provider = $this->provider(cache: new CountingCachePool(new RecordingCachePool()));
        $this->read($provider, $instance($answeringStatus, $failingEndOfLife));
        $this->clock->sleep(1);
        $snapshot = $this->read($provider, $instance($answeringStatus, $failingEndOfLife));

        self::assertSame(ResultState::Fresh, $snapshot->result('mysql')?->state);
        self::assertNull($snapshot->result('mysql')->error);
        self::assertSame(['Uptime' => 5], $snapshot->data('mysql'));
        self::assertSame('Lookup failed', $snapshot->result('eol.mysql')?->error);
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
        self::assertSame('warning', $logs[0][0]);
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

    public function testAValueIsDatedWhenItsCollectorReturns(): void
    {
        $status = new FakeCollector('php.status', ['requests' => 100], clock: $this->clock, duration: 3.0);
        $instance = self::instance([self::spec($status)]);
        $provider = $this->provider();

        $first = $this->read($provider, $instance);
        $this->clock->sleep(9);
        $this->read($provider, $instance);

        self::assertSame(self::START + 3, $first->result('php.status')?->collectedAt);
        self::assertSame(1, $status->collects, 'fresh for its whole lifetime');

        $this->clock->sleep(1);
        $this->read($provider, $instance);

        self::assertSame(2, $status->collects);
    }

    public function testAForgetByAServerWhoseClockIsAheadOutdatesTheValuesOnce(): void
    {
        $php = new FakeCollector('php', ['requests' => 100]);
        $failing = new FakeCollector('php.opcache', collectFailure: new UnreachableServerException('Connection timed out'));
        $instance = self::instance([self::spec($php), self::spec($failing)]);
        $ahead = new MockClock('@' . (self::START + 30));
        $web2 = new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($this->cache, 10), $this->cache, $ahead, new WebNode('web-2'));
        $web1 = $this->provider('web-1');

        $web2->forget([$instance]);

        for ($i = 0; $i < 5; ++$i) {
            $this->read($web1, $instance);
            $this->clock->sleep(1);
        }

        self::assertSame(1, $php->collects);
        self::assertSame(1, $failing->collects, 'its failure is kept for a minute');
    }

    public function testACacheThatRefusesWritesOnlyRunsImmediateCollectors(): void
    {
        $logger = new BufferingLogger();
        $immediate = new FakeCollector('php', ['requests' => 1]);
        $network = new FakeCollector('php.network', ['a' => 1]);
        $slow = new FakeCollector('eol.php', ['cycles' => []]);
        $instance = self::instance([
            self::spec($immediate),
            self::spec($network, Lifetime::Static, CostClass::Network),
            self::spec($slow, Lifetime::Day, CostClass::Slow),
        ]);
        $provider = $this->provider(logger: $logger, cache: new RefusingCachePool());

        for ($i = 0; $i < 5; ++$i) {
            $snapshot = $this->read($provider, $instance);
            $logs = $logger->cleanLogs();

            self::assertSame(ResultState::Fresh, $snapshot->result('php')?->state);
            self::assertSame(ResultState::Pending, $snapshot->result('php.network')?->state);
            self::assertCount(1, $logs);
            self::assertSame('warning', $logs[0][0]);
            $this->clock->sleep(1);
        }

        self::assertSame(5, $immediate->collects);
        self::assertSame(0, $network->collects);
        self::assertSame(0, $slow->collects);
    }

    public function testACommitThatFailsIsLogged(): void
    {
        $logger = new BufferingLogger();

        $snapshot = $this->read($this->provider(logger: $logger, cache: new RefusingCachePool(refusesSave: false)), self::instance([self::spec(new FakeCollector('php', ['a' => 1]))]));
        $logs = $logger->cleanLogs();

        self::assertSame(['a' => 1], $snapshot->data('php'));
        self::assertCount(1, $logs);
        self::assertSame('warning', $logs[0][0]);
    }

    public function testACommitThatFailsStillLiftsTheWitnesses(): void
    {
        $logger = new BufferingLogger();
        $pool = new RefusingCachePool(maxBytes: 64);
        $main = new FakeCollector('symfony', ['env' => 'prod']);
        $legacy = new FakeCollector('symfony', ['env' => 'prod']);
        $instances = [
            self::instance([self::spec($main)], 'main', Component::Symfony),
            self::instance([self::spec($legacy)], 'legacy', Component::Symfony),
        ];
        $provider = $this->provider(logger: $logger, cache: $pool);

        for ($i = 0; $i < 3; ++$i) {
            $snapshots = $provider->read($instances, CollectBudget::fragment($this->clock), CacheState::Working);

            self::assertSame(ResultState::Fresh, $snapshots['symfony.main']->result('symfony')?->state);
            self::assertSame(ResultState::Fresh, $snapshots['symfony.legacy']->result('symfony')?->state);
            self::assertFalse($pool->inner->hasItem('jmonitor.stack.collecting.%node.web-1.symfony.main'), 'the witness of the first instance is lifted');
            self::assertFalse($pool->inner->hasItem('jmonitor.stack.collecting.%node.web-1.symfony.legacy'), 'and so is the one of the next instance');
            self::assertCount(1, $logger->cleanLogs(), 'once per read');
            $this->clock->sleep(1);
        }

        self::assertSame([3, 3], [$main->collects, $legacy->collects]);
    }

    public function testAPoolThatBreaksAfterTheWitnessIsTakenStillLosesIt(): void
    {
        $logger = new BufferingLogger();
        $instance = self::instance([self::spec(new FakeCollector('php', ['a' => 1]), trackDelta: true)]);
        $pool = new BreakingCachePool(static fn(string $method): bool => $method === 'commit');

        $snapshot = $this->read($this->provider(logger: $logger, cache: $pool), $instance);

        self::assertSame(['a' => 1], $snapshot->data('php'));
        self::assertFalse($pool->inner->hasItem('jmonitor.stack.collecting.%node.web-1.php'), 'the witness is lifted after the cache failed');
        self::assertCount(1, $logger->cleanLogs());

        $broken = new BreakingCachePool(static fn(string $method, int $call): bool => $call > 2);
        $snapshot = $this->read($this->provider(logger: $logger, cache: $broken), $instance);

        self::assertSame(['a' => 1], $snapshot->data('php'), 'nothing gets out, even when the witness cannot be lifted');
        self::assertCount(1, $logger->cleanLogs());
    }

    public function testTheReasonOfAnUnavailableInstanceCarriesNoCredentials(): void
    {
        $snapshot = $this->read($this->provider(), Instance::unavailable(Component::Php, 'Cannot reach redis://app:s3cret@cache'));

        self::assertSame('Cannot reach redis://cache', $snapshot->error);
    }

    public function testAWitnessThatCannotBeDrawnLeavesTheCacheAside(): void
    {
        $logger = new BufferingLogger();
        $immediate = new FakeCollector('php', ['a' => 1]);
        $network = new FakeCollector('php.network', ['a' => 1]);
        $instance = self::instance([self::spec($immediate), self::spec($network, cost: CostClass::Network)]);
        $noEntropy = new class implements Engine {
            public function generate(): string
            {
                throw new RandomException('Cannot gather sufficient random data');
            }
        };
        $provider = new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($this->cache, 10), $this->cache, $this->clock, new WebNode('web-1'), $logger, new Randomizer($noEntropy));

        $snapshot = $this->read($provider, $instance);
        $logs = $logger->cleanLogs();

        self::assertSame(ResultState::Fresh, $snapshot->result('php')?->state);
        self::assertSame(ResultState::Pending, $snapshot->result('php.network')?->state);
        self::assertSame(0, $network->collects);
        self::assertCount(1, $logs);

        $provider->forget([$instance]);

        self::assertNotSame([], $logger->cleanLogs());
    }

    public function testTheWitnessOfEveryInstanceIsTakenBeforeTheFirstNetworkCollectorRuns(): void
    {
        $witnesses = ['jmonitor.stack.collecting.%node.web-1.symfony.main', 'jmonitor.stack.collecting.%node.web-1.symfony.legacy'];
        $found = null;
        $first = self::collector('symfony.network', function () use ($witnesses, &$found): array {
            $found = array_map(fn(string $witness): bool => $this->recording->hasItem($witness), $witnesses);

            return ['ok' => true];
        });
        $main = self::instance([new CollectorSpec($first, Lifetime::Dynamic, CostClass::Network)], 'main', Component::Symfony);
        $legacy = self::instance([self::spec(new FakeCollector('symfony.network', ['ok' => true]), cost: CostClass::Network)], 'legacy', Component::Symfony);

        $this->provider()->read([$main, $legacy], CollectBudget::fragment($this->clock), CacheState::Working);

        self::assertSame([true, true], $found);
        self::assertFalse($this->recording->hasItem($witnesses[0]));
        self::assertFalse($this->recording->hasItem($witnesses[1]));
    }

    public function testNoWitnessIsTakenWhenTheBudgetAllowsNothing(): void
    {
        $slow = new FakeCollector('php.slow', ['a' => 1]);
        $instance = self::instance([self::spec(new FakeCollector('php', ['a' => 1]), Lifetime::Static), self::spec($slow, cost: CostClass::Slow)]);
        $provider = $this->provider();
        $this->read($provider, $instance, CollectBudget::page($this->clock));
        $this->cache->reset();
        $this->recording->ttls = [];

        $snapshot = $this->read($provider, $instance, CollectBudget::page($this->clock));

        self::assertSame(0, $slow->collects);
        self::assertSame(ResultState::Pending, $snapshot->result('php.slow')?->state);
        self::assertSame(['getItems' => 1], $this->cache->calls);
        self::assertArrayNotHasKey('jmonitor.stack.collecting.%node.web-1.php', $this->recording->ttls);
    }

    public function testAPoolThatThrowsCostsOneAttemptToLiftTheWitnesses(): void
    {
        $broken = false;
        $afterTheCollects = [];
        $pool = new BreakingCachePool(static function (string $method) use (&$broken, &$afterTheCollects): bool {
            $broken = $broken || $method === 'commit';

            if ($broken) {
                $afterTheCollects[] = $method;
            }

            return $broken;
        });
        $instances = [];

        foreach (['main', 'legacy', 'admin'] as $id) {
            $instances[] = self::instance([self::spec(new FakeCollector('symfony', ['env' => 'prod']))], $id, Component::Symfony);
        }

        $snapshots = $this->provider(cache: $pool)->read($instances, CollectBudget::fragment($this->clock), CacheState::Working);

        self::assertSame(['commit', 'getItem'], $afterTheCollects, 'one write, then one witness to lift');

        foreach (['main', 'legacy', 'admin'] as $id) {
            self::assertSame(ResultState::Fresh, $snapshots['symfony.' . $id]->result('symfony')?->state);
            self::assertTrue($pool->inner->hasItem('jmonitor.stack.collecting.%node.web-1.symfony.' . $id), 'it expires on its own');
        }
    }

    public function testAPoolThatRefusesStillLiftsEveryWitness(): void
    {
        $pool = new RefusingCachePool(refusesSave: false);
        $instances = [];

        foreach (['main', 'legacy', 'admin'] as $id) {
            $instances[] = self::instance([self::spec(new FakeCollector('symfony', ['env' => 'prod']))], $id, Component::Symfony);
        }

        $this->provider(cache: $pool)->read($instances, CollectBudget::fragment($this->clock), CacheState::Working);

        foreach (['main', 'legacy', 'admin'] as $id) {
            self::assertFalse($pool->inner->hasItem('jmonitor.stack.collecting.%node.web-1.symfony.' . $id), $id);
        }
    }

    public function testAValueTheCacheRefusesIsNotCollectedAgainAtEveryDisplay(): void
    {
        $logger = new BufferingLogger();
        $large = new FakeCollector('php.network', ['page' => str_repeat('x', 2000)]);
        $small = new FakeCollector('php', ['requests' => 1]);
        $instance = self::instance([self::spec($large, cost: CostClass::Network), self::spec($small, Lifetime::Static)]);
        $provider = $this->provider(logger: $logger, cache: new RefusingCachePool(inner: $this->recording, maxBytes: 1000));

        $first = $this->read($provider, $instance);
        $this->clock->sleep(4.5);
        $second = $this->read($provider, $instance);
        $this->clock->sleep(4.5);
        $this->read($provider, $instance);

        self::assertSame(1, $large->collects);
        self::assertSame(1, $small->collects, 'the other values are kept');
        self::assertSame(ResultState::Fresh, $first->result('php.network')?->state);
        self::assertSame(['page' => str_repeat('x', 2000)], $first->data('php.network'));
        self::assertSame(ResultState::Failed, $second->result('php.network')?->state);
        self::assertSame('The cache refused to keep this value, it may be too large.', $second->result('php.network')->error);
        self::assertSame(self::START + 10, $second->result('php.network')->expiresAt);
        self::assertSame(10, $this->recording->ttls['jmonitor.stack.failure.%node.web-1.php.php.network']);
        self::assertCount(1, $logger->cleanLogs());

        $this->clock->sleep(1);
        $this->read($provider, $instance);

        self::assertSame(2, $large->collects, 'collected again once its failure expires');
        self::assertCount(1, $logger->cleanLogs());
    }

    public function testWhenOnlyTheGroupedWriteIsRefusedEachValueIsStillKept(): void
    {
        $logger = new BufferingLogger();
        $network = new FakeCollector('php.network', ['a' => 1]);
        $immediate = new FakeCollector('php', ['requests' => 1]);
        $instance = self::instance([self::spec($network, cost: CostClass::Network), self::spec($immediate)]);
        $provider = $this->provider(logger: $logger, cache: new RefusingCachePool(refusesSave: false, inner: $this->recording));

        $first = $this->read($provider, $instance);
        $firstLogs = $logger->cleanLogs();
        $this->clock->sleep(5);
        $second = $this->read($provider, $instance);

        self::assertSame([1, 1], [$network->collects, $immediate->collects]);
        self::assertSame(ResultState::Fresh, $first->result('php.network')?->state);
        self::assertSame(ResultState::Fresh, $second->result('php.network')?->state);
        self::assertSame(ResultState::Fresh, $second->result('php')?->state);
        self::assertCount(1, $firstLogs);
        self::assertSame([], $logger->cleanLogs());
        self::assertFalse($this->recording->hasItem('jmonitor.stack.failure.%node.web-1.php.php.network'));
        self::assertFalse($this->recording->hasItem('jmonitor.stack.failure.%node.web-1.php.php'));
    }

    public function testARefusedSlowValueIsKeptAsAFailureForTenMinutes(): void
    {
        $slow = new FakeCollector('php.slow', ['page' => str_repeat('x', 2000)]);
        $instance = self::instance([self::spec($slow, Lifetime::Static, CostClass::Slow)]);
        $provider = $this->provider(cache: new RefusingCachePool(inner: $this->recording, maxBytes: 1000));

        $this->read($provider, $instance);
        $this->clock->sleep(599);
        $kept = $this->read($provider, $instance);

        self::assertSame(1, $slow->collects);
        self::assertSame(ResultState::Failed, $kept->result('php.slow')?->state);
        self::assertSame(600, $this->recording->ttls['jmonitor.stack.failure.%node.web-1.php.php.slow']);
    }

    public function testARefusedImmediateValueLeavesNoFailure(): void
    {
        $large = new FakeCollector('php', ['page' => str_repeat('x', 2000)]);
        $instance = self::instance([self::spec($large)]);
        $provider = $this->provider(cache: new RefusingCachePool(inner: $this->recording, maxBytes: 1000));

        $first = $this->read($provider, $instance);
        $this->clock->sleep(1);
        $second = $this->read($provider, $instance);

        self::assertSame(2, $large->collects);
        self::assertSame(ResultState::Fresh, $first->result('php')?->state);
        self::assertSame(ResultState::Fresh, $second->result('php')?->state);
        self::assertFalse($this->recording->hasItem('jmonitor.stack.failure.%node.web-1.php.php'));
    }

    /**
     * @param \Closure(): array<string, mixed> $collect
     */
    private static function collector(string $name, \Closure $collect): CollectorInterface
    {
        return new class ($name, $collect) implements CollectorInterface {
            public function __construct(
                private readonly string $name,
                private readonly \Closure $collect,
            ) {}

            public function collect(): array
            {
                return ($this->collect)();
            }

            public function getVersion(): int
            {
                return 1;
            }

            public function getName(): string
            {
                return $this->name;
            }
        };
    }

    /**
     * @param list<CollectorSpec> $specs
     */
    private static function instance(array $specs, ?string $id = null, Component $component = Component::Php, ?Server $server = null): Instance
    {
        return Instance::found($component, new CollectorSet($component, $specs), $id, server: $server);
    }

    private static function spec(
        FakeCollector $collector,
        Lifetime $lifetime = Lifetime::Dynamic,
        CostClass $cost = CostClass::Immediate,
        bool $trackDelta = false,
        bool $shared = false,
        bool $detailOnly = false,
        bool $perServer = false,
    ): CollectorSpec {
        return new CollectorSpec($collector, $lifetime, $cost, $trackDelta, $shared, $detailOnly, $perServer);
    }

    private function provider(string $node = 'web-1', ?LoggerInterface $logger = null, ?CacheItemPoolInterface $cache = null): SnapshotProvider
    {
        $cache ??= $this->cache;

        return new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($cache, 10), $cache, $this->clock, new WebNode($node), $logger);
    }

    private function read(SnapshotProvider $provider, Instance $instance, ?CollectBudget $budget = null, CacheState $cache = CacheState::Working, bool $detail = false): ComponentSnapshot
    {
        return $provider->read([$instance], $budget ?? CollectBudget::fragment($this->clock), $cache, $detail)[$instance->key()];
    }
}
