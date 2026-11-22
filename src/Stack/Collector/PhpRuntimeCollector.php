<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Php\PhpCollector;
use Jmonitor\Collector\ResetInterface;

/**
 * The PHP of the running process, read without its warnings and without the requests its FPM workers are serving:
 * their URIs, query strings and users.
 */
final class PhpRuntimeCollector implements CollectorInterface, BootableCollectorInterface, ResetInterface
{
    private readonly CollectorInterface $php;

    public function __construct(?CollectorInterface $php = null)
    {
        $this->php = $php ?? new PhpCollector();
    }

    public function boot(): void
    {
        if ($this->php instanceof BootableCollectorInterface) {
            $this->php->boot();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        set_error_handler(static fn(): bool => true);

        try {
            $data = $this->php->collect();
        } finally {
            restore_error_handler();
        }

        if (is_array($data['fpm'] ?? null)) {
            unset($data['fpm']['procs']);
        }

        return $data;
    }

    public function reset(): void
    {
        if ($this->php instanceof ResetInterface) {
            $this->php->reset();
        }
    }

    public function getVersion(): int
    {
        return $this->php->getVersion();
    }

    public function getName(): string
    {
        return 'php';
    }
}
