<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Security;

use Patrimeter\Domain\UserId;
use Patrimeter\Security\CurrentUser;
use Patrimeter\Security\User;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/** The owner of the data is only given to a fully authenticated user. */
final class CurrentUserTest extends TestCase
{
    public function testFullyAuthenticatedUserOwnsTheRequest(): void
    {
        $user = self::user(totp: true);
        $current = self::currentUser(new PostAuthenticationToken($user, 'main', ['ROLE_USER']));

        self::assertSame($user, $current->user());
        self::assertTrue($current->id()->equals($user->id));
        self::assertSame($user, $current->authenticated());
    }

    public function testNoSessionIsRefused(): void
    {
        $current = new CurrentUser(new TokenStorage());

        self::assertNull($current->authenticated());
        $this->expectException(AccessDeniedException::class);
        $current->id();
    }

    public function testPendingSecondFactorIsRefused(): void
    {
        $user = self::user(totp: true);
        $pending = new TwoFactorToken(new PostAuthenticationToken($user, 'main', ['ROLE_USER']), null, 'main', ['totp']);
        $current = self::currentUser($pending);

        self::assertNull($current->authenticated(), 'premier facteur seulement');
        $this->expectException(AccessDeniedException::class);
        $current->user();
    }

    public function testUserWithoutTotpIsAuthenticatedButOwnsNothing(): void
    {
        $user = self::user(totp: false);
        $current = self::currentUser(new PostAuthenticationToken($user, 'main', ['ROLE_USER']));

        self::assertSame($user, $current->authenticated());
        $this->expectException(AccessDeniedException::class);
        $current->id();
    }

    private static function currentUser(TokenInterface $token): CurrentUser
    {
        $storage = new TokenStorage();
        $storage->setToken($token);

        return new CurrentUser($storage);
    }

    private static function user(bool $totp): User
    {
        return new User(
            UserId::fromString('01900000-0000-7000-8000-000000000001'),
            'marie@example.fr',
            'empreinte',
            true,
            $totp,
            'tampon',
            $totp ? 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP' : null,
        );
    }
}
