<?php

/*
 * This file is part of jmonitor/collector
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector;

use Jmonitor\Collector\AbstractCollector;
use Jmonitor\JmonitorBundle\Collector\Components\ComponentCollectorInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Collects metrics for Symfony
 */
class SymfonyCollector extends AbstractCollector
{
    private KernelInterface $kernel;
    /** @var ComponentCollectorInterface[] */
    private array $componentCollectors;

    public function __construct(KernelInterface $kernel, iterable $componentCollectors)
    {
        $this->kernel = $kernel;
        $this->componentCollectors = iterator_to_array($componentCollectors);
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        return [
            'env' => $this->kernel->getEnvironment(),
            'debug' => $this->kernel->isDebug(),
            'version' => $this->getSymfonyVersion(),
            'bundles' => array_keys($this->kernel->getBundles()),
            'project_dir' => $this->kernel->getProjectDir(),
            'cache_dir' => $this->getDirData($this->kernel->getCacheDir()),
            'log_dir' => $this->getDirData($this->kernel->getLogDir()),
            'build_dir' => $this->getDirData($this->kernel->getBuildDir()),
            // @phpstan-ignore-next-line
            'share_dir' => \method_exists($this->kernel, 'getShareDir') ? $this->getDirData($this->kernel->getShareDir()) : [],
            'charset' => $this->kernel->getCharset(),
            'components' => array_filter(array_map(function (ComponentCollectorInterface $collector) {
                return $collector->collect();
            }, $this->componentCollectors)),
        ];
    }

    public function getName(): string
    {
        return 'symfony';
    }

    public function getVersion(): int
    {
        return 1;
    }

    /**
     * return un tableau avec clé "path" (le chemin $dir) et "size" le poids du dossier
     *
     * @return array{}|array{path: string, size: int}
     */
    private function getDirData(string $dir): array
    {
        if (!$dir) {
            return [];
        }

        // todo match php >= 8.0
        $size = null;

        if (is_file($dir)) {
            $size = filesize($dir) ?: null;
        } elseif (is_dir($dir)) {
            $size = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                $size += $file->getSize();
            }
        }

        return ['path' => $dir, 'size' => $size];
    }

    private function getSymfonyVersion(): ?string
    {
        if (defined(get_class($this->kernel) . '::VERSION')) {
            return $this->kernel::VERSION; // @phpstan-ignore-line
        }

        return null;
    }
}
