<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\EasyAdmin;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class NonceExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('csp_nonce', static fn(string $usage): string => 'n0nce')];
    }
}
