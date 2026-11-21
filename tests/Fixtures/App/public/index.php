<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$kernel = TestKernel::fromEnvironment();

$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
