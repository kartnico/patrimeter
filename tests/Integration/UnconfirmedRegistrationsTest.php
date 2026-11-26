<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Integration;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Maintenance\PurgeExpiredData;
use Patrimeter\Application\Maintenance\PurgeExpiredDataHandler;
use Patrimeter\Application\Registration;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Tests\Support\IntegrationTestCase;
use Patrimeter\Tests\Support\TestUsers;

/**
 * Registrations never confirmed: deleted by the scheduled task once their confirmation link has expired (24 hours
 * after the last link sent), never a confirmed account, an account without address, a demonstration account nor an
 * account holding data.
 */
final class UnconfirmedRegistrationsTest extends IntegrationTestCase
{
    private const string PASSWORD = 'une phrase de passe assez longue';

    public function testARegistrationNeverConfirmedIsDeletedAfter24Hours(): void
    {
        $confirmed = self::owner();
        $this->register('oubli@example.test');

        self::clock()->sleep(23 * 3600);
        self::assertSame(0, self::service(Registration::class)->purgeUnconfirmed(), 'link still valid');
        self::assertNotNull($this->find('oubli@example.test'));

        self::clock()->sleep(2 * 3600);
        self::service(PurgeExpiredDataHandler::class)(new PurgeExpiredData());
        self::assertNull($this->find('oubli@example.test'), 'deleted by the scheduled task');
        self::assertNotNull(self::service(UserRepository::class)->find($confirmed), 'a confirmed account stays');
    }

    public function testARegistrationDoneAgainWaitsForItsNewLink(): void
    {
        $this->register('retard@example.test');
        self::clock()->sleep(20 * 3600);
        $this->register('retard@example.test');

        self::clock()->sleep(5 * 3600);
        self::assertSame(0, self::service(Registration::class)->purgeUnconfirmed(), 'new link valid until 44 h');
        self::clock()->sleep(20 * 3600);
        self::assertSame(1, self::service(Registration::class)->purgeUnconfirmed());
        self::assertNull($this->find('retard@example.test'));
    }

    public function testAnAccountHoldingDataIsNeverDeleted(): void
    {
        // An account with data whose address is no longer marked as confirmed (whatever the reason): kept.
        $owner = self::owner();
        self::createAccount('Livret', 'livret_a');
        self::service(Connection::class)->executeStatement('UPDATE users SET email_verified_at = NULL, totp_enabled_at = NULL WHERE id = ?', [$owner->value]);

        self::clock()->sleep(48 * 3600);
        self::assertSame(0, self::service(Registration::class)->purgeUnconfirmed());
        self::assertNotNull($this->find(TestUsers::EMAIL));
    }

    private function register(string $email): void
    {
        self::service(Registration::class)->register($email, self::PASSWORD);
    }

    /** @return array{id: UserId, email_verified: bool}|null */
    private function find(string $email): ?array
    {
        $row = self::service(UserRepository::class)->findByEmail($email);

        return $row === null ? null : ['id' => $row['id'], 'email_verified' => $row['email_verified']];
    }
}
