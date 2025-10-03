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

class JmonitorPhpController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse((new PhpCollector())->collect());
    }
}
