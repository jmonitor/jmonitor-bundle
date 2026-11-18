<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage;

use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

interface ReaderInterface
{
    public function read(ReportQuery $query): Report;

    /**
     * @param list<string> $keys
     *
     * @return array<string, string> key => label, without the keys that have none
     */
    public function labels(array $keys): array;
}
