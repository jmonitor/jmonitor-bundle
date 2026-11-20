<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql\Dbal;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

final class Connection extends AbstractConnectionMiddleware
{
    use TimesQueries;

    public function commit(): void
    {
        if (!$this->recorder->isRecording()) {
            parent::commit();

            return;
        }

        $start = hrtime(true);
        try {
            parent::commit();
        } finally {
            $this->recorder->addSql('COMMIT', (hrtime(true) - $start) / 1e6, $this->dialect);
        }
    }

    public function rollBack(): void
    {
        if (!$this->recorder->isRecording()) {
            parent::rollBack();

            return;
        }

        $start = hrtime(true);
        try {
            parent::rollBack();
        } finally {
            $this->recorder->addSql('ROLLBACK', (hrtime(true) - $start) / 1e6, $this->dialect);
        }
    }
}
