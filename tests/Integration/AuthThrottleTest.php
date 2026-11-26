<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Integration;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\UserId;
use Patrimeter\Security\Throttle\AuthThrottle;
use Patrimeter\Tests\Support\IntegrationTestCase;

/**
 * Limiters of config/packages/rate_limiter.yaml: 5 failures per IP over 15 minutes, 5 per account then one per minute
 * without a lockout, 100 in total per hour, 5 wrong codes per user over 15 minutes; 10 registrations and 10 forgotten
 * password requests per IP, 30 registrations for all addresses together, 3 emails per account, per hour. The passing
 * of time belongs to symfony/rate-limiter, tested by Symfony: here, the thresholds and the separation of the counters.
 */
final class AuthThrottleTest extends IntegrationTestCase
{
    private const string IP = '192.0.2.10';
    private const string OTHER_IP = '198.51.100.7';
    private const string EMAIL = 'quelquun@example.test';
    private const int FIFTEEN_MINUTES = 900;

    private AuthThrottle $throttle;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->throttle = self::service(AuthThrottle::class);
    }

    public function testFourFailuresFromAnAddressDoNotSlowItDown(): void
    {
        for ($i = 1; $i <= 4; ++$i) {
            $this->throttle->recordPasswordFailure(self::IP, "personne$i@example.test");
        }

        self::assertSame(0, $this->throttle->passwordRetryAfter(self::IP, 'autre@example.test'));
    }

    public function testFifthFailureLocksTheAddressForAboutFifteenMinutes(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->throttle->recordPasswordFailure(self::IP, "personne$i@example.test");
        }

        $wait = $this->throttle->passwordRetryAfter(self::IP, 'autre@example.test');
        self::assertGreaterThan(self::FIFTEEN_MINUTES - 60, $wait);
        self::assertLessThanOrEqual(self::FIFTEEN_MINUTES, $wait);
        self::assertSame(0, $this->throttle->passwordRetryAfter(self::OTHER_IP, 'autre@example.test'), 'les autres adresses ne sont pas touchées');
    }

    public function testAnAccountIsSlowedDownButNeverLockedFromOtherAddresses(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->throttle->recordPasswordFailure("192.0.2.$i", self::EMAIL);
        }

        $wait = $this->throttle->passwordRetryAfter(self::OTHER_IP, self::EMAIL);
        self::assertGreaterThan(0, $wait);
        self::assertLessThanOrEqual(60, $wait, 'un essai par minute, jamais de blocage');
        self::assertSame(0, $this->throttle->passwordRetryAfter(self::OTHER_IP, 'autre@example.test'));
    }

    public function testAccountCounterIgnoresCaseAndSpacesAndStoresNoAddress(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->throttle->recordPasswordFailure("192.0.2.$i", ' QuelquUn@Example.test ');
        }

        self::assertGreaterThan(0, $this->throttle->passwordRetryAfter(self::OTHER_IP, self::EMAIL));
        $stored = self::service(Connection::class)->fetchOne('SELECT group_concat(item_id || item_data) FROM cache_items');
        self::assertIsString($stored);
        self::assertStringNotContainsStringIgnoringCase('quelquun', $stored);
    }

    public function testGlobalCounterLocksEveryone(): void
    {
        for ($i = 1; $i <= 100; ++$i) {
            $this->throttle->recordPasswordFailure('10.0.' . intdiv($i, 250) . '.' . ($i % 250), "personne$i@example.test");
        }

        self::assertGreaterThan(0, $this->throttle->passwordRetryAfter(self::OTHER_IP, 'autre@example.test'));
    }

    public function testSuccessForgetsIpAndAccountCountersButNotTheGlobalOne(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->throttle->recordPasswordFailure(self::IP, self::EMAIL);
        }
        self::assertGreaterThan(0, $this->throttle->passwordRetryAfter(self::IP, self::EMAIL));

        $this->throttle->recordPasswordSuccess(self::IP, self::EMAIL);

        self::assertSame(0, $this->throttle->passwordRetryAfter(self::IP, self::EMAIL));
        for ($i = 1; $i <= 95; ++$i) {
            $this->throttle->recordPasswordFailure("10.1.0.$i", "personne$i@example.test");
        }
        self::assertGreaterThan(0, $this->throttle->passwordRetryAfter(self::OTHER_IP, 'autre@example.test'), 'les 5 premiers échecs comptent toujours au total');
    }

    public function testWrongCodesLockTheUserAndCountForTheIp(): void
    {
        $user = UserId::fromString('01890000-0000-7000-8000-000000000001');
        for ($i = 1; $i <= 5; ++$i) {
            $this->throttle->recordCodeFailure("192.0.2.$i", $user);
        }

        self::assertGreaterThan(self::FIFTEEN_MINUTES - 60, $this->throttle->codeRetryAfter(self::OTHER_IP, $user));
        self::assertSame(0, $this->throttle->codeRetryAfter(self::OTHER_IP, UserId::fromString('01890000-0000-7000-8000-000000000002')));
        for ($i = 1; $i <= 4; ++$i) {
            $this->throttle->recordCodeFailure(self::IP, UserId::fromString(sprintf('01890000-0000-7000-8000-%012d', 10 + $i)));
        }
        self::assertSame(0, $this->throttle->ipRetryAfter(self::IP));
        $this->throttle->recordCodeFailure(self::IP, UserId::fromString('01890000-0000-7000-8000-000000000099'));
        self::assertGreaterThan(0, $this->throttle->ipRetryAfter(self::IP), 'chaque code faux compte aussi pour son adresse');
    }

    public function testCodeSuccessAndNewPasswordForgetTheCounters(): void
    {
        $user = UserId::fromString('01890000-0000-7000-8000-000000000001');
        for ($i = 1; $i <= 5; ++$i) {
            $this->throttle->recordCodeFailure(self::IP, $user);
        }
        $this->throttle->recordCodeSuccess(self::IP, $user);
        self::assertSame(0, $this->throttle->codeRetryAfter(self::IP, $user));

        for ($i = 1; $i <= 5; ++$i) {
            $this->throttle->recordCodeFailure("192.0.2.$i", $user);
            $this->throttle->recordPasswordFailure("192.0.2.$i", self::EMAIL);
        }
        $this->throttle->forgetAccount(self::EMAIL, $user);
        self::assertSame(0, $this->throttle->codeRetryAfter(self::OTHER_IP, $user));
        self::assertSame(0, $this->throttle->passwordRetryAfter(self::OTHER_IP, self::EMAIL));
    }

    public function testRegistrationsAndPasswordResetsAreLimitedPerIp(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            self::assertSame(0, $this->throttle->registrationRetryAfter(self::IP));
            $this->throttle->recordRegistration(self::IP);
            self::assertSame(0, $this->throttle->passwordResetRetryAfter(self::IP));
            $this->throttle->recordPasswordResetRequest(self::IP);
        }

        self::assertGreaterThan(0, $this->throttle->registrationRetryAfter(self::IP));
        self::assertGreaterThan(0, $this->throttle->passwordResetRetryAfter(self::IP));
        self::assertSame(0, $this->throttle->registrationRetryAfter(self::OTHER_IP));
    }

    public function testEmailsPerAccountAreLimited(): void
    {
        $user = UserId::fromString('01890000-0000-7000-8000-000000000001');
        self::assertTrue($this->throttle->allowEmail($user));
        self::assertTrue($this->throttle->allowEmail($user));
        self::assertTrue($this->throttle->allowEmail($user));
        self::assertFalse($this->throttle->allowEmail($user));
        self::assertTrue($this->throttle->allowEmail(UserId::fromString('01890000-0000-7000-8000-000000000002')));
    }

    public function testIpCounterAlone(): void
    {
        for ($i = 1; $i <= 4; ++$i) {
            $this->throttle->recordIpFailure(self::IP);
        }
        self::assertSame(0, $this->throttle->ipRetryAfter(self::IP));
        $this->throttle->recordIpFailure(self::IP);

        self::assertGreaterThan(0, $this->throttle->ipRetryAfter(self::IP));
        self::assertSame(0, $this->throttle->ipRetryAfter(self::OTHER_IP));
    }
}
