<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Aggregation;

use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Unit;

final class TaskKey
{
    public const SEPARATOR = "\x1e";

    private const LABEL_MAX_BYTES = 200;
    private const TRIGGER_MAX_BYTES = 100;

    public readonly string $schedule;
    public readonly string $id;
    public readonly string $label;
    public readonly string $trigger;

    public function __construct(string $schedule, string $id, string $label, string $trigger)
    {
        $this->schedule = self::clean($schedule);
        $this->id = self::clean($id);
        $this->label = Unit::cut(self::clean($label), self::LABEL_MAX_BYTES);
        $this->trigger = Unit::cut(self::clean($trigger), self::TRIGGER_MAX_BYTES);
    }

    /**
     * @return self|null null when the key does not split into exactly a schedule, an id, a label and a trigger
     */
    public static function parse(string $key): ?self
    {
        $parts = explode(self::SEPARATOR, $key);

        return \count($parts) === 4 ? new self(...$parts) : null;
    }

    public function toString(): string
    {
        return $this->identity() . self::SEPARATOR . $this->label . self::SEPARATOR . $this->trigger;
    }

    public function identity(): string
    {
        return $this->schedule . self::SEPARATOR . $this->id;
    }

    private static function clean(string $part): string
    {
        return str_replace([self::SEPARATOR, Entry::KEY_SEPARATOR], '', $part);
    }
}
