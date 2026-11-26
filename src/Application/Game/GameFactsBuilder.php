<?php

declare(strict_types=1);

namespace Patrimeter\Application\Game;

use Patrimeter\Application\Budget\HouseholdBudget;
use Patrimeter\Application\Goals\SavingsGoals;
use Patrimeter\Application\PropertyPortfolio;
use Patrimeter\Domain\Account;
use Patrimeter\Domain\Budget\BudgetBook;
use Patrimeter\Domain\Game\GameFacts;
use Patrimeter\Domain\Goal\GoalProgress;
use Patrimeter\Domain\Goal\GoalStatus;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\OwnershipShares;
use Patrimeter\Domain\UserId;
use Patrimeter\Domain\Valuation;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\AccountTypeRepository;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\SavingsGoalRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Infrastructure\Persistence\WebAuthnCredentialRepository;

/**
 * Reads what the challenges measure (GameFacts) from the repositories of the user's own data: statements (months
 * entered, complete months, consumer loans), budget (household, months saved, emergency savings coverage), goals and
 * passkeys. The owner is always the signed-in user, passed by the caller.
 */
final class GameFactsBuilder
{
    /** Code of the consumer loan account type (account_types table). */
    private const string CONSUMER_CREDIT = 'credit_conso';

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly AccountTypeRepository $types,
        private readonly StatementRepository $statements,
        private readonly OwnershipShareRepository $shares,
        private readonly BudgetRepository $budgets,
        private readonly HouseholdBudget $budget,
        private readonly SavingsGoals $goals,
        private readonly WebAuthnCredentialRepository $passkeys,
        private readonly SavingsGoalRepository $goalRepository,
    ) {}

    /**
     * The facts of the first steps only (FirstStep), the other ones left empty: for the dashboard's card, without
     * decrypting the statements nor working out the goals. Never evaluated as challenges.
     */
    public function firstStepFacts(UserId $owner, Month $month): GameFacts
    {
        $accounts = $this->accounts->all($owner);

        return new GameFacts(
            month: $month,
            enteredMonths: $this->statements->hasStatementIn($owner, $month) ? [$month] : [],
            accountCount: count(array_filter($accounts, static fn(Account $account): bool => !PropertyPortfolio::isProperty($account))),
            hasHousehold: $this->budgets->household($owner) !== null,
            hasProperty: array_filter($accounts, PropertyPortfolio::isProperty(...)) !== [],
            goalCount: $this->goalRepository->count($owner),
            passkeys: count($this->passkeys->all($owner)),
        );
    }

    /** @param int|null $savingsRateTarget savings rate aimed at, chosen by the user (game settings) */
    public function facts(UserId $owner, Month $month, ?int $savingsRateTarget = null): GameFacts
    {
        $accounts = $this->accounts->all($owner);
        $statements = $this->statements->all($owner);
        $valuation = new Valuation($statements);
        $entered = self::enteredMonths($statements, $month);
        $first = $entered[0] ?? null;
        $snapshot = (new NetWorthCalculator($accounts, $valuation, new OwnershipShares($this->shares->all($owner))))->snapshot($month);
        $precaution = $this->budget->precaution($owner, $month, $snapshot);
        $coverage = $precaution['coverage']->months();
        $progress = $this->goals->progress($owner, $month);
        $best = self::bestGoal($progress);

        return new GameFacts(
            month: $month,
            enteredMonths: $entered,
            completeMonths: $first === null ? 0 : self::completeMonths($accounts, $statements, $first, $month),
            accountCount: count(array_filter($accounts, static fn(Account $account): bool => !PropertyPortfolio::isProperty($account))),
            hasHousehold: $this->budgets->household($owner) !== null,
            hasProperty: array_filter($accounts, PropertyPortfolio::isProperty(...)) !== [],
            precautionMonths: $precaution['budgeted'] && $coverage !== null ? (int) floor($coverage) : null,
            precautionTarget: $precaution['coverage']->targetMonths,
            consumerCreditFreeMonths: $first === null ? 0 : $this->consumerCreditFreeMonths($accounts, $valuation, $first, $month),
            budgetMonths: count(array_filter($this->budgets->months($owner), static fn(Month $m): bool => $m->compare($month) <= 0)),
            goalCount: count($progress),
            goalName: $best?->goal->name,
            goalPercent: $best?->percent(),
            goalReached: array_filter($progress, static fn(GoalProgress $p): bool => $p->status === GoalStatus::Reached) !== [],
            passkeys: count($this->passkeys->all($owner)),
            savingsRateTarget: $savingsRateTarget,
            savingsRateMonths: $savingsRateTarget === null ? 0 : $this->savingsRateMonths($owner, $month, $savingsRateTarget),
        );
    }

    /** Months in a row whose budget plans at least the savings rate aimed at (0 without a household). */
    private function savingsRateMonths(UserId $owner, Month $month, int $percent): int
    {
        $household = $this->budgets->household($owner);
        if ($household === null) {
            return 0;
        }

        return (new BudgetBook($household, $this->budgets->categories($owner), $this->budgets->lines($owner), $this->budgets->budgets($owner)))
            ->savingsRateMonths($month, $percent);
    }

    /**
     * @param array<int, array<string, int>> $statements account => (YYYY-MM => cents)
     *
     * @return list<Month> months up to $month with at least one balance entered, ascending
     */
    private static function enteredMonths(array $statements, Month $month): array
    {
        $months = [];
        foreach ($statements as $byMonth) {
            foreach (array_keys($byMonth) as $key) {
                $months[$key] = true;
            }
        }
        ksort($months);
        $entered = [];
        foreach (array_keys($months) as $key) {
            $candidate = Month::fromString($key);
            if ($candidate->compare($month) <= 0) {
                $entered[] = $candidate;
            }
        }

        return $entered;
    }

    /**
     * Months from the first one entered where every account open in that month has its own balance (not carried).
     *
     * @param list<Account>                  $accounts
     * @param array<int, array<string, int>> $statements
     */
    private static function completeMonths(array $accounts, array $statements, Month $first, Month $month): int
    {
        $complete = 0;
        foreach (Month::range($first, $month) as $candidate) {
            $open = array_filter($accounts, static fn(Account $account): bool => $account->isActiveIn($candidate));
            if ($open === []) {
                continue;
            }
            foreach ($open as $account) {
                if (!isset($statements[$account->id][$candidate->toString()])) {
                    continue 2;
                }
            }
            ++$complete;
        }

        return $complete;
    }

    /**
     * Months in a row, back from $month, without any consumer loan left to repay (value carried over included).
     *
     * @param list<Account> $accounts
     */
    private function consumerCreditFreeMonths(array $accounts, Valuation $valuation, Month $first, Month $month): int
    {
        $types = [];
        foreach ($this->types->all() as $type) {
            $types[$type['id']] = $type['code'];
        }
        $loans = array_filter($accounts, static fn(Account $account): bool => ($types[$account->typeId] ?? null) === self::CONSUMER_CREDIT);
        $free = 0;
        for ($candidate = $month; $candidate->compare($first) >= 0; $candidate = $candidate->previous()) {
            foreach ($loans as $loan) {
                if ($loan->isActiveIn($candidate) && ($valuation->valueAt($loan->id, $candidate)->cents ?? 0) > 0) {
                    return $free;
                }
            }
            ++$free;
        }

        return $free;
    }

    /** @param list<GoalProgress> $progress */
    private static function bestGoal(array $progress): ?GoalProgress
    {
        $best = null;
        foreach ($progress as $line) {
            if ($best === null || $line->percent() > $best->percent()) {
                $best = $line;
            }
        }

        return $best;
    }
}
