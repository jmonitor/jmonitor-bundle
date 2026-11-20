<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\HttpClient;

use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class CallRecorder
{
    private const UNKNOWN_HOST = '(unknown)';
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    private bool $failureLogged = false;

    /**
     * @param LoggerInterface|(\Closure(): ?LoggerInterface)|null $logger a closure keeps the logger, whose handlers may call out, out of the graph of the client
     */
    public function __construct(
        private readonly Recorder $recorder,
        private LoggerInterface|\Closure|null $logger = null,
    ) {}

    public function unit(): ?int
    {
        return $this->recorder->current();
    }

    /**
     * @param int $startedAt hrtime(true) when the call was made
     */
    public function record(int $unit, ResponseInterface $response, string $url, int $startedAt): void
    {
        try {
            $previousAttempts = $response->getInfo('previous_info');
            // A retried call is timed from its request, earlier attempts and pauses included.
            $seconds = \is_array($previousAttempts) && $previousAttempts !== [] ? null : $response->getInfo('total_time');
            $durationMs = \is_int($seconds) || \is_float($seconds) ? $seconds * 1000 : 0.0;
            if (!is_finite($durationMs) || $durationMs <= 0.0) {
                $durationMs = (hrtime(true) - $startedAt) / 1e6;
            }

            $host = self::host($response->getInfo('url')) ?? self::host($url) ?? self::UNKNOWN_HOST;
            $this->recorder->add($unit, Entry::TYPE_HTTP, $host, $durationMs, self::outcome($response));
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    private static function host(mixed $url): ?string
    {
        $parts = \is_string($url) ? parse_url($url) : false;
        if (!\is_array($parts) || ($parts['host'] ?? '') === '') {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        if ($port !== null && $port !== (self::DEFAULT_PORTS[strtolower($parts['scheme'] ?? '')] ?? null)) {
            $host .= ':' . $port;
        }

        return $host;
    }

    /**
     * A transport error before any header leaves no status, whatever the status code the client reports.
     *
     * @return array<string, int>
     */
    private static function outcome(ResponseInterface $response): array
    {
        $status = $response->getInfo('http_code');
        if (!\is_int($status) || $status <= 0 || ($response->getInfo('error') !== null && !$response->getInfo('response_headers'))) {
            return ['cf' => 1];
        }

        return match (intdiv($status, 100)) {
            4 => ['c4' => 1],
            5 => ['c5' => 1],
            default => [],
        };
    }

    public function logFailure(\Throwable $e): void
    {
        if ($this->failureLogged) {
            return;
        }

        $this->failureLogged = true;
        if ($this->logger instanceof \Closure) {
            $this->logger = ($this->logger)();
        }
        $this->logger?->error('JMonitor could not record an outgoing HTTP call: ' . $e->getMessage(), ['exception' => $e]);
    }
}
