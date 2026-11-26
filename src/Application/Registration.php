<?php

declare(strict_types=1);

namespace Patrimeter\Application;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\UserId;
use Patrimeter\Http\PublicUrl;
use Patrimeter\Infrastructure\Mail\AccountMailer;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\OneTimeTokens;
use Patrimeter\Security\Throttle\AuthThrottle;
use Patrimeter\Security\User;
use Patrimeter\Security\UserProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Translation\t;

/**
 * Registration and confirmation of the email address.
 *
 * - Same answer, same cost (one Argon2id hash, one email) whether the address is new or
 *   already registered: the form does not reveal who has an account. The holder of an
 *   already registered address receives an information message.
 * - A registration never confirmed can be done again (new password, old links
 *   invalidated): nobody can block an address that is not theirs.
 * - The confirmation requires the link received by email AND the chosen password: a third party
 *   who registers someone else's address cannot get the victim to confirm their account.
 */
final class Registration
{
    private const int VERIFICATION_LIFETIME = 86400;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserProvider $userProvider,
        private readonly OneTimeTokens $tokens,
        private readonly AccountMailer $mailer,
        private readonly PublicUrl $urls,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly AuthThrottle $throttle,
        private readonly Calendar $calendar,
        private readonly Connection $connection,
        #[Autowire('%env(bool:REGISTRATION_ENABLED)%')]
        private readonly bool $enabled,
        #[Autowire('%env(REGISTRATION_ALLOWED_DOMAINS)%')]
        private readonly string $allowedDomains,
    ) {}

    /**
     * Deletes the registrations never confirmed once their link has expired (24 hours, or 24 hours after the last
     * link sent): the address becomes free again (scheduled task).
     */
    public function purgeUnconfirmed(): int
    {
        return $this->users->deleteUnconfirmed(
            $this->calendar->timestamp(-self::VERIFICATION_LIFETIME),
            $this->calendar->timestamp(),
            OneTimeTokens::EMAIL_VERIFICATION,
        );
    }

    public function isOpen(): bool
    {
        return $this->enabled;
    }

    /** @return list<string> allowed domains (empty: all) */
    public function allowedDomains(): array
    {
        $domains = array_map(static fn(string $d): string => strtolower(trim($d)), explode(',', $this->allowedDomains));

        return array_values(array_filter($domains, static fn(string $d): bool => $d !== ''));
    }

    /** Normalised address whose domain is accepted. */
    public function acceptsDomain(string $email): bool
    {
        $domains = $this->allowedDomains();
        $at = strrpos($email, '@');

        return $domains === [] || ($at !== false && in_array(substr($email, $at + 1), $domains, true));
    }

    /** Normalised, valid and accepted address; password compliant with PasswordPolicy. */
    public function register(string $email, #[\SensitiveParameter] string $password): void
    {
        $hash = $this->hashers->getPasswordHasher(User::class)->hash($password);
        $existing = $this->users->findByEmail($email);

        if ($existing === null) {
            $id = UserId::fromString(Uuid::v7()->toRfc4122());
            $this->users->create($id, $email, $hash, null, $this->calendar->timestamp());
            // First sending of the quota, always allowed: the account has just been created.
            $this->throttle->allowEmail($id);
            $this->sendVerification($id, $email);

            return;
        }
        if (!$existing['email_verified']) {
            // Quota reached: nothing changes, otherwise a third party would invalidate the links already received.
            if (!$this->throttle->allowEmail($existing['id'])) {
                return;
            }
            $this->connection->transactional(function () use ($existing, $hash): void {
                $this->users->replacePendingPassword($existing['id'], $hash);
                $this->tokens->revokeAll($existing['id'], OneTimeTokens::EMAIL_VERIFICATION);
            });
            $this->sendVerification($existing['id'], $email);

            return;
        }
        if ($this->throttle->allowEmail($existing['id'])) {
            $this->mailer->send($email, t('Patrimeter : vous avez déjà un compte'), 'already_registered', [
                'login_url' => $this->urls->generate('app_login'),
            ]);
        }
    }

    /** Sends the confirmation link again (sign-in with an address not confirmed yet). */
    public function resendVerification(UserId $user): void
    {
        $row = $this->users->find($user);
        if ($row === null || $row['email'] === null || $row['email_verified'] || !$this->throttle->allowEmail($user)) {
            return;
        }
        $this->tokens->revokeAll($user, OneTimeTokens::EMAIL_VERIFICATION);
        $this->sendVerification($user, $row['email']);
    }

    /** True if the confirmation link is still valid. */
    public function isPending(#[\SensitiveParameter] string $token): bool
    {
        return $this->tokens->peek($token, OneTimeTokens::EMAIL_VERIFICATION) !== null;
    }

    /**
     * Confirms the address if the link is valid and the password is that of the registration.
     *
     * @return User|null confirmed user (to sign in), null if the link or the password is wrong
     */
    public function confirm(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): ?User
    {
        $id = $this->tokens->peek($token, OneTimeTokens::EMAIL_VERIFICATION);
        $row = $id === null ? null : $this->users->find($id);
        $hasher = $this->hashers->getPasswordHasher(User::class);
        if ($id === null || $row === null || $row['password_hash'] === null || $password === '') {
            if ($password !== '') {
                $hasher->hash($password);
            }

            return null;
        }
        if (!$hasher->verify($row['password_hash'], $password) || $this->tokens->consume($token, OneTimeTokens::EMAIL_VERIFICATION) === null) {
            return null;
        }
        $this->users->markEmailVerified($id, $this->calendar->timestamp());

        return $this->userProvider->loadUserByIdentifier($id->value);
    }

    private function sendVerification(UserId $user, string $email): void
    {
        $token = $this->tokens->issue($user, OneTimeTokens::EMAIL_VERIFICATION, self::VERIFICATION_LIFETIME);
        $this->mailer->send($email, t('Patrimeter : confirmez votre adresse e-mail'), 'verification', [
            'url' => $this->urls->generate('app_register_confirm', ['token' => $token]),
            'hours' => intdiv(self::VERIFICATION_LIFETIME, 3600),
        ]);
    }
}
