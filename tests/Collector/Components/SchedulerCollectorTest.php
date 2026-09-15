<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Collector\Components;

use Jmonitor\JmonitorBundle\Collector\CommandRunner;
use Jmonitor\JmonitorBundle\Collector\Components\SchedulerCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\NamespaceNotFoundException;

class SchedulerCollectorTest extends TestCase
{
    public function testParseOutput(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $application = $this->createMock(Application::class);
        $command = $this->createMock(Command::class);

        $command->method('getDescription')->willReturn('Sample description');
        $application->method('find')->willReturn($command);
        $commandRunner->method('getApplication')->willReturn($application);

        $output = <<<EOF
             --------------------------------------- ----------------------------------------------------------------------------------------------------- ---------------------------------
              Trigger                                 Provider                                                                                              Next Run
             --------------------------------------- ----------------------------------------------------------------------------------------------------- ---------------------------------
              every 3 hour                            Symfony\Component\Console\Messenger\RunCommandMessage (app:foo)                                       Sat, 20 Dec 2025 03:20:00 +0100
              every 24 hours                          Symfony\Component\Console\Messenger\RunCommandMessage (app:foo:bar)                                   Sat, 20 Dec 2025 13:30:00 +0100
              every 24 hours with 0-5 second jitter   Symfony\Component\Console\Messenger\RunCommandMessage (app:bar)                                       Sat, 20 Dec 2025 02:05:03 +0100
              0 0 * * *                               Symfony\Component\Console\Messenger\RunCommandMessage (app:bar:foo)                                   Sun, 21 Dec 2025 00:00:00 +0100
              every 15 seconds                        Symfony\Component\Console\Messenger\RunCommandMessage (jmonitor:collect)                              Sat, 20 Dec 2025 00:56:24 +0100
             --------------------------------------- ----------------------------------------------------------------------------------------------------- ---------------------------------
            EOF;

        $commandRunner->method('run')->willReturn(['exit_code' => 0, 'output' => $output]);

        $collector = new SchedulerCollector($commandRunner);
        $result = $collector->collect();

        static::assertCount(5, $result);

        static::assertSame('every 3 hour', $result[0]['trigger']);
        static::assertSame('app:foo', $result[0]['command']);
        static::assertSame([], $result[0]['arguments']);
        static::assertSame('Sample description', $result[0]['description']);
        static::assertSame(1_766_197_200, $result[0]['next_run']); // Sat, 20 Dec 2025 03:20:00 +0100

        static::assertSame('every 24 hours with 0-5 second jitter', $result[2]['trigger']);
        static::assertSame('app:bar', $result[2]['command']);
        static::assertSame('Sample description', $result[2]['description']);

        static::assertSame('0 0 * * *', $result[3]['trigger']);
        static::assertSame('app:bar:foo', $result[3]['command']);
        static::assertSame('Sample description', $result[3]['description']);
        static::assertSame(1_766_271_600, $result[3]['next_run']); // Sun, 21 Dec 2025 00:00:00 +0100
    }

    public function testParseOutputWithArguments(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $application = $this->createMock(Application::class);

        $application->method('find')->willReturnCallback(function (string $name): Command {
            if ($name === 'app:gone') {
                throw new NamespaceNotFoundException('There are no commands defined in the "app" namespace.');
            }

            $command = $this->createMock(Command::class);
            $command->method('getDescription')->willReturn('Description of ' . $name);

            return $command;
        });
        $commandRunner->method('getApplication')->willReturn($application);

        $output = <<<EOF
             ------------------ ---------------------------------------------------------------------------------------------------------------- ---------------------------------
              Trigger            Provider                                                                                                         Next Run
             ------------------ ---------------------------------------------------------------------------------------------------------------- ---------------------------------
              every 6 hours      Symfony\Component\Console\Messenger\RunCommandMessage ('app:artist:publish-ready' --apply --limit 100)           Sat, 20 Dec 2025 06:29:00 +0100
              every 12 hours     Symfony\Component\Console\Messenger\RunCommandMessage ("app:artist:publish-ready" --apply --limit 100)           Sat, 20 Dec 2025 12:29:00 +0100
              every 24 hours     Symfony\Component\Console\Messenger\RunCommandMessage ('app:report' --label='a) b' "--to=ops team")              Sun, 21 Dec 2025 00:00:00 +0100
              every 48 hours     Symfony\Component\Console\Messenger\RunCommandMessage ('app:gone' --force)                                       Mon, 22 Dec 2025 00:00:00 +0100
             ------------------ ---------------------------------------------------------------------------------------------------------------- ---------------------------------
            EOF;

        $commandRunner->method('run')->willReturn(['exit_code' => 0, 'output' => $output]);

        $result = (new SchedulerCollector($commandRunner))->collect();

        static::assertCount(4, $result);

        static::assertSame('app:artist:publish-ready', $result[0]['command']);
        static::assertSame(['--apply', '--limit', '100'], $result[0]['arguments']);
        static::assertSame('Description of app:artist:publish-ready', $result[0]['description']);
        static::assertSame(1_766_208_540, $result[0]['next_run']); // Sat, 20 Dec 2025 06:29:00 +0100

        static::assertSame('app:artist:publish-ready', $result[1]['command']);
        static::assertSame(['--apply', '--limit', '100'], $result[1]['arguments']);
        static::assertSame('Description of app:artist:publish-ready', $result[1]['description']);

        static::assertSame('app:report', $result[2]['command']);
        static::assertSame(['--label=a) b', '--to=ops team'], $result[2]['arguments']);
        static::assertSame(1_766_271_600, $result[2]['next_run']); // Sun, 21 Dec 2025 00:00:00 +0100

        static::assertSame('app:gone', $result[3]['command']);
        static::assertSame(['--force'], $result[3]['arguments']);
        static::assertNull($result[3]['description']);
    }
}
