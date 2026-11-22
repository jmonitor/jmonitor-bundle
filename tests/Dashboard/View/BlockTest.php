<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\View;

use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
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
}
