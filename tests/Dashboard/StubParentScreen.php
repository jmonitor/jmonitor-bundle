<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Dashboard\ActionScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\BlockScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ParentScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;

final class StubParentScreen implements ParentScreenInterface, BlockScreenInterface, ActionScreenInterface
{
    /** @var list<string> */
    public array $asked = [];

    /** @var list<string> */
    public array $ran = [];

    /**
     * @param array<string, ScreenInterface>|\Throwable $children
     * @param list<Block>                               $blocks
     * @param array<string, string>|\Throwable          $actions
     */
    public function __construct(
        private readonly string $id = 'stub',
        private readonly array|\Throwable $children = [],
        private readonly array $blocks = [],
        private readonly array|\Throwable $actions = [],
        private readonly bool $available = true,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return ucfirst($this->id);
    }

    public function heading(): string
    {
        return ucfirst($this->id) . ' heading';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function child(string $id): ?ScreenInterface
    {
        $this->asked[] = $id;

        if ($this->children instanceof \Throwable) {
            throw $this->children;
        }

        return $this->children[$id] ?? null;
    }

    public function blocks(ScreenRequest $request): array
    {
        return $this->blocks;
    }

    public function actions(): array
    {
        if ($this->actions instanceof \Throwable) {
            throw $this->actions;
        }

        return $this->actions;
    }

    public function run(string $action): void
    {
        $this->ran[] = $action;
    }
}
