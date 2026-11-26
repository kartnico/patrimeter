<?php

declare(strict_types=1);

namespace Patrimeter\Security\Vault;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/** Cookie of the session key: set with the answer, cleared at sign-out. */
final class VaultCookieSubscriber implements EventSubscriberInterface
{
    private const int PRIORITY_AFTER_LOGOUT_RESPONSE = -200;

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onResponse',
            LogoutEvent::class => ['onLogout', self::PRIORITY_AFTER_LOGOUT_RESPONSE],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $value = $event->getRequest()->attributes->getString(Vault::NEW_COOKIE_ATTRIBUTE);
        if ($event->isMainRequest() && $value !== '') {
            $event->getResponse()->headers->setCookie(
                Cookie::create(Vault::COOKIE, $value)
                    ->withPath('/')
                    ->withSecure($event->getRequest()->isSecure())
                    ->withHttpOnly(true)
                    ->withSameSite(Cookie::SAMESITE_STRICT),
            );
        }
    }

    public function onLogout(LogoutEvent $event): void
    {
        $event->getResponse()?->headers->clearCookie(Vault::COOKIE, '/', null, $event->getRequest()->isSecure(), true, Cookie::SAMESITE_STRICT);
    }
}
