<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql\Dbal;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Jmonitor\JmonitorBundle\Recording\Recorder;

/**
 * The part of the connection middleware shared by DBAL 3 and 4, whose commit() and rollBack() signatures differ.
 */
trait TimesQueries
{
    public function __construct(ConnectionInterface $connection, private readonly Recorder $recorder)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): StatementInterface
    {
        return new Statement(parent::prepare($sql), $this->recorder, $sql);
    }

    public function query(string $sql): Result
    {
        if (!$this->recorder->isRecording()) {
            return parent::query($sql);
        }

        $start = hrtime(true);
        try {
            return parent::query($sql);
        } finally {
            $this->recorder->addSql($sql, (hrtime(true) - $start) / 1e6);
        }
    }

    public function exec(string $sql): int
    {
        if (!$this->recorder->isRecording()) {
            return (int) parent::exec($sql);
        }

        $start = hrtime(true);
        try {
            return (int) parent::exec($sql);
        } finally {
            $this->recorder->addSql($sql, (hrtime(true) - $start) / 1e6);
        }
    }
}
