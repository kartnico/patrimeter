<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Reminder\EntryReminders;
use Patrimeter\Domain\PasswordPolicy;
use Patrimeter\Http\ApiAvailability;
use Patrimeter\Http\InternalPath;
use Patrimeter\Http\Sentences;
use Patrimeter\Infrastructure\Mail\AccountMailer;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Infrastructure\Persistence\WebAuthnCredentialRepository;
use Patrimeter\Security\Api\ApiResource;
use Patrimeter\Security\Api\ApiTokens;
use Patrimeter\Security\AuthJournal;
use Patrimeter\Security\CurrentUser;
use Patrimeter\Security\PasswordRules;
use Patrimeter\Security\Reauthentication;
use Patrimeter\Security\RetryDelay;
use Patrimeter\Security\TwoFactor\BackupCodes;
use Patrimeter\Security\TwoFactor\TrustedBrowsers;
use Patrimeter\Security\User;
use Patrimeter\Security\UserProvider;
use Patrimeter\Security\UserSessions;
use Patrimeter\Security\Vault\DataKeys;
use Patrimeter\Security\Vault\Vault;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * My account page, in tabs: profile, sign-in and security (authentication methods, sessions, sign-in log), AI agents;
 * password change, backup codes, sessions signed out one by one or all together. Sensitive actions require an identity confirmation less than 5 minutes old
 * (Reauthentication); changing the password requires the current one.
 */
final class SecurityController extends AbstractController
{
    public const string TAB_PROFILE = 'profile';
    public const string TAB_SIGN_IN = 'sign_in';
    public const string TAB_AGENTS = 'agents';
    /** Languages of the emails, by their code, with their name in that language. */
    public const array EMAIL_LOCALES = ['fr', 'en'];

    public function __construct(
        private readonly PasswordRules $passwordRules,
        private readonly CurrentUser $currentUser,
        private readonly Reauthentication $reauthentication,
        private readonly AuthJournal $journal,
        private readonly AccountMailer $mailer,
        private readonly ApiAvailability $apiAvailability,
        private readonly UserSessions $sessions,
    ) {}

    #[Route('/securite', name: 'app_security', methods: ['GET'])]
    public function show(Request $request, BackupCodes $backupCodes, WebAuthnCredentialRepository $credentials, ApiTokens $apiTokens, EntryReminders $reminders, UserRepository $users): Response
    {
        $user = $this->currentUser->user();

        return $this->tab($request, self::TAB_PROFILE, [
            'email_locale' => $users->emailLocale($user->id),
            'reminder_day' => $reminders->day($user->id),
            'reminder_default_day' => EntryReminders::DEFAULT_DAY,
            'reminder_hour' => EntryReminders::SEND_HOUR,
            'reminder_sender' => $reminders->sender(),
            'backup_codes_left' => $backupCodes->remaining($user->id),
            'credentials' => $credentials->all($user->id),
            'api_grants' => $apiTokens->grantsOf($user->id),
            'min_length' => PasswordPolicy::MIN_LENGTH,
        ]);
    }

