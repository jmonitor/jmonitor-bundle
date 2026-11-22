<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Nginx\NginxCollector;
use Jmonitor\Utils\UrlFetcher;

/**
 * The version, the CPU count and a few settings of the Nginx configuration, without its status page and
 * without the rest of what "nginx -T" prints. Without a version from "nginx -V", it takes $fallbackVersion.
 */
final readonly class NginxConfigCollector implements CollectorInterface
{
    private const SETTINGS = ['config_path', 'worker_processes', 'worker_connections', 'keepalive_timeout', 'gzip', 'server_tokens', 'ssl_protocols'];

    public function __construct(
        private StackShell $shell,
        private ?string $fallbackVersion = null,
    ) {}

    /**
     * @return array{version: ?string, cpu_count: ?int, config: array<string, mixed>}
     *
     * @throws MissingPrerequisiteException nginx cannot be found
     */
    public function collect(): array
    {
        $payload = $this->inner()->collect();
        $config = [];

        foreach (self::SETTINGS as $setting) {
            $config[$setting] = $payload['config'][$setting] ?? null;
        }

        return [
            'version' => $payload['version'] ?? $this->fallbackVersion,
            'cpu_count' => $payload['cpu_count'],
            'config' => $config,
        ];
    }

    public function getVersion(): int
    {
        return $this->inner()->getVersion();
    }

    public function getName(): string
    {
        return 'nginx.config';
    }

    private function inner(): NginxCollector
    {
        return new NginxCollector('', $this->shell, new class extends UrlFetcher {
            public function fetch(string $url): string
            {
                return '';
            }
        });
    }
}
