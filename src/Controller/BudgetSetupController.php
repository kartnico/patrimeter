<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Budget\HouseholdBudget;
use Patrimeter\Application\Form\BudgetCategoryForm;
use Patrimeter\Application\Form\BudgetLineForm;
use Patrimeter\Application\Form\HouseholdForm;
use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\Translation\t;

/**
 * Organisation of the budget: the household (adults, children, emergency savings target), the categories and their
 * items. A category or an item of another user cannot be found (404), like a non-existent identifier.
 */
final class BudgetSetupController extends AbstractController
{
    private const string ID = '\d{1,9}';

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly HouseholdBudget $budget,
        private readonly BudgetRepository $budgets,
    ) {}

    #[Route('/budget/foyer', name: 'app_budget_household', methods: ['GET'])]
    public function household(): Response
    {
        $household = $this->budgets->household($this->currentUser->id());
        if ($household === null) {
            // No household yet: the creation form, in this tab of My account.
            return $this->render('budget/setup.html.twig', ['form' => null]);
        }

        return $this->renderHousehold($household, null);
    }

    /** Creation of the household (with the suggested categories) or change. */
    #[Route('/budget/foyer', name: 'app_budget_household_save', methods: ['POST'])]
    public function saveHousehold(Request $request): Response
    {
        $owner = $this->currentUser->id();
        $current = $this->budgets->household($owner);
        $form = HouseholdForm::validate(
            $request->request->getString('adults'),
            $request->request->all('adult_names'),
            $request->request->all('adult_years'),
            $request->request->getString('children_count'),
            $request->request->all('child_ids'),
            $request->request->all('child_names'),
            $request->request->all('child_years'),
            $request->request->getString('precaution_months'),
            $current,
        );
        if ($form->household !== null && $current !== null && $current->isCouple() && !$form->household->isCouple()
            && $this->budget->hasCategoriesOf($owner, Household::SECOND_ADULT)) {
            $form->errors['adults'] = t('%name% a encore des catégories propres : supprimez-les, ou rattachez-les au budget commun, avant de retirer cet adulte du foyer.', ['%name%' => $current->adultName(Household::SECOND_ADULT)]);
        }
        if (!$form->isValid() || $form->household === null) {
            return $current === null
                ? $this->render('budget/setup.html.twig', ['form' => $form])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY)
                : $this->renderHousehold($current, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->budget->saveHousehold($owner, $form->household, $request->getLocale());
        if ($current === null) {
            $this->addFlash('success', t('Foyer fiscal créé, avec des catégories et des postes à adapter. Saisissez maintenant les montants de votre budget.'));

            return $this->redirectToRoute('app_budget_entry', status: Response::HTTP_SEE_OTHER);
        }
        $this->addFlash('success', t('Foyer fiscal enregistré.'));

        return $this->redirectToRoute('app_budget_household', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/budget/categories', name: 'app_budget_category_create', methods: ['POST'])]
    public function createCategory(Request $request): Response
    {
        $owner = $this->currentUser->id();
        $household = $this->householdOr404();
        $form = BudgetCategoryForm::validate($request->request->getString('name'), $request->request->getString('group'), $request->request->getString('adult'), $household);
        if (!$form->isValid() || $form->groupValue === null) {
            return $this->renderHousehold($household, null, Response::HTTP_UNPROCESSABLE_ENTITY, $form);
        }
        $this->budgets->createCategory($owner, $form->name, $form->groupValue, $form->adultValue);
        $this->addFlash('success', t('Catégorie « %name% » créée.', ['%name%' => $form->name]));

        return $this->redirectToRoute('app_budget_household', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/budget/categories/{id}', name: 'app_budget_category_edit', requirements: ['id' => self::ID], methods: ['GET'])]
    public function editCategory(int $id): Response
    {
        $household = $this->householdOr404();

        return $this->renderCategory($household, $this->categoryOr404($id), null);
    }

    #[Route('/budget/categories/{id}', name: 'app_budget_category_update', requirements: ['id' => self::ID], methods: ['POST'])]
    public function updateCategory(Request $request, int $id): Response
    {
        $household = $this->householdOr404();
        $category = $this->categoryOr404($id);
        $form = BudgetCategoryForm::validate($request->request->getString('name'), $request->request->getString('group'), $request->request->getString('adult'), $household);
        if (!$form->isValid()) {
            return $this->renderCategory($household, $category, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->budgets->updateCategory($this->currentUser->id(), $form->category($category->id));
        $this->addFlash('success', t('Catégorie « %name% » enregistrée.', ['%name%' => $form->name]));

        return $this->redirectToRoute('app_budget_household', status: Response::HTTP_SEE_OTHER);
    }

    /** Deletes the category and its items; their amounts disappear from every month. */
    #[Route('/budget/categories/{id}/supprimer', name: 'app_budget_category_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function deleteCategory(int $id): Response
    {
        $category = $this->categoryOr404($id);
        $this->budgets->deleteCategory($this->currentUser->id(), $category->id);
        $this->addFlash('success', t('Catégorie « %name% » supprimée, avec ses postes.', ['%name%' => $category->name]));

        return $this->redirectToRoute('app_budget_household', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/budget/postes', name: 'app_budget_line_create', methods: ['POST'])]
    public function createLine(Request $request): Response
    {
        $owner = $this->currentUser->id();
        $household = $this->householdOr404();
        $form = BudgetLineForm::validate(
            $request->request->getString('name'),
            $request->request->getString('category_id'),
            '',
            '',
            $this->budgets->categories($owner),
            [],
            $household,
        );
        if (!$form->isValid() || $form->categoryValue === null) {
            return $this->renderHousehold($household, null, Response::HTTP_UNPROCESSABLE_ENTITY, lineForm: $form);
        }
        $this->budgets->createLine($owner, $form->categoryValue->id, $form->name);
        $this->addFlash('success', t('Poste « %name% » créé : son montant se saisit avec le budget du mois.', ['%name%' => $form->name]));

        return $this->redirectToRoute('app_budget_household', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/budget/postes/{id}', name: 'app_budget_line_edit', requirements: ['id' => self::ID], methods: ['GET'])]
    public function editLine(int $id): Response
    {
        return $this->renderLine($this->householdOr404(), $this->lineOr404($id), null);
    }

    #[Route('/budget/postes/{id}', name: 'app_budget_line_update', requirements: ['id' => self::ID], methods: ['POST'])]
    public function updateLine(Request $request, int $id): Response
    {
        $owner = $this->currentUser->id();
        $household = $this->householdOr404();
        $line = $this->lineOr404($id);
        $form = BudgetLineForm::validate(
            $request->request->getString('name'),
            $request->request->getString('category_id'),
            $request->request->getString('child'),
            $request->request->getString('account'),
            $this->budgets->categories($owner),
            $this->budget->savingsAccounts($owner, $line->accountId),
            $household,
        );
        if (!$form->isValid()) {
            return $this->renderLine($household, $line, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->budgets->updateLine($owner, $form->line($line->id));
        $this->addFlash('success', t('Poste « %name% » enregistré.', ['%name%' => $form->name]));

        return $this->redirectToRoute('app_budget_household', status: Response::HTTP_SEE_OTHER);
    }

    /** Moves the category one place up or down among those of the same budget (field "sens": haut or bas). */
    #[Route('/budget/categories/{id}/deplacer', name: 'app_budget_category_move', requirements: ['id' => self::ID], methods: ['POST'])]
    public function moveCategory(Request $request, int $id): Response
    {
        $category = $this->categoryOr404($id);
        $this->budget->moveCategory($this->currentUser->id(), $category->id, self::offset($request));

        return $this->redirectToRoute('app_budget_household', ['_fragment' => 'categories-title'], Response::HTTP_SEE_OTHER);
    }

    /** Moves the item one place up or down within its category (field "sens": haut or bas). */
    #[Route('/budget/postes/{id}/deplacer', name: 'app_budget_line_move', requirements: ['id' => self::ID], methods: ['POST'])]
    public function moveLine(Request $request, int $id): Response
    {
        $line = $this->lineOr404($id);
        $this->budget->moveLine($this->currentUser->id(), $line->id, self::offset($request));

        return $this->redirectToRoute('app_budget_household', ['_fragment' => 'categories-title'], Response::HTTP_SEE_OTHER);
    }

    private static function offset(Request $request): int
    {
        return $request->request->getString('sens') === 'haut' ? -1 : 1;
    }

    /** Deletes the item; its amount disappears from every month. */
    #[Route('/budget/postes/{id}/supprimer', name: 'app_budget_line_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function deleteLine(int $id): Response
    {
        $line = $this->lineOr404($id);
        $this->budgets->deleteLine($this->currentUser->id(), $line->id);
        $this->addFlash('success', t('Poste « %name% » supprimé.', ['%name%' => $line->name]));

        return $this->redirectToRoute('app_budget_household', status: Response::HTTP_SEE_OTHER);
    }

    private function renderHousehold(Household $household, ?HouseholdForm $form, int $status = Response::HTTP_OK, ?BudgetCategoryForm $categoryForm = null, ?BudgetLineForm $lineForm = null): Response
    {
        $owner = $this->currentUser->id();
        $lines = [];
        foreach ($this->budgets->lines($owner) as $line) {
            $lines[$line->categoryId][] = $line;
        }
        $accounts = [];
        foreach ($this->budget->savingsAccounts($owner) as $account) {
            $accounts[$account->id] = $account;
        }

        return $this->render('budget/household.html.twig', [
            'household' => $household,
            'form' => $form,
            'categories' => $this->budgets->categories($owner),
            'lines' => $lines,
            'accounts' => $accounts,
            'category_form' => $categoryForm,
            'line_form' => $lineForm,
        ])->setStatusCode($status);
    }

    private function renderCategory(Household $household, BudgetCategory $category, ?BudgetCategoryForm $form, int $status = Response::HTTP_OK): Response
    {
        $lines = array_values(array_filter($this->budgets->lines($this->currentUser->id()), static fn(BudgetLine $line): bool => $line->categoryId === $category->id));

        return $this->render('budget/category.html.twig', ['household' => $household, 'category' => $category, 'form' => $form, 'line_count' => count($lines)])
            ->setStatusCode($status);
    }

    private function renderLine(Household $household, BudgetLine $line, ?BudgetLineForm $form, int $status = Response::HTTP_OK): Response
    {
        $owner = $this->currentUser->id();

        return $this->render('budget/line.html.twig', [
            'household' => $household,
            'line' => $line,
            'form' => $form,
            'categories' => $this->budgets->categories($owner),
            'accounts' => $this->budget->savingsAccounts($owner, $line->accountId),
        ])->setStatusCode($status);
    }

    private function householdOr404(): Household
    {
        return $this->budgets->household($this->currentUser->id()) ?? throw $this->createNotFoundException();
    }

    private function categoryOr404(int $id): BudgetCategory
    {
        return $this->budgets->findCategory($this->currentUser->id(), $id) ?? throw $this->createNotFoundException();
    }

    private function lineOr404(int $id): BudgetLine
    {
        return $this->budgets->findLine($this->currentUser->id(), $id) ?? throw $this->createNotFoundException();
    }
}