    /** Language of the emails: French, English, or that of the interface when sending (empty value). */
    #[Route('/securite/langue-des-e-mails', name: 'app_security_email_locale', methods: ['POST'])]
    public function emailLocale(Request $request, UserRepository $users): Response
    {
        $locale = $request->request->getString('email_locale');
        $users->saveEmailLocale($this->currentUser->id(), in_array($locale, self::EMAIL_LOCALES, true) ? $locale : null);
        $this->addFlash('success', t('Langue des e-mails enregistrée.'));

        return $this->redirectToRoute('app_security', ['_fragment' => 'email-locale-title'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/securite/connexion', name: 'app_security_signin', methods: ['GET'])]
    public function signIn(Request $request, BackupCodes $backupCodes, WebAuthnCredentialRepository $credentials, TrustedBrowsers $browsers): Response
    {
        $user = $this->currentUser->user();

        return $this->tab($request, self::TAB_SIGN_IN, [
            'backup_codes_left' => $backupCodes->remaining($user->id),
            'credentials' => $credentials->all($user->id),
            'events' => $this->journal->recent($user->id),
            'sessions' => $this->sessions->list($request, $user->id),
            'session_idle_minutes' => intdiv($this->sessions->idleTimeout, 60),
            'session_max_hours' => intdiv($this->sessions->maxLifetime, 3600),
            'trusted_browsers' => $browsers->list($request, $user->id),
            'trusted_days' => TrustedBrowsers::DAYS,
        ]);
    }

    /** AI agents: tokens and applications of the API and the MCP service (404 when both are disabled, ApiAvailability). */
    #[Route('/securite/agents-ia', name: 'app_security_agents', methods: ['GET'])]
    public function agents(Request $request, ApiTokens $apiTokens, ApiResource $resource): Response
    {
        return $this->tab($request, self::TAB_AGENTS, [
            'api_grants' => $apiTokens->grantsOf($this->currentUser->user()->id),
            'api_token_days' => ApiTokens::PERSONAL_DAYS,
            'mcp_url' => $resource->mcp(),
            'api_url' => $resource->api(),
        ]);
    }

    #[Route('/securite/journal', name: 'app_security_journal', methods: ['GET'])]
    public function journal(): Response
    {
        // Former tab (until 2.11.0): the sign-in log is now at the end of the sign-in and security tab.
        return $this->redirectToRoute('app_security_signin', ['_fragment' => 'journal'], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/securite/confirmer', name: 'app_security_reauthenticate', methods: ['POST'])]
    public function reauthenticate(Request $request): Response
    {
        $result = $this->reauthentication->confirm($request, $this->currentUser->user(), $request->request->getString('password'));
        if (!$result['confirmed']) {
            $this->addFlash('error', Sentences::of($result['checked'] ? t('Mot de passe incorrect.') : t('Trop de tentatives.'), self::delaySentence($result['retryAfter'])));
        }

        return $this->redirect(InternalPath::sanitize($request->request->getString('_return', '/securite/connexion')), Response::HTTP_SEE_OTHER);
    }

    #[Route('/securite/mot-de-passe', name: 'app_security_password', methods: ['POST'])]
    public function changePassword(
        Request $request,
        PasswordHasherFactoryInterface $hashers,
        UserRepository $users,
        UserProvider $provider,
        TokenStorageInterface $tokens,
        DataKeys $dataKeys,
        Vault $vault,
        Connection $connection,
    ): Response {
        $user = $this->currentUser->user();
        // Current password checked with the same rate limit as signing in.
        $confirmation = $this->reauthentication->confirm($request, $user, $request->request->getString('current_password'));
        if (!$confirmation['confirmed']) {
            $this->addFlash('error', Sentences::of($confirmation['checked'] ? t('Mot de passe actuel incorrect.') : t('Trop de tentatives.'), self::delaySentence($confirmation['retryAfter'])));

            return $this->redirectToRoute('app_security', status: Response::HTTP_SEE_OTHER);
        }

        $new = $request->request->getString('new_password');
        $error = $this->passwordRules->violation($new, $user->email);
        if ($error === null && !hash_equals($new, $request->request->getString('new_password_confirmation'))) {
            $error = t('Les deux nouveaux mots de passe ne correspondent pas.');
        }
        if ($error !== null) {
            $this->addFlash('error', $error);

            return $this->redirectToRoute('app_security', status: Response::HTTP_SEE_OTHER);
        }

        $hash = $hashers->getPasswordHasher(User::class)->hash($new);
        $key = $vault->key($user->id);
        $connection->transactional(static function () use ($users, $dataKeys, $user, $hash, $key, $new): void {
            $users->updatePasswordHash($user->id, $hash);
            // Only the envelope changes: the data does not have to be re-encrypted.
            $dataKeys->savePasswordEnvelope($user->id, $key, $new);
        });
        // The current session stays open; all the others end.
        $tokens->getToken()?->setUser($provider->loadUserByIdentifier($user->getUserIdentifier()));
        $this->sessions->revokeOthers($request, $user->id);
        $this->journal->record($user->id, AuthJournal::PASSWORD_CHANGED, $request);
        $this->notify($user, t('Patrimeter : mot de passe modifié'), t('Le mot de passe de votre compte a été modifié.'), accessLost: true);
        $this->addFlash('success', t('Mot de passe modifié. Vos autres sessions ont été déconnectées.'));

        return $this->redirectToRoute('app_security', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/securite/codes-de-secours', name: 'app_security_backup_codes', methods: ['POST'])]
    public function regenerateBackupCodes(Request $request, BackupCodes $backupCodes): Response
    {
        $user = $this->currentUser->user();
        if (!$this->reauthentication->isFresh($request)) {
            return $this->identityRequired();
        }
        $codes = $backupCodes->regenerate($user->id);
        $this->journal->record($user->id, AuthJournal::BACKUP_CODES_REGENERATED, $request);
        $this->notify($user, t('Patrimeter : nouveaux codes de secours'), t('De nouveaux codes de secours ont été générés ; les anciens ne fonctionnent plus.'));

        // Codes shown only once, directly (never kept in the session).
        return $this->render('security/backup_codes.html.twig', ['codes' => $codes, 'first_setup' => false]);
    }

    #[Route('/securite/sessions', name: 'app_security_sessions', methods: ['POST'])]
    public function revokeOtherSessions(Request $request, UserRepository $users, UserProvider $provider, TokenStorageInterface $tokens): Response
    {
        $user = $this->currentUser->user();
        if (!$this->reauthentication->isFresh($request)) {
            return $this->identityRequired();
        }
        $users->renewSessionStamp($user->id);
        $tokens->getToken()?->setUser($provider->loadUserByIdentifier($user->getUserIdentifier()));
        $this->sessions->revokeOthers($request, $user->id);
        $this->journal->record($user->id, AuthJournal::SESSIONS_REVOKED, $request);
        $this->addFlash('success', t('Toutes vos autres sessions ont été déconnectées.'));

        return $this->redirectToRoute('app_security_signin', ['_fragment' => 'sessions'], Response::HTTP_SEE_OTHER);
    }

    /** Signs out one other session of the user; its next request goes back to the sign-in page. */
    #[Route('/securite/sessions/{session}/deconnecter', name: 'app_security_session_revoke', requirements: ['session' => '[0-9a-f]{32}'], methods: ['POST'])]
    public function revokeSession(Request $request, string $session): Response
    {
        $user = $this->currentUser->user();
        if (!$this->reauthentication->isFresh($request)) {
            return $this->identityRequired();
        }
        if ($this->sessions->revoke($request, $user->id, $session)) {
            $this->journal->record($user->id, AuthJournal::SESSION_REVOKED, $request);
            $this->addFlash('success', t('La session a été déconnectée.'));
        } else {
            $this->addFlash('error', t('Cette session n\'existe plus, ou c\'est la vôtre : utilisez « Déconnexion ».'));
        }

        return $this->redirectToRoute('app_security_signin', ['_fragment' => 'sessions'], Response::HTTP_SEE_OTHER);
    }

    /** Removes one trusted browser of the user: the next sign-in there asks for the code again. */
    #[Route('/securite/navigateurs/{browser}/retirer', name: 'app_security_trusted_browser_revoke', requirements: ['browser' => '[0-9a-f]{32}'], methods: ['POST'])]
    public function revokeTrustedBrowser(Request $request, TrustedBrowsers $browsers, string $browser): Response
    {
        $user = $this->currentUser->user();
        if (!$this->reauthentication->isFresh($request)) {
            return $this->identityRequired();
        }
        if ($browsers->revoke($user->id, $browser)) {
            $this->journal->record($user->id, AuthJournal::TRUSTED_BROWSER_REMOVED, $request);
            $this->addFlash('success', t('Ce navigateur n\'est plus de confiance : le code sera demandé à la prochaine connexion.'));
        } else {
            $this->addFlash('error', t('Ce navigateur n\'est déjà plus de confiance.'));
        }

        return $this->redirectToRoute('app_security_signin', ['_fragment' => 'trusted-browsers'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/securite/navigateurs/retirer', name: 'app_security_trusted_browsers_revoke', methods: ['POST'])]
    public function revokeTrustedBrowsers(Request $request, TrustedBrowsers $browsers): Response
    {
        $user = $this->currentUser->user();
        if (!$this->reauthentication->isFresh($request)) {
            return $this->identityRequired();
        }
        if ($browsers->revokeAll($user->id) > 0) {
            $this->journal->record($user->id, AuthJournal::TRUSTED_BROWSER_REMOVED, $request);
        }
        $this->addFlash('success', t('Plus aucun navigateur de confiance : le code sera demandé à chaque connexion.'));

        return $this->redirectToRoute('app_security_signin', ['_fragment' => 'trusted-browsers'], Response::HTTP_SEE_OTHER);
    }

    /**
     * Tab of the My account page: user, current tab and availability of the API and the MCP (AI agents tab).
     *
     * @param array<string, mixed> $parameters
     */
    private function tab(Request $request, string $tab, array $parameters): Response
    {
        return $this->render('security/index.html.twig', [
            'user' => $this->currentUser->user(),
            'tab' => $tab,
            'reauthenticated' => $this->reauthentication->isFresh($request),
            'api_available' => $this->apiAvailability,
        ] + $parameters);
    }

    private static function delaySentence(int $retryAfter): ?TranslatableInterface
    {
        return $retryAfter > 0 ? RetryDelay::sentence($retryAfter) : null;
    }

    private function identityRequired(): Response
    {
        $this->addFlash('error', t('Pour cette action, confirmez d\'abord votre identité (mot de passe).'));

        return $this->redirectToRoute('app_security_signin', status: Response::HTTP_SEE_OTHER);
    }

    /** $accessLost: the action may have shut the user out, the email does not advise to change the password. */
    private function notify(User $user, TranslatableInterface $subject, TranslatableInterface $event, bool $accessLost = false): void
    {
        if ($user->email !== null) {
            $this->mailer->send($user->email, $subject, 'security_notice', ['event' => $event, 'access_lost' => $accessLost]);
        }
    }
}
