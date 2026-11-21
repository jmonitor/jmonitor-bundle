<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Controller;

use Jmonitor\JmonitorBundle\Controller\StandaloneUrls;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class StandaloneUrlsTest extends TestCase
{
    public function testWithoutARouterThePageIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new StandaloneUrls(null))->page('requests');
    }
}
