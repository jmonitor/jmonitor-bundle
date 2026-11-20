<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

final class ExceptionRecorder implements EventSubscriberInterface, ResetInterface
{
    private const ANONYMOUS = 'class@anonymous';

    private ?string $class = null;

    public function __construct(private readonly Recorder $recorder) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 4096],
            KernelEvents::EXCEPTION => ['onException', 4096],
            KernelEvents::TERMINATE => ['onTerminate', 2048],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->class = null;
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        $class = $event->getThrowable()::class;
        $this->class = str_contains($class, '@anonymous') ? self::ANONYMOUS : $class;
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $class = $this->class;
        $this->class = null;

        if ($class === null || $event->getResponse()->getStatusCode() < 500) {
            return;
        }

        $token = $event->getRequest()->attributes->get(RequestRecorder::UNIT);
        $this->recorder->add(\is_int($token) ? $token : null, Entry::TYPE_EXCEPTION, $class);
    }

    public function reset(): void
    {
        $this->class = null;
    }
}
