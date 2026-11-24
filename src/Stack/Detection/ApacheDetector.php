<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\ApacheSoftwareCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\ApacheStatusCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\EndOfLifeCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\StatusPageFetcher;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\Utils\UrlFetcher;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Apache is detected from the SAPI of mod_php, or from the SERVER_SOFTWARE it passes to PHP-FPM.
 * The SAPI is read when the service is built, not when the container is compiled: "cache:clear" runs under the CLI SAPI.
 */
final readonly class ApacheDetector implements ComponentDetectorInterface
{
    public function __construct(
        private string $endpoint,
        private RequestStack $requests,
        private bool $endOfLife,
        private string $sapi = PHP_SAPI,
        private ?UrlFetcher $fetcher = null,
        private ?UrlFetcher $endOfLifeFetcher = null,
    ) {}

    public function component(): Component
    {
        return Component::Apache;
    }

    public function detect(): Detection
    {
        $software = $this->requests->getMainRequest()?->server->get('SERVER_SOFTWARE');

        if ($this->sapi !== 'apache2handler' && !(is_string($software) && str_starts_with($software, 'Apache'))) {
            return Detection::unavailable('Apache is not serving this page.');
        }

        $specs = [
            new CollectorSpec(new ApacheStatusCollector($this->endpoint, $this->fetcher ?? new StatusPageFetcher()), Lifetime::Dynamic, CostClass::Network, trackDelta: true),
            new CollectorSpec(new ApacheSoftwareCollector(is_string($software) ? $software : null, $this->sapi), Lifetime::Dynamic, CostClass::Immediate),
        ];

        if ($this->endOfLife) {
            $specs[] = new CollectorSpec(new EndOfLifeCollector('apache-http-server', $this->endOfLifeFetcher ?? new UrlFetcher(3)), Lifetime::Day, CostClass::Slow, shared: true);
        }

        return Detection::found(new CollectorSet(Component::Apache, $specs));
    }
}
