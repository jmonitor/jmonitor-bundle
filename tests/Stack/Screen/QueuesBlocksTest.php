<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Fact;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\ComponentState;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Read\StackReaderInterface;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Screen\QueuesBlocks;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\FakeStackReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class QueuesBlocksTest extends TestCase
{
    private const NOW = 1_780_000_000;
    private const NBSP = "\u{a0}";
    private const TRANSPORTS = [
        ['name' => 'async', 'count' => 1234, 'reason' => null],
        ['name' => 'failed', 'count' => null, 'reason' => 'this transport cannot count'],
    ];

    public function testNoQueuesGiveNoBlock(): void
    {
        self::assertSame([], $this->blocks(new FakeStackReader(self::stack(null))));
    }

    public function testAnEngineFailureSaysTheQueuesCouldNotBeCounted(): void
    {
        $state = new StackState('web-1', self::NOW, CacheState::Working, [], [], 'Cannot reach redis://cache:6379');

        self::assertEquals(
            [Block::notice(new Notice('warn', 'The queues could not be counted', 'Cannot reach redis://cache:6379'))],
            $this->blocks(new FakeStackReader(self::stack(null), $state)),
        );
    }

    public function testACacheOutOfOrderAsksForAWorkingCacheAndLeavesNothingReading(): void
    {
        $blocks = $this->blocks(new FakeStackReader(self::stack(null), self::queues(new CollectorResult('queues', [], ResultState::Pending), CacheState::OutOfOrder)));

        self::assertEquals([Block::notice(new Notice('warn', 'The queues need a working cache', 'Check the cache.app pool of the application.'))], $blocks);
        self::assertFalse($blocks[0]->reading);
    }

    public function testQueuesNeverCountedAreBeingRead(): void
    {
        foreach ([ResultState::Pending, ResultState::Busy] as $state) {
            $blocks = $this->blocks(new FakeStackReader(self::stack(null), self::queues(new CollectorResult('queues', [], $state))));

            self::assertEquals([Block::unavailable(Block::FACTS, BlockState::Reading)], $blocks, $state->name);
            self::assertTrue($blocks[0]->reading);
        }

        self::assertEquals([Block::unavailable(Block::FACTS, BlockState::Reading)], $this->blocks(new FakeStackReader(self::stack(null), self::queues(null))));
    }

    public function testAFailureWithoutValueSaysWhyAndWhenItIsTriedAgain(): void
    {
        $result = new CollectorResult('queues', [], ResultState::Failed, 'The command counting the queues failed (exit code: 255): Fatal error', expiresAt: self::NOW + 9 * 60);

        self::assertEquals(
            [Block::notice(new Notice('warn', 'The queues could not be counted', 'The command counting the queues failed (exit code: 255): Fatal error — next try in 9' . self::NBSP . 'min'))],
            $this->blocks(new FakeStackReader(self::stack(null), self::queues($result))),
        );
    }

    public function testAnInstanceThatCouldNotBeReadSaysWhy(): void
    {
        $snapshot = new ComponentSnapshot(Component::Symfony, [], 'The console was not found.');
        $state = new StackState('web-1', self::NOW, CacheState::Working, [new ComponentState(Component::Symfony, [new InstanceState('queues', null, null, $snapshot)], 0)], []);

        self::assertEquals(
            [Block::notice(new Notice('warn', 'The queues could not be counted', 'The console was not found.'))],
            $this->blocks(new FakeStackReader(self::stack(null), $state)),
        );
    }

    public function testEachTransportIsAFactUnderANoteThatGivesTheAge(): void
    {
        $result = new CollectorResult('queues', ['transports' => self::TRANSPORTS, 'complete' => true], ResultState::Fresh, collectedAt: self::NOW - 12, expiresAt: self::NOW + 18);

        $blocks = $this->blocks(new FakeStackReader(self::stack(null), self::queues($result)));

        self::assertEquals([Block::facts(new Facts('Queues', [
            new Fact('async', '1,234'),
            new Fact('failed', 'Uncountable', hint: 'this transport cannot count'),
        ], 'Messages waiting in each transport, counted 12' . self::NBSP . 's ago'))], $blocks);
        self::assertFalse($blocks[0]->reading);
    }

    public function testAnIncompleteCountShowsNothingMoreThanTheReasonsOfItsTransports(): void
    {
        $result = new CollectorResult('queues', ['transports' => [
            ['name' => 'async', 'count' => 3, 'reason' => null],
            ['name' => 'slow', 'count' => null, 'reason' => 'did not answer in time'],
            ['name' => 'later', 'count' => null, 'reason' => 'not counted: an earlier transport did not answer in time'],
        ], 'complete' => false], ResultState::Fresh, collectedAt: self::NOW, expiresAt: self::NOW + 30);

        self::assertEquals([Block::facts(new Facts('Queues', [
            new Fact('async', '3'),
            new Fact('slow', 'Uncountable', hint: 'did not answer in time'),
            new Fact('later', 'Uncountable', hint: 'not counted: an earlier transport did not answer in time'),
        ], 'Messages waiting in each transport, counted just now'))], $this->blocks(new FakeStackReader(self::stack(null), self::queues($result))));
    }

    public function testAnOutdatedCountThatCouldNotBeRenewedIsShownUnderAWarning(): void
    {
        $result = new CollectorResult('queues', ['transports' => self::TRANSPORTS, 'complete' => true], ResultState::Stale, 'did not answer', self::NOW - 120, expiresAt: self::NOW + 9 * 60);

        $blocks = $this->blocks(new FakeStackReader(self::stack(null), self::queues($result)));

        self::assertEquals([
            Block::notice(new Notice('warn', 'Could not be counted again: did not answer', 'next try in 9' . self::NBSP . 'min')),
            Block::facts(new Facts('Queues', [
                new Fact('async', '1,234'),
                new Fact('failed', 'Uncountable', hint: 'this transport cannot count'),
            ], 'Messages waiting in each transport, counted 2' . self::NBSP . 'min ago')),
        ], $blocks);
        self::assertFalse($blocks[0]->reading);
    }

    public function testAnOutdatedCountKeepsShowingAndAsksForTheRest(): void
    {
        $result = new CollectorResult('queues', ['transports' => self::TRANSPORTS, 'complete' => true], ResultState::Stale, collectedAt: self::NOW - 45, expiresAt: self::NOW - 15);

        $blocks = $this->blocks(new FakeStackReader(self::stack(null), self::queues($result)));

        self::assertEquals([Block::facts(new Facts('Queues', [
            new Fact('async', '1,234'),
            new Fact('failed', 'Uncountable', hint: 'this transport cannot count'),
        ], 'Messages waiting in each transport, counted 45' . self::NBSP . 's ago'))->withReading()], $blocks);
        self::assertTrue($blocks[0]->reading);
    }

    public function testNoTransportToCountSaysSo(): void
    {
        $result = new CollectorResult('queues', ['transports' => [], 'complete' => true], ResultState::Fresh, collectedAt: self::NOW, expiresAt: self::NOW + 30);

        self::assertEquals(
            [Block::notice(new Notice('info', 'No transport to count'))],
            $this->blocks(new FakeStackReader(self::stack(null), self::queues($result))),
        );
    }

    public function testAPageReadsAsAPageAndAFollowUpAsAFragment(): void
    {
        $reader = new FakeStackReader(self::stack(null), self::queues(null));
        $blocks = $this->queuesBlocks($reader);

        $blocks->blocks(new ScreenRequest(self::NOW));
        $blocks->blocks(new ScreenRequest(self::NOW, true));

        self::assertSame(['queues Page', 'queues Fragment'], $reader->calls);
    }

    public function testATransportNameIsShownAsWritten(): void
    {
        $result = new CollectorResult('queues', ['transports' => [
            ['name' => '<b>x', 'count' => 1, 'reason' => null],
            ['name' => '2024', 'count' => null, 'reason' => 'Connection refused <i>'],
        ], 'complete' => true], ResultState::Fresh, collectedAt: self::NOW, expiresAt: self::NOW + 30);

        $facts = $this->blocks(new FakeStackReader(self::stack(null), self::queues($result)))[0]->content;

        self::assertInstanceOf(Facts::class, $facts);
        self::assertSame(['<b>x', '2024'], array_map(static fn(Fact $fact): string => $fact->label, $facts->facts));
        self::assertSame('Connection refused <i>', $facts->facts[1]->hint);
    }

    public function testNothingButTheReaderIsAskedFor(): void
    {
        $asked = [];
        $reader = new FakeStackReader(self::stack(null), self::queues(null));
        $services = new ServiceLocator([StackReaderInterface::class => static function () use ($reader, &$asked): StackReaderInterface {
            $asked[] = StackReaderInterface::class;

            return $reader;
        }]);

        (new QueuesBlocks($services, new MockClock('@' . self::NOW)))->blocks(new ScreenRequest(self::NOW));

        self::assertSame([StackReaderInterface::class], $asked);
    }

    /**
     * @return list<Block>
     */
    private function blocks(FakeStackReader $reader): array
    {
        return $this->queuesBlocks($reader)->blocks(new ScreenRequest(self::NOW));
    }

    private function queuesBlocks(FakeStackReader $reader): QueuesBlocks
    {
        return new QueuesBlocks(new ServiceLocator([StackReaderInterface::class => static fn(): StackReaderInterface => $reader]), new MockClock('@' . self::NOW));
    }

    private static function stack(?string $error): StackState
    {
        return new StackState('web-1', self::NOW, CacheState::Working, [], [], $error);
    }

    private static function queues(?CollectorResult $result, CacheState $cache = CacheState::Working): StackState
    {
        $snapshot = new ComponentSnapshot(Component::Symfony, $result === null ? [] : ['queues' => $result]);

        return new StackState('web-1', self::NOW, $cache, [new ComponentState(Component::Symfony, [new InstanceState('queues', null, null, $snapshot)], 0)], []);
    }
}
