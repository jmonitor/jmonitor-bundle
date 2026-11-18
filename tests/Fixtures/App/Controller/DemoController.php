<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\HttpFoundation\Response;

final class DemoController
{
    public function __construct(private readonly ?Connection $connection = null) {}

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

    private function connection(): Connection
    {
        return $this->connection ?? throw new \LogicException('These routes need Doctrine.');
    }
}
