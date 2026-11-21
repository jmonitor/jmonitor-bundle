<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

final class DeclaredTask
{
    public function __construct(
        public readonly string $schedule,
        public readonly string $id,
        public readonly string $label,
        public readonly string $trigger,
        public readonly ?int $nextRun,
    ) {}
}
