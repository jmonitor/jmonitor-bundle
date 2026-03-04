<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Controller;

use Jmonitor\Collection;
use Jmonitor\Collector\Php\PhpCollector;
use Symfony\Component\HttpFoundation\JsonResponse;

class JmonitorPhpController
{
    public function __invoke(): JsonResponse
    {
        $collection = new Collection();
        (new PhpCollector())->collect($collection);

        return new JsonResponse($collection);
    }
}
