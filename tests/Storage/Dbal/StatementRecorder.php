<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Storage\Dbal;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;

final class StatementRecorder implements Middleware
{
    /** @var list<array{sql: string, params: array<int|string, mixed>}> */
    public array $statements = [];

    /**
     * @param array<string, mixed> $params
     *
     * @return array{Connection, self}
     */
    public static function connect(array $params): array
    {
        $recorder = new self();
        $configuration = new Configuration();
        $configuration->setMiddlewares([$recorder]);

        return [DriverManager::getConnection($params, $configuration), $recorder];
    }

    public function wrap(Driver $driver): Driver
    {
        $recorder = $this;

        return new class ($driver, $recorder) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly StatementRecorder $recorder)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): DriverConnection
            {
                return new RecordingConnection(parent::connect($params), $this->recorder);
            }
        };
    }

    /**
     * @return list<array<int|string, mixed>>
     */
    public function paramsOf(string $sqlPrefix): array
    {
        $params = [];
        foreach ($this->statements as $statement) {
            if (str_starts_with($statement['sql'], $sqlPrefix)) {
                $params[] = $statement['params'];
            }
        }

        return $params;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function record(string $sql, array $params): void
    {
        $this->statements[] = ['sql' => $sql, 'params' => $params];
    }
}

final class RecordingConnection extends AbstractConnectionMiddleware
{
    public function __construct(DriverConnection $connection, private readonly StatementRecorder $recorder)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new RecordingStatement(parent::prepare($sql), $this->recorder, $sql);
    }
}

final class RecordingStatement extends AbstractStatementMiddleware
{
    /** @var array<int|string, mixed> */
    private array $params = [];

    public function __construct(Statement $statement, private readonly StatementRecorder $recorder, private readonly string $sql)
    {
        parent::__construct($statement);
    }

    public function bindValue($param, $value, $type = ParameterType::STRING): void
    {
        $this->params[$param] = $value;

        parent::bindValue($param, $value, $type);
    }

    public function execute($params = null): Result
    {
        $this->recorder->record($this->sql, $params ?? $this->params);

        return $params === null ? parent::execute() : parent::execute($params);
    }
}
