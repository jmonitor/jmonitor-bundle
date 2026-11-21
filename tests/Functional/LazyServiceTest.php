<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Jmonitor\JmonitorBundle\Dashboard\PageRenderer;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\Embed\JmonitorPage;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FakeHostUrls;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

final class LazyServiceTest extends TestCase
{
    private string $bufferDir;
    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-lazy-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        (new Filesystem())->remove(array_filter([$this->bufferDir, $this->kernel?->getVarDir()]));
    }

    public function testBuildingThePublicServiceBuildsNothingElse(): void
    {
        // The boot that compiles the container also runs the cache warmers, which build twig.
        $warmUp = $this->kernel();
        $warmUp->boot();
        $warmUp->shutdown();
        $this->kernel = $this->kernel();
        $this->kernel->boot();
        $container = $this->kernel->getContainer();

        $jmonitor = $container->get(JmonitorPage::class);
        static::assertInstanceOf(JmonitorPage::class, $jmonitor);
        $jmonitor->doNotRecord(Request::create('/'));

        foreach ([PageRenderer::class, ScreenRegistry::class, ScreenBuilder::class, DbalReader::class, Flusher::class, 'twig', 'doctrine.dbal.default_connection'] as $id) {
            static::assertFalse($container->initialized($id), $id);
        }

        $jmonitor->page(Request::create('/'), '', new FakeHostUrls());

        static::assertTrue($container->initialized(PageRenderer::class));
    }

    private function kernel(): TestKernel
    {
        return new TestKernel(
            'test',
            true,
            ['recording' => ['enabled' => true, 'buffer_dir' => $this->bufferDir]],
            withPublicInternals: true,
        );
    }
}
