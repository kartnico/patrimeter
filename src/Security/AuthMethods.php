<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\WebAuthnCredentialRepository;
use Patrimeter\Security\WebAuthn\WebAuthn;

/** Removal rule: there is always at least one way left to sign in. */
final class AuthMethods
{
    public function __construct(private readonly WebAuthnCredentialRepository $credentials) {}

    public function count(UserId $id, bool $hasPassword): int
    {
        $passkeys = array_filter($this->credentials->all($id), static fn(array $c): bool => $c['usage'] === WebAuthn::PASSKEY);

        return ($hasPassword ? 1 : 0) + count($passkeys);
    }

    public function canRemoveCredential(User $user, string $usage): bool
    {
        return $usage !== WebAuthn::PASSKEY || $this->count($user->id, $user->getPassword() !== null) > 1;
    }
}
