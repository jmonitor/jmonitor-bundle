<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Nginx\NginxCollector;
use Jmonitor\Exceptions\JmonitorException;
use Jmonitor\Utils\ShellExecutor;
use Jmonitor\Utils\UrlFetcher;

/**
 * The stub_status page only, with no command run. A page that is not stub_status is a missing prerequisite,
 * and the failure of the reader stays in the chain of causes.
 */
final readonly class NginxStatusCollector implements CollectorInterface
{
    public function __construct(
        private string $endpoint,
        private UrlFetcher $fetcher,
    ) {}

    /**
     * @return array{status: array<string, ?int>}
     *
     * @throws MissingPrerequisiteException the page is not a stub_status page
     * @throws UnreachableServerException   the page could not be read
     */
    public function collect(): array
    {
        $message = sprintf('Cannot read the Nginx status at %s. Enable stub_status in a location reachable from PHP, and check "jmonitor.stack.nginx.endpoint".', $this->endpoint);
        $inner = new NginxCollector($this->endpoint, new class extends ShellExecutor {
            public function execute(string $command): ?string
            {
                return null;
            }
        }, $this->fetcher);

        try {
            $status = $inner->collect()['status'];
        } catch (JmonitorException|\RuntimeException $e) {
            throw UnreachableServerException::withRootCause($message, $e);
        }

        if (($status['active'] ?? null) === null) {
            throw new MissingPrerequisiteException($message);
        }

        return ['status' => $status];
    }

    public function getVersion(): int
    {
        return (new NginxCollector($this->endpoint))->getVersion();
    }

    public function getName(): string
    {
        return 'nginx';
    }
}
