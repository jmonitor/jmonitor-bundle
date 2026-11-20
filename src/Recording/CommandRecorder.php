<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording;

use Jmonitor\JmonitorBundle\Flush\Flusher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Service\ResetInterface;

final class CommandRecorder implements EventSubscriberInterface, ResetInterface
{
    public const DEFAULT_IGNORED = [
        'messenger:consume',
        'messenger:failed:*',
        'jmonitor:*',
        'cache:*',
        'assets:*',
        'list',
        'help',
        '_complete',
        'completion',
        'debug:*',
        'lint:*',
        'server:*',
        'doctrine:database:*',
    ];

    private const UNKNOWN = '(unknown)';

    /** @var list<array{command: ?Command, name: string, token: int, startedAt: float, ignored: bool}> innermost last */
    private array $frames = [];

    /** @var \WeakMap<\Throwable, true> errors already counted on a command, so that the commands they go through do not count them again */
    private \WeakMap $counted;

    private bool $failureLogged = false;

    /**
     * @param list<string> $ignore fnmatch patterns on the command name, on top of the default ones
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly ?Flusher $flusher,
        private readonly array $ignore = [],
        private readonly bool $exceptions = true,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->counted = new \WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onCommand', 4096],
            ConsoleEvents::ERROR => ['onError', 0],
            ConsoleEvents::TERMINATE => ['onTerminate', -4096],
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        try {
            $command = $event->getCommand();
            $name = $command?->getName();
            $name = $name === null || $name === '' ? self::UNKNOWN : $name;
            $ignored = $this->isIgnored($name);

            if (\count($this->frames) >= Recorder::MAX_DEPTH) {
                $this->frames = [];
            }

            $startedAt = microtime(true);
            $this->frames[] = [
                'command' => $command,
                'name' => $name,
                'token' => $this->recorder->begin(Entry::TYPE_COMMAND, $startedAt, $ignored),
                'startedAt' => $startedAt,
                'ignored' => $ignored,
            ];
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        if (!$this->exceptions) {
            return;
        }

        try {
            $index = $this->frameOf($event->getCommand());
            $error = $event->getError();
            if ($index === null || $this->frames[$index]['ignored'] || isset($this->counted[$error])) {
                return;
            }

            $this->counted[$error] = true;
            $this->recorder->add($this->frames[$index]['token'], Entry::TYPE_EXCEPTION, ClassName::of($error));
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        try {
            $now = microtime(true);
            $index = $this->frameOf($event->getCommand());
            if ($index === null) {
                return;
            }

            $frame = $this->frames[$index];
            $this->frames = \array_slice($this->frames, 0, $index);

            if ($frame['ignored']) {
                $this->recorder->discard($frame['token']);
            } else {
                $this->recorder->end(
                    $frame['token'],
                    $frame['name'],
                    ($now - $frame['startedAt']) * 1000,
                    ['exit' => $event->getExitCode(), 'memory' => memory_get_peak_usage(true)],
                );
            }

            if ($this->recorder->openUnits() === 0) {
                $this->flusher?->flushOnExit();
            }
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function reset(): void
    {
        $this->frames = [];
        $this->counted = new \WeakMap();
    }

    private function frameOf(?Command $command): ?int
    {
        if ($command === null) {
            return null;
        }

        for ($i = \count($this->frames) - 1; $i >= 0; --$i) {
            if ($this->frames[$i]['command'] === $command) {
                return $i;
            }
        }

        return null;
    }

    private function isIgnored(string $name): bool
    {
        foreach ([...self::DEFAULT_IGNORED, ...$this->ignore] as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    private function logFailure(\Throwable $e): void
    {
        if ($this->failureLogged) {
            return;
        }

        $this->failureLogged = true;
        $this->logger?->error('JMonitor could not record a command: ' . $e->getMessage(), ['exception' => $e]);
    }
}
