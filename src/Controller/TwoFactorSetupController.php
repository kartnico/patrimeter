<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Infrastructure\Mail\AccountMailer;
use Patrimeter\Security\AuthJournal;
use Patrimeter\Security\CurrentUser;
use Patrimeter\Security\Reauthentication;
use Patrimeter\Security\TwoFactor\BackupCodes;
use Patrimeter\Security\TwoFactor\TotpEnrollment;
use Patrimeter\Security\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Setting up the authenticator app (TOTP): mandatory after registration, possible later from the My account page
 * (change of phone), after identity confirmation. The backup codes are generated and shown only once at the first
 * setup.
 */
final class TwoFactorSetupController extends AbstractController
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly TotpEnrollment $enrollment,
        private readonly Reauthentication $reauthentication,
    ) {}

    #[Route('/securite/application', name: 'app_totp_setup', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $user = $this->user();
        if ($user->isTotpAuthenticationEnabled() && !$this->reauthentication->isFresh($request)) {
            return $this->redirectToRoute('app_security_signin', status: Response::HTTP_SEE_OTHER);
        }

        return $this->renderSetup($request, $user, null);
    }

    #[Route('/securite/application', name: 'app_totp_enable', methods: ['POST'])]
    public function enable(Request $request, BackupCodes $backupCodes, AuthJournal $journal, AccountMailer $mailer): Response
    {
        $user = $this->user();
        $firstSetup = !$user->isTotpAuthenticationEnabled();
        if (!$firstSetup && !$this->reauthentication->isFresh($request)) {
            $this->addFlash('error', t('Confirmez d\'abord votre identité.'));

            return $this->redirectToRoute('app_security_signin', status: Response::HTTP_SEE_OTHER);
        }
        if (!$this->enrollment->confirm($request, $user, $request->request->getString('code'))) {
            return $this->renderSetup($request, $user, t('Code incorrect : vérifiez l\'heure du téléphone et saisissez le code affiché.'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $journal->record($user->id, AuthJournal::TOTP_ENABLED, $request);
        if (!$firstSetup) {
            if ($user->email !== null) {
                $mailer->send($user->email, t('Patrimeter : application d\'authentification changée'), 'security_notice', [
                    'event' => t('Une nouvelle application d\'authentification a été enregistrée ; l\'ancienne ne fonctionne plus.'),
                    'access_lost' => true,
                ]);
            }
            $this->addFlash('success', t('Nouvelle application d\'authentification enregistrée ; l\'ancienne ne fonctionne plus.'));

            return $this->redirectToRoute('app_security_signin', status: Response::HTTP_SEE_OTHER);
        }

        // Codes shown only once, directly (never kept in the session).
        return $this->render('security/backup_codes.html.twig', ['codes' => $backupCodes->regenerate($user->id), 'first_setup' => true]);
    }

    private function user(): User
    {
        return $this->currentUser->authenticated() ?? throw $this->createAccessDeniedException();
    }

    private function renderSetup(Request $request, User $user, string|TranslatableInterface|null $error, int $status = Response::HTTP_OK): Response
    {
        $secret = $this->enrollment->pendingSecret($request, $user);

        return $this->render('security/totp_setup.html.twig', [
            'provisioning_uri' => $this->enrollment->provisioningUri($user, $secret),
            'secret' => TotpEnrollment::formatSecret($secret),
            'first_setup' => !$user->isTotpAuthenticationEnabled(),
            'error' => $error,
        ], new Response(status: $status));
    }
}
