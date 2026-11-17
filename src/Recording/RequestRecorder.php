<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording;

use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestRecorder implements EventSubscriberInterface
{
    private const STARTED_AT = '_jmonitor_started_at';
    private const MAX_REQUEST_AGE = 300;
    private const INTERNAL_ROUTE_PREFIXES = ['_wdt', '_profiler', 'jmonitor_'];
    private const STANDARD_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    private int $handledRequests = 0;
    private bool $failureLogged = false;

    /**
     * @param list<string> $ignoreRoutes fnmatch patterns on the route name
     */
    public function __construct(
        private readonly BufferInterface $buffer,
        private readonly Flusher $flusher,
        private readonly array $ignoreRoutes = [],
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 4096],
            KernelEvents::TERMINATE => [['onTerminate', 1024], ['onTerminateFlush', -1024]],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        try {
            if (!$event->isMainRequest()) {
                return;
            }

            if ($this->handledRequests++ > 0) {
                memory_reset_peak_usage();
            }

            $now = microtime(true);
            $request = $event->getRequest();
            $startedAt = $request->server->get('REQUEST_TIME_FLOAT');

            if (!\is_float($startedAt) || $startedAt < $now - self::MAX_REQUEST_AGE || $startedAt > $now) {
                $startedAt = $now;
            }

            $request->attributes->set(self::STARTED_AT, $startedAt);
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        try {
            $now = microtime(true);
            $request = $event->getRequest();
            $startedAt = $request->attributes->get(self::STARTED_AT);

            if (!\is_float($startedAt)) {
                return;
            }

            $route = $request->attributes->get('_route');
            if (\is_string($route) && $this->isIgnored($route)) {
                return;
            }

            $this->buffer->append(new Entry(
                Entry::TYPE_REQUEST,
                self::method($request) . ' ' . (\is_string($route) ? $route : '(unmatched)'),
                $startedAt,
                ($now - $startedAt) * 1000,
                ['status' => $event->getResponse()->getStatusCode(), 'memory' => memory_get_peak_usage(true)],
            ));
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function onTerminateFlush(TerminateEvent $event): void
    {
        try {
            $this->flusher->flushIfDue();
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    private static function method(Request $request): string
    {
        try {
            $method = $request->getMethod();
        } catch (SuspiciousOperationException) {
            return 'OTHER';
        }

        return \in_array($method, self::STANDARD_METHODS, true) ? $method : 'OTHER';
    }

    private function isIgnored(string $route): bool
    {
        foreach (self::INTERNAL_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        foreach ($this->ignoreRoutes as $pattern) {
            if (fnmatch($pattern, $route)) {
                return true;
            }
        }

        return false;
    }

    private function logFailure(\Throwable $e): void
    {
        if ($this->failureLogged) {
            return;
        }

        $this->failureLogged = true;
        $this->logger?->error('JMonitor could not record a request: ' . $e->getMessage(), ['exception' => $e]);
    }
}
