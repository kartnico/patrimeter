<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Http\Flash;
use Patrimeter\Security\Throttle\AuthThrottle;
use Patrimeter\Security\TwoFactor\BackupCodes;
use Patrimeter\Security\TwoFactor\TrustedBrowsers;
use Psr\Clock\ClockInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Exception\InvalidTwoFactorCodeException;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\Http\Authenticator\Passport\Credentials\TwoFactorCodeCredentials;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\BackupCodeEvents;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorCodeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

use function Symfony\Component\Translation\t;

/**
 * Tracking of the sign-in steps: limiting single-use codes, log, start of the session (for its
 * maximum duration) and reminder of the number of backup codes left.
 */
final class AuthenticationSubscriber implements EventSubscriberInterface
{
    private const string SESSION_FIREWALL = 'main';
    public const string LOGIN_AT_KEY = '_patrimeter_login_at';

    /** Before scheb's code checks (backup codes: 16, TOTP: 0). */
    private const int PRIORITY_BEFORE_CODE_CHECKS = 32;
    private const int LOW_BACKUP_CODES = 3;

    public function __construct(
        private readonly AuthThrottle $throttle,
        private readonly AuthJournal $journal,
        private readonly BackupCodes $backupCodes,
        private readonly RequestStack $requests,
        private readonly ClockInterface $clock,
    ) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            CheckPassportEvent::class => ['throttleCodes', self::PRIORITY_BEFORE_CODE_CHECKS],
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => ['onLogout', 64],
            BackupCodeEvents::VALID => 'onBackupCodeUsed',
        ];
    }

    /** Single-use code: refused without checking during a delay or a lockout. */
    public function throttleCodes(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();
        $request = $this->requests->getMainRequest();
        if (!$passport->hasBadge(TwoFactorCodeCredentials::class) || $request === null) {
            return;
        }
        $user = $passport->getUser();
        if (!$user instanceof User) {
            return;
        }
        $wait = $this->throttle->codeRetryAfter(PasswordLoginAuthenticator::clientIp($request), $user->id);
        if ($wait > 0) {
            throw new LoginThrottledException($wait);
        }
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        // API firewall: a token on each request, without a session nor a sign-in to log.
        if ($event->getFirewallName() !== self::SESSION_FIREWALL) {
            return;
        }
        $user = $event->getUser();
        $token = $event->getAuthenticatedToken();
        $request = $event->getRequest();
        if (!$user instanceof User) {
            return;
        }
        $request->getSession()->set(self::LOGIN_AT_KEY, $this->clock->now()->getTimestamp());
        if ($token instanceof TwoFactorTokenInterface) {
            return; // First factor only: the sign-in will be logged after the code.
        }
        if ($event->getPassport()->hasBadge(TwoFactorCodeCredentials::class)) {
            $this->throttle->recordCodeSuccess(PasswordLoginAuthenticator::clientIp($request), $user->id);
            $remaining = $this->backupCodes->remaining($user->id);
            if ($remaining <= self::LOW_BACKUP_CODES) {
                Flash::add($request, 'info', t('Il vous reste %count% code de secours : pensez à en générer de nouveaux (page Mon compte).|Il vous reste %count% codes de secours : pensez à en générer de nouveaux (page Mon compte).', ['%count%' => $remaining]));
            }
        }
        $this->journal->record($user->id, $request->attributes->getBoolean(TrustedBrowsers::USED) ? AuthJournal::LOGIN_TRUSTED_BROWSER : AuthJournal::LOGIN, $request);
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ($event->getFirewallName() !== self::SESSION_FIREWALL) {
            return;
        }
        $exception = $event->getException();
        $request = $event->getRequest();
        $passport = $event->getPassport();
        if ($passport === null || $exception instanceof LoginThrottledException || $exception->getPrevious() instanceof UserNotFoundException) {
            return;
        }
        if ($exception instanceof InvalidTwoFactorCodeException) {
            $user = $passport->getUser();
            if ($user instanceof User) {
                $this->throttle->recordCodeFailure(PasswordLoginAuthenticator::clientIp($request), $user->id);
                $this->journal->record($user->id, AuthJournal::CODE_FAILED, $request, false);
            }

            return;
        }
        if ($event->getAuthenticator() instanceof PasswordLoginAuthenticator && !$exception instanceof EmailNotVerifiedException) {
            try {
                $user = $passport->getUser();
            } catch (UserNotFoundException) {
                return;
            }
            if ($user instanceof User) {
                $this->journal->record($user->id, AuthJournal::LOGIN_FAILED, $request, false);
            }
        }
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user instanceof User && !$event->getToken() instanceof TwoFactorTokenInterface) {
            $this->journal->record($user->id, AuthJournal::LOGOUT, $event->getRequest());
        }
    }

    public function onBackupCodeUsed(TwoFactorCodeEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User) {
            $this->journal->record($user->id, AuthJournal::BACKUP_CODE_USED, $this->requests->getMainRequest());
        }
    }
}
