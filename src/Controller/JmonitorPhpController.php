<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Controller;

use Jmonitor\Collector\Php\PhpCollector;
use Symfony\Component\HttpFoundation\JsonResponse;

final class JmonitorPhpController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse((new PhpCollector())->collect());
    }
}
