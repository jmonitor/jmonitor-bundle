<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

enum ResultState: string
{
    /** read within its lifetime */
    case Fresh = 'fresh';
    /** older than its lifetime, still shown */
    case Stale = 'stale';
    /** never read, or left aside by the budget */
    case Pending = 'pending';
    /** never read, another request is reading it */
    case Busy = 'busy';
    /** no value to show, the reason is the error of the result */
    case Failed = 'failed';
}
