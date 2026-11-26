<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Budget\BudgetView;
use Patrimeter\Application\Budget\HouseholdBudget;
use Patrimeter\Application\Calendar;
use Patrimeter\Application\Carbure\CarbureActuals;
use Patrimeter\Application\Carbure\CarbureBudget;
use Patrimeter\Application\Carbure\CarbureUnavailable;
use Patrimeter\Application\Family\FamilyPortfolio;
use Patrimeter\Application\Form\BudgetAmountsForm;
use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\BudgetOverview;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\OwnershipShares;
use Patrimeter\Domain\UserId;
use Patrimeter\Http\PerimeterSelection;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\Translation\t;

/**
 * Monthly household budget: view of a month (flow of incomes, 50/30/20 rule, joint account and each adult's share,
 * comparison with the month's net worth) and entry of its amounts. The amounts saved for a month apply to the
 * following ones until the next save.
 */
final class BudgetController extends AbstractController
{
    private const string MONTH = '\d{4}-\d{2}';

    public function __construct(
        private readonly Calendar $calendar,
        private readonly CurrentUser $currentUser,
        private readonly HouseholdBudget $budget,
        private readonly BudgetRepository $budgets,
        private readonly FamilyPortfolio $family,
        private readonly PerimeterSelection $perimeters,
        private readonly CarbureBudget $carbure,
    ) {}

    #[Route('/budget', name: 'app_budget', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $current = $this->calendar->currentMonth();
        $month = MonthQuery::from($request, $current);
        // Up to next month, whose budget can be entered in advance.
        if ($month->compare($current->next()) > 0) {
            return $this->redirectToRoute('app_budget', status: Response::HTTP_SEE_OTHER);
        }
        $owner = $this->currentUser->id();
        // Family account: budget of the family or of a member, read-only; otherwise this account's.
        $selection = $this->perimeters->resolve($request, $owner);
        $perimeter = $selection['perimeter'];
        $carbure = null;
        if ($perimeter->isOwn()) {
            $view = $this->budget->view($owner, $month);
            if ($view->household === null) {
                return $this->render('budget/setup.html.twig', ['form' => null, 'perimeters' => $selection['choices']]);
            }
            $carbure = $this->carbureActuals($owner, $view->overview, $month);
            $trends = $this->budget->trends($owner, $month);
        } else {
            $budget = $this->family->budget($owner, $perimeter, $month);
            $view = $this->budget->viewOf($budget['book'], $budget['first'], $this->family->portfolio($owner, $perimeter), $month);
            $trends = HouseholdBudget::trendsOf($budget['book'], $month);
        }

