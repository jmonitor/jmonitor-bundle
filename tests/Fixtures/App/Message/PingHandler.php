<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;

final class PingHandler
{
    public function __construct(
        private readonly ?Connection $connection = null,
        private readonly ?MessageBusInterface $bus = null,
    ) {}

    public function __invoke(Ping $ping): void
    {
        match ($ping->mode) {
            'fail' => throw new \DomainException('Ping failed'),
            'send' => $this->bus?->dispatch(new Ping()),
            default => $this->connection?->executeQuery('SELECT 1')->fetchOne(),
        };
    }
}
