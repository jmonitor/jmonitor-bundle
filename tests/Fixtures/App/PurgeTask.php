<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App;

use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[AsPeriodicTask('1 day', from: '2100-01-02T00:00:00+00:00', schedule: 'maintenance', method: 'purge')]
final class PurgeTask
{
    public function purge(): void {}
}
