<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Security;

use Patrimeter\Domain\UserId;
use Patrimeter\Security\LoginThrottledException;
use Patrimeter\Security\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    private const string SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public function testSessionCopyNeverContainsTheTotpSecret(): void
    {
        $user = self::user(self::SECRET);
        $serialized = serialize($user);

        self::assertStringNotContainsString(self::SECRET, $serialized);
        $copy = unserialize($serialized);
        self::assertInstanceOf(User::class, $copy);
        self::assertTrue($copy->isEqualTo($user), 'même utilisateur pour le contrôle de session');
        self::assertNull($copy->getTotpAuthenticationConfiguration());
        self::assertFalse($copy->isTotpAuthenticationEnabled(), 'rechargé depuis la base à chaque requête');
    }

    public function testEqualityFollowsPasswordAndSessionStamp(): void
    {
        $user = self::user(self::SECRET);

        self::assertTrue($user->isEqualTo(self::user(null)));
        self::assertFalse($user->isEqualTo(self::user(self::SECRET, hash: 'autre')));
        self::assertFalse($user->isEqualTo(self::user(self::SECRET, stamp: 'nouveau')));
        self::assertFalse($user->isEqualTo(self::user(self::SECRET, id: '01900000-0000-7000-8000-000000000002')));
    }

    public function testTotpConfiguration(): void
    {
        $user = self::user(self::SECRET);
        $configuration = $user->getTotpAuthenticationConfiguration();

        self::assertTrue($user->isTotpAuthenticationEnabled());
        self::assertSame('marie@example.fr', $user->getTotpAuthenticationUsername());
        self::assertNotNull($configuration);
        self::assertSame([self::SECRET, 'sha1', 30, 6], [$configuration->getSecret(), $configuration->getAlgorithm(), $configuration->getPeriod(), $configuration->getDigits()]);
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertSame('01900000-0000-7000-8000-000000000001', $user->getUserIdentifier());
    }

    public function testUpgradedHashIsKeptInTheSessionObject(): void
    {
        $user = self::user(null);
        $user->upgradePasswordHash('nouvelle-empreinte');

        self::assertSame('nouvelle-empreinte', $user->getPassword());
    }

    public function testThrottledExceptionSurvivesTheSession(): void
    {
        $copy = unserialize(serialize(new LoginThrottledException(120)));

        self::assertInstanceOf(LoginThrottledException::class, $copy);
        self::assertSame('Trop de tentatives. Réessayez dans 2 minutes.', $copy->getMessageKey());
    }

    private static function user(?string $secret, string $hash = 'empreinte', string $stamp = 'tampon', string $id = '01900000-0000-7000-8000-000000000001'): User
    {
        return new User(UserId::fromString($id), 'marie@example.fr', $hash, true, true, $stamp, $secret);
    }
}
