<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Stack\Check\Severity;
use Jmonitor\JmonitorBundle\Stack\Check\Verdict;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;

final class Marks
{
    public static function verdict(Verdict $verdict): Mark
    {
        return new Mark(match ($verdict) {
            Verdict::Critical => 'bad',
            Verdict::Warning => 'warn',
            Verdict::Healthy => 'ok',
            Verdict::Unknown => 'unknown',
        }, $verdict->label());
    }

    public static function severity(Severity $severity): Mark
    {
        return new Mark(match ($severity) {
            Severity::Critical => 'bad',
            Severity::Warning => 'warn',
            Severity::Info => 'info',
        }, $severity->label());
    }

    public static function support(SupportStatus $status): ?Mark
    {
        return match ($status) {
            SupportStatus::SecurityOnly => new Mark('warn', 'Security fixes only'),
            SupportStatus::Ended => new Mark('bad', 'End of life'),
            SupportStatus::Active => null,
        };
    }
}
