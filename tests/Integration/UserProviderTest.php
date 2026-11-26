<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Integration;

use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\User;
use Patrimeter\Security\UserProvider;
use Patrimeter\Tests\Support\IntegrationTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class UserProviderTest extends IntegrationTestCase
{
    private UserProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->provider = self::service(UserProvider::class);
    }

    public function testLoadsTheUserWithItsDecryptedTotpSecret(): void
    {
        $id = self::owner();
        $user = $this->provider->loadUserByIdentifier($id->value);

        self::assertTrue($user->id->equals($id));
        self::assertSame(TestUsers::EMAIL, $user->email);
        self::assertSame(TestUsers::TOTP_SECRET, $user->getTotpAuthenticationConfiguration()?->getSecret());
        self::assertTrue($this->provider->refreshUser($user)->isEqualTo($user));
        self::assertTrue($this->provider->supportsClass(User::class));
        self::assertFalse($this->provider->supportsClass(InMemoryUser::class));
    }

    public function testFindsUsersByNormalizedEmailOnly(): void
    {
        self::owner();

        self::assertNotNull($this->provider->findByEmail(TestUsers::EMAIL));
        self::assertNull($this->provider->findByEmail('inconnu@example.test'));
    }

    public function testUnknownOrMalformedIdentifiersAreNotFound(): void
    {
        foreach (['pas-un-uuid', '01900000-0000-7000-8000-000000000099'] as $identifier) {
            try {
                $this->provider->loadUserByIdentifier($identifier);
                self::fail("Utilisateur inattendu : $identifier");
            } catch (UserNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRefusesOtherUserClasses(): void
    {
        $this->expectException(UnsupportedUserException::class);
        $this->provider->refreshUser(new InMemoryUser('x', null));
    }

    public function testUpgradedHashIsStoredAndKeptInTheSessionObject(): void
    {
        $id = self::owner();
        $user = $this->provider->loadUserByIdentifier($id->value);

        $this->provider->upgradePassword($user, 'nouvelle-empreinte');
        $this->provider->upgradePassword(new InMemoryUser('x', null), 'ignorée');

        self::assertSame('nouvelle-empreinte', $user->getPassword());
        self::assertSame('nouvelle-empreinte', self::service(UserRepository::class)->find($id)['password_hash'] ?? null);
        self::assertTrue($this->provider->refreshUser($user)->isEqualTo($user), 'la session reste valable');
    }

    public function testUserWithoutTotpHasNoSecret(): void
    {
        $id = TestUsers::create(static::getContainer(), 'sans-totp@example.test', totp: false);

        $user = $this->provider->loadUserByIdentifier($id->value);

        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertNull($user->getTotpAuthenticationConfiguration());
        self::assertTrue(UserId::fromString($user->getUserIdentifier())->equals($id));
    }
}
