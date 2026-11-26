<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Application\Calendar;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\AuthEventRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Log of sign-ins and security actions, which the user can read (My account page) and kept for
 * 180 days. Never a secret: type, success, IP, browser, date.
 *
 * @phpstan-type JournalEntry array{type: string, success: bool, ip: ?string, user_agent: ?string, created_at: string, label: string}
 */
final class AuthJournal
{
    public const string LOGIN = 'login';
    public const string LOGIN_FAILED = 'login_failed';
    public const string CODE_FAILED = 'code_failed';
    public const string BACKUP_CODE_USED = 'backup_code_used';
    public const string LOGOUT = 'logout';
    public const string EMAIL_VERIFIED = 'email_verified';
    public const string TOTP_ENABLED = 'totp_enabled';
    public const string BACKUP_CODES_REGENERATED = 'backup_codes_regenerated';
    public const string PASSWORD_CHANGED = 'password_changed';
    public const string SESSIONS_REVOKED = 'sessions_revoked';
    public const string SESSION_REVOKED = 'session_revoked';
    public const string REAUTHENTICATED = 'reauthenticated';
    public const string REAUTH_FAILED = 'reauth_failed';
    public const string VAULT_UNLOCKED = 'vault_unlocked';
    public const string RECOVERY_KEY_CREATED = 'recovery_key_created';
    public const string PASSWORD_RESET = 'password_reset';
    public const string DATA_ERASED = 'data_erased';
    public const string PASSKEY_ADDED = 'passkey_added';
    public const string PASSKEY_REMOVED = 'passkey_removed';
    public const string API_GRANTED = 'api_granted';
    public const string API_REVOKED = 'api_revoked';
    public const string LOGIN_TRUSTED_BROWSER = 'login_trusted_browser';
    public const string TRUSTED_BROWSER_ADDED = 'trusted_browser_added';
    public const string TRUSTED_BROWSER_REMOVED = 'trusted_browser_removed';

    private const array LABELS = [
        self::LOGIN => 'Connexion',
        self::LOGIN_FAILED => 'Mot de passe incorrect',
        self::CODE_FAILED => 'Code de vérification incorrect',
        self::BACKUP_CODE_USED => 'Connexion avec un code de secours',
        self::LOGOUT => 'Déconnexion',
        self::EMAIL_VERIFIED => 'Adresse e-mail confirmée',
        self::TOTP_ENABLED => 'Application d\'authentification configurée',
        self::BACKUP_CODES_REGENERATED => 'Nouveaux codes de secours',
        self::PASSWORD_CHANGED => 'Mot de passe modifié',
        self::SESSIONS_REVOKED => 'Autres sessions déconnectées',
        self::SESSION_REVOKED => 'Une session déconnectée',
        self::REAUTHENTICATED => 'Identité confirmée',
        self::REAUTH_FAILED => 'Confirmation d\'identité refusée',
        self::VAULT_UNLOCKED => 'Données déverrouillées',
        self::RECOVERY_KEY_CREATED => 'Nouvelle clé de récupération',
        self::PASSWORD_RESET => 'Mot de passe réinitialisé',
        self::DATA_ERASED => 'Données effacées (mot de passe oublié)',
        self::PASSKEY_ADDED => 'Appareil de connexion ajouté',
        self::PASSKEY_REMOVED => 'Appareil de connexion retiré',
        self::API_GRANTED => 'Accès à l\'API accordé',
        self::API_REVOKED => 'Accès à l\'API retiré',
        self::LOGIN_TRUSTED_BROWSER => 'Connexion sans code (navigateur de confiance)',
        self::TRUSTED_BROWSER_ADDED => 'Navigateur ajouté aux navigateurs de confiance',
        self::TRUSTED_BROWSER_REMOVED => 'Navigateur de confiance retiré',
    ];

    private const int RETENTION_SECONDS = 180 * 86400;
    public const int USER_AGENT_MAX = 255;
    private const int SHOWN_EVENTS = 30;

    public function __construct(
        private readonly AuthEventRepository $events,
        private readonly Calendar $calendar,
    ) {}

    public function record(UserId $user, string $type, ?Request $request, bool $success = true): void
    {
        $userAgent = $request?->headers->get('User-Agent');
        $this->events->add(
            $user,
            $type,
            $success,
            $request?->getClientIp(),
            $userAgent === null ? null : mb_substr($userAgent, 0, self::USER_AGENT_MAX),
            $this->calendar->timestamp(),
        );
    }

    /** Forgets the events older than 180 days (scheduled task). */
    public function purgeOld(): void
    {
        $this->events->purge($this->calendar->timestamp(-self::RETENTION_SECONDS));
    }

    /** @return list<JournalEntry> recent events, with their label */
    public function recent(UserId $user): array
    {
        return array_map(
            static fn(array $event): array => $event + ['label' => self::LABELS[$event['type']] ?? $event['type']],
            $this->events->recent($user, self::SHOWN_EVENTS),
        );
    }
}
