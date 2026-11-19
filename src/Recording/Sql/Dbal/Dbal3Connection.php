<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql\Dbal;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

/**
 * Under DBAL 3, commit() and rollBack() return the result of the wrapped connection, which the host may read.
 */
final class Dbal3Connection extends AbstractConnectionMiddleware
{
    use TimesQueries;

    /**
     * @return bool
     */
    public function commit()
    {
        if (!$this->recorder->isRecording()) {
            return parent::commit();
        }

        $start = hrtime(true);
        try {
            return parent::commit();
        } finally {
            $this->recorder->addSql('COMMIT', (hrtime(true) - $start) / 1e6, $this->dialect);
        }
    }

    /**
     * @return bool
     */
    public function rollBack()
    {
        if (!$this->recorder->isRecording()) {
            return parent::rollBack();
        }

        $start = hrtime(true);
        try {
            return parent::rollBack();
        } finally {
            $this->recorder->addSql('ROLLBACK', (hrtime(true) - $start) / 1e6, $this->dialect);
        }
    }
}
