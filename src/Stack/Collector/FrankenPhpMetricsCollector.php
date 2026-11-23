<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\FrankenPhp\FrankenPhpCollector;
use Jmonitor\Exceptions\JmonitorException;
use Jmonitor\Prometheus\PrometheusMetricsProvider;
use Jmonitor\Utils\ShellExecutor;
use Jmonitor\Utils\UrlFetcher;

/**
 * The FrankenPHP metrics of Caddy without the version, with no command run. An export without any FrankenPHP
 * metric is a missing prerequisite, and the failure of the reader stays in the chain of causes.
 */
final readonly class FrankenPhpMetricsCollector implements CollectorInterface
{
    public function __construct(
        private string $endpoint,
        private UrlFetcher $fetcher,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws MissingPrerequisiteException the export has no FrankenPHP metric
     * @throws UnreachableServerException   the metrics could not be read
     */
    public function collect(): array
    {
        $message = sprintf('Cannot read the FrankenPHP metrics at %s. Enable them with the "metrics" global option of the Caddyfile, and check "jmonitor.stack.frankenphp.endpoint".', $this->endpoint);

        try {
            $payload = $this->inner()->collect();
        } catch (JmonitorException|\RuntimeException $e) {
            throw UnreachableServerException::withRootCause($message, $e);
        }

        if (($payload['mode'] ?? null) === null) {
            throw new MissingPrerequisiteException($message);
        }

        unset($payload['version']);

        return $payload;
    }

    public function getVersion(): int
    {
        return $this->inner()->getVersion();
    }

    public function getName(): string
    {
        return 'frankenphp';
    }

    private function inner(): FrankenPhpCollector
    {
        return new FrankenPhpCollector(new PrometheusMetricsProvider($this->endpoint, $this->fetcher), new class extends ShellExecutor {
            public function execute(string $command): ?string
            {
                return null;
            }
        });
    }
}