        return $this->render('budget/index.html.twig', [
            'view' => $view,
            'month' => $month,
            'current_month' => $current,
            'previous' => $month->previous(),
            'next' => $month->compare($current->next()) < 0 ? $month->next() : null,
            'last_month' => $current->next(),
            'perimeters' => $selection['choices'],
            'read_only' => !$perimeter->isOwn(),
            'carbure' => $carbure,
            // Average spending and savings over 3, 6 and 12 months, compared with the period before.
            'trends' => $trends,
        ]);
    }

    /**
     * Actual spending of the month read from Carbure, if the user has linked it: comparison with the planned budget,
     * or the reason why Carbure did not answer.
     *
     * @return array{comparison: ?array{rows: list<array{category: BudgetCategory, planned: int, actual: int}>, planned: int, actual: int}, actuals: ?CarbureActuals, error: ?string}|null
     */
    private function carbureActuals(UserId $owner, ?BudgetOverview $overview, Month $month): ?array
    {
        $link = $this->carbure->link($owner);
        if ($link === null || $overview === null) {
            return null;
        }
        try {
            $actuals = $this->carbure->actuals($owner, $link, $month);

            return ['comparison' => $actuals->compare($overview), 'actuals' => $actuals, 'error' => null];
        } catch (CarbureUnavailable $failure) {
            return ['comparison' => null, 'actuals' => null, 'error' => $failure->getMessage()];
        }
    }

    #[Route('/budget/saisie', name: 'app_budget_entry', methods: ['GET'])]
    public function entry(Request $request): Response
    {
        $current = $this->calendar->currentMonth();
        $month = MonthQuery::from($request, $current);
        if ($month->compare($current->next()) > 0) {
            return $this->redirectToRoute('app_budget_entry', status: Response::HTTP_SEE_OTHER);
        }
        $owner = $this->currentUser->id();
        $view = $this->budget->view($owner, $month);
        // No tax household yet: the entry says so, with the way to create it (and the current month only).
        if ($view->household === null) {
            if (!$month->equals($current)) {
                return $this->redirectToRoute('app_budget_entry', status: Response::HTTP_SEE_OTHER);
            }

            return $this->render('budget/entry_empty.html.twig', ['month' => $month]);
        }
        // Copy of another month: the form takes the amounts in force that month, saved only when submitted.
        $source = Month::tryFromString($request->query->getString('depuis'));
        $copy = null;
        if ($source !== null && !$source->equals($month) && $source->compare($current->next()) <= 0) {
            $copy = ['month' => $source, 'budget' => $this->budget->view($owner, $source)->budget];
        }

        return $this->renderEntry($view, null, $request->getLocale(), copy: $copy);
    }

    /** All or nothing: a single invalid amount and nothing is saved. */
    #[Route('/budget/saisie', name: 'app_budget_entry_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $month = Month::tryFromString($request->request->getString('mois'));
        if ($month === null || $month->compare($this->calendar->currentMonth()->next()) > 0) {
            $this->addFlash('error', t('Mois invalide.'));

            return $this->redirectToRoute('app_budget', status: Response::HTTP_SEE_OTHER);
        }
        $owner = $this->currentUser->id();
        $view = $this->budget->view($owner, $month);
        if ($view->household === null) {
            return $this->redirectToRoute('app_budget', status: Response::HTTP_SEE_OTHER);
        }
        $form = BudgetAmountsForm::validate(
            $month,
            $request->request->all('incomes'),
            $request->request->all('amounts'),
            $request->request->getString('split_rule'),
            $request->request->getString('first_share'),
            $view->household,
            array_map(static fn(BudgetLine $line): int => $line->id, $view->lines),
            $request->getLocale(),
            $request->request->all('child_incomes'),
        );
        if ($form->budget === null) {
            return $this->renderEntry($view, $form, $request->getLocale(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->budgets->saveBudget($owner, $form->budget);
        $this->addFlash('success', t('Budget %of_month% enregistré.', ['%of_month%' => $month->ofLabel($request->getLocale())]));

        return $this->redirectToRoute('app_budget', ['mois' => $month->toString()], Response::HTTP_SEE_OTHER);
    }

    /** Removes the amounts saved for this month: those of an earlier month apply again. */
    #[Route('/budget/saisie/{month}/supprimer', name: 'app_budget_month_delete', requirements: ['month' => self::MONTH], methods: ['POST'])]
    public function deleteMonth(Request $request, string $month): Response
    {
        $value = Month::tryFromString($month) ?? throw $this->createNotFoundException();
        $this->budgets->deleteBudget($this->currentUser->id(), $value);
        $this->addFlash('success', t('Montants %of_month% supprimés : ceux d\'un mois antérieur s\'appliquent de nouveau.', ['%of_month%' => $value->ofLabel($request->getLocale())]));

        return $this->redirectToRoute('app_budget', ['mois' => $value->toString()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Form prefilled with the amounts in force (carried over if needed), those of another month copied, or shown again
     * with the refused input.
     *
     * @param array{month: Month, budget: ?MonthlyBudget}|null $copy month copied and its budget in force (null: none)
     */
    private function renderEntry(BudgetView $view, ?BudgetAmountsForm $form, string $locale, int $status = Response::HTTP_OK, ?array $copy = null): Response
    {
        $household = $view->household ?? throw new \LogicException('Foyer attendu.');
        $budget = $copy['budget'] ?? $view->budget ?? new MonthlyBudget($view->month);
        $incomes = [];
        foreach ($household->adultIds() as $adult) {
            $incomes[$adult] = $form !== null ? ($form->incomes[$adult] ?? '') : self::input($budget->income($adult), $locale);
        }
        // Children of working age in the month shown: an income field each.
        $childIncomes = [];
        foreach (array_keys($household->workingChildren($view->month)) as $child) {
            $childIncomes[$child] = $form !== null ? ($form->childIncomes[$child] ?? '') : self::input($budget->childIncome($child), $locale);
        }
        $amounts = [];
        foreach ($view->lines as $line) {
            $amounts[$line->id] = $form !== null ? ($form->amounts[$line->id] ?? '') : self::input($budget->amount($line->id), $locale);
        }
        $current = $this->calendar->currentMonth();

        return $this->render('budget/entry.html.twig', [
            'view' => $view,
            'month' => $view->month,
            'current_month' => $current,
            'last_month' => $current->next(),
            'previous' => $view->month->previous(),
            'next' => $view->month->compare($current->next()) < 0 ? $view->month->next() : null,
            // Starting structure and totals; app.js recalculates them while typing.
            'overview' => new BudgetOverview($household, $budget, $view->categories, $view->lines),
            'incomes' => $incomes,
            'child_incomes' => $childIncomes,
            'amounts' => $amounts,
            'split_rule' => $form !== null ? $form->splitRule : $budget->splitRule->value,
            'first_share' => $form !== null ? $form->firstShare : OwnershipShares::formatPercent($budget->firstAdultShare, $locale),
            'copy_from' => $copy['month'] ?? null,
            'copied' => ($copy['budget'] ?? null) !== null,
            'errors' => $form->errors ?? [],
        ])->setStatusCode($status);
    }

    /** Value of a field: empty for zero, otherwise "1234,56" ("1234.56" in English). */
    private static function input(int $cents, string $locale): string
    {
        return $cents === 0 ? '' : Money::toInput($cents, $locale);
    }
}
