<?php

/*
 * This file is part of the jmonitor/jmonitor-bundle package.
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jmonitor\JmonitorBundle\Controller;

use Jmonitor\Collector\Php\PhpCollector;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

abstract class JmonitorPhpController
{
    #[Route(name: 'jmonitor_expose_php_metrics')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse((new PhpCollector())->collect());
    }
}
