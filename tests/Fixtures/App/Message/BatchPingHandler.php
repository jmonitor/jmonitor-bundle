<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;

final class BatchPingHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    public const SIZE = 3;

    public function __construct(private readonly ?Connection $connection = null) {}

    public function __invoke(BatchPing $message, ?Acknowledger $ack = null): mixed
    {
        return $this->handle($message, $ack);
    }

    /**
     * @param list<array{0: object, 1: Acknowledger}> $jobs
     */
    private function process(array $jobs): void
    {
        foreach ($jobs as [$message, $ack]) {
            $this->connection?->executeQuery('SELECT 1')->fetchOne();
            $ack->ack($message);
        }
    }

    private function shouldFlush(): bool
    {
        return \count($this->jobs) >= self::SIZE;
    }
}
