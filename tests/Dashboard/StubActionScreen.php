<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Dashboard\ActionScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\BlockScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\Space;

final class StubActionScreen implements BlockScreenInterface, ActionScreenInterface
{
    /** @var list<string> */
    public array $ran = [];

    /**
     * @param array<string, string> $actions
     */
    public function __construct(
        private readonly string $id = 'stub',
        private readonly array $actions = ['ping' => 'Ping'],
        private readonly ?\Throwable $failure = null,
        private readonly Space $space = Space::Application,
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
        return 'Stub heading';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function space(): Space
    {
        return $this->space;
    }

    public function blocks(ScreenRequest $request): array
    {
        return [];
    }

    public function actions(): array
    {
        return $this->actions;
    }

    public function run(string $action): void
    {
        $this->ran[] = $action;

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
