<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Carbure\CarbureBudget;
use Patrimeter\Application\Carbure\CarbureUnavailable;
use Patrimeter\Application\ValidationFailed;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\Translation\t;

/**
 * Link with the user's Carbure instance (CarbureBudget): address and API token, then mapping between the Carbure
 * spending categories and those of the budget. The actual spending shows on the budget page.
 */
final class CarbureController extends AbstractController
{
    public function __construct(
        private readonly CarbureBudget $carbure,
        private readonly BudgetRepository $budgets,
        private readonly CurrentUser $currentUser,
    ) {}

    #[Route('/budget/carbure', name: 'app_budget_carbure', methods: ['GET'])]
    public function show(): Response
    {
        return $this->page([], null);
    }

    #[Route('/budget/carbure', name: 'app_budget_carbure_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $url = $request->request->getString('url');
        try {
            $link = $this->carbure->connect($this->currentUser->id(), $url, $request->request->getString('token'));
            $this->addFlash('success', t('Carbure relié : %count% catégorie de dépenses.|Carbure relié : %count% catégories de dépenses.', ['%count%' => count($link->categories)]));
        } catch (ValidationFailed $failure) {
            return $this->page($failure->errors, $url)->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (CarbureUnavailable $failure) {
            $this->addFlash('error', t($failure->getMessage()));
        }

        return $this->redirectToRoute('app_budget_carbure', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/budget/carbure/correspondances', name: 'app_budget_carbure_mapping', methods: ['POST'])]
    public function mapping(Request $request): Response
    {
        $owner = $this->currentUser->id();
        $link = $this->carbure->link($owner);
        if ($link === null) {
            $this->addFlash('error', t('Reliez d\'abord votre instance Carbure.'));
        } else {
            $this->carbure->saveMapping($owner, $link, $request->request->all('mapping'));
            $this->addFlash('success', t('Correspondance des catégories enregistrée.'));
        }

        return $this->redirectToRoute('app_budget_carbure', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/budget/carbure/supprimer', name: 'app_budget_carbure_delete', methods: ['POST'])]
    public function delete(): Response
    {
        $this->carbure->disconnect($this->currentUser->id());
        $this->addFlash('success', t('Lien avec Carbure supprimé.'));

        return $this->redirectToRoute('app_budget_carbure', status: Response::HTTP_SEE_OTHER);
    }

    /** @param array<string, string> $errors */
    private function page(array $errors, ?string $url): Response
    {
        $owner = $this->currentUser->id();
        $link = $this->carbure->link($owner);

        return $this->render('budget/carbure.html.twig', [
            'link' => $link,
            'url' => $url ?? $link->url ?? '',
            'errors' => $errors,
            'categories' => $this->budgets->categories($owner),
        ]);
    }
}
