<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App;

use Doctrine\DBAL\Connection;
use Psr\Log\AbstractLogger;

/** A logger whose handler writes through the Doctrine connection, as a database log handler would. */
final class ConnectionLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $records = [];

    public function __construct(public readonly Connection $connection) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = $level . ' ' . $message;
    }
}
