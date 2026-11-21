<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Jmonitor\JmonitorBundle\Recording\TaskLabel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Trigger\StaticMessageProvider;
use Symfony\Contracts\Service\ServiceProviderInterface;

final class DeclaredTasks
{
    public function __construct(
        private readonly ?ServiceProviderInterface $schedules = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** @return list<DeclaredTask> */
    public function all(int $now): array
    {
        if ($this->schedules === null) {
            return [];
        }

        $tasks = [];
        foreach (array_keys($this->schedules->getProvidedServices()) as $name) {
            $name = (string) $name;

            try {
                array_push($tasks, ...$this->read($this->schedules, $name, $now));
            } catch (\Throwable $e) {
                $this->logger?->error(sprintf('JMonitor could not read the "%s" schedule: %s', $name, $e->getMessage()), ['exception' => $e]);
            }
        }

        return $tasks;
    }

    /** @return list<DeclaredTask> */
    private function read(ServiceProviderInterface $schedules, string $name, int $now): array
    {
        $at = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        $tasks = [];
        foreach ($schedules->get($name)->getSchedule()->getRecurringMessages() as $message) {
            $trigger = $message->getTrigger();
            $tasks[] = new DeclaredTask($name, $message->getId(), self::label($name, $message, $at), (string) $trigger, $trigger->getNextRunDate($at)?->getTimestamp());
        }

        return $tasks;
    }

    private static function label(string $schedule, RecurringMessage $message, \DateTimeImmutable $at): string
    {
        $provider = $message->getProvider();

        if ($provider instanceof StaticMessageProvider) {
            foreach ($provider->getMessages(new MessageContext($schedule, $message->getId(), $message->getTrigger(), $at)) as $first) {
                return TaskLabel::of($first instanceof Envelope ? $first->getMessage() : $first);
            }
        }

        return $provider instanceof \Stringable ? (string) $provider : $provider->getId();
    }
}
