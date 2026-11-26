<?php

declare(strict_types=1);

namespace Patrimeter\Application\Goals;

use Patrimeter\Application\Calendar;
use Patrimeter\Application\Form\GoalForm;
use Patrimeter\Domain\Account;
use Patrimeter\Domain\Budget\BudgetBook;
use Patrimeter\Domain\Budget\BudgetOverview;
use Patrimeter\Domain\Goal\GoalProgress;
use Patrimeter\Domain\Goal\GoalScope;
use Patrimeter\Domain\Goal\GoalStatus;
use Patrimeter\Domain\Goal\SavingsGoal;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\OwnershipShares;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\SavingsGoalRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;

/**
 * Savings goals of a user: creation (starting point = value of the perimeter this month), change, deletion, and where
 * each one stands in a month (GoalProgress), computed from the statements and the household budget each time: nothing
 * else is stored. A goal in months of spending follows the spending (needs and wants) of the budget in force; the
 * savings items of the budget tied to the goal's accounts give the savings planned towards it.
 *
 * @phpstan-type GoalFields array{name: string, target: string, target_month: string, scope: string, level: string, kind: string, spending_months: string, start_month: string, accounts: list<string>}
 */
final class SavingsGoals
{
    /** Months of history drawn on the projection chart, at most. */
    public const int CHART_MONTHS = 36;

    public function __construct(
        private readonly SavingsGoalRepository $goals,
        private readonly AccountRepository $accounts,
        private readonly StatementRepository $statements,
        private readonly OwnershipShareRepository $shares,
        private readonly BudgetRepository $budgets,
        private readonly Calendar $calendar,
    ) {}

    /** @return list<SavingsGoal> */
    public function all(UserId $owner): array
    {
        return $this->goals->all($owner);
    }

    public function find(UserId $owner, int $id): ?SavingsGoal
    {
        return $this->goals->find($owner, $id);
    }

    public function isFull(UserId $owner): bool
    {
        return $this->goals->count($owner) >= SavingsGoalRepository::MAX_GOALS;
    }

    /** @return list<Account> accounts that a goal can measure (not archived) */
    public function accounts(UserId $owner): array
    {
        return array_values(array_filter($this->accounts->all($owner), static fn(Account $account): bool => !$account->isArchived()));
    }

    /**
     * @param GoalFields $fields
     *
     * @return GoalForm valid form if the goal was created
     */
    public function create(UserId $owner, array $fields, string $locale): GoalForm
    {
        $current = $this->calendar->currentMonth();
        $form = GoalForm::validate($fields, $this->accounts->all($owner), $current->next(), $current, $current, $locale);
        $spendingTarget = $this->spendingTarget($owner, $form, $current);
        if ($form->isValid() && $form->startMonthValue !== null) {
            $draft = $form->goal(0, 0, $spendingTarget);
            $this->goals->create($owner, $form->goal(0, $draft->valueIn($this->calculator($owner)->snapshot($form->startMonthValue)), $spendingTarget));
        }

        return $form;
    }

    /**
     * Changes a goal; its starting point stays, unless its start month changes (value of the perimeter that month).
     *
     * @param GoalFields $fields
     */
    public function update(UserId $owner, SavingsGoal $goal, array $fields, string $locale): GoalForm
    {
        $current = $this->calendar->currentMonth();
        $form = GoalForm::validate($fields, $this->accounts->all($owner), $goal->startMonth->next(), $goal->startMonth, $current, $locale);
        $spendingTarget = $this->spendingTarget($owner, $form, $current);
        if ($form->isValid() && $form->startMonthValue !== null) {
            $draft = $form->goal($goal->id, $goal->startCents, $spendingTarget);
            $startCents = $draft->startMonth->equals($goal->startMonth) && $draft->scope === $goal->scope && $draft->level === $goal->level && $draft->accountIds === $goal->accountIds
                ? $goal->startCents
                : $draft->valueIn($this->calculator($owner)->snapshot($draft->startMonth));
            $this->goals->update($owner, $form->goal($goal->id, $startCents, $spendingTarget));
        }

        return $form;
    }

    public function delete(UserId $owner, int $id): void
    {
        $this->goals->delete($owner, $id);
    }

    /** @return list<GoalProgress> where each goal stands in $month */
    public function progress(UserId $owner, Month $month): array
    {
        $goals = $this->goals->all($owner);
        if ($goals === []) {
            return [];
        }
        $snapshot = $this->calculator($owner)->snapshot(...);
        $book = $this->book($owner);
        $accounts = $this->accounts->all($owner);

        return array_map(
            fn(SavingsGoal $goal): GoalProgress => GoalProgress::of($goal, $month, $snapshot, self::target($goal, $book), self::planned($goal, $book, $month, $accounts)),
            $goals,
        );
    }

