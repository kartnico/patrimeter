<?php

declare(strict_types=1);

namespace Patrimeter\Application;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\EmailAddress;
use Patrimeter\Domain\UserId;
use Patrimeter\Http\PublicUrl;
use Patrimeter\Http\Sentences;
use Patrimeter\Infrastructure\Mail\AccountMailer;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\BackupCodeRepository;
use Patrimeter\Infrastructure\Persistence\KeyEnvelopeRepository;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\AuthJournal;
use Patrimeter\Security\OneTimeTokens;
use Patrimeter\Security\PasswordLoginAuthenticator;
use Patrimeter\Security\PasswordRules;
use Patrimeter\Security\RetryDelay;
use Patrimeter\Security\Throttle\AuthThrottle;
use Patrimeter\Security\TwoFactor\BackupCodes;
use Patrimeter\Security\User;
use Patrimeter\Security\UserProvider;
use Patrimeter\Security\UserSessions;
use Patrimeter\Security\Vault\DataKeys;
use Patrimeter\Security\Vault\RecoveryKey;
use Scheb\TwoFactorBundle\Security\Authentication\Exception\ReusedTwoFactorCodeException;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorCodeEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Forgotten password: single-use link (30 minutes), then two cases.
 *
 * - With the recovery key: the data key is kept, and so is the data. A valid TOTP or backup
 *   code keeps the second factor; without a code (lost phone), it is removed and must be set up
 *   again at the next sign-in.
 * - Without a recovery key: the second factor is required and the data, unreadable without the
 *   old password, is permanently erased after explicit confirmation.
 */
final class PasswordReset
{
    private const int LIFETIME = 1800;

    public function __construct(
        private readonly PasswordRules $passwordRules,
        private readonly UserSessions $sessions,
        private readonly UserRepository $users,
        private readonly UserProvider $userProvider,
        private readonly OneTimeTokens $tokens,
        private readonly AccountMailer $mailer,
        private readonly PublicUrl $urls,
        private readonly AuthThrottle $throttle,
        private readonly AuthJournal $journal,
        private readonly DataKeys $dataKeys,
        private readonly KeyEnvelopeRepository $envelopes,
        private readonly AccountRepository $accounts,
        private readonly BackupCodes $backupCodes,
        private readonly BackupCodeRepository $backupCodeRows,
        private readonly TotpAuthenticatorInterface $totp,
        private readonly EventDispatcherInterface $events,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly Connection $connection,
    ) {}

    /** Sends the link if the address has a confirmed account. Nothing tells the other cases apart for the requester. */
    public function request(string $typedEmail): void
    {
        $email = EmailAddress::normalize($typedEmail);
        $row = $email === null ? null : $this->users->findByEmail($email);
        if ($email === null || $row === null || !$row['email_verified'] || !$this->throttle->allowEmail($row['id'])) {
            return;
        }
        $this->tokens->revokeAll($row['id'], OneTimeTokens::PASSWORD_RESET);
        $token = $this->tokens->issue($row['id'], OneTimeTokens::PASSWORD_RESET, self::LIFETIME);
        $this->mailer->send($email, t('Patrimeter : réinitialisation du mot de passe'), 'password_reset', [
            'url' => $this->urls->generate('app_password_reset', ['token' => $token]),
            'minutes' => intdiv(self::LIFETIME, 60),
        ]);
    }

