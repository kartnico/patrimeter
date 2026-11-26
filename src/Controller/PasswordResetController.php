<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\PasswordReset;
use Patrimeter\Domain\PasswordPolicy;
use Patrimeter\Http\Sentences;
use Patrimeter\Security\PasswordLoginAuthenticator;
use Patrimeter\Security\RetryDelay;
use Patrimeter\Security\Throttle\AuthThrottle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/** Forgotten password: request for the link, then new password (see PasswordReset). */
final class PasswordResetController extends AbstractController
{
    private const string TOKEN = '[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{43}';

    public function __construct(private readonly PasswordReset $reset) {}

    #[Route('/mot-de-passe-oublie', name: 'app_password_forgot', methods: ['GET'])]
    public function form(): Response
    {
        return $this->render('password_reset/request.html.twig', ['error' => null]);
    }

    #[Route('/mot-de-passe-oublie', name: 'app_password_forgot_submit', methods: ['POST'])]
    public function submit(Request $request, AuthThrottle $throttle): Response
    {
        $ip = PasswordLoginAuthenticator::clientIp($request);
        $wait = $throttle->passwordResetRetryAfter($ip);
        if ($wait > 0) {
            $response = $this->render('password_reset/request.html.twig', ['error' => Sentences::of(t('Trop de demandes.'), RetryDelay::sentence($wait))], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            $response->headers->set('Retry-After', (string) $wait);

            return $response;
        }
        $throttle->recordPasswordResetRequest($ip);
        $this->reset->request($request->request->getString('email'));

        return $this->redirectToRoute('app_password_forgot_sent', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/mot-de-passe-oublie/envoye', name: 'app_password_forgot_sent', methods: ['GET'])]
    public function sent(): Response
    {
        return $this->render('password_reset/sent.html.twig');
    }

    #[Route('/mot-de-passe-oublie/{token}', name: 'app_password_reset', requirements: ['token' => self::TOKEN], methods: ['GET'])]
    public function resetForm(#[\SensitiveParameter] string $token): Response
    {
        return $this->renderReset($token, null);
    }

    #[Route('/mot-de-passe-oublie/{token}', name: 'app_password_reset_submit', requirements: ['token' => self::TOKEN], methods: ['POST'])]
    public function resetSubmit(Request $request, #[\SensitiveParameter] string $token): Response
    {
        if (!$this->reset->isValid($token)) {
            return $this->renderReset($token, null);
        }
        $error = $this->reset->complete(
            $request,
            $token,
            $request->request->getString('password'),
            $request->request->getString('password_confirmation'),
            $request->request->getString('recovery_key'),
            $request->request->getString('code'),
            $request->request->getBoolean('accept_data_loss'),
        );
        if ($error !== null) {
            return $this->renderReset($token, $error, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', t('Mot de passe modifié. Vous pouvez vous connecter.'));

        return $this->redirectToRoute('app_login', status: Response::HTTP_SEE_OTHER);
    }

    private function renderReset(#[\SensitiveParameter] string $token, string|TranslatableInterface|null $error, int $status = Response::HTTP_OK): Response
    {
        if (!$this->reset->isValid($token)) {
            return $this->render('password_reset/invalid_link.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
        }

        return $this->render('password_reset/form.html.twig', ['token' => $token, 'error' => $error, 'min_length' => PasswordPolicy::MIN_LENGTH], new Response(status: $status));
    }
}
