<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\View;

use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCards;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauges;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\Section;
use Jmonitor\JmonitorBundle\Dashboard\View\SubNav;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlockTest extends TestCase
{
    public function testCardsAndTablesAreReadyWithTheirContent(): void
    {
        $cards = Block::cards([]);
        static::assertSame([Block::CARDS, BlockState::Ready, []], [$cards->type, $cards->state, $cards->content]);

        $card = new Card('Hits', '12');
        static::assertSame([$card], Block::cards([$card])->content);

        $table = new Table('stubs', 'Stubs', [], []);
        $block = Block::table($table);
        static::assertSame([Block::TABLE, BlockState::Ready, $table], [$block->type, $block->state, $block->content]);
    }

    public function testAnUnavailableBlockHasNoContent(): void
    {
        $block = Block::unavailable(Block::TABLE, BlockState::Empty, 'T', 'X');

        static::assertSame([Block::TABLE, BlockState::Empty, null, 'T', 'X'], [$block->type, $block->state, $block->content, $block->title, $block->text]);
        static::assertSame(['', ''], [Block::unavailable(Block::CARDS, BlockState::Error)->title, Block::unavailable(Block::CARDS, BlockState::Error)->text]);
    }

    public function testAReadingBlockSaysSo(): void
    {
        $cards = Block::cards([new Card('Hits', '12')]);
        $partial = $cards->withReading();

        static::assertFalse($cards->reading);
        static::assertFalse(Block::unavailable(Block::TABLE, BlockState::Empty)->reading);
        static::assertTrue(Block::unavailable(Block::TABLE, BlockState::Reading)->reading);
        static::assertSame([Block::CARDS, BlockState::Ready, $cards->content, true], [$partial->type, $partial->state, $partial->content, $partial->reading]);
    }

    public function testAnUnavailableBlockCannotBeReady(): void
    {
        $this->expectException(\LogicException::class);

        Block::unavailable(Block::TABLE, BlockState::Ready);
    }

    /**
     * @return iterable<string, array{string, \Closure(): Block, object}>
     */
    public static function stackBlocks(): iterable
    {
        $subnav = new SubNav('Stack', []);
        $notice = new Notice('warn', 'Title');
        $checks = new CheckList([]);
        $components = new ComponentCards([]);
        $gauges = new Gauges('Memory', []);
        $facts = new Facts('Runtime', []);

        yield 'subnav' => ['subnav', static fn(): Block => Block::subnav($subnav), $subnav];
        yield 'notice' => ['notice', static fn(): Block => Block::notice($notice), $notice];
        yield 'checks' => ['checks', static fn(): Block => Block::checks($checks), $checks];
        yield 'components' => ['components', static fn(): Block => Block::components($components), $components];
        yield 'gauges' => ['gauges', static fn(): Block => Block::gauges($gauges), $gauges];
        yield 'facts' => ['facts', static fn(): Block => Block::facts($facts), $facts];
    }

    /**
     * @param \Closure(): Block $create
     */
    #[DataProvider('stackBlocks')]
    public function testAStackBlockIsReadyWithItsContentAndNamesItsTemplate(string $type, \Closure $create, object $content): void
    {
        $block = $create();

        static::assertSame([$type, BlockState::Ready, $content, false], [$block->type, $block->state, $block->content, $block->reading]);
        static::assertSame($type, \constant(Block::class . '::' . strtoupper($type)));
        static::assertFileExists(__DIR__ . '/../../../templates/dashboard/block/' . $type . '.html.twig');
    }

    /**
     * @param \Closure(): Block $create
     */
    #[DataProvider('stackBlocks')]
    public function testAStackBlockCanBeReading(string $type, \Closure $create, object $content): void
    {
        $block = $create()->withReading();

        static::assertSame([$type, BlockState::Ready, $content, true], [$block->type, $block->state, $block->content, $block->reading]);
    }

    public function testASectionIsReadyAndReadingWhenOneOfItsBlocksIs(): void
    {
        $section = new Section('commands', 'Commands', 'Runs of bin/console.', [Block::cards([])]);
        $block = Block::section($section);

        static::assertSame([Block::SECTION, BlockState::Ready, $section, false], [$block->type, $block->state, $block->content, $block->reading]);
        static::assertFileExists(__DIR__ . '/../../../templates/dashboard/block/section.html.twig');
        static::assertTrue(Block::section(new Section('messages', 'Messages', '', [Block::cards([]), Block::unavailable(Block::FACTS, BlockState::Reading)]))->reading);
        static::assertTrue(Block::section(new Section('messages', 'Messages', '', [Block::cards([])->withReading()]))->reading);
        static::assertFalse(Block::section(new Section('messages', 'Messages', '', [], empty: ['title' => 'Nothing', 'text' => '']))->reading);
    }

    public function testAFactListMayCarryANote(): void
    {
        static::assertNull((new Facts('Runtime', []))->note);
        static::assertSame('Messages waiting in each transport, counted just now', (new Facts('Queues', [], 'Messages waiting in each transport, counted just now'))->note);
    }
}
