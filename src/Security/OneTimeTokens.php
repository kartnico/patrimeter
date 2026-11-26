<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Application\Calendar;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\OneTimeTokenRepository;

/**
 * Single-use tokens sent by email (address confirmation…).
 *
 * Token = selector (128 bits) + "." + verifier (256 bits), in base64url. The database only holds the
 * selector and the SHA-256 hash of the verifier, compared in constant time: a copy of the database does
 * not allow rebuilding a valid link.
 */
final class OneTimeTokens
{
    public const string EMAIL_VERIFICATION = 'email_verification';
    public const string PASSWORD_RESET = 'password_reset';

    private const int SELECTOR_BYTES = 16;
    private const int VERIFIER_BYTES = 32;
    private const string PATTERN = '/\A([A-Za-z0-9_-]{22})\.([A-Za-z0-9_-]{43})\z/';
    private const int PURGE_AFTER_SECONDS = 86400;

    public function __construct(
        private readonly OneTimeTokenRepository $tokens,
        private readonly Calendar $calendar,
    ) {}

    /** Forgets the tokens expired for more than one day (scheduled task). */
    public function purgeExpired(): void
    {
        $this->tokens->purge($this->calendar->timestamp(-self::PURGE_AFTER_SECONDS));
    }

    public function issue(UserId $user, string $purpose, int $lifetimeSeconds): string
    {
        $selector = self::encode(random_bytes(self::SELECTOR_BYTES));
        $verifier = self::encode(random_bytes(self::VERIFIER_BYTES));
        $this->tokens->create($selector, $user, $purpose, hash('sha256', $verifier), $this->calendar->timestamp($lifetimeSeconds), $this->calendar->timestamp());

        return $selector . '.' . $verifier;
    }

    /** User of the token if it is valid (neither expired nor used), without consuming it. */
    public function peek(#[\SensitiveParameter] string $token, string $purpose): ?UserId
    {
        if (preg_match(self::PATTERN, $token, $parts) !== 1) {
            return null;
        }
        $row = $this->tokens->find($parts[1], $purpose);
        if ($row === null || $row['used_at'] !== null || $row['expires_at'] <= $this->calendar->timestamp()
            || !hash_equals($row['verifier_hash'], hash('sha256', $parts[2]))) {
            return null;
        }

        return $row['user_id'];
    }

    /** Consumes the token: user if it was valid, null otherwise (a single use possible). */
    public function consume(#[\SensitiveParameter] string $token, string $purpose): ?UserId
    {
        $user = $this->peek($token, $purpose);
        if ($user === null || !$this->tokens->consume(explode('.', $token, 2)[0], $this->calendar->timestamp())) {
            return null;
        }

        return $user;
    }

    public function revokeAll(UserId $user, string $purpose): void
    {
        $this->tokens->revokeAll($user, $purpose, $this->calendar->timestamp());
    }

    private static function encode(string $bytes): string
    {
        return sodium_bin2base64($bytes, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }
}
