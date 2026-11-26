<?php

declare(strict_types=1);

namespace Patrimeter\Security\Throttle;

use Patrimeter\Domain\UserId;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Attempt limiting, on symfony/rate-limiter: limiters declared in config/packages/rate_limiter.yaml,
 * counters in the cache.security pool (cache_items table), shared by the php-fpm processes and kept by
 * cache:clear.
 *
 * - per IP address: password, code or token failures, lockout beyond the threshold;
 * - per account: a few failures in a row, then one attempt per minute, never a lockout (a third party must
 *   not be able to lock the user out); the key is a hash of the address entered;
 * - global: against an attack spread over many addresses;
 * - wrong single-use codes, per user;
 * - registrations (per IP and for all addresses together) and forgotten password requests per IP, emails
 *   sent per account.
 */
final class AuthThrottle
{
    private const string GLOBAL_KEY = 'global';

    public function __construct(
        private readonly RateLimiterFactoryInterface $authIpLimiter,
        private readonly RateLimiterFactoryInterface $authAccountLimiter,
        private readonly RateLimiterFactoryInterface $authGlobalLimiter,
        private readonly RateLimiterFactoryInterface $authCodeLimiter,
        private readonly RateLimiterFactoryInterface $registrationLimiter,
        private readonly RateLimiterFactoryInterface $registrationGlobalLimiter,
        private readonly RateLimiterFactoryInterface $passwordResetLimiter,
        private readonly RateLimiterFactoryInterface $accountEmailLimiter,
    ) {}

    /** Wait before a password can be checked (0 = allowed). */
    public function passwordRetryAfter(string $ip, string $email): int
    {
        return self::wait(...$this->passwordLimiters($ip, $email));
    }

    public function recordPasswordFailure(string $ip, string $email): void
    {
        self::hit(...$this->passwordLimiters($ip, $email));
    }

    /** A success clears the failures of the IP address and of the account, not the global counter. */
    public function recordPasswordSuccess(string $ip, string $email): void
    {
        $this->authIpLimiter->create($ip)->reset();
        $this->authAccountLimiter->create(self::accountKey($email))->reset();
    }

    /** Wait before a password check outside sign-in (confirmation link…). */
    public function ipRetryAfter(string $ip): int
    {
        return self::wait($this->authIpLimiter->create($ip));
    }

    public function recordIpFailure(string $ip): void
    {
        self::hit($this->authIpLimiter->create($ip));
    }

    /** Wait before a single-use code can be checked (0 = allowed). */
    public function codeRetryAfter(string $ip, UserId $user): int
    {
        return self::wait($this->authIpLimiter->create($ip), $this->authCodeLimiter->create($user->value));
    }

    public function recordCodeFailure(string $ip, UserId $user): void
    {
        self::hit($this->authIpLimiter->create($ip), $this->authCodeLimiter->create($user->value));
    }

    public function recordCodeSuccess(string $ip, UserId $user): void
    {
        $this->authIpLimiter->create($ip)->reset();
        $this->authCodeLimiter->create($user->value)->reset();
    }

    /** Wait before a new registration from this IP (0 = allowed): per address, and all addresses together. */
    public function registrationRetryAfter(string $ip): int
    {
        return self::wait($this->registrationLimiter->create($ip), $this->registrationGlobalLimiter->create(self::GLOBAL_KEY));
    }

    public function recordRegistration(string $ip): void
    {
        self::hit($this->registrationLimiter->create($ip), $this->registrationGlobalLimiter->create(self::GLOBAL_KEY));
    }

    /** Wait before a new reset request from this IP (0 = allowed). */
    public function passwordResetRetryAfter(string $ip): int
    {
        return self::wait($this->passwordResetLimiter->create($ip));
    }

    public function recordPasswordResetRequest(string $ip): void
    {
        self::hit($this->passwordResetLimiter->create($ip));
    }

    /** True if a new email can be sent for this account; the sending is then counted. */
    public function allowEmail(UserId $user): bool
    {
        return $this->accountEmailLimiter->create($user->value)->consume()->isAccepted();
    }

    /** Forgets the counters of an account (password set again). */
    public function forgetAccount(string $email, UserId $user): void
    {
        $this->authAccountLimiter->create(self::accountKey($email))->reset();
        $this->authCodeLimiter->create($user->value)->reset();
    }

    /** @return list<LimiterInterface> */
    private function passwordLimiters(string $ip, string $email): array
    {
        return [
            $this->authIpLimiter->create($ip),
            $this->authAccountLimiter->create(self::accountKey($email)),
            $this->authGlobalLimiter->create(self::GLOBAL_KEY),
        ];
    }

    /** Longest wait among the exhausted limiters, in seconds; read without counting anything. */
    private static function wait(LimiterInterface ...$limiters): int
    {
        $wait = 0;
        foreach ($limiters as $limiter) {
            $limit = $limiter->consume(0);
            if ($limit->getRemainingTokens() === 0) {
                // The deadline is rounded up to the next second: never wait one second too many.
                $wait = max($wait, (int) floor((float) $limit->getRetryAfter()->getTimestamp() - microtime(true)), 1);
            }
        }

        return $wait;
    }

    /** Counts a failure, even beyond the threshold. */
    private static function hit(LimiterInterface ...$limiters): void
    {
        foreach ($limiters as $limiter) {
            $limiter->consume();
        }
    }

    /** Hash of the address entered: no address in clear in the counters. */
    private static function accountKey(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }
}
