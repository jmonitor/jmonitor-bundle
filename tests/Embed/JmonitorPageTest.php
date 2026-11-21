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
use Jmonitor\JmonitorBundle\Tests\Dashboard\StubActionScreen;
use Jmonitor\JmonitorBundle\Tests\Dashboard\StubScreen;
use Jmonitor\JmonitorBundle\Twig\JmonitorExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function testTheActionMarksTheRequest(): void
    {
        $jmonitor = new JmonitorPage(new ServiceLocator([PageRenderer::class => $this->renderer(...)]));
        $request = Request::create('/', 'POST', ['action' => 'ping'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        $response = $jmonitor->action($request, 'act', new FakeHostUrls());

        static::assertSame(303, $response->getStatusCode());
        static::assertTrue($request->attributes->get('_jmonitor'));
    }

    /**
     * @return iterable<string, array{'page'|'fragment'|'action', string, string, array<string, string>, string, int}>
     */
    public static function refusedCalls(): iterable
    {
        yield 'page of an unknown screen' => ['page', 'GET', 'nope', [], 'same-origin', 404];
        yield 'fragment of an unknown screen' => ['fragment', 'GET', 'nope', [], 'same-origin', 404];
        yield 'action not posted' => ['action', 'GET', 'act', ['action' => 'ping'], 'same-origin', 405];
        yield 'unknown action' => ['action', 'POST', 'act', ['action' => 'pong'], 'same-origin', 404];
        yield 'action from another site' => ['action', 'POST', 'act', ['action' => 'ping'], 'cross-site', 403];
    }

    /**
     * @param 'page'|'fragment'|'action' $call
     * @param array<string, string>      $fields
     */
    #[DataProvider('refusedCalls')]
    public function testARefusedCallStillMarksTheRequest(string $call, string $method, string $path, array $fields, string $site, int $status): void
    {
        $jmonitor = new JmonitorPage(new ServiceLocator([PageRenderer::class => $this->renderer(...)]));
        $request = Request::create('/', $method, $fields, server: ['HTTP_SEC_FETCH_SITE' => $site]);

        try {
            match ($call) {
                'page' => $jmonitor->page($request, $path, new FakeHostUrls()),
                'fragment' => $jmonitor->fragment($request, $path, new FakeHostUrls()),
                'action' => $jmonitor->action($request, $path, new FakeHostUrls()),
            };
            static::fail('The call was not refused.');
        } catch (HttpException $e) {
            static::assertSame($status, $e->getStatusCode());
        }

        static::assertTrue($request->attributes->get('_jmonitor'));
    }

    private function renderer(): PageRenderer
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../templates', 'Jmonitor');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new JmonitorExtension());

        return new PageRenderer(
            new ScreenRegistry([new StubScreen(), new StubActionScreen('act')]),
            new ScreenBuilder(new FixedReader(), true, new MockClock('@1800000000')),
            null,
            $twig,
            new Assets(),
        );
    }
}
