<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\Apache\ApacheCollector;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\JmonitorException;
use Jmonitor\Utils\UrlFetcher;

/**
 * The mod_status report without the list of modules and with "total_duration" in seconds. A page that is not
 * mod_status is a missing prerequisite, and the failure of the reader stays in the chain of causes.
 */
final readonly class ApacheStatusCollector implements CollectorInterface
{
    public function __construct(
        private string $endpoint,
        private UrlFetcher $fetcher,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws MissingPrerequisiteException the page is not a mod_status report
     * @throws UnreachableServerException   the page could not be read
     */
    public function collect(): array
    {
        try {
            $payload = $this->inner()->collect();
        } catch (JmonitorException|\RuntimeException $e) {
            $message = sprintf('Cannot read the Apache status at %s. Enable mod_status with a "SetHandler server-status" location reachable from PHP, and check "jmonitor.stack.apache.endpoint".', $this->endpoint);

            throw $e->getPrevious() === null ? new MissingPrerequisiteException($message) : UnreachableServerException::withRootCause($message, $e);
        }

        unset($payload['modules']);

        if (isset($payload['total_duration'])) {
            $payload['total_duration'] /= 1000.0;
        }

        return $payload;
    }

    public function getVersion(): int
    {
        return $this->inner()->getVersion();
    }

    public function getName(): string
    {
        return 'apache';
    }

    private function inner(): ApacheCollector
    {
        return new ApacheCollector($this->endpoint, $this->fetcher);
    }
}
