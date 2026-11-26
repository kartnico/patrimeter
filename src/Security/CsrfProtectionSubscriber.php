<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Http\Flash;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function Symfony\Component\Translation\t;

/**
 * Central check of the CSRF token of every POST, before any controller.
 *
 * Missing or invalid token: nothing runs. If the same address exists in GET (form page), back to that
 * page with a message; otherwise a 400 error.
 * Signing in and out are checked by the Security component itself.
 */
final class CsrfProtectionSubscriber implements EventSubscriberInterface
{
    /** Right after the firewall (priority 8): an anonymous visitor is first sent to the sign-in page. */
    private const int PRIORITY_AFTER_FIREWALL = 7;
    private const array ROUTES_CHECKED_BY_SECURITY = ['app_login', 'app_logout', 'app_2fa_login_check', 'app_passkey_login'];

    public function __construct(
        private readonly CsrfTokenManagerInterface $tokens,
        private readonly RouterInterface $router,
    ) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY_AFTER_FIREWALL]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // Stateless routes (API, MCP, OAuth tokens): no cookie, hence nothing to forge from another site.
        if (!$event->isMainRequest() || !$request->isMethod('POST') || $request->attributes->getBoolean('_stateless')
            || in_array($request->attributes->get('_route'), self::ROUTES_CHECKED_BY_SECURITY, true)) {
            return;
        }

        $token = new CsrfToken(PasswordLoginAuthenticator::CSRF_TOKEN_ID, $request->request->getString('_csrf'));
        if ($this->tokens->isTokenValid($token)) {
            return;
        }

        if (!$this->existsAsPage($request->getPathInfo())) {
            throw new BadRequestHttpException('Jeton CSRF absent ou invalide.');
        }
        Flash::add($request, 'error', t('Le formulaire a expiré : rien n\'a été enregistré. Veuillez réessayer.'));
        $event->setResponse(new RedirectResponse($request->getPathInfo(), Response::HTTP_SEE_OTHER));
    }

    /** True if the address also matches a page that can be viewed with GET. */
    private function existsAsPage(string $path): bool
    {
        $context = clone $this->router->getContext();
        $context->setMethod(Request::METHOD_GET);
        try {
            (new UrlMatcher($this->router->getRouteCollection(), $context))->match($path);

            return true;
        } catch (RoutingException) {
            return false;
        }
    }
}
