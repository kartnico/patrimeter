<?php

declare(strict_types=1);

namespace Patrimeter\Security\TwoFactor;

use Patrimeter\Application\Calendar;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\BackupCodeRepository;
use Patrimeter\Security\AuthSecrets;
use Patrimeter\Security\User;
use Scheb\TwoFactorBundle\Security\TwoFactor\Backup\BackupCodeManagerInterface;

/**
 * Backup codes: 10 single-use codes of 10 characters (about 50 bits each), shown only once; only their
 * HMAC hash is kept (AuthSecrets).
 *
 * The check consumes the code atomically: two simultaneous sign-ins with the same code cannot both
 * succeed. scheb/2fa-bundle then calls invalidateBackupCode(), which has nothing left to do.
 */
final class BackupCodes implements BackupCodeManagerInterface
{
    public const int COUNT = 10;
    private const int LENGTH = 10;
    private const int GROUP = 5;
    /** Without 0/O nor 1/I/L: no confusion when copying them. */
    private const string ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(
        private readonly BackupCodeRepository $codes,
        private readonly AuthSecrets $secrets,
        private readonly Calendar $calendar,
    ) {}

    /**
     * Replaces the user's codes with new ones.
     *
     * @return list<string> codes to show, in the "XXXXX-XXXXX" format
     */
    public function regenerate(UserId $user): array
    {
        $codes = [];
        $hashes = [];
        for ($i = 0; $i < self::COUNT; ++$i) {
            $code = self::randomCode();
            $codes[] = substr($code, 0, self::GROUP) . '-' . substr($code, self::GROUP);
            $hashes[] = $this->secrets->backupCodeHash($user, $code);
        }
        $this->codes->replaceAll($user, $hashes, $this->calendar->timestamp());

        return $codes;
    }

    public function remaining(UserId $user): int
    {
        return $this->codes->remaining($user);
    }

    #[\Override]
    public function isBackupCode(object $user, #[\SensitiveParameter] string $code): bool
    {
        $normalized = self::normalize($code);
        if (!$user instanceof User || $normalized === null) {
            return false;
        }

        foreach ($this->secrets->backupCodeHashes($user->id, $normalized) as $hash) {
            if ($this->codes->consume($user->id, $hash, $this->calendar->timestamp())) {
                return true;
            }
        }

        return false;
    }

    /** Already done by isBackupCode(), atomically. */
    #[\Override]
    public function invalidateBackupCode(object $user, #[\SensitiveParameter] string $code): void {}

    /** Code entered without spaces nor dashes, uppercase; null if it cannot be a code. */
    public static function normalize(string $input): ?string
    {
        $code = strtoupper((string) preg_replace('/[\s-]+/', '', $input));

        return strlen($code) === self::LENGTH && strspn($code, self::ALPHABET) === self::LENGTH ? $code : null;
    }

    private static function randomCode(): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
