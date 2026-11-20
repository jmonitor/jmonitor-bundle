<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class UserRecorder implements EventSubscriberInterface
{
    private const MAX_LENGTH = 190;

    private bool $failureLogged = false;

    /** @var (\Closure(): bool)|null null when the storage cannot tell whether its authentication is pending */
    private readonly ?\Closure $isPending;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly TokenStorageInterface $tokens,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->isPending = $tokens instanceof TokenStorage && property_exists(TokenStorage::class, 'initializer')
            ? \Closure::bind(fn(): bool => $this->initializer !== null, $tokens, TokenStorage::class)
            : null;
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', 4096]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        try {
            $unit = $event->getRequest()->attributes->get(RequestRecorder::UNIT);
            if (!\is_int($unit)) {
                return;
            }

            // Reading the token of a lazy firewall would run its authentication, which the application did not need.
            if ($this->isPending === null || ($this->isPending)()) {
                return;
            }

            $user = $this->tokens->getToken()?->getUser();
            if ($user === null) {
                return;
            }

            $identifier = mb_substr(mb_scrub($user->getUserIdentifier(), 'UTF-8'), 0, self::MAX_LENGTH, 'UTF-8');
            if ($identifier !== '') {
                $this->recorder->add($unit, Entry::TYPE_USER, $identifier);
            }
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    private function logFailure(\Throwable $e): void
    {
        if ($this->failureLogged) {
            return;
        }

        $this->failureLogged = true;
        $this->logger?->error('JMonitor could not record the user of a request: ' . $e->getMessage(), ['exception' => $e]);
    }
}
