<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;

/**
 * The version Apache tells PHP, when its status page cannot.
 */
final readonly class ApacheSoftwareCollector implements CollectorInterface
{
    private const VERSION = '~^Apache/(\d+\.\d+\.\d+)~';

    public function __construct(
        private ?string $software,
        private string $sapi = PHP_SAPI,
    ) {}

    /**
     * @return array{version?: string}
     */
    public function collect(): array
    {
        $software = $this->software;

        if (($software === null || preg_match(self::VERSION, $software) !== 1) && $this->sapi === 'apache2handler' && function_exists('apache_get_version')) {
            $software = apache_get_version();
        }

        return is_string($software) && preg_match(self::VERSION, $software, $matches) === 1 ? ['version' => $matches[1]] : [];
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'apache.software';
    }
}
