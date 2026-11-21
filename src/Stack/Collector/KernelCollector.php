<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final readonly class KernelCollector implements CollectorInterface
{
    public function __construct(
        private KernelInterface $kernel,
    ) {}

    /**
     * @return array{env: string, debug: bool, version: ?string, bundles: list<string>}
     */
    public function collect(): array
    {
        return [
            'env' => $this->kernel->getEnvironment(),
            'debug' => $this->kernel->isDebug(),
            'version' => $this->version(),
            'bundles' => array_keys($this->kernel->getBundles()),
        ];
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'symfony';
    }

    private function version(): ?string
    {
        $constant = $this->kernel::class . '::VERSION';

        return defined($constant) && is_string(constant($constant)) ? constant($constant) : null;
    }
}
