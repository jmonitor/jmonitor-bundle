<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Storage\Read\Report;

/**
 * @internal what the Overview read of the application in a period
 */
final class OverviewRead
{
    /**
     * @param Report       $requests the Request family, read for the curve of the Requests box
     * @param list<string> $counted  the types of the totals that count an activity
     */
    public function __construct(
        public readonly RaisedChecks $raised,
        public readonly Report $requests,
        private readonly array $counted,
    ) {}

    public function isEmpty(): bool
    {
        foreach ($this->counted as $type) {
            if ($this->count($type) > 0) {
                return false;
            }
        }

        return true;
    }

    /** the count of a type in the read that raised the checks, zero when it counted nothing */
    public function count(string $metric): int
    {
        return $this->raised->total($metric)->count ?? 0;
    }
}
