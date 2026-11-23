<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\EndOfLifeCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\NginxConfigCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\NginxStatusCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\ProcessRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\StackShell;
use Jmonitor\JmonitorBundle\Stack\Collector\StatusPageFetcher;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\Utils\UrlFetcher;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Nginx is detected from the SERVER_SOFTWARE it passes to PHP-FPM, "nginx/<version>" in the fastcgi_params it ships.
 */
final readonly class NginxDetector implements ComponentDetectorInterface
{
    public function __construct(
        private string $endpoint,
        private RequestStack $requests,
        private ProcessRunner $runner,
        private bool $endOfLife,
        private ?UrlFetcher $fetcher = null,
        private ?UrlFetcher $endOfLifeFetcher = null,
    ) {}

    public function component(): Component
    {
        return Component::Nginx;
    }

    public function detect(): Detection
    {
        $software = $this->requests->getMainRequest()?->server->get('SERVER_SOFTWARE');

        if (!is_string($software) || preg_match('~^nginx(?:/(\S+)|\s|$)~i', $software, $matches) !== 1) {
            return Detection::unavailable('Nginx is not serving this page.');
        }

        $specs = [
            new CollectorSpec(new NginxStatusCollector($this->endpoint, $this->fetcher ?? new StatusPageFetcher()), Lifetime::Dynamic, CostClass::Network, trackDelta: true),
            new CollectorSpec(new NginxConfigCollector(new StackShell($this->runner), ($matches[1] ?? '') !== '' ? $matches[1] : null), Lifetime::Static, CostClass::Slow),
        ];

        if ($this->endOfLife) {
            $specs[] = new CollectorSpec(new EndOfLifeCollector('nginx', $this->endOfLifeFetcher ?? new UrlFetcher(3)), Lifetime::Day, CostClass::Slow, shared: true);
        }

        return Detection::found(new CollectorSet(Component::Nginx, $specs));
    }
}
