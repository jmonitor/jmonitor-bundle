<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

trait PollsThePage
{
    private static function requestUntil(KernelBrowser $client, string $uri, string $selector): Crawler
    {
        $deadline = microtime(true) + 10;

        do {
            $crawler = $client->request('GET', $uri);
            if ($crawler->filter($selector)->count() > 0) {
                break;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        return $crawler;
    }
}
