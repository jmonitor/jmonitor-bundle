<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql\Dbal;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Jmonitor\JmonitorBundle\Recording\Recorder;

final class Statement extends AbstractStatementMiddleware
{
    public function __construct(
        StatementInterface $statement,
        private readonly Recorder $recorder,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    /**
     * @param mixed[]|null $params only DBAL 3 passes them
     */
    public function execute($params = null): Result
    {
        if (!$this->recorder->isRecording()) {
            return $this->executeWrapped($params);
        }

        $start = hrtime(true);
        try {
            return $this->executeWrapped($params);
        } finally {
            $this->recorder->addSql($this->sql, (hrtime(true) - $start) / 1e6);
        }
    }

    /**
     * @param mixed[]|null $params
     */
    private function executeWrapped(?array $params): Result
    {
        return $params === null ? parent::execute() : parent::execute($params);
    }
}
