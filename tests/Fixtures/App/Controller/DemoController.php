<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\Controller;

use Symfony\Component\HttpFoundation\Response;

final class DemoController
{
    public function hello(string $name): Response
    {
        return new Response('Hello ' . htmlspecialchars($name));
    }

    public function slow(): Response
    {
        usleep(20000);

        return new Response('Slow');
    }

    public function alloc(): Response
    {
        $block = str_repeat('x', 32 * 1024 * 1024);

        return new Response((string) \strlen($block));
    }

    public function boom(): Response
    {
        throw new \RuntimeException('Boom');
    }

    public function teapot(): Response
    {
        return new Response('I am a teapot', 418);
    }
}
