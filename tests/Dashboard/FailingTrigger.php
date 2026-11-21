<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Symfony\Component\Scheduler\Trigger\TriggerInterface;

final class FailingTrigger implements TriggerInterface
{
    public function __toString(): string
    {
        return 'never';
    }

    public function getNextRunDate(\DateTimeImmutable $run): ?\DateTimeImmutable
    {
        throw new \LogicException('Broken trigger');
    }
}
