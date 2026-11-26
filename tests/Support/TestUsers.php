<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Support;

use OTPHP\TOTP;
use Patrimeter\Application\Calendar;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Crypto\Aead;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\AuthSecrets;
use Patrimeter\Security\TwoFactor\BackupCodes;
use Patrimeter\Security\User;
use Patrimeter\Security\Vault\DataKeys;
use Patrimeter\Security\Vault\Vault;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Test users: address confirmed and TOTP active by default, known TOTP secret to compute the codes at the frozen time
 * of the test clock.
 */
final class TestUsers
{
    /** @var array<string, string> user => data key */
    private static array $keys = [];

    public const string EMAIL = 'proprietaire@example.test';
    public const string OTHER_EMAIL = 'voisin@example.test';
    public const string PASSWORD = 'mot de passe de test';
    /** Recovery key of the test users (32 bytes). */
    public const string RECOVERY_KEY = 'cle de recuperation de test 1234';
    /** 20 bytes in base32 (RFC 4648): TOTP key of the test users. */
    public const string TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /**
     * Creates a user. With TOTP, they also get their 10 backup codes (returned in $backupCodes) as after a real setup.
     *
     * @param list<string> $backupCodes
     *
     * @param-out list<string> $backupCodes
     */
    public static function create(
        ContainerInterface $container,
        string $email = self::EMAIL,
        bool $verified = true,
        bool $totp = true,
        string $password = self::PASSWORD,
        array &$backupCodes = [],
    ): UserId {
        $users = self::get($container, UserRepository::class);
        $now = self::get($container, Calendar::class)->timestamp();
        $id = UserId::fromString(Uuid::v7()->toRfc4122());
        $hash = self::get($container, PasswordHasherFactoryInterface::class)->getPasswordHasher(User::class)->hash($password);
        $users->create($id, $email, $hash, $verified ? $now : null, $now);
        if ($totp) {
            $users->enableTotp($id, self::get($container, AuthSecrets::class)->encryptTotpSecret($id, self::TOTP_SECRET), $now);
            $backupCodes = self::get($container, BackupCodes::class)->regenerate($id);
            // Account ready: data key created, recovery key written down.
            $key = random_bytes(Aead::KEY_BYTES);
            self::$keys[$id->value] = $key;
            $keys = self::get($container, DataKeys::class);
            $keys->savePasswordEnvelope($id, $key, $password);
            $keys->saveRecoveryEnvelope($id, $key, self::RECOVERY_KEY);
            self::unlock($container, $id);
        }

        return $id;
    }

    /** Identifier of the user of this address, created if needed. */
    public static function findOrCreate(ContainerInterface $container, string $email = self::EMAIL): UserId
    {
        $id = self::get($container, UserRepository::class)->findByEmail($email)['id'] ?? self::create($container, $email);
        self::unlock($container, $id);

        return $id;
    }

    /** Makes the user's data key available to the repositories called by the test. */
    public static function unlock(ContainerInterface $container, UserId $id): void
    {
        if (isset(self::$keys[$id->value])) {
            self::get($container, Vault::class)->unlock($id, self::$keys[$id->value]);
        }
    }

    /**
     * TOTP code valid at the time of the test clock (shifted by $periods periods of 30 s).
     *
     * @param non-empty-string $secret
     */
    public static function totpCode(ContainerInterface $container, int $periods = 0, string $secret = self::TOTP_SECRET): string
    {
        // Frozen clock of the test container (config/services.yaml, when@test).
        $clock = self::get($container, ClockInterface::class);
        $totp = TOTP::create($secret, clock: $clock);

        return $totp->at(max(0, $clock->now()->getTimestamp() + 30 * $periods));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function get(ContainerInterface $container, string $id): object
    {
        $service = $container->get($id);
        if (!$service instanceof $id) {
            throw new \LogicException("Service $id indisponible");
        }

        return $service;
    }
}
