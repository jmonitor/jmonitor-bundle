<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

final class ScreenRegistry
{
    /** @var list<ScreenInterface> */
    private readonly array $screens;

    /** @param iterable<ScreenInterface> $screens */
    public function __construct(iterable $screens)
    {
        $this->screens = array_values([...$screens]);
    }

    /** @return list<ScreenInterface> */
    public function available(): array
    {
        $available = [];
        foreach ($this->screens as $screen) {
            if ($screen->isAvailable()) {
                $available[] = $screen;
            }
        }

        return $available;
    }

    /** @return list<Space> */
    public function spaces(): array
    {
        $available = array_map(static fn(ScreenInterface $screen): Space => $screen->space(), $this->available());

        return array_values(array_filter(Space::cases(), static fn(Space $space): bool => \in_array($space, $available, true)));
    }

    /** @return list<ScreenInterface> */
    public function in(Space $space): array
    {
        return array_values(array_filter($this->available(), static fn(ScreenInterface $screen): bool => $screen->space() === $space));
    }

    public function get(string $id): ?ScreenInterface
    {
        foreach ($this->available() as $screen) {
            if ($screen->id() === $id) {
                return $screen;
            }
        }

        return null;
    }

    public function default(bool $storageUsable = true): ScreenInterface
    {
        $available = $this->available();

        if (!$storageUsable) {
            foreach ($available as $screen) {
                if (!$screen instanceof RecordedScreenInterface) {
                    return $screen;
                }
            }
        }

        return $available[0] ?? throw new \LogicException('JMonitor has no available screen.');
    }
}
