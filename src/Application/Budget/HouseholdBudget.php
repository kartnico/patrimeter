<?php

declare(strict_types=1);

namespace Patrimeter\Application\Budget;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Family\Portfolio;
use Patrimeter\Domain\Account;
use Patrimeter\Domain\Budget\BudgetBook;
use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\BudgetOverview;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Budget\PrecautionCoverage;
use Patrimeter\Domain\Budget\Reorder;
use Patrimeter\Domain\Budget\SavingsTrack;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\NetWorthSnapshot;
use Patrimeter\Domain\Trend;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

/**
 * Monthly household budget, kept in step with the net worth: the budget of a month is read with the net worth of the
 * same month (net worth, emergency savings compared with spending, change in the savings accounts tied to an item).
 * Creation of the household with suggested categories and items, in the page's language.
 */
final class HouseholdBudget
{
    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly AccountRepository $accounts,
        private readonly StatementRepository $statements,
        private readonly OwnershipShareRepository $shares,
        private readonly Connection $connection,
        private readonly TranslatorInterface $translator,
    ) {}

    /** Budget of the user's month, with their own net worth. */
    public function view(UserId $owner, Month $month): BudgetView
    {
        $household = $this->budgets->household($owner);
        if ($household === null) {
            return new BudgetView($month, null);
        }
        $portfolio = new Portfolio($this->accounts->all($owner), $this->statements->all($owner), $this->shares->all($owner));

        return $this->compose(
            $household,
            $this->budgets->categories($owner),
            $this->budgets->lines($owner),
            $this->budgets->budgetAt($owner, $month),
            $this->budgets->firstMonth($owner),
            $portfolio,
            $month,
        );
    }

    /**
     * Budget of the month of a family account's member, or of the family, with the net worth of the same perimeter
     * (FamilyPortfolio): read-only.
     */
    public function viewOf(?BudgetBook $book, ?Month $first, Portfolio $portfolio, Month $month): BudgetView
    {
        if ($book === null) {
            return new BudgetView($month, null, firstMonth: $first);
        }

        return $this->compose($book->household, $book->categories, $book->lines, $book->budgetAt($month), $first, $portfolio, $month);
    }

    /**
     * Emergency savings of the month relative to the spending of the budget in force (needs and wants) and to the
     * household's target; without a budget for that month, unknown spending (0) and default target.
     *
     * @return array{coverage: PrecautionCoverage, budgeted: bool}
     */
    public function precaution(UserId $owner, Month $month, NetWorthSnapshot $snapshot): array
    {
        $household = $this->budgets->household($owner);
        $budget = $household === null ? null : $this->budgets->budgetAt($owner, $month);

        return self::coverage($household, $budget, $household === null ? [] : $this->budgets->categories($owner), $household === null ? [] : $this->budgets->lines($owner), $snapshot);
    }

    /**
     * Same for the budget of a family account's member, or of the family.
     *
     * @return array{coverage: PrecautionCoverage, budgeted: bool}
     */
    public function precautionOf(?BudgetBook $book, Month $month, NetWorthSnapshot $snapshot): array
    {
        return self::coverage($book?->household, $book?->budgetAt($month), $book->categories ?? [], $book->lines ?? [], $snapshot);
    }

    /**
     * @param list<BudgetCategory> $categories
     * @param list<BudgetLine>     $lines
     *
     * @return array{coverage: PrecautionCoverage, budgeted: bool}
     */
    private static function coverage(?Household $household, ?MonthlyBudget $budget, array $categories, array $lines, NetWorthSnapshot $snapshot): array
    {
        $amount = $snapshot->level(Level::Precaution)->net();
        if ($household === null || $budget === null) {
            return ['coverage' => new PrecautionCoverage($amount, 0, Household::DEFAULT_PRECAUTION_MONTHS), 'budgeted' => false];
        }
        $overview = new BudgetOverview($household, $budget, $categories, $lines);

        return ['coverage' => new PrecautionCoverage($amount, $overview->expenses(), $household->precautionMonths), 'budgeted' => true];
    }

    /**
     * Budget of the month set against the net worth of the same month: net worth and its change, emergency savings,
     * savings accounts tied to an item.
     *
     * @param list<BudgetCategory> $categories
     * @param list<BudgetLine>     $lines
     */
    private function compose(Household $household, array $categories, array $lines, ?MonthlyBudget $budget, ?Month $first, Portfolio $portfolio, Month $month): BudgetView
    {
        $accounts = $portfolio->byId();
        $valuation = $portfolio->valuation();
        $calculator = $portfolio->calculator();
        $netWorth = $calculator->snapshot($month);
        $change = $netWorth->comparedTo($calculator->snapshot($month->previous()))['variation'];
        if ($budget === null) {
            return new BudgetView($month, $household, $categories, $lines, netWorth: $netWorth, accounts: $accounts, firstMonth: $first);
        }

        $overview = new BudgetOverview($household, $budget, $categories, $lines);
        $savings = [];
        foreach ($overview->categories as $category) {
            if ($category->group !== BudgetGroup::Savings) {
                continue;
            }
            foreach ($overview->lines($category->id) as $line) {
                $account = $line->accountId !== null ? ($accounts[$line->accountId] ?? null) : null;
                if ($account !== null) {
                    $savings[] = SavingsTrack::of($line, $account, $budget->amount($line->id), $valuation, $month);
                }
            }
        }

        return new BudgetView(
            $month,
            $household,
            $categories,
            $lines,
            $budget,
            $overview,
            $netWorth,
            new PrecautionCoverage($netWorth->level(Level::Precaution)->net(), $overview->expenses(), $household->precautionMonths),
            $savings,
            $accounts,
            $first,
            $change,
        );
    }

    /**
     * Accounts a savings item can point to: the asset accounts not closed, and the one already chosen.
     *
     * @return list<Account>
     */
    public function savingsAccounts(UserId $owner, ?int $selected = null): array
    {
        return array_values(array_filter(
            $this->accounts->all($owner),
            static fn(Account $account): bool => !$account->isLiability() && (!$account->isArchived() || $account->id === $selected),
        ));
    }

    /**
     * Saves the household. On its creation, and for a second adult who joins it, the suggested categories and their
     * items are created in the page's language.
     */
    public function saveHousehold(UserId $owner, Household $household, string $locale): void
    {
        $this->connection->transactional(function () use ($owner, $household, $locale): void {
            $previous = $this->budgets->household($owner);
            $this->budgets->saveHousehold($owner, $household);
            if ($previous === null) {
                $this->createDefaults($owner, self::sharedDefaults(), null, $locale);
            }
            foreach ($household->adultIds() as $adult) {
                if ($previous === null || !$previous->hasAdult($adult)) {
                    $this->createDefaults($owner, self::personalDefaults(), $adult, $locale);
                }
            }
        });
    }

    /**
     * Trends of the user's budget up to $month: average spending (needs and wants) and savings per month over 3, 6 and
     * 12 months, compared with the previous period; null without a household.
     *
     * @return array{expenses: list<Trend>, savings: list<Trend>}|null
     */
    public function trends(UserId $owner, Month $month): ?array
    {
        $household = $this->budgets->household($owner);

        return $household === null ? null : self::trendsOf(new BudgetBook($household, $this->budgets->categories($owner), $this->budgets->lines($owner), $this->budgets->budgets($owner)), $month);
    }

    /**
     * Same for a budget in memory (member of a family account, or the family).
     *
     * @return array{expenses: list<Trend>, savings: list<Trend>}|null
     */
    public static function trendsOf(?BudgetBook $book, Month $month): ?array
    {
        if ($book === null) {
            return null;
        }
        $overview = static function (Month $m) use ($book): ?BudgetOverview {
            $budget = $book->budgetAt($m);

            return $budget === null ? null : new BudgetOverview($book->household, $budget, $book->categories, $book->lines);
        };

        return [
            'expenses' => Trend::averages(static fn(Month $m): ?int => $overview($m)?->expenses(), $month),
            'savings' => Trend::averages(static fn(Month $m): ?int => $overview($m)?->savings(), $month),
        ];
    }

    /** Moves a category one place up (-1) or down (+1) among those of the same budget; false if it cannot. */
    public function moveCategory(UserId $owner, int $id, int $offset): bool
    {
        $categories = $this->budgets->categories($owner);
        $moving = array_values(array_filter($categories, static fn(BudgetCategory $c): bool => $c->id === $id))[0] ?? null;
        if ($moving === null) {
            return false;
        }
        $siblings = array_values(array_filter($categories, static fn(BudgetCategory $c): bool => $c->adult === $moving->adult));
        $order = Reorder::move(self::ids($categories), self::ids($siblings), $id, $offset);
        if ($order !== null) {
            $this->budgets->reorderCategories($owner, $order);
        }

        return $order !== null;
    }

    /** Moves an item one place up (-1) or down (+1) within its category; false if it cannot. */
    public function moveLine(UserId $owner, int $id, int $offset): bool
    {
        $lines = $this->budgets->lines($owner);
        $moving = array_values(array_filter($lines, static fn(BudgetLine $l): bool => $l->id === $id))[0] ?? null;
        if ($moving === null) {
            return false;
        }
        $siblings = array_values(array_filter($lines, static fn(BudgetLine $l): bool => $l->categoryId === $moving->categoryId));
        $order = Reorder::move(self::ids($lines), self::ids($siblings), $id, $offset);
        if ($order !== null) {
            $this->budgets->reorderLines($owner, $order);
        }

        return $order !== null;
    }

    /**
     * @param list<BudgetCategory|BudgetLine> $elements
     *
     * @return list<int>
     */
    private static function ids(array $elements): array
    {
        return array_map(static fn(BudgetCategory|BudgetLine $element): int => $element->id, $elements);
    }

    /** An adult who leaves the household must no longer have a category of their own. */
    public function hasCategoriesOf(UserId $owner, int $adult): bool
    {
        foreach ($this->budgets->categories($owner) as $category) {
            if ($category->adult === $adult) {
                return true;
            }
        }

        return false;
    }

    /**
     * Suggested categories for the shared budget: name, group, items.
     *
     * @return list<array{TranslatableMessage, BudgetGroup, list<TranslatableMessage>}>
     */
    public static function sharedDefaults(): array
    {
        return [
            [t('Logement'), BudgetGroup::Needs, [t('Loyer ou crédit'), t('Charges'), t('Assurances'), t('Énergie'), t('Internet et téléphone')]],
            [t('Vie courante'), BudgetGroup::Needs, [t('Courses'), t('Santé'), t('Transports')]],
            [t('Loisirs'), BudgetGroup::Wants, [t('Sorties et restaurants'), t('Abonnements'), t('Cadeaux')]],
            [t('Épargne du foyer'), BudgetGroup::Savings, [t('Épargne de précaution'), t('Réserve vacances'), t('Réserve travaux')]],
        ];
    }

    /**
     * Suggested categories for each adult.
     *
     * @return list<array{TranslatableMessage, BudgetGroup, list<TranslatableMessage>}>
     */
    public static function personalDefaults(): array
    {
        return [
            [t('Dépenses personnelles'), BudgetGroup::Needs, [t('Téléphone'), t('Transports')]],
            [t('Loisirs personnels'), BudgetGroup::Wants, [t('Sorties'), t('Sport')]],
            [t('Épargne personnelle'), BudgetGroup::Savings, [t('Livret A'), t('PEA')]],
        ];
    }

    /** @param list<array{TranslatableMessage, BudgetGroup, list<TranslatableMessage>}> $defaults */
    private function createDefaults(UserId $owner, array $defaults, ?int $adult, string $locale): void
    {
        foreach ($defaults as [$name, $group, $lines]) {
            $category = $this->budgets->createCategory($owner, $name->trans($this->translator, $locale), $group, $adult);
            foreach ($lines as $line) {
                $this->budgets->createLine($owner, $category, $line->trans($this->translator, $locale));
            }
        }
    }
}