    public function isValid(#[\SensitiveParameter] string $token): bool
    {
        return $this->tokens->peek($token, OneTimeTokens::PASSWORD_RESET) !== null;
    }

    /** User of the link, if it is still valid. */
    public function userOf(#[\SensitiveParameter] string $token): ?User
    {
        $id = $this->tokens->peek($token, OneTimeTokens::PASSWORD_RESET);

        return $id === null ? null : $this->userProvider->loadUserByIdentifier($id->value);
    }

    /**
     * Applies the reset.
     *
     * @return string|TranslatableInterface|null reason for the refusal (translated on display), null on success
     */
    public function complete(
        Request $request,
        #[\SensitiveParameter]
        string $token,
        #[\SensitiveParameter]
        string $newPassword,
        #[\SensitiveParameter]
        string $confirmation,
        #[\SensitiveParameter]
        string $recoveryInput,
        #[\SensitiveParameter]
        string $code,
        bool $acceptDataLoss,
    ): string|TranslatableInterface|null {
        $user = $this->userOf($token);
        if ($user === null) {
            return t('Ce lien n\'est plus valable.');
        }
        $ip = PasswordLoginAuthenticator::clientIp($request);
        $wait = $this->throttle->codeRetryAfter($ip, $user->id);
        if ($wait > 0) {
            return Sentences::of(t('Trop de tentatives.'), RetryDelay::sentence($wait));
        }
        $violation = $this->passwordRules->violation($newPassword, $user->email);
        if ($violation !== null) {
            return $violation;
        }
        if (!hash_equals($newPassword, $confirmation)) {
            return t('Les deux mots de passe ne correspondent pas.');
        }
        $hash = $this->hashers->getPasswordHasher(User::class)->hash($newPassword);

        return trim($recoveryInput) !== ''
            ? $this->withRecoveryKey($request, $token, $user, $hash, $newPassword, $recoveryInput, $code)
            : $this->withoutRecoveryKey($request, $token, $user, $hash, $code, $acceptDataLoss);
    }

    private function withRecoveryKey(Request $request, string $token, User $user, string $hash, #[\SensitiveParameter] string $newPassword, #[\SensitiveParameter] string $recoveryInput, #[\SensitiveParameter] string $code): ?TranslatableInterface
    {
        $recoveryKey = RecoveryKey::parse($recoveryInput);
        $key = $recoveryKey === null ? null : $this->dataKeys->keyFromRecovery($user->id, $recoveryKey);
        if ($key === null) {
            return $this->failed($request, $user, t('Clé de récupération incorrecte.'));
        }
        $keepSecondFactor = false;
        if (trim($code) !== '') {
            if (!$this->isValidCode($user, $code)) {
                return $this->failed($request, $user, t('Code incorrect.'));
            }
            $keepSecondFactor = true;
        }
        $this->connection->transactional(function () use ($token, $user, $hash, $key, $newPassword, $keepSecondFactor): void {
            $this->finish($token, $user->id, $hash);
            $this->dataKeys->savePasswordEnvelope($user->id, $key, $newPassword);
            if (!$keepSecondFactor) {
                $this->users->disableTotp($user->id);
                $this->backupCodeRows->deleteAll($user->id);
            }
        });
        $this->conclude($request, $user, AuthJournal::PASSWORD_RESET, t('Le mot de passe de votre compte a été réinitialisé avec votre clé de récupération.'));

        return null;
    }

    private function withoutRecoveryKey(Request $request, string $token, User $user, string $hash, #[\SensitiveParameter] string $code, bool $acceptDataLoss): ?TranslatableInterface
    {
        $hasData = $this->envelopes->hasAny($user->id);
        if ($hasData && !$acceptDataLoss) {
            return t('Sans clé de récupération, confirmez que vous acceptez la perte définitive de vos données.');
        }
        if ($user->isTotpAuthenticationEnabled() && !$this->isValidCode($user, $code)) {
            return $this->failed($request, $user, t('Code incorrect.'));
        }
        $this->connection->transactional(function () use ($token, $user, $hash): void {
            $this->finish($token, $user->id, $hash);
            $this->accounts->deleteAll($user->id);
            $this->envelopes->deleteAll($user->id);
        });
        $this->conclude(
            $request,
            $user,
            $hasData ? AuthJournal::DATA_ERASED : AuthJournal::PASSWORD_RESET,
            $hasData ? t('Le mot de passe de votre compte a été réinitialisé sans clé de récupération : vos données ont été effacées.') : t('Le mot de passe de votre compte a été réinitialisé.'),
        );

        return null;
    }

    private function finish(#[\SensitiveParameter] string $token, UserId $user, #[\SensitiveParameter] string $hash): void
    {
        if ($this->tokens->consume($token, OneTimeTokens::PASSWORD_RESET) === null) {
            throw new \RuntimeException('Jeton de réinitialisation déjà utilisé.');
        }
        $this->users->updatePasswordHash($user, $hash);
        $this->users->renewSessionStamp($user);
        $this->sessions->revokeOthers(null, $user);
    }

    private function conclude(Request $request, User $user, string $event, TranslatableInterface $notice): void
    {
        $this->journal->record($user->id, $event, $request);
        $this->throttle->forgetAccount((string) $user->email, $user->id);
        if ($user->email !== null) {
            $this->mailer->send($user->email, t('Patrimeter : mot de passe réinitialisé'), 'security_notice', ['event' => $notice, 'access_lost' => true]);
        }
    }

    private function failed(Request $request, User $user, TranslatableInterface $message): TranslatableInterface
    {
        $ip = PasswordLoginAuthenticator::clientIp($request);
        $this->throttle->recordCodeFailure($ip, $user->id);
        $this->journal->record($user->id, AuthJournal::CODE_FAILED, $request, false);
        $wait = $this->throttle->codeRetryAfter($ip, $user->id);

        return Sentences::of($message, $wait > 0 ? RetryDelay::sentence($wait) : null);
    }

    private function isValidCode(User $user, #[\SensitiveParameter] string $code): bool
    {
        if (!$user->isTotpAuthenticationEnabled() || trim($code) === '') {
            return false;
        }
        if ($this->backupCodes->isBackupCode($user, $code)) {
            return true;
        }
        // Same events as the sign-in: a code already accepted is refused, and this one will not serve again.
        $event = new TwoFactorCodeEvent($user, $code);
        try {
            $this->events->dispatch($event, TwoFactorAuthenticationEvents::CHECK);
        } catch (ReusedTwoFactorCodeException) {
            return false;
        }
        if (!$this->totp->checkCode($user, $code)) {
            return false;
        }
        $this->events->dispatch($event, TwoFactorAuthenticationEvents::CODE_VALID);

        return true;
    }
}
