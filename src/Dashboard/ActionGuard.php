<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ActionGuard
{
    private const TOKEN_ID = 'jmonitor';

    public function __construct(private readonly ?CsrfTokenManagerInterface $tokens = null) {}

    public function token(Request $request): ?string
    {
        if ($this->tokens === null || !$request->hasSession() || $request->attributes->getBoolean('_stateless')) {
            return null;
        }

        return $this->tokens->getToken(self::TOKEN_ID)->getValue();
    }

    public function allows(Request $request): bool
    {
        if ($this->tokens !== null && $request->hasSession() && !$request->attributes->getBoolean('_stateless')) {
            $token = $request->request->all()['token'] ?? null;

            return \is_string($token) && $this->tokens->isTokenValid(new CsrfToken(self::TOKEN_ID, $token));
        }

        $site = $request->headers->get('Sec-Fetch-Site');
        if ($site !== null) {
            return $site === 'same-origin';
        }

        return $request->headers->get('Origin') === $request->getSchemeAndHttpHost();
    }
}
