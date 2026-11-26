<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Check after the password has been verified: the email address must be confirmed. Reported only to
 * whoever knows the password, hence revealing nothing to a third party.
 */
final class UserChecker implements UserCheckerInterface
{
    #[\Override]
    public function checkPreAuth(UserInterface $user): void {}

    #[\Override]
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && !$user->emailVerified) {
            $exception = new EmailNotVerifiedException();
            $exception->setUser($user);

            throw $exception;
        }
    }
}
