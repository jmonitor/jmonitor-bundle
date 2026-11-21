<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Embed;

use Jmonitor\JmonitorBundle\Dashboard\Assets;
use Jmonitor\JmonitorBundle\Dashboard\PageRenderer;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\Embed\JmonitorPage;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FakeHostUrls;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use Jmonitor\JmonitorBundle\Tests\Dashboard\StubScreen;
use Jmonitor\JmonitorBundle\Twig\JmonitorExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class JmonitorPageTest extends TestCase
{
    public function testDoNotRecordMarksTheRequestWithAnEmptyLocator(): void
    {
        $request = Request::create('/');

        (new JmonitorPage(new ServiceLocator([])))->doNotRecord($request);

        static::assertSame('_jmonitor', JmonitorPage::NOT_RECORDED);
        static::assertTrue($request->attributes->get('_jmonitor'));
    }

    public function testThePageAndTheFragmentMarkTheRequest(): void
    {
        $jmonitor = new JmonitorPage(new ServiceLocator([PageRenderer::class => $this->renderer(...)]));
        $page = Request::create('/');
        $fragment = Request::create('/');

        $jmonitor->page($page, 'stub', new FakeHostUrls());
        $jmonitor->fragment($fragment, 'stub', new FakeHostUrls());

        static::assertTrue($page->attributes->get('_jmonitor'));
        static::assertTrue($fragment->attributes->get('_jmonitor'));
    }

    private function renderer(): PageRenderer
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../templates', 'Jmonitor');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new JmonitorExtension());

        return new PageRenderer(
            new ScreenRegistry([new StubScreen()]),
            new ScreenBuilder(new FixedReader(), true, new MockClock('@1800000000')),
            null,
            $twig,
            new Assets(),
        );
    }
}
