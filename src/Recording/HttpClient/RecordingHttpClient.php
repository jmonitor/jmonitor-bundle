<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\HttpClient;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

final class RecordingHttpClient implements HttpClientInterface, ResetInterface, LoggerAwareInterface
{
    public function __construct(
        private HttpClientInterface $client,
        private readonly CallRecorder $calls,
    ) {}

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $unit = $this->calls->unit();
        if ($unit === null) {
            return $this->client->request($method, $url, $options);
        }

        $startedAt = hrtime(true);

        return new RecordingResponse($this->client, $this->client->request($method, $url, $options), $this->calls, $unit, $url, $startedAt);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof ResponseInterface) {
            $responses = [$responses];
        }

        return new ResponseStream(RecordingResponse::stream($this->client, $responses, $timeout));
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }

    public function reset(): void
    {
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }

    public function setLogger(LoggerInterface $logger): void
    {
        if ($this->client instanceof LoggerAwareInterface) {
            $this->client->setLogger($logger);
        }
    }
}
