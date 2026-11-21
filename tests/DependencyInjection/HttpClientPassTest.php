<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\DependencyInjection;

use Jmonitor\JmonitorBundle\Dashboard\ScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\Recording\HttpClient\RecordingHttpClient;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpClientPassTest extends KernelTestCase
{
    /** @var list<string> */
    private static array $varDirs = [];

    private string $bufferDir;

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-http-client-pass-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testTheDefaultAndTheScopedClientsRecord(): void
    {
        self::bootKernel(['jmonitor' => $this->recording()]);

        static::assertInstanceOf(RecordingHttpClient::class, static::getContainer()->get('http_client'));
        static::assertInstanceOf(RecordingHttpClient::class, static::getContainer()->get('flaky.client'));
        static::assertContains('http-client', $this->screens());
    }

    public function testAKernelWithoutAnyClientRunsWithoutTheScreen(): void
    {
        $kernel = self::bootKernel(['jmonitor' => $this->recording(), 'http_client' => false]);

        static::assertFalse(static::getContainer()->has('http_client'));
        static::assertNotContains('http-client', $this->screens());
        static::assertSame(200, $kernel->handle(Request::create('/hello/world'))->getStatusCode());
    }

    public function testTurningTheCaptureOffWrapsNothing(): void
    {
        self::bootKernel(['jmonitor' => ['recording' => ['http_client' => false] + $this->recording()['recording']]]);

        foreach (['http_client', 'flaky.client'] as $id) {
            $client = static::getContainer()->get($id);
            static::assertInstanceOf(HttpClientInterface::class, $client);
            static::assertNotInstanceOf(RecordingHttpClient::class, $client, $id);
        }
        static::assertNotContains('http-client', $this->screens());
    }

    /** @return list<string> */
    private function screens(): array
    {
        $registry = static::getContainer()->get(ScreenRegistry::class);
        \assert($registry instanceof ScreenRegistry);

        return array_map(static fn(ScreenInterface $screen): string => $screen->id(), $registry->available());
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, http_client?: bool} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel('test', true, $options['jmonitor'] ?? [], withHttpClient: $options['http_client'] ?? true);
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }

    /**
     * @return array{recording: array<string, mixed>}
     */
    private function recording(): array
    {
        return ['recording' => ['enabled' => true, 'buffer_dir' => $this->bufferDir]];
    }
}
