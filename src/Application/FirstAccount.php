<?php

declare(strict_types=1);

namespace Patrimeter\Application;

use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\User;
use Patrimeter\Security\UserProvider;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * First account of an instance, created from the browser by the installation wizard (/installation) as long as the
 * instance has none of its own (demonstration accounts aside): no console needed. As with app:user:create, its address
 * is deemed confirmed; the authenticator app is set up right after, as for any new account.
 */
final class FirstAccount
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly UserProvider $userProvider,
        private readonly Calendar $calendar,
    ) {}

    /** Whether the instance still waits for its first account. */
    public function isNeeded(): bool
    {
        return !$this->users->hasAccount();
    }

    /** The account created, signed in by the caller; null if another one was created in the meantime. */
    public function create(string $email, #[\SensitiveParameter] string $password): ?User
    {
        $id = UserId::fromString(Uuid::v7()->toRfc4122());
        $hash = $this->hashers->getPasswordHasher(User::class)->hash($password);
        if (!$this->users->createFirst($id, $email, $hash, $this->calendar->timestamp())) {
            return null;
        }

        return $this->userProvider->loadUserByIdentifier($id->value);
    }
}
