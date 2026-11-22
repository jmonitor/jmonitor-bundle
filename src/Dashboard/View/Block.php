<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Block
{
    public const CARDS = 'cards';
    public const TABLE = 'table';
    public const SUBNAV = 'subnav';
    public const NOTICE = 'notice';
    public const CHECKS = 'checks';
    public const COMPONENTS = 'components';
    public const GAUGES = 'gauges';
    public const FACTS = 'facts';

    private function __construct(
        public readonly string $type,
        public readonly BlockState $state,
        public readonly mixed $content,
        public readonly string $title = '',
        public readonly string $text = '',
        public readonly bool $reading = false,
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

    public static function subnav(SubNav $subnav): self
    {
        return new self(self::SUBNAV, BlockState::Ready, $subnav);
    }

    public static function notice(Notice $notice): self
    {
        return new self(self::NOTICE, BlockState::Ready, $notice);
    }

    public static function checks(CheckList $checks): self
    {
        return new self(self::CHECKS, BlockState::Ready, $checks);
    }

    public static function components(ComponentCards $components): self
    {
        return new self(self::COMPONENTS, BlockState::Ready, $components);
    }

    public static function gauges(Gauges $gauges): self
    {
        return new self(self::GAUGES, BlockState::Ready, $gauges);
    }

    public static function facts(Facts $facts): self
    {
        return new self(self::FACTS, BlockState::Ready, $facts);
    }

    public static function unavailable(string $type, BlockState $state, string $title = '', string $text = ''): self
    {
        if ($state === BlockState::Ready) {
            throw new \LogicException('An unavailable block cannot be ready.');
        }

        return new self($type, $state, null, $title, $text, $state === BlockState::Reading);
    }

    public function withReading(): self
    {
        return new self($this->type, $this->state, $this->content, $this->title, $this->text, true);
    }
}
