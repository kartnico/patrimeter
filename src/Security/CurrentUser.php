<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Domain\UserId;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * User who owns the data of the current request.
 *
 * Refuses (fail-closed) as long as the authentication is not complete: no session, second factor
 * pending, or TOTP not set up yet (demonstration accounts excepted, see User::isReady). The controllers get here the owner they pass to the repositories:
 * never from the request.
 */
final class CurrentUser
{
    public function __construct(private readonly TokenStorageInterface $tokens) {}

    public function user(): User
    {
        $token = $this->tokens->getToken();
        $user = $token?->getUser();
        if ($token instanceof TwoFactorTokenInterface || !$user instanceof User || !$user->isReady()) {
            throw new AccessDeniedException('Authentification incomplète.');
        }

        return $user;
    }

    public function id(): UserId
    {
        return $this->user()->id;
    }

    /** User whose password has been checked and whose second factor is expected. */
    public function pendingSecondFactor(): ?User
    {
        $token = $this->tokens->getToken();
        $user = $token?->getUser();

        return $token instanceof TwoFactorTokenInterface && $user instanceof User ? $user : null;
    }

    /** Signed-in user (first factor at least), whether or not they have set up their TOTP. */
    public function authenticated(): ?User
    {
        $token = $this->tokens->getToken();
        $user = $token?->getUser();

        return !$token instanceof TwoFactorTokenInterface && $user instanceof User ? $user : null;
    }
}
