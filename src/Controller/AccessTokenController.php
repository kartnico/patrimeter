<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Domain\Account;
use Patrimeter\Infrastructure\Mail\AccountMailer;
use Patrimeter\Security\Api\ApiScope;
use Patrimeter\Security\Api\ApiTokens;
use Patrimeter\Security\AuthJournal;
use Patrimeter\Security\CurrentUser;
use Patrimeter\Security\Reauthentication;
use Patrimeter\Security\Vault\Vault;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\Translation\t;

/** My account page: personal API tokens and removal of the granted accesses. */
final class AccessTokenController extends AbstractController
{
    private const string ID = '[0-9a-f-]{1,36}';

    public function __construct(
        private readonly ApiTokens $tokens,
        private readonly CurrentUser $currentUser,
        private readonly Reauthentication $reauthentication,
        private readonly AuthJournal $journal,
    ) {}

    /** The token is only shown here, once: the server keeps nothing from which it could be rebuilt. */
    #[Route('/securite/jetons', name: 'app_access_token_create', methods: ['POST'])]
    public function create(Request $request, Vault $vault, AccountMailer $mailer): Response
    {
        $user = $this->currentUser->user();
        if (!$this->reauthentication->isFresh($request)) {
            $this->addFlash('error', t('Pour cette action, confirmez d\'abord votre identité.'));

            return $this->redirectToRoute('app_security_agents', status: Response::HTTP_SEE_OTHER);
        }
        $name = trim($request->request->getString('name'));
        $days = $request->request->getInt('days');
        if (preg_match(Account::NAME_PATTERN, $name) !== 1 || !in_array($days, ApiTokens::PERSONAL_DAYS, true)) {
            $this->addFlash('error', t('Donnez un nom au jeton (80 caractères au plus) et une durée.'));

            return $this->redirectToRoute('app_security_agents', status: Response::HTTP_SEE_OTHER);
        }
        $scope = $request->request->getBoolean('write') ? ApiScope::READ . ' ' . ApiScope::WRITE : ApiScope::READ;
        $token = $this->tokens->createPersonal($user, $name, $scope, $days, $vault->key($user->id));
        $this->journal->record($user->id, AuthJournal::API_GRANTED, $request);
        if ($user->email !== null) {
            $mailer->send($user->email, t('Patrimeter : nouveau jeton d\'API'), 'security_notice', ['event' => t('Le jeton d\'API « %name% » a été créé. Vous pouvez le retirer depuis la page Mon compte.', ['%name%' => $name])]);
        }

        return $this->render('security/access_token.html.twig', ['token' => $token, 'name' => $name]);
    }

    #[Route('/securite/jetons/{id}/retirer', name: 'app_access_token_revoke', requirements: ['id' => self::ID], methods: ['POST'])]
    public function revoke(Request $request, string $id): Response
    {
        $user = $this->currentUser->user();
        if ($this->tokens->revoke($user->id, $id)) {
            $this->journal->record($user->id, AuthJournal::API_REVOKED, $request);
            $this->addFlash('success', t('Accès retiré.'));
        }

        return $this->redirectToRoute('app_security_agents', status: Response::HTTP_SEE_OTHER);
    }
}
