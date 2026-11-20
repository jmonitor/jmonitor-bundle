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
    /** @var array{token: int, message: object, type: string, key: string, startedAt: float}|null */
    private ?array $unit = null;

    private bool $failureLogged = false;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly ?Flusher $flusher,
        private readonly bool $messages = true,
        private readonly bool $tasks = true,
        private readonly bool $exceptions = true,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => ['onReceived', -4096],
            WorkerMessageHandledEvent::class => ['onHandled', 4096],
            WorkerMessageFailedEvent::class => ['onFailed', 0],
            SendMessageToTransportsEvent::class => ['onSent', 0],
            WorkerRunningEvent::class => ['onRunning', 0],
            WorkerStoppedEvent::class => ['onStopped', 0],
        ];
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
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
            ];
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        try {
            $now = microtime(true);
            $unit = $this->takeUnitOf($event);
            if ($unit !== null) {
                $this->close($unit, $now, []);
            }
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        try {
            $now = microtime(true);
            $unit = $this->takeUnitOf($event);
            if ($unit === null) {
                return;
            }

            if ($this->exceptions) {
                $error = $event->getThrowable();
                if ($error instanceof HandlerFailedException) {
                    $error = $error->getPrevious() ?? $error;
                }
                $this->recorder->add($unit['token'], Entry::TYPE_EXCEPTION, ClassName::of($error));
            }

            $outcome = $unit['type'] === Entry::TYPE_MESSAGE && $event->willRetry() ? 'retried' : 'failed';
            $this->close($unit, $now, [$outcome => 1]);
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function onSent(SendMessageToTransportsEvent $event): void
    {
        if (!$this->messages) {
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
        try {
            $this->flusher?->flushIfDue();
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function onStopped(WorkerStoppedEvent $event): void
    {
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
     * @return array{token: int, message: object, type: string, key: string, startedAt: float}|null
     */
    private function takeUnitOf(AbstractWorkerMessageEvent $event): ?array
    {
        $unit = $this->unit;
        if ($unit === null || $unit['message'] !== $event->getEnvelope()->getMessage()) {
            return null;
        }

        $this->unit = null;

        return $unit;
    }

    /**
     * @param array{token: int, message: object, type: string, key: string, startedAt: float} $unit
     * @param array<string, int>                                                             $attributes
     */
    private function close(array $unit, float $now, array $attributes): void
    {
        if ($unit['type'] === Entry::TYPE_MESSAGE) {
            $attributes['memory'] = memory_get_peak_usage(true);
        }

        $this->recorder->end($unit['token'], $unit['key'], ($now - $unit['startedAt']) * 1000, $attributes);
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
