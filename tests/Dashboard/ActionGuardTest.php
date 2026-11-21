<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Dashboard\ActionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class ActionGuardTest extends TestCase
{
    private RequestStack $stack;

    protected function setUp(): void
    {
        $this->stack = new RequestStack();
    }

    public function testAValidTokenIsAccepted(): void
    {
        $guard = new ActionGuard($this->tokens());
        $request = $this->request(session: true);

        $token = $guard->token($request);
        static::assertNotNull($token);
        $request->request->set('token', $token);

        static::assertTrue($guard->allows($request));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badTokens(): iterable
    {
        yield 'missing' => [[]];
        yield 'wrong' => [['token' => 'nope']];
        yield 'empty' => [['token' => '']];
        yield 'array' => [['token' => ['nope']]];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('badTokens')]
    public function testAMissingOrWrongTokenIsRefusedWhateverTheHeaders(array $fields): void
    {
        $guard = new ActionGuard($this->tokens());
        $request = $this->request(['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'https://app.example'], $fields, true);
        $guard->token($request);

        static::assertFalse($guard->allows($request));
    }

    /**
     * @return iterable<string, array{array<string, string>, bool}>
     */
    public static function headers(): iterable
    {
        yield 'same origin fetch' => [['Sec-Fetch-Site' => 'same-origin'], true];
        yield 'same site fetch' => [['Sec-Fetch-Site' => 'same-site'], false];
        yield 'cross site fetch' => [['Sec-Fetch-Site' => 'cross-site'], false];
        yield 'fetch from nowhere' => [['Sec-Fetch-Site' => 'none'], false];
        yield 'same origin fetch with a foreign origin' => [['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'https://evil.example'], true];
        yield 'cross site fetch with the same origin' => [['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://app.example'], false];
        yield 'same origin' => [['Origin' => 'https://app.example'], true];
        yield 'origin of another host' => [['Origin' => 'https://evil.example'], false];
        yield 'origin on another port' => [['Origin' => 'https://app.example:8443'], false];
        yield 'origin with another scheme' => [['Origin' => 'http://app.example'], false];
        yield 'null origin' => [['Origin' => 'null'], false];
        yield 'no header' => [[], false];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('headers')]
    public function testWithoutTokenManagerTheHeadersDecide(array $headers, bool $allowed): void
    {
        static::assertSame($allowed, (new ActionGuard())->allows($this->request($headers)));
        static::assertSame($allowed, (new ActionGuard())->allows($this->request($headers, session: true)));
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('headers')]
    public function testWithoutSessionTheHeadersDecide(array $headers, bool $allowed): void
    {
        static::assertSame($allowed, (new ActionGuard($this->tokens()))->allows($this->request($headers)));
    }

    public function testThereIsNoTokenWithoutTokenManagerOrWithoutSession(): void
    {
        static::assertNull((new ActionGuard())->token($this->request(session: true)));
        static::assertNull((new ActionGuard($this->tokens()))->token($this->request()));
    }

    private function tokens(): CsrfTokenManager
    {
        return new CsrfTokenManager(storage: new SessionTokenStorage($this->stack));
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $fields
     */
    private function request(array $headers = [], array $fields = [], bool $session = false): Request
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create('https://app.example/jmonitor/_action/stub', 'POST', $fields, server: $server);

        if ($session) {
            $request->setSession(new Session(new MockArraySessionStorage()));
            $this->stack->push($request);
        }

        return $request;
    }
}
