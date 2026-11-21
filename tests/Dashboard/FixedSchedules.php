<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

final class FixedSchedules
{
    /**
     * @param array<string, \Closure(): Schedule> $schedules schedule name => what its provider returns or throws
     */
    public static function locator(array $schedules): ServiceLocator
    {
        return new ServiceLocator(array_map(
            static fn(\Closure $schedule): \Closure => static fn(): ScheduleProviderInterface => new class ($schedule) implements ScheduleProviderInterface {
                public function __construct(private readonly \Closure $schedule) {}

                public function getSchedule(): Schedule
                {
                    return ($this->schedule)();
                }
            },
            $schedules,
        ));
    }
}
