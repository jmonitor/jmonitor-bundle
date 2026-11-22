<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Check;

enum Verdict: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Healthy = 'healthy';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::Warning => 'Warning',
            self::Healthy => 'Healthy',
            self::Unknown => 'Unknown',
        };
    }

    public static function worst(self ...$verdicts): self
    {
        foreach ([self::Critical, self::Warning, self::Unknown, self::Healthy] as $verdict) {
            if (in_array($verdict, $verdicts, true)) {
                return $verdict;
            }
        }

        return self::Unknown;
    }
}
