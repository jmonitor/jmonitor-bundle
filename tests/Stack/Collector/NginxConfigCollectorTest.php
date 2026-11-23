<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\NginxConfigCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\StackShell;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeProcessRunner;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use PHPUnit\Framework\TestCase;

final class NginxConfigCollectorTest extends TestCase
{
    private const VERSION = ['sh', '-c', 'nginx -V 2>&1'];
    private const DUMP = ['sh', '-c', 'nginx -T'];
    private const CPUS = ['sh', '-c', 'getconf _NPROCESSORS_ONLN'];

    public function testItReadsTheVersionAndTheUsefulSettings(): void
    {
        $collector = new NginxConfigCollector(new StackShell($this->runner()));

        self::assertSame(
            [
                'version' => '1.26.2',
                'cpu_count' => PHP_OS_FAMILY === 'Linux' ? 4 : null,
                'config' => [
                    'config_path' => '/etc/nginx/nginx.conf',
                    'worker_processes' => 'auto',
                    'worker_connections' => 768,
                    'keepalive_timeout' => 65,
                    'gzip' => 'on',
                    'server_tokens' => 'off',
                    'ssl_protocols' => 'TLSv1.2 TLSv1.3',
                ],
            ],
            $collector->collect(),
        );
        self::assertSame('nginx.config', $collector->getName());
        self::assertSame(1, $collector->getVersion());
    }

    public function testOnlyTheListedSettingsAreKept(): void
    {
        $dump = str_replace(
            "http {\n",
            "http {\n\tproxy_set_header Authorization \"Bearer s3cret\";\n\tinclude /etc/nginx/secrets.conf;\n",
            $this->fixture('nginx-T.txt'),
        );
        $runner = $this->runner()->answer(self::DUMP, $dump);

        $payload = (new NginxConfigCollector(new StackShell($runner)))->collect();

        self::assertStringNotContainsString('s3cret', (string) json_encode($payload));
        self::assertSame(['config_path', 'worker_processes', 'worker_connections', 'keepalive_timeout', 'gzip', 'server_tokens', 'ssl_protocols'], array_keys($payload['config']));
        self::assertSame(['version', 'cpu_count', 'config'], array_keys($payload));
    }

    public function testWithoutTheNginxCommandTheVersionOfTheServerSoftwareIsKept(): void
    {
        $runner = (new FakeProcessRunner())->answer(null, 'sh: nginx: not found', 127);

        self::assertSame(
            ['version' => '1.26.2', 'cpu_count' => null, 'config' => array_fill_keys(['config_path', 'worker_processes', 'worker_connections', 'keepalive_timeout', 'gzip', 'server_tokens', 'ssl_protocols'], null)],
            (new NginxConfigCollector(new StackShell($runner), '1.26.2'))->collect(),
        );
        self::assertNull((new NginxConfigCollector(new StackShell($runner)))->collect()['version']);
    }

    public function testACommandThatRunsTooLongStaysAFailure(): void
    {
        $runner = (new FakeProcessRunner())->answer(null, '', null, timedOut: true);

        $e = Failures::thrownBy(static fn() => (new NginxConfigCollector(new StackShell($runner), '1.26.2'))->collect());

        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertNotInstanceOf(MissingPrerequisiteException::class, $e);
    }

    public function testACommandThatFailsLeavesTheVersionOfTheServerSoftware(): void
    {
        $runner = (new FakeProcessRunner())
            ->answer(self::VERSION, '', 1)
            ->answer(self::DUMP, '', 1)
            ->answer(self::CPUS, "4\n");

        $payload = (new NginxConfigCollector(new StackShell($runner), '1.27.2'))->collect();

        self::assertSame('1.27.2', $payload['version']);
        self::assertSame(array_fill_keys(['config_path', 'worker_processes', 'worker_connections', 'keepalive_timeout', 'gzip', 'server_tokens', 'ssl_protocols'], null), $payload['config']);
    }

    public function testTheVersionOfTheCommandWinsOverTheOneOfTheServerSoftware(): void
    {
        $payload = (new NginxConfigCollector(new StackShell($this->runner()), '1.27.2'))->collect();

        self::assertSame('1.26.2', $payload['version']);
    }

    public function testTheDumpOfAServerWithoutRootIsEmptyAndTheVersionStays(): void
    {
        $runner = $this->runner()->answer(self::DUMP, '', 1, 'nginx: [alert] could not open error log file');

        $payload = (new NginxConfigCollector(new StackShell($runner)))->collect();

        self::assertSame('1.26.2', $payload['version']);
        self::assertNull($payload['config']['config_path']);
    }

    public function testItReadsNoStatusPage(): void
    {
        $runner = $this->runner();

        $payload = (new NginxConfigCollector(new StackShell($runner)))->collect();

        self::assertArrayNotHasKey('status', $payload);
        self::assertArrayNotHasKey('modules', $payload);
        self::assertSame([self::VERSION, self::DUMP, self::CPUS], array_column($runner->calls, 'command'));
    }

    public function testItReadsTheCommandsAgainAtEachCollect(): void
    {
        $runner = $this->runner();
        $collector = new NginxConfigCollector(new StackShell($runner));
        $collector->collect();

        $runner->answer(self::VERSION, str_replace('1.26.2', '1.28.0', $this->fixture('nginx-V.txt')));

        self::assertSame('1.28.0', $collector->collect()['version']);
    }

    private function runner(): FakeProcessRunner
    {
        return (new FakeProcessRunner())
            ->answer(self::VERSION, $this->fixture('nginx-V.txt'))
            ->answer(self::DUMP, $this->fixture('nginx-T.txt'))
            ->answer(self::CPUS, "4\n");
    }

    private function fixture(string $file): string
    {
        return (string) file_get_contents(\dirname(__DIR__) . '/Fixtures/Nginx/' . $file);
    }
}
