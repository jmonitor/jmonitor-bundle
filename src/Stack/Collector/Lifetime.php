<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

enum Lifetime
{
    case Dynamic;
    case HalfMinute;
    case Minute;
    case Static;
    case Day;

    public function seconds(): int
    {
        return match ($this) {
            self::Dynamic => 10,
            self::HalfMinute => 30,
            self::Minute => 60,
            self::Static => 3600,
            self::Day => 86400,
        };
    }

    /**
     * Age up to which a value past its lifetime is still shown, null for as long as it is not replaced.
     */
    public function shownFor(): ?int
    {
        return match ($this) {
            self::Dynamic, self::HalfMinute, self::Minute => 300,
            self::Static, self::Day => null,
        };
    }

    /**
     * How long a value stays in the cache.
     */
    public function keptFor(): int
    {
        return $this->shownFor() ?? 604800;
    }
}
