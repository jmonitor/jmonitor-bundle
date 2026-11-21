<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Dashboard\BlockScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;

final class StubBlockScreen implements BlockScreenInterface
{
    /** @var list<ScreenRequest> */
    public array $requests = [];

    /**
     * @param list<Block>|\Throwable $blocks
     */
    public function __construct(
        private readonly string $id = 'probe',
        private readonly bool $available = true,
        private readonly array|\Throwable $blocks = [],
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
        return $this->available;
    }

    public function blocks(ScreenRequest $request): array
    {
        $this->requests[] = $request;

        if ($this->blocks instanceof \Throwable) {
            throw $this->blocks;
        }

        return $this->blocks;
    }
}
