<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message;

use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * "counted://12" holds 12 messages; "counted://uncountable", "counted://broken" and "counted://hanging"
 * cannot count, fail to, or take 30 seconds to.
 */
final class CountedTransportFactory implements TransportFactoryInterface
{
    private const SCHEME = 'counted://';

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return match ($dsn) {
            self::SCHEME . 'uncountable' => new QueueTransport(),
            self::SCHEME . 'broken' => new CountedTransport(0, 'Cannot reach amqp://guest:s3cret@broker'),
            self::SCHEME . 'hanging' => new CountedTransport(0, hangsFor: 30),
            default => new CountedTransport((int) substr($dsn, \strlen(self::SCHEME))),
        };
    }

    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, self::SCHEME);
    }
}
