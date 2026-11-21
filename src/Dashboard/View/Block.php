<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Block
{
    public const CARDS = 'cards';
    public const TABLE = 'table';

    private function __construct(
        public readonly string $type,
        public readonly BlockState $state,
        public readonly mixed $content,
        public readonly string $title = '',
        public readonly string $text = '',
    ) {}

    /** @param list<Card> $cards */
    public static function cards(array $cards): self
    {
        return new self(self::CARDS, BlockState::Ready, $cards);
    }

    public static function table(Table $table): self
    {
        return new self(self::TABLE, BlockState::Ready, $table);
    }

    public static function unavailable(string $type, BlockState $state, string $title = '', string $text = ''): self
    {
        if ($state === BlockState::Ready) {
            throw new \LogicException('An unavailable block cannot be ready.');
        }

        return new self($type, $state, null, $title, $text);
    }
}
