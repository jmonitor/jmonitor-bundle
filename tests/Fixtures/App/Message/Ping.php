<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message;

final class Ping
{
    public function __construct(public readonly string $mode = 'ok') {}
}
