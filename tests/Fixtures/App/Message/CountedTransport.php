<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class CountedTransport implements TransportInterface, MessageCountAwareInterface
{
    public function __construct(
        private readonly int $count,
        private readonly ?string $failure = null,
        private readonly int $hangsFor = 0,
    ) {}

    public function getMessageCount(): int
    {
        if ($this->hangsFor > 0) {
            sleep($this->hangsFor);
        }

        if ($this->failure !== null) {
            throw new TransportException($this->failure);
        }

        return $this->count;
    }

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void {}

    public function send(Envelope $envelope): Envelope
    {
        return $envelope;
    }
}
