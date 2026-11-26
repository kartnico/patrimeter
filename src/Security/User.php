<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Domain\UserId;
use Patrimeter\Security\Api\ApiScope;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Signed-in user.
 *
 * Session identifier: the UUID (stable), the email address serving to sign in. The session keeps
 * neither the TOTP secret nor any other secret (see __serialize); the user is reloaded from the
 * database on each request and the session ends if their password or their "session stamp" has
 * changed since (password change, signing out the other sessions).
 */
final class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface, TwoFactorInterface
{
    private bool $apiWrite = false;

    private const int TOTP_PERIOD = 30;
    private const int TOTP_DIGITS = 6;

    public function __construct(
        public readonly UserId $id,
        public readonly ?string $email,
        private ?string $passwordHash,
        public readonly bool $emailVerified,
        public readonly bool $totpEnabled,
        public readonly string $sessionStamp,
        #[\SensitiveParameter]
        private readonly ?string $totpSecret = null,
        /** End of a demonstration account (UTC timestamp, see DemoAccounts), null for any other account. */
        public readonly ?string $demoExpiresAt = null,
    ) {}

    /** Temporary account of the demonstration mode: signed in without a password nor TOTP, restricted (DemoGuard). */
    public function isDemo(): bool
    {
        return $this->demoExpiresAt !== null;
    }

    /**
     * Sign-in complete: TOTP set up, or demonstration account (which never has one). Until then, nothing of the account
     * is reachable (CurrentUser, AccountGateSubscriber).
     */
    public function isReady(): bool
    {
        return $this->isTotpAuthenticationEnabled() || $this->isDemo();
    }

    /** @return non-empty-string */
    #[\Override]
    public function getUserIdentifier(): string
    {
        return $this->id->value;
    }

    /** @return list<string> */
    #[\Override]
    public function getRoles(): array
    {
        return $this->apiWrite ? ['ROLE_USER', ApiScope::WRITE_ROLE] : ['ROLE_USER'];
    }

    /** Same user, authenticated by an API token that can write. */
    public function withApiWrite(): self
    {
        $user = clone $this;
        $user->apiWrite = true;

        return $user;
    }

    #[\Override]
    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    /**
     * New hash of the same password (hashing parameters strengthened at sign-in): the object in the
     * session thus stays identical to the one read again from the database at the next request.
     */
    public function upgradePasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    /** No sensitive data in clear is kept in the object after the sign-in. */
    #[\Override]
    #[\Deprecated]
    public function eraseCredentials(): void {}

    /** Changes when a session should end: an API token falls at the same time. */
    public function credentialsFingerprint(): string
    {
        return hash('sha256', ($this->passwordHash ?? '') . '|' . $this->sessionStamp);
    }

    #[\Override]
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->id->equals($this->id)
            && $user->passwordHash === $this->passwordHash
            && $user->sessionStamp === $this->sessionStamp;
    }

    #[\Override]
    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->totpEnabled && $this->totpSecret !== null;
    }

    #[\Override]
    public function getTotpAuthenticationUsername(): string
    {
        return $this->email ?? '';
    }

    #[\Override]
    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        return $this->totpSecret === null
            ? null
            : new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, self::TOTP_PERIOD, self::TOTP_DIGITS);
    }

    /**
     * What is written into the session: never the TOTP secret.
     *
     * @return array{id: string, email: ?string, password: ?string, verified: bool, totp: bool, stamp: string, demo: ?string}
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id->value,
            'email' => $this->email,
            'password' => $this->passwordHash,
            'verified' => $this->emailVerified,
            'totp' => $this->totpEnabled,
            'stamp' => $this->sessionStamp,
            'demo' => $this->demoExpiresAt,
        ];
    }

    /** @param array{id: string, email: ?string, password: ?string, verified: bool, totp: bool, stamp: string, demo?: ?string} $data */
    public function __unserialize(array $data): void
    {
        $this->id = UserId::fromString($data['id']);
        $this->email = $data['email'];
        $this->passwordHash = $data['password'];
        $this->emailVerified = $data['verified'];
        $this->totpEnabled = $data['totp'];
        $this->sessionStamp = $data['stamp'];
        $this->totpSecret = null;
        $this->demoExpiresAt = $data['demo'] ?? null;
    }
}
