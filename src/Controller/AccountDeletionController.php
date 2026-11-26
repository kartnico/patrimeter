<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Infrastructure\Mail\AccountMailer;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\CurrentUser;
use Patrimeter\Security\Reauthentication;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\Translation\t;

/**
 * Deletion of one's own account (My account > Profile): identity confirmed less than 5 minutes ago, then the address
 * of the account typed again; sign out (session and vault cookie), then deletion of the user and of all their data.
 */
final class AccountDeletionController extends AbstractController
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly Reauthentication $reauthentication,
        private readonly UserRepository $users,
        private readonly AccountMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/securite/supprimer-le-compte', name: 'app_security_delete_account', methods: ['POST'])]
    public function delete(Request $request, Security $security): Response
    {
        $user = $this->currentUser->user();
        if (!$this->reauthentication->isFresh($request)) {
            $this->addFlash('error', t('Pour cette action, confirmez d\'abord votre identité (mot de passe).'));

            return $this->redirectToRoute('app_security', status: Response::HTTP_SEE_OTHER);
        }
        // The address of the account, typed again (or, for an account without address, the ticked box).
        $confirmed = $user->email !== null
            ? mb_strtolower(trim($request->request->getString('confirm_email'))) === $user->email
            : $request->request->getString('confirm') === '1';
        if (!$confirmed) {
            $this->addFlash('error', $user->email !== null
                ? t('L\'adresse saisie n\'est pas celle du compte : rien n\'a été supprimé.')
                : t('Cochez la case de confirmation pour supprimer votre compte.'));

            return $this->redirectToRoute('app_security', status: Response::HTTP_SEE_OTHER);
        }

        // Sign out first (the sign-out is recorded in the log of the user), then delete; the language of the emails is
        // read before, while the account exists.
        $locale = $this->users->emailLocale($user->id);
        $logout = $security->logout(false);
        $this->users->delete($user->id);
        $this->logger->notice('Account deleted.', ['user' => $user->id->value]);
        if ($user->email !== null) {
            $this->mailer->send($user->email, t('Patrimeter : compte supprimé'), 'security_notice', [
                'event' => t('Votre compte et toutes ses données ont été supprimés.'),
                'access_lost' => true,
            ], $locale);
        }
        $this->addFlash('success', t('Votre compte et toutes ses données ont été supprimés.'));

        $response = $this->redirectToRoute('app_login', status: Response::HTTP_SEE_OTHER);
        foreach ($logout?->headers->getCookies() ?? [] as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
