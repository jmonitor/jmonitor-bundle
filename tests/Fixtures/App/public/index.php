<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$environment = $_SERVER['APP_ENV'] ?? getenv('APP_ENV');
$debug = $_SERVER['APP_DEBUG'] ?? getenv('APP_DEBUG');

$kernel = new TestKernel(
    \is_string($environment) && $environment !== '' ? $environment : 'dev',
    \is_string($debug) && $debug !== '' ? filter_var($debug, \FILTER_VALIDATE_BOOL) : true,
);

$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
