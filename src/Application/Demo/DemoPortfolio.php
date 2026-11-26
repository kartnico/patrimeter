<?php

declare(strict_types=1);

namespace Patrimeter\Application\Demo;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Budget\HouseholdBudget;
use Patrimeter\Application\Calendar;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Goal\GoalProgress;
use Patrimeter\Domain\Goal\GoalScope;
use Patrimeter\Domain\Goal\SavingsGoal;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\LoanMode;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\OwnershipShares;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\AccountTypeRepository;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\SavingsGoalRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;

/**
 * Example data of a fictitious household: a main residence held at 50 % with its loan, savings, life insurance,
 * stocks, retirement plan, SCPI and crypto-assets with a monthly history, a household budget (two adults, one
 * child, amounts saved three months ago then this month) and two savings goals, one behind and one ahead. Used by the demonstration accounts (DemoAccounts) and by the
 * users of the end-to-end tests and of the screenshots (tests/e2e/seed.php). Names in French or in English.
 */
final class DemoPortfolio
{
    /** Accounts: key => [type, French name, English name, first balance, monthly change]. */
    public const array ACCOUNTS = [
        'house' => ['residence_principale', 'Résidence principale', 'Main residence', 30_000_000, 2_000],
        'loan' => ['credit_immo_rp', 'Crédit résidence', 'Home loan', 20_000_000, -8_000],
        'livret_a' => ['livret_a', 'Livret A', 'Livret A', 500_000, 1_000],
        'ldds' => ['ldds', 'LDDS', 'LDDS', 300_000, 500],
        'current' => ['compte_courant', 'Compte courant', 'Current account', 200_000, 100],
        'euro_fund' => ['fonds_euros', 'Assurance-vie fonds euros', 'Life insurance, guaranteed fund', 2_000_000, 4_000],
        'pea' => ['pea', 'PEA', 'PEA', 1_500_000, 6_000],
        'cto' => ['cto', 'CTO', 'Securities account', 800_000, 3_000],
        'per' => ['per', 'PER', 'Retirement savings plan', 1_000_000, 3_500],
        'scpi' => ['scpi', 'SCPI', 'SCPI', 1_200_000, 2_500],
        'crypto' => ['crypto', 'Cryptoactifs', 'Crypto-assets', 100_000, 800],
    ];

    /** Household: first names of the two adults and of the child, in French then in English. */
    public const array HOUSEHOLD = ['fr' => ['Camille', 'Julien', 'Léa'], 'en' => ['Sam', 'Alex', 'Emma']];

    /**
     * Budget amounts, in cents, in the creation order of the suggested items: shared budget (housing, everyday life,
     * leisure, savings), then each adult's spending, leisure and savings.
     */
    private const array BUDGET = [
        150_801, 15_000, 16_000, 21_500, 6_000, 120_000, 5_000, 9_100, 20_000, 6_300, 10_000, 30_000, 15_000, 10_000,
        3_100, 5_000, 6_000, 4_000, 10_000, 0,
        2_000, 7_500, 6_000, 4_000, 20_000, 30_000,
    ];

    /** Items tied to the child: [suggested category (rank), French name, English name, amount]. */
    private const array CHILD_LINES = [[1, 'Cantine', 'School meals', 4_800], [2, 'Activités', 'Activities', 3_600]];

    /** Savings items tied to an account: rank of the item => account key. */
    private const array SAVINGS_LINES = [11 => 'ldds', 18 => 'livret_a', 25 => 'pea'];

