<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\FirstAccount;
use Patrimeter\Domain\EmailAddress;
use Patrimeter\Domain\PasswordPolicy;
use Patrimeter\Http\InstallationChecks;
use Patrimeter\Security\PasswordLoginAuthenticator;
use Patrimeter\Security\PasswordRules;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Installation wizard: as long as the instance has no account of its own, the configuration checked from the browser
 * and the first account created, then signed in and taken to setting up its authenticator app. Afterwards, the page
 * no longer exists (404): accounts come from registration or from app:user:create.
 */
final class SetupController extends AbstractController
{
    public function __construct(
        private readonly FirstAccount $firstAccount,
        private readonly PasswordRules $passwordRules,
        private readonly InstallationChecks $checks,
    ) {}

    #[Route('/installation', name: 'app_setup', methods: ['GET'])]
    public function form(Request $request): Response
    {
        $this->denyUnlessNeeded();

        return $this->renderForm($request, '', []);
    }

    #[Route('/installation', name: 'app_setup_submit', methods: ['POST'])]
    public function submit(Request $request, Security $security): Response
    {
        $this->denyUnlessNeeded();
        $typedEmail = mb_substr(trim($request->request->getString('email')), 0, EmailAddress::MAX_LENGTH);
        $email = EmailAddress::normalize($typedEmail);
        $password = $request->request->getString('password');
        $errors = [];
        if ($email === null) {
            $errors['email'] = t('Adresse e-mail invalide.');
        }
        $passwordError = $this->passwordRules->violation($password, $email);
        if ($passwordError !== null) {
            $errors['password'] = $passwordError;
        } elseif (!hash_equals($password, $request->request->getString('password_confirmation'))) {
            $errors['password_confirmation'] = t('Les deux mots de passe ne correspondent pas.');
        }
        if ($email === null || $errors !== []) {
            return $this->renderForm($request, $typedEmail, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Another visitor may have been quicker: only the very first account is created here.
        $user = $this->firstAccount->create($email, $password);
        if ($user === null) {
            throw $this->createNotFoundException();
        }
        $security->login($user, PasswordLoginAuthenticator::class, 'main');
        $this->addFlash('success', t('Compte créé. Étape suivante : protégez-le avec une application d\'authentification.'));

        return $this->redirectToRoute('app_totp_setup', status: Response::HTTP_SEE_OTHER);
    }

    /** Once the instance has an account, the wizard is gone. */
    private function denyUnlessNeeded(): void
    {
        if (!$this->firstAccount->isNeeded()) {
            throw $this->createNotFoundException();
        }
    }

    /** @param array<string, string|TranslatableInterface> $errors messages by field, translated on display */
    private function renderForm(Request $request, string $email, array $errors, int $status = Response::HTTP_OK): Response
    {
        return $this->render('setup/index.html.twig', [
            'checks' => $this->checks->of($request),
            'email' => $email,
            'errors' => $errors,
            'min_length' => PasswordPolicy::MIN_LENGTH,
        ], new Response(status: $status));
    }
}
