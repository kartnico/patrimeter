<?php

declare(strict_types=1);

namespace Patrimeter\Security\TwoFactor;

use Patrimeter\Http\InternalPath;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Answers to the second factor form, as 303 like any redirection after a POST: to the page asked for
 * before the sign-in (internal path only) on success, to the form with the reason for the failure
 * otherwise.
 */
final class TwoFactorRedirects implements AuthenticationSuccessHandlerInterface, AuthenticationFailureHandlerInterface
{
    use TargetPathTrait;

    private const string FIREWALL = 'main';

    public function __construct(private readonly UrlGeneratorInterface $urls) {}

    #[\Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $session = $request->getSession();
        $session->remove(SecurityRequestAttributes::AUTHENTICATION_ERROR);
        $target = $this->getTargetPath($session, self::FIREWALL);
        $this->removeTargetPath($session, self::FIREWALL);

        return new RedirectResponse(InternalPath::fromUrl($target), Response::HTTP_SEE_OTHER);
    }

    #[\Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->urls->generate('app_2fa_login'), Response::HTTP_SEE_OTHER);
    }
}
