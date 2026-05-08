<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\CollectorException;
use Jmonitor\JmonitorBundle\Collector\Components\ComponentCollectorInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Collects metrics for Symfony
 */
class SymfonyCollector implements CollectorInterface, LoggerAwareInterface, BootableCollectorInterface
{
    use LoggerAwareTrait;

    private KernelInterface $kernel;

    /** @var ComponentCollectorInterface[] */
    private array $componentCollectors;

    /**
     * @var array<string, true>
     */
    private array $disabledComponents = [];

    public function __construct(KernelInterface $kernel, iterable $componentCollectors)
    {
        $this->kernel = $kernel;
        $this->componentCollectors = is_array($componentCollectors) ? $componentCollectors : iterator_to_array($componentCollectors);
    }

    public function boot(): void
    {
        foreach ($this->componentCollectors as $name => $collector) {
            try {
                $collector->boot();
            } catch (BootFailedException $e) {
                $this->logger?->error('Symfony component "{component}" failed to boot; component disabled until worker restart.', [
                    'component' => $name,
                    'message' => $e->getPrevious()?->getMessage() ?? $e->getMessage(),
                    'exception' => $e->getPrevious() ?? $e,
                ]);

                $this->disabledComponents[$name] = true;
            }
        }
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
            if (isset($this->disabledComponents[$name])) {
                continue;
            }

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
