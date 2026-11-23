<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\FrankenPhpBinary;
use Jmonitor\JmonitorBundle\Stack\Collector\FrankenPhpMetricsCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\FrankenPhpVersionCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\ProcessRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\StatusPageFetcher;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\Utils\UrlFetcher;

/**
 * The SAPI is read when the service is built, not when the container is compiled: "cache:clear" runs under the CLI SAPI.
 */
final readonly class FrankenPhpDetector implements ComponentDetectorInterface
{
    public function __construct(
        private string $endpoint,
        private ProcessRunner $runner,
        private string $sapi = PHP_SAPI,
        private ?UrlFetcher $fetcher = null,
    ) {}

    public function component(): Component
    {
        return Component::FrankenPhp;
    }

    public function detect(): Detection
    {
        if ($this->sapi !== 'frankenphp') {
            return Detection::unavailable('FrankenPHP is not serving this page.');
        }

        return Detection::found(new CollectorSet(Component::FrankenPhp, [
            new CollectorSpec(new FrankenPhpMetricsCollector($this->endpoint, $this->fetcher ?? new StatusPageFetcher()), Lifetime::Dynamic, CostClass::Network, trackDelta: true),
            new CollectorSpec(new FrankenPhpVersionCollector($this->runner, FrankenPhpBinary::path()), Lifetime::Static, CostClass::Slow),
        ]));
    }
}
