<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Aggregation;

enum Period: int
{
    case OneHour = 3600;
    case SixHours = 21600;
    case OneDay = 86400;
    case SevenDays = 604800;

    public function bucketSeconds(): int
    {
        return intdiv($this->value, 60);
    }

    public function bucketFor(int|float $timestamp): int
    {
        $size = $this->bucketSeconds();

        return intdiv((int) floor($timestamp), $size) * $size;
    }

    public function label(): string
    {
        return match ($this) {
            self::OneHour => '1h',
            self::SixHours => '6h',
            self::OneDay => '24h',
            self::SevenDays => '7d',
        };
    }

    public static function fromLabel(string $label): ?self
    {
        foreach (self::cases() as $period) {
            if ($period->label() === $label) {
                return $period;
            }
        }

        return null;
    }
}
