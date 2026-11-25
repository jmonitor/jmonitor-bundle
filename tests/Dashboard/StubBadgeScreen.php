<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Dashboard\BlockScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\NavBadge;
use Jmonitor\JmonitorBundle\Dashboard\NavBadgeScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\Space;

final class StubBadgeScreen implements NavBadgeScreenInterface, BlockScreenInterface
{
    /** @var list<'blocks'|'badge'> */
    public array $calls = [];

    public function __construct(
        private readonly string $id = 'watched',
        private readonly NavBadge|\Throwable|null $badge = null,
        private readonly bool $available = true,
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
        return $this->available;
    }

    public function space(): Space
    {
        return $this->space;
    }

    public function navBadge(): ?NavBadge
    {
        $this->calls[] = 'badge';

        if ($this->badge instanceof \Throwable) {
            throw $this->badge;
        }

        return $this->badge;
    }

    public function blocks(ScreenRequest $request): array
    {
        $this->calls[] = 'blocks';

        return [];
    }
}
