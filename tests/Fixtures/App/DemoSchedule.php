<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App;

use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\Ping;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

final class DemoSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::every('1 second', new RunCommandMessage('app:work')),
            RecurringMessage::every('1 hour', new Ping()),
            RecurringMessage::every('1 day', new RunCommandMessage('app:work --times=2'), '2100-01-01T00:00:00+00:00'),
        );
    }
}
