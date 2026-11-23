<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver;
use Doctrine\DBAL\Exception\ConnectionLost;

/**
 * A connection that answers each query by the first fragment it contains. A query that succeeds
 * opens it; a lost connection closes it, as DBAL does; another failure leaves it as it was.
 */
final class FakeDatabase extends Connection
{
    /** @var list<string> */
    public array $queries = [];

    /** @var list<string|false> the read timeout of mysqlnd while each query ran */
    public array $readTimeouts = [];

    public int $closes = 0;

    /** @var list<\Throwable> */
    private array $next = [];

    /**
     * @param array<string, list<array<string, mixed>>|\Throwable> $answers query fragment => rows, or what each query containing it throws
     */
    public function __construct(
        private array $answers = [],
        private bool $connected = false,
    ) {
        parent::__construct([], new Driver());
    }

    /**
     * @param list<array<string, mixed>>|\Throwable $answer
     */
    public function answer(string $fragment, array|\Throwable $answer): self
    {
        $this->answers[$fragment] = $answer;

        return $this;
    }

    /**
     * The next query throws $e, whatever it asks.
     */
    public function failNext(\Throwable $e): self
    {
        $this->next[] = $e;

        return $this;
    }

    public function calls(): int
    {
        return \count($this->queries);
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        $this->queries[] = $query;
        $this->readTimeouts[] = ini_get('mysqlnd.net_read_timeout');

        if ($this->next !== []) {
            $this->fail(array_shift($this->next));
        }

        $answer = [];
        foreach ($this->answers as $fragment => $rows) {
            if (str_contains($query, (string) $fragment)) {
                $answer = $rows;
                break;
            }
        }

        if ($answer instanceof \Throwable) {
            $this->fail($answer);
        }

        $this->connected = true;

        return $answer;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close(): void
    {
        ++$this->closes;
        $this->connected = false;
    }

    private function fail(\Throwable $e): never
    {
        if ($e instanceof ConnectionLost) {
            $this->connected = false;
        }

        throw $e;
    }
}
