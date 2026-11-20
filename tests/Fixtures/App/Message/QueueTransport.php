<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Hands each message out once, as a broker does while the message waits for its acknowledgement:
 * the in-memory transport hands it out again on every fetch.
 */
final class QueueTransport implements TransportInterface
{
    /** @var array<int, Envelope> */
    private array $queue = [];
    private int $nextId = 1;

    public function get(): iterable
    {
        $id = array_key_first($this->queue);
        if ($id === null) {
            return [];
        }

        $envelope = $this->queue[$id];
        unset($this->queue[$id]);

        return [$envelope->with(new TransportMessageIdStamp($id))];
    }

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void {}

    public function send(Envelope $envelope): Envelope
    {
        $this->queue[$this->nextId++] = $envelope;

        return $envelope;
    }
}
