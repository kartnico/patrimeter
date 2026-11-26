<?php

declare(strict_types=1);

namespace Patrimeter\Controller\Api;

use Patrimeter\Application\Payload\AccountPayload;
use Patrimeter\Application\Payload\ClosePayload;
use Patrimeter\Application\Payload\LoanLinkPayload;
use Patrimeter\Application\Payload\SharePayload;
use Patrimeter\Application\Payload\StatementPayload;
use Patrimeter\Application\PortfolioService;
use Patrimeter\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/** REST API: accounts, statements, shares and linking of the loans (docs/api.md). */
#[Route('/api/v1', name: 'app_api_', stateless: true)]
final class AccountApiController extends AbstractController
{
    private const string ID = '\d{1,9}';
    private const string MONTH = '\d{4}-\d{2}';

    public function __construct(
        private readonly PortfolioService $portfolio,
        private readonly CurrentUser $currentUser,
    ) {}

    #[Route('/accounts', name: 'accounts', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json(['accounts' => $this->portfolio->accountList($this->currentUser->id())]);
    }

    #[Route('/accounts', name: 'account_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] AccountPayload $payload): JsonResponse
    {
        return $this->json($this->portfolio->createAccount($this->currentUser->id(), $payload), Response::HTTP_CREATED);
    }

    #[Route('/accounts/{id}', name: 'account', requirements: ['id' => self::ID], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        return $this->json($this->portfolio->accountDetail($this->currentUser->id(), $id));
    }

    #[Route('/accounts/{id}', name: 'account_update', requirements: ['id' => self::ID], methods: ['PUT'])]
    public function update(#[MapRequestPayload] AccountPayload $payload, int $id): JsonResponse
    {
        return $this->json($this->portfolio->updateAccount($this->currentUser->id(), $id, $payload));
    }

    #[Route('/accounts/{id}', name: 'account_delete', requirements: ['id' => self::ID], methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->portfolio->deleteAccount($this->currentUser->id(), $id);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/accounts/{id}/archive', name: 'account_archive', requirements: ['id' => self::ID], methods: ['POST'])]
    public function archive(#[MapRequestPayload] ClosePayload $payload, int $id): JsonResponse
    {
        return $this->json($this->portfolio->archiveAccount($this->currentUser->id(), $id, $payload));
    }

    #[Route('/accounts/{id}/unarchive', name: 'account_unarchive', requirements: ['id' => self::ID], methods: ['POST'])]
    public function unarchive(int $id): JsonResponse
    {
        return $this->json($this->portfolio->unarchiveAccount($this->currentUser->id(), $id));
    }

    #[Route('/accounts/{id}/loan-link', name: 'account_link', requirements: ['id' => self::ID], methods: ['PUT'])]
    public function link(#[MapRequestPayload] LoanLinkPayload $payload, int $id): Response
    {
        $this->portfolio->linkLoan($this->currentUser->id(), $id, $payload);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/accounts/{id}/statements', name: 'statements', requirements: ['id' => self::ID], methods: ['GET'])]
    public function statements(int $id): JsonResponse
    {
        return $this->json(['statements' => $this->portfolio->statements($this->currentUser->id(), $id)]);
    }

    #[Route('/accounts/{id}/statements/{month}', name: 'statement_save', requirements: ['id' => self::ID, 'month' => self::MONTH], methods: ['PUT'])]
    public function saveStatement(#[MapRequestPayload] StatementPayload $payload, int $id, string $month): Response
    {
        $this->portfolio->saveStatement($this->currentUser->id(), $id, $month, $payload);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/accounts/{id}/statements/{month}', name: 'statement_delete', requirements: ['id' => self::ID, 'month' => self::MONTH], methods: ['DELETE'])]
    public function deleteStatement(int $id, string $month): Response
    {
        $this->portfolio->deleteStatement($this->currentUser->id(), $id, $month);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/accounts/{id}/shares', name: 'shares', requirements: ['id' => self::ID], methods: ['GET'])]
    public function shares(int $id): JsonResponse
    {
        return $this->json(['shares' => $this->portfolio->shares($this->currentUser->id(), $id)]);
    }

    #[Route('/accounts/{id}/shares/{month}', name: 'share_save', requirements: ['id' => self::ID, 'month' => self::MONTH], methods: ['PUT'])]
    public function saveShare(#[MapRequestPayload] SharePayload $payload, int $id, string $month): Response
    {
        $this->portfolio->saveShare($this->currentUser->id(), $id, $month, $payload);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/accounts/{id}/shares/{month}', name: 'share_delete', requirements: ['id' => self::ID, 'month' => self::MONTH], methods: ['DELETE'])]
    public function deleteShare(int $id, string $month): Response
    {
        $this->portfolio->deleteShare($this->currentUser->id(), $id, $month);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
