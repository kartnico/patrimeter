<?php

declare(strict_types=1);

namespace Patrimeter\Security\WebAuthn;

use Patrimeter\Security\User;
use Scheb\TwoFactorBundle\Security\Http\Authenticator\Passport\Credentials\TwoFactorCodeCredentials;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * FIDO2 key or passkey as second factor: its answer (JSON) arrives in the code field, and thus gets the
 * same limiting and the same log as the other codes.
 */
final class SecurityKeyCodeListener implements EventSubscriberInterface
{
    /** Before the checks of the backup codes (16) and of TOTP (0), after the limiting (32). */
    private const int PRIORITY = 24;

    public function __construct(
        private readonly WebAuthn $webAuthn,
        private readonly RequestStack $requests,
    ) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [CheckPassportEvent::class => ['check', self::PRIORITY]];
    }

    public function check(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();
        $credentials = $passport->getBadge(TwoFactorCodeCredentials::class);
        $request = $this->requests->getMainRequest();
        if (!$credentials instanceof TwoFactorCodeCredentials || $request === null || !str_starts_with(ltrim($credentials->getCode()), '{')) {
            return;
        }
        $user = $passport->getUser();
        if ($user instanceof User && $this->webAuthn->verify($request, $credentials->getCode(), $user->id) !== null) {
            $credentials->markResolved();
        }
    }
}
