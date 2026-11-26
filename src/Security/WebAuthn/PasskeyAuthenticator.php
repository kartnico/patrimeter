<?php

declare(strict_types=1);

namespace Patrimeter\Security\WebAuthn;

use Patrimeter\Http\Flash;
use Patrimeter\Http\InternalPath;
use Patrimeter\Http\Sentences;
use Patrimeter\Security\PasswordLoginAuthenticator;
use Patrimeter\Security\RetryDelay;
use Patrimeter\Security\Throttle\AuthThrottle;
use Patrimeter\Security\User;
use Patrimeter\Security\UserProvider;
use Patrimeter\Security\Vault\DataKeys;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

use function Symfony\Component\Translation\t;

/**
 * Sign-in with a passkey (POST /connexion/passkey). With the PRF extension, the output given by the
 * authenticator also opens the vault; without it, the unlock screen asks for the password.
 */
final class PasskeyAuthenticator extends AbstractAuthenticator
{
    use TargetPathTrait;

    public const string ROUTE = 'app_passkey_login';
    private const string REFUSED = 'Connexion par passkey (FIDO2) refusée.';
    private const string CREDENTIAL_ATTRIBUTE = '_patrimeter_passkey_credential';

    public function __construct(
        private readonly WebAuthn $webAuthn,
        private readonly UserProvider $users,
        private readonly AuthThrottle $throttle,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly UrlGeneratorInterface $urls,
        private readonly DataKeys $dataKeys,
    ) {}

    #[\Override]
    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && $request->attributes->get('_route') === self::ROUTE;
    }

    #[\Override]
    public function authenticate(Request $request): Passport
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(PasswordLoginAuthenticator::CSRF_TOKEN_ID, $request->request->getString('_csrf')))) {
            throw new CustomUserMessageAuthenticationException(self::REFUSED);
        }
        $wait = $this->throttle->ipRetryAfter(PasswordLoginAuthenticator::clientIp($request));
        if ($wait > 0) {
            throw new CustomUserMessageAuthenticationException('Trop de tentatives.', ['wait' => $wait]);
        }
        $result = $this->webAuthn->verify($request, $request->request->getString('credential'), null);
        if ($result === null) {
            $this->throttle->recordIpFailure(PasswordLoginAuthenticator::clientIp($request));

            throw new CustomUserMessageAuthenticationException(self::REFUSED);
        }
        $request->attributes->set(self::CREDENTIAL_ATTRIBUTE, $result['credential_id']);

        return new SelfValidatingPassport(new UserBadge($result['user']->value, $this->users->loadUserByIdentifier(...)), [new PasskeyLoginBadge()]);
    }

    #[\Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $user = $token->getUser();
        $prf = self::decode($request->request->getString('prf_output'));
        if ($user instanceof User && $prf !== null) {
            $this->dataKeys->openWithPasskey($request, $user->id, $request->attributes->getString(self::CREDENTIAL_ATTRIBUTE), $prf);
        }

        $session = $request->getSession();
        $target = $this->getTargetPath($session, $firewallName);
        $this->removeTargetPath($session, $firewallName);

        return new RedirectResponse(InternalPath::fromUrl($target), Response::HTTP_SEE_OTHER);
    }

    #[\Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        /** @var mixed $wait waiting time sent with the refusal, in seconds */
        $wait = $exception->getMessageData()['wait'] ?? 0;
        Flash::add($request, 'error', $exception instanceof CustomUserMessageAuthenticationException
            ? Sentences::of(t($exception->getMessageKey()), is_int($wait) && $wait > 0 ? RetryDelay::sentence($wait) : null)
            : t('Connexion par passkey (FIDO2) refusée.'));

        return new RedirectResponse($this->urls->generate(PasswordLoginAuthenticator::LOGIN_ROUTE), Response::HTTP_SEE_OTHER);
    }

    /** PRF output encoded by the browser (base64url), or null. */
    public static function decode(#[\SensitiveParameter] string $encoded): ?string
    {
        if ($encoded === '') {
            return null;
        }
        try {
            return sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            return null;
        }
    }
}
