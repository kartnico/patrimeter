<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Application\Registration;
use Patrimeter\Domain\EmailAddress;
use Patrimeter\Domain\PasswordPolicy;
use Patrimeter\Http\Flash;
use Patrimeter\Http\InternalPath;
use Patrimeter\Http\Sentences;
use Patrimeter\Security\Throttle\AuthThrottle;
use Patrimeter\Security\Vault\DataKeys;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Contracts\Translation\TranslatableInterface;
use Twig\Environment;

use function Symfony\Component\Translation\t;

/**
 * Sign-in with email address and password (POST /connexion), first factor. The second one (TOTP or
 * backup code) is then asked by scheb/2fa-bundle.
 *
 * Order of the checks: CSRF token, attempt limiting (nothing is checked during a delay), then the
 * password. Unknown address or wrong password: same message, same cost (an Argon2id hash is computed
 * in both cases); only these failures are counted.
 */
final class PasswordLoginAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    use TargetPathTrait;

    public const string CSRF_TOKEN_ID = 'patrimeter';
    public const string LOGIN_ROUTE = 'app_login';
    private const string LAST_EMAIL = '_patrimeter_last_email';

    public function __construct(
        private readonly AuthThrottle $throttle,
        private readonly UserProvider $users,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        #[Autowire(service: 'security.csrf.token_storage')]
        private readonly ClearableTokenStorageInterface $csrfTokens,
        private readonly UrlGeneratorInterface $urls,
        private readonly Environment $twig,
        private readonly Registration $registration,
        private readonly DataKeys $dataKeys,
    ) {}

    #[\Override]
    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && $request->attributes->get('_route') === self::LOGIN_ROUTE;
    }

    #[\Override]
    public function authenticate(Request $request): Passport
    {
        $csrfToken = new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_csrf'));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            throw new InvalidCsrfTokenException();
        }
        $typedEmail = mb_substr(trim($request->request->getString('email')), 0, EmailAddress::MAX_LENGTH);
        $request->getSession()->set(self::LAST_EMAIL, $typedEmail);
        $wait = $this->throttle->passwordRetryAfter(self::clientIp($request), $typedEmail);
        if ($wait > 0) {
            throw new LoginThrottledException($wait);
        }

        $password = $request->request->getString('password');
        if (strlen($password) > PasswordPolicy::MAX_BYTES) {
            $password = '';
        }
        $email = EmailAddress::normalize($typedEmail);

        return new Passport(
            new UserBadge($email ?? '-', fn(): User => $this->loadUser($email, $password)),
            new PasswordCredentials($password),
            [new PasswordUpgradeBadge($password, $this->users)],
        );
    }

    #[\Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $user = $token->getUser();
        $this->throttle->recordPasswordSuccess(self::clientIp($request), $user instanceof User ? (string) $user->email : '');
        // The password, available here only, opens the session's vault; failing that (unreadable
        // envelope), the unlock screen will ask for it again. A demonstration account, signed in without a password
        // (DemoController), already has its vault open.
        if ($user instanceof User && !$user->isDemo()) {
            $this->dataKeys->openWithPassword($request, $user->id, $request->request->getString('password'));
        }
        $request->getSession()->remove(self::LAST_EMAIL);
        // New CSRF token at sign-in (the session identifier is already regenerated by Symfony).
        $this->csrfTokens->clear();

        // The page asked for at first (for instance an OAuth consent), not the home page: otherwise the second
        // factor would remember the home page instead.
        return new RedirectResponse(InternalPath::fromUrl($this->getTargetPath($request->getSession(), $firewallName)), Response::HTTP_SEE_OTHER);
    }

    #[\Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof InvalidCsrfTokenException) {
            Flash::add($request, 'error', t('Le formulaire a expiré : rien n\'a été enregistré. Veuillez réessayer.'));

            return new RedirectResponse($this->urls->generate(self::LOGIN_ROUTE), Response::HTTP_SEE_OTHER);
        }
        if ($exception instanceof LoginThrottledException) {
            return $this->loginPage($request, Sentences::of(t('Trop de tentatives.'), RetryDelay::sentence($exception->retryAfter)), Response::HTTP_TOO_MANY_REQUESTS, $exception->retryAfter);
        }
        if ($exception instanceof EmailNotVerifiedException) {
            $user = $exception->getUser();
            if ($user instanceof User) {
                $this->registration->resendVerification($user->id);
            }

            return $this->loginPage($request, t('Confirmez d\'abord votre adresse e-mail : le lien de confirmation vient de vous être renvoyé (pensez à vérifier les indésirables).'), Response::HTTP_FORBIDDEN);
        }

        $ip = self::clientIp($request);
        $email = $request->request->getString('email');
        $this->throttle->recordPasswordFailure($ip, $email);
        $wait = $this->throttle->passwordRetryAfter($ip, $email);

        return $this->loginPage($request, Sentences::of(t('Adresse e-mail ou mot de passe incorrect.'), $wait > 0 ? RetryDelay::sentence($wait) : null), Response::HTTP_UNAUTHORIZED, $wait);
    }

    #[\Override]
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urls->generate(self::LOGIN_ROUTE), Response::HTTP_SEE_OTHER);
    }

    /**
     * User who can sign in with a password. Otherwise (unknown address or no password), a hash is
     * computed all the same: the answer comes after the same delay.
     */
    private function loadUser(?string $email, #[\SensitiveParameter] string $password): User
    {
        $user = $email === null ? null : $this->users->findByEmail($email);
        if ($user === null || $user->getPassword() === null) {
            // As for a real account: an empty password is refused without computation.
            if ($password !== '') {
                $this->hashers->getPasswordHasher(User::class)->hash($password);
            }

            throw new UserNotFoundException();
        }

        return $user;
    }

    private function loginPage(Request $request, string|TranslatableInterface $error, int $status, int $retryAfter = 0): Response
    {
        $response = new Response($this->twig->render('security/login.html.twig', [
            'error' => $error,
            'last_email' => self::text($request->getSession()->get(self::LAST_EMAIL)),
            'registration_open' => $this->registration->isOpen(),
        ]), $status);
        if ($retryAfter > 0) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    public static function clientIp(Request $request): string
    {
        return $request->getClientIp() ?? 'inconnue';
    }
}
