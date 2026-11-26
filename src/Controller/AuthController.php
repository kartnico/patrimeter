<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\FirstAccount;
use Patrimeter\Application\Registration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sign-in page. The POST is handled by PasswordLoginAuthenticator, the second factor by scheb/2fa-bundle
 * (security/two_factor.html.twig template), signing out by the firewall.
 */
final class AuthController extends AbstractController
{
    #[Route('/connexion', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Registration $registration, FirstAccount $firstAccount): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard', status: Response::HTTP_SEE_OTHER);
        }
        // A new instance: no account to sign in to yet, the installation wizard creates the first one.
        if ($firstAccount->isNeeded()) {
            return $this->redirectToRoute('app_setup');
        }

        return $this->render('security/login.html.twig', [
            'error' => null,
            'last_email' => '',
            'registration_open' => $registration->isOpen(),
        ]);
    }

    #[Route('/deconnexion', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepté par le pare-feu (security.yaml, logout).');
    }
}
