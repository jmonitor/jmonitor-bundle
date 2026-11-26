<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\HttpResponses;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\Ping;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DemoController
{
    public function __construct(
        private readonly ?Connection $connection = null,
        private readonly ?HttpKernelInterface $kernel = null,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly ?HttpClientInterface $flakyClient = null,
        private readonly ?CacheInterface $cache = null,
        private readonly ?MessageBusInterface $bus = null,
        private readonly ?CacheItemPoolInterface $stackCache = null,
    ) {}

    public function hello(string $name): Response
    {
        return new Response('Hello ' . htmlspecialchars($name));
    }

    public function slow(): Response
    {
        usleep(20000);

        return new Response('Slow');
    }

    public function alloc(): Response
    {
        $block = str_repeat('x', 32 * 1024 * 1024);

        return new Response((string) \strlen($block));
    }

    public function boom(): Response
    {
        throw new \RuntimeException('Boom');
    }

    public function handled(): Response
    {
        throw new NotFoundHttpException('Handled');
    }

    public function subBoom(): Response
    {
        $kernel = $this->kernel ?? throw new \LogicException('This route needs the HTTP kernel.');

        return $kernel->handle(Request::create('/boom'), HttpKernelInterface::SUB_REQUEST, false);
    }

    public function teapot(): Response
    {
        return new Response('I am a teapot', 418);
    }

    public function nPlusOne(): Response
    {
        $connection = $this->connection();
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS demo_items (id INTEGER NOT NULL PRIMARY KEY)');
        for ($id = 1; $id <= 12; ++$id) {
            $connection->executeQuery('SELECT * FROM demo_items WHERE id = ?', [$id])->fetchAllAssociative();
        }

        return new Response('12');
    }

    public function sqlError(): Response
    {
        try {
            $this->connection()->executeQuery('SELECT * FROM missing_table');
        } catch (DbalException $e) {
            return new Response($e::class);
        }

        return new Response('No error', 500);
    }

    public function hostileQuery(): Response
    {
        $this->connection()->executeQuery('SELECT \'<script>\' AS "<img src=x onerror=alert(1)>"')->fetchAllAssociative();

        return new Response('Done');
    }

    public function call(): Response
    {
        $client = $this->httpClient ?? throw new \LogicException('This route needs the HTTP client.');

        return new Response($client->request('GET', 'https://API.example.com:8443/secret?token=1')->getContent());
    }

    public function callFlaky(): Response
    {
        $client = $this->flakyClient ?? throw new \LogicException('This route needs the flaky client.');

        return new Response($client->request('GET', 'status')->getContent());
    }

    public function callDown(): Response
    {
        $client = $this->httpClient ?? throw new \LogicException('This route needs the HTTP client.');

        try {
            return new Response($client->request('GET', HttpResponses::DOWN_URI)->getContent());
        } catch (TransportExceptionInterface) {
            return new Response('No answer');
        }
    }

    public function cached(): Response
    {
        $cache = $this->cache ?? throw new \LogicException('This route needs the application cache.');

        return new Response($cache->get('demo', static fn(): string => 'Computed once'));
    }

    public function cacheMisses(Request $request): Response
    {
        if (!$this->cache instanceof CacheItemPoolInterface) {
            throw new \LogicException('This route needs the application cache.');
        }

        $count = $request->query->getInt('count', 1);
        for ($i = 0; $i < $count; ++$i) {
            $this->cache->getItem('missing-' . $i);
        }

        return new Response('Missed');
    }

    public function stackCached(): Response
    {
        foreach ([$this->cache, $this->stackCache] as $pool) {
            if (!$pool instanceof CacheItemPoolInterface) {
                throw new \LogicException('This route needs the application cache and the stack cache.');
            }
            $item = $pool->getItem('stacked');
            $pool->save($item->set('Stored'));
        }

        return new Response('Stored');
    }

    public function dispatch(Request $request): Response
    {
        $bus = $this->bus ?? throw new \LogicException('This route needs Messenger.');
        $count = $request->query->getInt('count', 3);
        for ($i = 0; $i < $count; ++$i) {
            $bus->dispatch(new Ping($request->query->getString('mode', 'ok')));
        }

        return new Response((string) $count);
    }

    private function connection(): Connection
    {
        return $this->connection ?? throw new \LogicException('These routes need Doctrine.');
    }
}
