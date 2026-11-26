<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loading of the users: by identifier (session) or by email address (sign-in).
 * The TOTP secret is decrypted here, in memory only.
 *
 * @phpstan-import-type UserRow from UserRepository
 *
 * @implements UserProviderInterface<User>
 */
final class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthSecrets $secrets,
    ) {}

    #[\Override]
    public function loadUserByIdentifier(string $identifier): User
    {
        try {
            $id = UserId::fromString($identifier);
        } catch (\InvalidArgumentException) {
            throw new UserNotFoundException();
        }
        $row = $this->users->find($id);
        if ($row === null) {
            throw new UserNotFoundException();
        }

        return $this->user($row);
    }

    /** User whose (normalised) address is given, or null. */
    public function findByEmail(string $email): ?User
    {
        $row = $this->users->findByEmail($email);

        return $row === null ? null : $this->user($row);
    }

    #[\Override]
    public function refreshUser(UserInterface $user): User
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Utilisateur non pris en charge : %s', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    #[\Override]
    public function supportsClass(string $class): bool
    {
        return $class === User::class;
    }

    #[\Override]
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            return;
        }
        $this->users->updatePasswordHash($user->id, $newHashedPassword);
        $user->upgradePasswordHash($newHashedPassword);
    }

    /** @param UserRow $row */
    private function user(array $row): User
    {
        $secret = $row['totp_secret'] === null ? null : $this->secrets->decryptTotpSecret($row['id'], $row['totp_secret']);

        return new User(
            $row['id'],
            $row['email'],
            $row['password_hash'],
            $row['email_verified'],
            $row['totp_enabled'],
            $row['session_stamp'],
            $secret,
            $row['demo_expires_at'],
        );
    }
}
