<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Domain\PasswordPolicy;
use Patrimeter\Security\Throttle\AuthThrottle;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Identity confirmation before a sensitive action (backup codes, authenticator app, sessions):
 * password entered again less than 5 minutes ago. A stolen session is therefore not enough to change
 * the authentication methods.
 */
final class Reauthentication
{
    public const int LIFETIME_SECONDS = 300;
    private const string SESSION_KEY = '_patrimeter_reauthenticated_at';

    public function __construct(
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly AuthThrottle $throttle,
        private readonly AuthJournal $journal,
        private readonly ClockInterface $clock,
    ) {}

    public function isFresh(Request $request): bool
    {
        return $this->isRecent($request->getSession()->get(self::SESSION_KEY));
    }

    /**
     * Checks the password (with the same limiting as the sign-in).
     *
     * @return array{confirmed: bool, checked: bool, retryAfter: int} identity confirmed;
     *         password actually checked (otherwise immediate refusal during a delay);
     *         seconds to wait before a new attempt
     */
    public function confirm(Request $request, User $user, #[\SensitiveParameter] string $password): array
    {
        $ip = PasswordLoginAuthenticator::clientIp($request);
        $email = (string) $user->email;
        $wait = $this->throttle->passwordRetryAfter($ip, $email);
        if ($wait > 0) {
            return ['confirmed' => false, 'checked' => false, 'retryAfter' => $wait];
        }
        if (!$this->isValid($user, $password)) {
            $this->throttle->recordPasswordFailure($ip, $email);
            $this->journal->record($user->id, AuthJournal::REAUTH_FAILED, $request, false);

            return ['confirmed' => false, 'checked' => true, 'retryAfter' => $this->throttle->passwordRetryAfter($ip, $email)];
        }
        $this->throttle->recordPasswordSuccess($ip, $email);
        $request->getSession()->set(self::SESSION_KEY, $this->now());
        $this->journal->record($user->id, AuthJournal::REAUTHENTICATED, $request);

        return ['confirmed' => true, 'checked' => true, 'retryAfter' => 0];
    }

    /** Password of the account. */
    private function isValid(User $user, #[\SensitiveParameter] string $secret): bool
    {
        $hash = $user->getPassword();
        if ($hash === null || strlen($secret) > PasswordPolicy::MAX_BYTES) {
            return false;
        }

        return $this->hashers->getPasswordHasher(User::class)->verify($hash, $secret);
    }

    private function isRecent(mixed $timestamp): bool
    {
        return is_int($timestamp) && $this->now() - $timestamp <= self::LIFETIME_SECONDS;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