    /** Share of the main residence held by the household, in millionths. */
    private const int HOUSE_SHARE = 500_000;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly AccountTypeRepository $types,
        private readonly StatementRepository $statements,
        private readonly OwnershipShareRepository $shares,
        private readonly HouseholdBudget $household,
        private readonly BudgetRepository $budgets,
        private readonly Calendar $calendar,
        private readonly Connection $connection,
        private readonly SavingsGoalRepository $goals,
    ) {}

    /**
     * Writes the example data of this user (vault unlocked), with $years years of monthly statements up to the month
     * before the current one. English names if $locale is "en", French otherwise.
     */
    public function seed(UserId $owner, string $locale, int $years): void
    {
        $english = $locale === 'en';
        $current = $this->calendar->currentMonth();
        $first = $current->addMonths(-12 * max(1, $years));
        $this->connection->transactional(function () use ($owner, $english, $current, $first): void {
            $ids = $this->createAccounts($owner, $english, $first);
            $this->createBudget($owner, $english, $current, $ids);
            $this->shares->save($owner, $ids['house'], $first, self::HOUSE_SHARE);
            foreach (self::ACCOUNTS as $key => [, , , $start, $step]) {
                $month = $first;
                for ($index = 0; $month->compare($current) < 0; ++$index, $month = $month->addMonths(1)) {
                    $this->statements->save($owner, $ids[$key], $month, $start + $index * $step);
                }
            }
            $this->createGoals($owner, $english, $current, $ids);
        });
    }

    /**
     * Two savings goals started six months ago: emergency savings well behind (the savings accounts barely grow), and
     * a down payment on the life insurance and the PEA, ahead.
     *
     * @param array<string, int> $ids account key => identifier
     */
    private function createGoals(UserId $owner, bool $english, Month $current, array $ids): void
    {
        $calculator = new NetWorthCalculator($this->accounts->all($owner), $this->statements->valuation($owner), new OwnershipShares($this->shares->all($owner)));
        $start = $current->addMonths(-GoalProgress::PACE_MONTHS);
        $goals = [
            [$english ? 'Emergency fund' : 'Épargne de précaution', GoalScope::Level, Level::Precaution, [], 400_000, 12],
            [$english ? 'Property down payment' : 'Apport immobilier', GoalScope::Accounts, null, [$ids['euro_fund'], $ids['pea']], 150_000, 24],
        ];
        foreach ($goals as [$name, $scope, $level, $accounts, $increase, $months]) {
            $draft = new SavingsGoal(0, $name, 0, $current, $scope, $level, $accounts, $start, 0);
            $now = $draft->valueIn($calculator->snapshot($current));
            $this->goals->create($owner, new SavingsGoal(0, $name, $now + $increase, $current->addMonths($months), $scope, $level, $accounts, $start, $draft->valueIn($calculator->snapshot($start))));
        }
    }

    /** @return array<string, int> account key => identifier */
    private function createAccounts(UserId $owner, bool $english, Month $first): array
    {
        $types = [];
        foreach ($this->types->all() as $type) {
            $types[$type['code']] = $type;
        }
        $ids = [];
        foreach (self::ACCOUNTS as $key => [$code, $french, $englishName]) {
            $type = $types[$code];
            $ids[$key] = $this->accounts->create($owner, [
                'name' => $english ? $englishName : $french,
                'type_id' => $type['id'],
                'level_code' => $type['default_level'],
                'nature' => $type['nature'],
                'opened_on' => $first->toString() . '-01',
            ]);
        }
        if (isset($ids['loan'], $ids['house'])) {
            $this->accounts->linkLoan($owner, $ids['loan'], $ids['house'], LoanMode::Prorata);
        }

        return $ids;
    }

    /** @param array<string, int> $ids account key => identifier */
    private function createBudget(UserId $owner, bool $english, Month $current, array $ids): void
    {
        [$firstAdult, $secondAdult, $child] = self::HOUSEHOLD[$english ? 'en' : 'fr'];
        $this->household->saveHousehold($owner, new Household([1 => $firstAdult, 2 => $secondAdult], [1 => $child], 4, 2, [1 => 1987, 2 => 1985], [1 => 2016]), $english ? 'en' : 'fr');
        $categories = $this->budgets->categories($owner);
        $amounts = [];
        foreach ($this->budgets->lines($owner) as $index => $line) {
            $amounts[$line->id] = self::BUDGET[$index] ?? 0;
        }
        foreach (self::CHILD_LINES as [$rank, $french, $englishName, $cents]) {
            $amounts[$this->budgets->createLine($owner, $categories[$rank]->id, $english ? $englishName : $french, 1)] = $cents;
        }
        $lines = $this->budgets->lines($owner);
        foreach (self::SAVINGS_LINES as $index => $key) {
            if (isset($lines[$index])) {
                $line = $lines[$index];
                $this->budgets->updateLine($owner, new BudgetLine($line->id, $line->categoryId, $line->name, null, $ids[$key]));
            }
        }
        $earlier = array_map(static fn(int $cents): int => intdiv($cents * 95, 100), $amounts);
        $this->budgets->saveBudget($owner, new MonthlyBudget($current->addMonths(-3), [1 => 180_000, 2 => 400_000], $earlier));
        $this->budgets->saveBudget($owner, new MonthlyBudget($current, [1 => 186_000, 2 => 414_300], $amounts));
    }
}
