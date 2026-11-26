<?php

declare(strict_types=1);

namespace Patrimeter\Controller\Api;

use Patrimeter\Application\PortfolioService;
use Patrimeter\Security\Api\ApiScope;
use Patrimeter\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/** REST API: identity of the token, reference data and calculated states, savings goals, budget and challenges (docs/api.md). */
#[Route('/api/v1', name: 'app_api_', stateless: true)]
final class ReportApiController extends AbstractController
{
    public function __construct(
        private readonly PortfolioService $portfolio,
        private readonly CurrentUser $currentUser,
    ) {}

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->currentUser->user();

        return $this->json(['id' => $user->id->value, 'email' => $user->email, 'can_write' => in_array(ApiScope::WRITE_ROLE, $user->getRoles(), true)]);
    }

    #[Route('/account-types', name: 'account_types', methods: ['GET'])]
    public function accountTypes(): JsonResponse
    {
        $this->currentUser->id();

        return $this->json(['account_types' => $this->portfolio->accountTypes()]);
    }

    #[Route('/net-worth', name: 'net_worth', methods: ['GET'])]
    public function netWorth(#[MapQueryParameter] ?string $month = null): JsonResponse
    {
        return $this->json($this->portfolio->netWorth($this->currentUser->id(), $month));
    }

    #[Route('/history', name: 'history', methods: ['GET'])]
    public function history(#[MapQueryParameter] ?string $from = null, #[MapQueryParameter] ?string $to = null): JsonResponse
    {
        return $this->json(['months' => $this->portfolio->history($this->currentUser->id(), $from, $to)]);
    }

    #[Route('/goals', name: 'goals', methods: ['GET'])]
    public function goals(#[MapQueryParameter] ?string $month = null): JsonResponse
    {
        return $this->json(['goals' => $this->portfolio->goals($this->currentUser->id(), $month)]);
    }

    #[Route('/challenges', name: 'challenges', methods: ['GET'])]
    public function challenges(): JsonResponse
    {
        return $this->json($this->portfolio->challenges($this->currentUser->id()));
    }

    #[Route('/budget', name: 'budget', methods: ['GET'])]
    public function budget(#[MapQueryParameter] ?string $month = null): JsonResponse
    {
        return $this->json($this->portfolio->budget($this->currentUser->id(), $month));
    }

    #[Route('/properties', name: 'properties', methods: ['GET'])]
    public function properties(#[MapQueryParameter] ?string $month = null): JsonResponse
    {
        return $this->json(['properties' => $this->portfolio->propertyList($this->currentUser->id(), $month)]);
    }
}
