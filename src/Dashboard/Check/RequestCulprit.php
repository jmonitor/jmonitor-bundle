<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

/**
 * @internal the read that names the culprit of a server error and of a slow request, the same for both so that a screen showing both reads it once
 */
final class RequestCulprit
{
    public static function query(Period $period, int $now): ReportQuery
    {
        return new ReportQuery($period, $now, Family::Request, metrics: [Metric::REQUEST_STATUS_PREFIX . '5xx', Metric::REQUEST_SLOW, Metric::REQUEST_DURATION]);
    }
}
