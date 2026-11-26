<?php

declare(strict_types=1);

namespace Patrimeter\Security\TwoFactor;

use Patrimeter\Security\User;
use Patrimeter\Security\WebAuthn\PasskeyLoginBadge;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Condition\TwoFactorConditionInterface;

/**
 * When the second factor is asked for after the first one: not after a passkey (it already combines two factors: the
 * device and the user verification), nor on a browser the user trusted (TrustedBrowsers).
 */
final class SecondFactorCondition implements TwoFactorConditionInterface
{
    public function __construct(private readonly TrustedBrowsers $browsers) {}

    #[\Override]
    public function shouldPerformTwoFactorAuthentication(AuthenticationContextInterface $context): bool
    {
        if ($context->getPassport()->hasBadge(PasskeyLoginBadge::class)) {
            return false;
        }
        $user = $context->getUser();
        if ($user instanceof User && $this->browsers->isTrusted($context->getRequest(), $user)) {
            $context->getRequest()->attributes->set(TrustedBrowsers::USED, true);

            return false;
        }

        return true;
    }
}
