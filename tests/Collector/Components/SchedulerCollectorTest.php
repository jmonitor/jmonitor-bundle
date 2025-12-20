<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Collector\Components;

use Jmonitor\JmonitorBundle\Collector\CommandRunner;
use Jmonitor\JmonitorBundle\Collector\Components\SchedulerCollector;
use PHPUnit\Framework\TestCase;

class SchedulerCollectorTest extends TestCase
{
    public function testParseOutput(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
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

        $commandRunner->method('run')->willReturn($output);

        $collector = new SchedulerCollector($commandRunner);
        $result = $collector->collect();

        $this->assertCount(5, $result);

        $this->assertEquals('every 3 hour', $result[0]['trigger']);
        $this->assertEquals('app:foo', $result[0]['command']);
        $this->assertEquals(1766197200, $result[0]['next_run']); // Sat, 20 Dec 2025 03:20:00 +0100

        $this->assertEquals('every 24 hours with 0-5 second jitter', $result[2]['trigger']);
        $this->assertEquals('app:bar', $result[2]['command']);

        $this->assertEquals('0 0 * * *', $result[3]['trigger']);
        $this->assertEquals('app:bar:foo', $result[3]['command']);
        $this->assertEquals(1766271600, $result[3]['next_run']); // Sun, 21 Dec 2025 00:00:00 +0100
    }
}
