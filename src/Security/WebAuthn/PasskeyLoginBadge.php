<?php

declare(strict_types=1);

namespace Patrimeter\Security\WebAuthn;

use Symfony\Component\Security\Http\Authenticator\Passport\Badge\BadgeInterface;

/** Sign-in with a passkey: user verification included, no second factor to ask for. */
final class PasskeyLoginBadge implements BadgeInterface
{
    #[\Override]
    public function isResolved(): bool
    {
        return true;
    }
}
