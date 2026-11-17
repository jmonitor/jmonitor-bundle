<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$kernel = new TestKernel('dev', true);
$kernel->boot();

$handled = 0;

$handler = static function () use ($kernel, &$handled): void {
    ++$handled;

    $request = Request::createFromGlobals();
    $response = $kernel->handle($request);
    $response->headers->set('X-Worker-Requests', (string) $handled);
    $response->send();
    $kernel->terminate($request, $response);
};

while (frankenphp_handle_request($handler)) {
    gc_collect_cycles();
}
