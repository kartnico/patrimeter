<?php

declare(strict_types=1);

namespace Patrimeter\Security\TwoFactor;

use Patrimeter\Security\AuthJournal;
use Patrimeter\Security\User;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * "Trust this browser" ticked on the second factor form: once the second factor is passed, the browser is recorded and
 * its cookie leaves with the response (TrustedBrowsers).
 */
final class TrustedBrowserSubscriber implements EventSubscriberInterface
{
    private const string COOKIE_ATTRIBUTE = '_patrimeter_trusted_cookie';

    public function __construct(
        private readonly TrustedBrowsers $browsers,
        private readonly AuthJournal $journal,
    ) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            TwoFactorAuthenticationEvents::COMPLETE => 'onComplete',
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onComplete(TwoFactorAuthenticationEvent $event): void
    {
        $request = $event->getRequest();
        $user = $event->getToken()->getUser();
        if (!$user instanceof User || !$request->request->getBoolean(TrustedBrowsers::FIELD)) {
            return;
        }
        $request->attributes->set(self::COOKIE_ATTRIBUTE, $this->browsers->trust($request, $user));
        $this->journal->record($user->id, AuthJournal::TRUSTED_BROWSER_ADDED, $request);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $cookie = self::cookie($event->getRequest()->attributes->get(self::COOKIE_ATTRIBUTE));
        if ($event->isMainRequest() && $cookie !== null) {
            $event->getResponse()->headers->setCookie($cookie);
        }
    }

    private static function cookie(mixed $value): ?Cookie
    {
        return $value instanceof Cookie ? $value : null;
    }
}
