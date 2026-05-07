<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\CollectorException;
use Jmonitor\JmonitorBundle\Collector\Components\ComponentCollectorInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Collects metrics for Symfony
 */
class SymfonyCollector implements CollectorInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private KernelInterface $kernel;

    /** @var ComponentCollectorInterface[] */
    private array $componentCollectors;

    public function __construct(KernelInterface $kernel, iterable $componentCollectors)
    {
        $this->kernel = $kernel;
        $this->componentCollectors = iterator_to_array($componentCollectors);
    }

    public function collect(): array
    {
        $output =  [
            'env' => $this->kernel->getEnvironment(),
            'debug' => $this->kernel->isDebug(),
            'version' => $this->getSymfonyVersion(),
            'bundles' => array_keys($this->kernel->getBundles()),
            'project_dir' => $this->kernel->getProjectDir(),
            'cache_dir' => $this->kernel->getCacheDir(),
            'log_dir' => $this->kernel->getLogDir(),
            'build_dir' => $this->kernel->getBuildDir(),
            // @phpstan-ignore-next-line
            'share_dir' => \method_exists($this->kernel, 'getShareDir')
                ? $this->kernel->getShareDir()
                : null,
            'charset' => $this->kernel->getCharset(),
            'components' => [],
        ];

        // make the collector resilient to component errors
        foreach ($this->componentCollectors as $name => $collector) {
            try {
                $output['components'][$name] = $collector->collect();
            } catch (CollectorException $e) {
                $this->logger?->warning($e->getMessage(), ['exception' => $e]);
            }
        }

        $output['components'] = array_filter($output['components']);

        return $output;
    }

    public function getName(): string
    {
        return 'symfony';
    }

    public function getVersion(): int
    {
        return 1;
    }

    private function getSymfonyVersion(): ?string
    {
        if (defined(get_class($this->kernel) . '::VERSION')) {
            return $this->kernel::VERSION; // @phpstan-ignore-line
        }

        return null;
    }
}
