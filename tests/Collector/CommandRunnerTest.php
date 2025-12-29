<?php

/*
 * This file is part of the jmonitor/jmonitor-bundle package.
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Collector;

use Jmonitor\JmonitorBundle\Collector\CommandRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class CommandRunnerTest extends TestCase
{
    private $kernel;
    private $commandRunner;

    protected function setUp(): void
    {
        $this->kernel = $this->createMock(KernelInterface::class);
        $this->kernel->method('getBundles')->willReturn([]);
        $this->kernel->method('getProjectDir')->willReturn(__DIR__);

        $container = new \Symfony\Component\DependencyInjection\Container();
        // Add minimal services required by Symfony Application
        $container->set('event_dispatcher', new \Symfony\Component\EventDispatcher\EventDispatcher());

        $this->kernel->method('getContainer')->willReturn($container);

        $this->commandRunner = new CommandRunner($this->kernel);
    }

    public function testGetApplication(): void
    {
        static::assertInstanceOf(Application::class, $this->commandRunner->getApplication());
    }

    public function testRunReturnsNullIfCommandDoesNotExist(): void
    {
        $result = $this->commandRunner->run('non:existent:command');
        static::assertNull($result);
    }

    public function testRunReturnsOutputIfCommandExists(): void
    {
        $command = new class('test:command') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->write('test output');
                return Command::SUCCESS;
            }
        };

        $this->commandRunner->getApplication()->add($command);

        $result = $this->commandRunner->run('test:command');
        static::assertSame(0, $result['exit_code']);
        static::assertSame('test output', $result['output']);
    }

    public function testRunReturnsEmptyOutputIfOutputIsEmpty(): void
    {
        $command = new class('test:empty') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return Command::SUCCESS;
            }
        };

        $this->commandRunner->getApplication()->add($command);

        $result = $this->commandRunner->run('test:empty');
        static::assertSame(0, $result['exit_code']);
        static::assertSame('', $result['output']);
    }

    public function testRunPassesInputArguments(): void
    {
        $command = new class('test:input') extends Command {
            protected function configure(): void
            {
                $this->addArgument('arg');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->write($input->getArgument('arg'));
                return Command::SUCCESS;
            }
        };

        $this->commandRunner->getApplication()->add($command);

        $result = $this->commandRunner->run('test:input', ['arg' => 'hello']);
        static::assertSame(0, $result['exit_code']);
        static::assertSame('hello', $result['output']);
    }

    public function testRunExternal(): void
    {
        // Use a simple command that works on all platforms (like 'php -v' or just 'php')
        // Or 'echo' if we want to be very safe, but 'php' is guaranteed since we are running tests.
        $result = $this->commandRunner->runProcess(['php', '-v']);

        static::assertSame(0, $result['exit_code']);
        static::assertStringContainsString('PHP', $result['output']);
    }
}
