<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording;

use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\AbstractWorkerMessageEvent;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Contracts\Service\ResetInterface;

final class MessengerRecorder implements EventSubscriberInterface, ResetInterface
{
    /** @var array{token: int, message: object, type: string, key: string, startedAt: float, stoppedAt: ?float, memory: ?int}|null */
    private ?array $unit = null;

    private bool $failureLogged = false;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly ?Flusher $flusher,
        private readonly bool $messages = true,
        private readonly bool $tasks = true,
        private readonly bool $exceptions = true,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $enabled = true,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => ['onReceived', -4096],
            WorkerMessageHandledEvent::class => ['onHandled', 4096],
            WorkerMessageFailedEvent::class => [['stopOnFailure', 4096], ['closeOnFailure', 0]],
            SendMessageToTransportsEvent::class => ['onSent', 0],
            WorkerRunningEvent::class => ['onRunning', 0],
            WorkerStoppedEvent::class => ['onStopped', 0],
        ];
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->discardOpenUnit();
            if (!$event->shouldHandle()) {
                return;
            }

            $envelope = $event->getEnvelope();
            $message = $envelope->getMessage();
            $context = $envelope->last(ScheduledStamp::class)?->messageContext;

            if ($context !== null) {
                if (!$this->tasks) {
                    return;
                }
                $type = Entry::TYPE_TASK;
                $key = (new TaskKey($context->name, $context->id, TaskLabel::of($message), (string) $context->trigger))->toString();
            } else {
                if (!$this->messages) {
                    return;
                }
                $type = Entry::TYPE_MESSAGE;
                $key = ClassName::of($message);
            }

            memory_reset_peak_usage();
            $startedAt = microtime(true);
            $this->unit = [
                'token' => $this->recorder->begin($type, $startedAt),
                'message' => $message,
                'type' => $type,
                'key' => $key,
                'startedAt' => $startedAt,
                'stoppedAt' => null,
                'memory' => null,
            ];
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $now = microtime(true);
            $unit = $this->unitOf($event);
            if ($unit === null || $unit['stoppedAt'] !== null) {
                return;
            }

            $this->unit = null;
            $this->close($unit, $now, $this->peakMemory($unit['type']), []);
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    /**
     * Ends the measure before the other listeners, such as the one that sends the message again for a retry,
     * and mutes what they do until the unit is closed.
     */
    public function stopOnFailure(WorkerMessageFailedEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $now = microtime(true);
            $unit = $this->unitOf($event);
            if ($unit === null || $unit['stoppedAt'] !== null) {
                return;
            }

            if ($this->exceptions) {
                $error = $event->getThrowable();
                if ($error instanceof HandlerFailedException) {
                    $error = $error->getPrevious() ?? $error;
                }
                $this->recorder->add($unit['token'], Entry::TYPE_EXCEPTION, ClassName::of($error));
            }

            $unit['stoppedAt'] = $now;
            $unit['memory'] = $this->peakMemory($unit['type']);
            $this->unit = $unit;
            $this->recorder->begin($unit['type'], $now, true);
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function closeOnFailure(WorkerMessageFailedEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $unit = $this->unit;
            if ($unit === null || $unit['stoppedAt'] === null || $unit['message'] !== $event->getEnvelope()->getMessage()) {
                return;
            }

            $this->unit = null;
            $outcome = $unit['type'] === Entry::TYPE_MESSAGE && $event->willRetry() ? 'retried' : 'failed';
            $this->close($unit, $unit['stoppedAt'], $unit['memory'], [$outcome => 1]);
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function onSent(SendMessageToTransportsEvent $event): void
    {
        if (!$this->enabled || !$this->messages) {
            return;
        }

        try {
            $this->recorder->add($this->recorder->current(), Entry::TYPE_SENT, ClassName::of($event->getEnvelope()->getMessage()));
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function onRunning(WorkerRunningEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->discardOpenUnit();
            $this->flusher?->flushIfDue();
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function onStopped(WorkerStoppedEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->discardOpenUnit();
            $this->flusher?->flushOnExit();
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function reset(): void
    {
        $this->unit = null;
    }

    /**
     * An acknowledgement for another message than the one of the open unit comes from a batch: the open unit
     * holds the work of the whole batch, and is abandoned.
     *
     * @return array{token: int, message: object, type: string, key: string, startedAt: float, stoppedAt: ?float, memory: ?int}|null
     */
    private function unitOf(AbstractWorkerMessageEvent $event): ?array
    {
        if ($this->unit !== null && $this->unit['message'] !== $event->getEnvelope()->getMessage()) {
            $this->discardOpenUnit();
        }

        return $this->unit;
    }

    /**
     * @param array{token: int, message: object, type: string, key: string, startedAt: float, stoppedAt: ?float, memory: ?int} $unit
     * @param array<string, int> $attributes
     */
    private function close(array $unit, float $endedAt, ?int $memory, array $attributes): void
    {
        if ($memory !== null) {
            $attributes['memory'] = $memory;
        }

        $this->recorder->end($unit['token'], $unit['key'], ($endedAt - $unit['startedAt']) * 1000, $attributes);
    }

    private function peakMemory(string $type): ?int
    {
        return $type === Entry::TYPE_MESSAGE ? memory_get_peak_usage(true) : null;
    }

    private function discardOpenUnit(): void
    {
        if ($this->unit === null) {
            return;
        }

        $token = $this->unit['token'];
        $this->unit = null;
        $this->recorder->discard($token);
    }

    private function logFailure(\Throwable $e): void
    {
        if ($this->failureLogged) {
            return;
        }

        $this->failureLogged = true;
        $this->logger?->error('JMonitor could not record a message: ' . $e->getMessage(), ['exception' => $e]);
    }
}