    /** @return list<GoalProgress> status of the goal in each of the last months up to $month */
    public function history(UserId $owner, SavingsGoal $goal, Month $month): array
    {
        return GoalProgress::history($goal, $month, $this->calculator($owner)->snapshot(...), GoalProgress::PACE_MONTHS, self::target($goal, $this->book($owner)));
    }

    /**
     * Value of the goal's perimeter each month from its start (the last CHART_MONTHS months at most) up to $month.
     *
     * @return list<array{month: Month, value: int}>
     */
    public function series(UserId $owner, SavingsGoal $goal, Month $month): array
    {
        $first = $month->addMonths(1 - self::CHART_MONTHS);
        if ($first->compare($goal->startMonth) < 0) {
            $first = $goal->startMonth;
        }
        if ($first->compare($month) > 0) {
            return [];
        }
        $calculator = $this->calculator($owner);

        return array_map(static fn(Month $m): array => ['month' => $m, 'value' => $goal->valueIn($calculator->snapshot($m))], Month::range($first, $month));
    }

    /**
     * Goals whose status changed between two evaluations of the same goals (before and after an entry).
     *
     * @param list<GoalProgress> $before
     * @param list<GoalProgress> $after
     *
     * @return list<array{name: string, from: GoalStatus, to: GoalStatus}>
     */
    public static function changes(array $before, array $after): array
    {
        $previous = [];
        foreach ($before as $progress) {
            $previous[$progress->goal->id] = $progress->status;
        }
        $changes = [];
        foreach ($after as $progress) {
            $from = $previous[$progress->goal->id] ?? null;
            if ($from !== null && $from !== $progress->status) {
                $changes[] = ['name' => $progress->goal->name, 'from' => $from, 'to' => $progress->status];
            }
        }

        return $changes;
    }

    /**
     * Target of a goal in months of spending for each month: months × spending (needs and wants) of the budget in force;
     * null for a goal in amount, or a month without budget (the amount saved with the goal then applies).
     *
     * @return (callable(Month): ?int)|null
     */
    private static function target(SavingsGoal $goal, ?BudgetBook $book): ?callable
    {
        $months = $goal->spendingMonths;
        if ($months === null || $book === null) {
            return null;
        }

        return static function (Month $month) use ($book, $months): ?int {
            $expenses = self::expenses($book, $month);

            return $expenses === null || $expenses <= 0 ? null : $months * $expenses;
        };
    }

    /**
     * Savings planned this month by the budget's items tied to an account of the goal's perimeter; null without any.
     *
     * @param list<Account> $accounts
     */
    private static function planned(SavingsGoal $goal, ?BudgetBook $book, Month $month, array $accounts): ?int
    {
        $budget = $book?->budgetAt($month);
        if ($book === null || $budget === null) {
            return null;
        }
        $perimeter = match ($goal->scope) {
            GoalScope::Accounts => $goal->accountIds,
            GoalScope::Level => array_map(static fn(Account $a): int => $a->id, array_values(array_filter($accounts, static fn(Account $a): bool => $a->level === $goal->level))),
            GoalScope::NetWorth => array_map(static fn(Account $a): int => $a->id, $accounts),
        };
        $planned = null;
        foreach ($book->lines as $line) {
            if ($line->accountId !== null && in_array($line->accountId, $perimeter, true)) {
                $planned = ($planned ?? 0) + $budget->amount($line->id);
            }
        }

        return $planned;
    }

    private static function expenses(BudgetBook $book, Month $month): ?int
    {
        $budget = $book->budgetAt($month);

        return $budget === null ? null : (new BudgetOverview($book->household, $budget, $book->categories, $book->lines))->expenses();
    }

    /** Spending target of the form this month (target in months of spending); refuses the form without a budget. */
    private function spendingTarget(UserId $owner, GoalForm $form, Month $month): ?int
    {
        if ($form->spendingMonths === null) {
            return null;
        }
        $book = $this->book($owner);
        $expenses = $book === null ? null : self::expenses($book, $month);
        if ($expenses === null || $expenses <= 0) {
            $form->refuseSpending();

            return null;
        }

        return $form->spendingMonths * $expenses;
    }

    private function book(UserId $owner): ?BudgetBook
    {
        $household = $this->budgets->household($owner);

        return $household === null ? null : new BudgetBook($household, $this->budgets->categories($owner), $this->budgets->lines($owner), $this->budgets->budgets($owner));
    }

    private function calculator(UserId $owner): NetWorthCalculator
    {
        return new NetWorthCalculator($this->accounts->all($owner), $this->statements->valuation($owner), new OwnershipShares($this->shares->all($owner)));
    }
}
