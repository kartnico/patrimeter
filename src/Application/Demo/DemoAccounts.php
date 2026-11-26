<?php

declare(strict_types=1);

namespace Patrimeter\Application\Demo;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Calendar;
use Patrimeter\Domain\UserId;
use Patrimeter\Http\Sentences;
use Patrimeter\Infrastructure\Crypto\Aead;
use Patrimeter\Infrastructure\Persistence\DemoAccountRepository;
use Patrimeter\Security\RetryDelay;
use Patrimeter\Security\User;
use Patrimeter\Security\UserProvider;
use Patrimeter\Security\Vault\Vault;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Demonstration mode (DEMO_MODE): anyone who can reach the sign-in page can try Patrimeter in a temporary account,
 * without registering, password or TOTP, filled with example data (DemoPortfolio).
 *
 * What keeps it from polluting the instance:
 * - each account is deleted with all its data at the end of its lifetime (scheduled tasks), or when signing out;
 * - a limited number of demonstration accounts at the same time, and of creations per IP address and in all;
 * - a quota of stored rows per account, and the restrictions of DemoGuard (no email sent, no import, no API, no
 *   authentication method, writes limited).
 * Its address ends in ".invalid" (RFC 2606): it can receive nothing. Without a password nor TOTP, nobody can sign in to
 * it again; its data key only exists in the session.
 */
final class DemoAccounts
{
    public const string EMAIL_DOMAIN = 'demo.invalid';

    public function __construct(
        private readonly DemoAccountRepository $repository,
        private readonly DemoPortfolio $portfolio,
        private readonly UserProvider $users,
        private readonly Vault $vault,
        private readonly Calendar $calendar,
        private readonly Connection $connection,
        private readonly RateLimiterFactoryInterface $demoStartLimiter,
        private readonly RateLimiterFactoryInterface $demoStartGlobalLimiter,
        #[Autowire('%env(bool:DEMO_MODE)%')]
        private readonly bool $enabled,
        #[Autowire('%app.demo.lifetime%')]
        public readonly int $lifetime,
        #[Autowire('%app.demo.max_accounts%')]
        private readonly int $maxAccounts,
        #[Autowire('%app.demo.max_rows%')]
        public readonly int $maxRows,
        #[Autowire('%app.demo.history_years%')]
        private readonly int $historyYears,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Creates a demonstration account with its example data, in the language of the request, and opens its vault for
     * the session (data key that only exists there). The caller signs it in.
     *
     * @return User|TranslatableInterface the account, or why the demonstration is not available right now and how long
     *     to wait
     */
    public function start(Request $request): User|TranslatableInterface
    {
        $now = $this->calendar->timestamp();
        $this->repository->deleteExpired($now);
        if ($this->repository->activeCount($now) >= $this->maxAccounts) {
            // Full: a place frees up when the first demonstration account ends.
            $next = $this->repository->nextExpiry($now);

            return Sentences::of(t('La démonstration est complète pour le moment.'), RetryDelay::sentence($next === null ? 60 : $this->secondsUntil($next)));
        }
        $perAddress = $this->demoStartLimiter->create($request->getClientIp() ?? 'inconnue')->consume();
        $global = $this->demoStartGlobalLimiter->create('global')->consume();
        if (!$perAddress->isAccepted() || !$global->isAccepted()) {
            $wait = 1;
            foreach ([$perAddress, $global] as $limit) {
                if (!$limit->isAccepted()) {
                    // Rounded up to the next second: never wait one second too few.
                    $wait = max($wait, (int) ceil((float) $limit->getRetryAfter()->format('U.u') - microtime(true)));
                }
            }

            return Sentences::of(t('Trop de comptes de démonstration ont été créés.'), RetryDelay::sentence($wait));
        }

        $id = UserId::fromString(Uuid::v7()->toRfc4122());
        $key = random_bytes(Aead::KEY_BYTES);
        $this->connection->transactional(function () use ($id, $key, $now, $request): void {
            $this->repository->create($id, 'demo-' . bin2hex(random_bytes(6)) . '@' . self::EMAIL_DOMAIN, $now, $this->calendar->timestamp($this->lifetime));
            $this->vault->startSession($request, $id, $key);
            $this->portfolio->seed($id, $request->getLocale(), $this->historyYears);
        });
        sodium_memzero($key);

        return $this->users->loadUserByIdentifier($id->value);
    }

    /** True once the account's lifetime is over (it is then signed out and deleted). */
    public function hasExpired(User $user): bool
    {
        return $user->demoExpiresAt !== null && $user->demoExpiresAt <= $this->calendar->timestamp();
    }

    /** True when the account has reached its quota of stored rows: it can then only delete. */
    public function isFull(UserId $user): bool
    {
        return $this->repository->storedRows($user) >= $this->maxRows;
    }

    /** Deletes this demonstration account and all its data (sign-out, end of lifetime, mode disabled). */
    public function end(UserId $user): void
    {
        $this->repository->delete($user);
    }

    /** Deletes the expired demonstration accounts (scheduled tasks); returns how many were deleted. */
    public function purgeExpired(): int
    {
        return $this->repository->deleteExpired($this->calendar->timestamp());
    }

    /** Seconds from now to a UTC timestamp of the database (1 at least). */
    private function secondsUntil(string $timestamp): int
    {
        $end = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $timestamp, new \DateTimeZone('UTC'));

        return $end === false ? 60 : max(1, $end->getTimestamp() - $this->calendar->now()->getTimestamp());
    }

    /** Time (in the application's time zone) when this account is deleted, "HH:MM", or null for an ordinary account. */
    public function endTime(User $user): ?string
    {
        if ($user->demoExpiresAt === null) {
            return null;
        }
        $end = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $user->demoExpiresAt, new \DateTimeZone('UTC'));

        return $end === false ? null : $end->setTimezone($this->calendar->timezone())->format('H:i');
    }
}
