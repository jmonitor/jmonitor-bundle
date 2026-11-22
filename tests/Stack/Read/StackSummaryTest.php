<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Read;

use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Check\StackHealth;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Read\StackSummary;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\ThrowingCachePool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class StackSummaryTest extends TestCase
{
    private const EXPECTED = [
        'at' => Snapshots::NOW,
        'critical' => 1,
        'warning' => 1,
        'components' => ['system' => 'warning', 'php' => 'healthy', 'symfony' => 'critical'],
    ];

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('@' . Snapshots::NOW);
    }

    public function testWhatIsRememberedIsReadBack(): void
    {
        $pool = new ArrayAdapter();
        [$state, $health] = $this->visit();

        $this->summary($pool)->remember($state, $health);

        self::assertSame(self::EXPECTED, $this->summary($pool)->last());
    }

    public function testEachWebServerHasItsOwnSummary(): void
    {
        $pool = new ArrayAdapter();
        [$state, $health] = $this->visit();

        $this->summary($pool, 'web-1')->remember($state, $health);

        self::assertNull($this->summary($pool, 'web-2')->last());
        self::assertNull($this->summary($pool, null)->last());
        self::assertSame(self::EXPECTED, $this->summary($pool, 'web-1')->last());
    }

    public function testItIsForgottenAfterFifteenMinutes(): void
    {
        $pool = new ArrayAdapter();
        [$state, $health] = $this->visit();
        $this->summary($pool)->remember($state, $health);

        $this->clock->sleep(900);
        self::assertSame(self::EXPECTED, $this->summary($pool)->last());

        $this->clock->sleep(1);
        self::assertNull($this->summary($pool)->last());
    }

    public function testNothingIsWrittenForAnEngineFailureNorACacheOutOfOrder(): void
    {
        $pool = new CountingCachePool();
        [$state, $health] = $this->visit();
        $failed = new StackState('web-1', Snapshots::NOW, CacheState::Working, [], [], 'Cannot reach redis://cache');
        $outOfOrder = new StackState('web-1', Snapshots::NOW, CacheState::OutOfOrder, $state->components, []);

        $this->summary($pool)->remember($failed, new StackHealth([], []));
        $this->summary($pool)->remember($outOfOrder, $health);

        self::assertSame([], $pool->calls);
        self::assertNull($this->summary($pool)->last());
    }

    public function testReadingBackWhatWasJustRememberedCostsNoCacheRead(): void
    {
        $pool = new CountingCachePool();
        [$state, $health] = $this->visit();
        $summary = $this->summary($pool);

        $summary->remember($state, $health);
        $pool->reset();

        self::assertSame(self::EXPECTED, $summary->last());
        self::assertSame(0, $pool->roundTrips());

        $other = $this->summary($pool);
        self::assertSame(self::EXPECTED, $other->last());
        self::assertSame(self::EXPECTED, $other->last());
        self::assertSame(1, $pool->roundTrips());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function payloadsOfUnknownShape(): iterable
    {
        yield 'a string' => ['2 critical'];
        yield 'an empty array' => [[]];
        yield 'a date as a string' => [['at' => '1780000000'] + self::EXPECTED];
        yield 'a count as a float' => [['critical' => 1.0] + self::EXPECTED];
        yield 'a negative count' => [['warning' => -1] + self::EXPECTED];
        yield 'no components' => [array_diff_key(self::EXPECTED, ['components' => true])];
        yield 'an unknown component' => [['components' => ['mysql' => 'healthy']] + self::EXPECTED];
        yield 'an unknown verdict' => [['components' => ['php' => 'fine']] + self::EXPECTED];
        yield 'a verdict as a number' => [['components' => ['php' => 1]] + self::EXPECTED];
    }

    #[DataProvider('payloadsOfUnknownShape')]
    public function testAPayloadOfUnknownShapeIsNoSummary(mixed $payload): void
    {
        $pool = new ArrayAdapter();
        $pool->save($pool->getItem('jmonitor.stack.summary.' . (new WebNode('web-1'))->keyPrefix() . 'last')->set($payload));

        self::assertNull($this->summary($pool)->last());
    }

    public function testAPoolThatThrowsGivesNoSummaryAndNoException(): void
    {
        $logger = self::logger();
        [$state, $health] = $this->visit();
        $summary = new StackSummary(new ServiceLocator(['cache' => static fn(): ThrowingCachePool => new ThrowingCachePool()]), new WebNode('web-1'), $this->clock, $logger);
        $broken = new StackSummary(new ServiceLocator(['cache' => static fn(): never => throw new \RuntimeException('No such pool')]), new WebNode('web-1'), $this->clock, $logger);

        self::assertNull($summary->last());
        self::assertNull($broken->last());
        $summary->remember($state, $health);

        self::assertSame([
            'warning The summary of the live stack could not be read: Connection refused',
            'warning The summary of the live stack could not be read: No such pool',
            'warning The summary of the live stack could not be written: Connection refused',
        ], $logger->messages);
    }

    public function testItForgetsBetweenTwoRequests(): void
    {
        $pool = new CountingCachePool();
        [$state, $health] = $this->visit();
        $summary = $this->summary($pool);
        $summary->remember($state, $health);
        $pool->clear();

        self::assertSame(self::EXPECTED, $summary->last());

        $summary->reset();
        $pool->reset();

        self::assertNull($summary->last());
        self::assertSame(1, $pool->roundTrips());
    }

    /**
     * @return array{StackState, StackHealth} a disk 90 % full, debug mode in prod
     */
    private function visit(): array
    {
        $system = Snapshots::system();
        $system['disk']['free'] = 10_000_000_000;
        $state = Snapshots::stack(
            Snapshots::of(Component::System, ['system' => $system]),
            Snapshots::of(Component::Php, ['php' => Snapshots::php()]),
            Snapshots::of(Component::Symfony, ['symfony' => ['debug' => true] + Snapshots::symfony()]),
        );

        return [$state, (new StackChecks([], false, $this->clock))->health($state)];
    }

    private function summary(CacheItemPoolInterface $pool, ?string $node = 'web-1'): StackSummary
    {
        return new StackSummary(new ServiceLocator(['cache' => static fn(): CacheItemPoolInterface => $pool]), new WebNode($node ?? ''), $this->clock);
    }

    /** @return AbstractLogger&object{messages: list<string>} */
    private static function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = $level . ' ' . strtr((string) $message, ['{reason}' => (string) ($context['reason'] ?? '')]);
            }
        };
    }
}
