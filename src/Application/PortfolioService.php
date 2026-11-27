<?php

declare(strict_types=1);

namespace Patrimeter\Application;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Budget\HouseholdBudget;
use Patrimeter\Application\Form\AccountForm;
use Patrimeter\Application\Form\Violations;
use Patrimeter\Application\Game\Game;
use Patrimeter\Application\Goals\SavingsGoals;
use Patrimeter\Application\Payload\AccountPayload;
use Patrimeter\Application\Payload\ClosePayload;
use Patrimeter\Application\Payload\LoanLinkPayload;
use Patrimeter\Application\Payload\SharePayload;
use Patrimeter\Application\Payload\StatementPayload;
use Patrimeter\Domain\Account;
use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Game\ChallengeResult;
use Patrimeter\Domain\Game\SaverLevel;
use Patrimeter\Domain\Goal\GoalProgress;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\LoanMode;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\NetWorthSnapshot;
use Patrimeter\Domain\OwnershipShares;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\AccountTypeRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Reads and writes of the API and MCP, on the same repositories and with the same rules as the
 * pages: the owner always comes from CurrentUser, and amounts stay in cents.
 */
final class PortfolioService
{
    /** Shown with every answer about the challenges (not investment advice). */
    public const string CHALLENGES_NOTICE = 'Repères indicatifs, à adapter à votre situation : ce n\'est pas un conseil en investissement.';

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly AccountTypeRepository $types,
        private readonly StatementRepository $statements,
        private readonly OwnershipShareRepository $shares,
        private readonly PropertyPortfolio $properties,
        private readonly Calendar $calendar,
        private readonly Connection $connection,
        private readonly ValidatorInterface $validator,
        private readonly SavingsGoals $goals,
        private readonly HouseholdBudget $budget,
        private readonly Game $game,
    ) {}

    /**
     * Savings goals and where each one stands in the month (current month by default).
     *
     * @return list<array<string, mixed>>
     */
    public function goals(UserId $owner, ?string $month): array
    {
        $at = $this->month($month, 'month') ?? $this->calendar->currentMonth();

        return array_map(static fn(GoalProgress $progress): array => [
            'id' => $progress->goal->id,
            'name' => $progress->goal->name,
            'target_cents' => $progress->goal->targetCents,
            'target_month' => $progress->goal->targetMonth->toString(),
            'spending_months' => $progress->goal->spendingMonths,
            'scope' => $progress->goal->scope->value,
            'level' => $progress->goal->level?->value,
            'account_ids' => $progress->goal->accountIds,
            'start_month' => $progress->goal->startMonth->toString(),
            'start_cents' => $progress->goal->startCents,
            'month' => $progress->month->toString(),
            'value_cents' => $progress->value,
            'status' => $progress->status->value,
            'required_cents' => $progress->required,
            'pace_cents' => $progress->pace,
            'pace_months' => $progress->paceMonths,
            'projected_month' => $progress->projectedMonth?->toString(),
            'planned_cents' => $progress->planned,
            'overdue' => $progress->overdue,
        ], $this->goals->progress($owner, $at));
    }

    /**
     * Household budget in force for the month (current month by default): household, totals, categories and items with
     * their amounts; null household if the budget is not set up.
     *
     * @return array<string, mixed>
     */
    public function budget(UserId $owner, ?string $month): array
    {
        $at = $this->month($month, 'month') ?? $this->calendar->currentMonth();
        $view = $this->budget->view($owner, $at);
        $overview = $view->overview;
        if ($view->household === null || $overview === null) {
            return ['month' => $at->toString(), 'household' => null, 'entered_month' => null, 'totals' => null, 'categories' => []];
        }
        $household = $view->household;

        return [
            'month' => $at->toString(),
            'household' => [
                'adults' => array_values($household->adults),
                'children' => array_values($household->children),
                'precaution_months' => $household->precautionMonths,
            ],
            'entered_month' => $overview->budget->month->toString(),
            'totals' => [
                'income_cents' => $overview->income,
                'needs_cents' => $overview->groupTotal(BudgetGroup::Needs),
                'wants_cents' => $overview->groupTotal(BudgetGroup::Wants),
                'savings_cents' => $overview->savings(),
                'available_cents' => $overview->available(),
                'joint_cents' => $overview->jointTotal,
            ],
            'categories' => array_map(static fn(BudgetCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'group' => $category->group->value,
                'adult' => $category->adult,
                'total_cents' => $overview->categoryTotal($category->id),
                'lines' => array_map(static fn(BudgetLine $line): array => [
                    'id' => $line->id,
                    'name' => $line->name,
                    'amount_cents' => $overview->budget->amount($line->id),
                    'account_id' => $line->accountId,
                ], $overview->lines($category->id)),
            ], $overview->categories),
        ];
    }

    /**
     * Challenges and stars of this month: level, saver stage and style, entry streak, and each challenge with its best
     * stars (never lowered) and where it stands. Disabled (instance or user): enabled false and nothing else measured.
     *
     * @return array<string, mixed>
     */
    public function challenges(UserId $owner): array
    {
        if (!$this->game->isEnabled($owner)) {
            return ['enabled' => false, 'notice' => self::CHALLENGES_NOTICE];
        }
        $view = $this->game->view($owner);
        $level = $view->level();
        $streak = $view->streak();

        return [
            'enabled' => true,
            'notice' => self::CHALLENGES_NOTICE,
            'month' => $view->facts->month->toString(),
            'stars' => $view->totalStars(),
            'max_stars' => SaverLevel::maxStars(),
            'level' => ['number' => $level->number, 'levels' => SaverLevel::count(), 'next_at' => $level->next],
            'stage' => $view->stage()?->value,
            'style' => $view->style?->value,
            'streak' => ['months' => $streak->length, 'joker_available' => $streak->jokerLeftIn($view->facts->month->year)],
            'challenges' => array_map(static fn(ChallengeResult $result): array => [
                'id' => $result->challenge->value,
                'stars' => $view->stars($result->challenge),
                'max_stars' => $result->maxStars(),
                'value' => $result->value,
                'thresholds' => $result->thresholds,
                'available' => $result->available,
            ], $view->results),
        ];
    }

    /** @return array<string, mixed> */
    public function netWorth(UserId $owner, ?string $month): array
    {
        $at = $this->month($month, 'month') ?? $this->calendar->currentMonth();

        return self::snapshot($this->calculator($owner)->snapshot($at));
    }

    /** Net worth of the month, by level (pyramid of the welcome pages). */
    public function netWorthSnapshot(UserId $owner, Month $month): NetWorthSnapshot
    {
        return $this->calculator($owner)->snapshot($month);
    }

    /** @return list<array<string, mixed>> one snapshot per month, from the first statement to the current month by default */
    public function history(UserId $owner, ?string $from, ?string $to): array
    {
        $calculator = $this->calculator($owner);
        $start = $this->month($from, 'from') ?? $calculator->firstMonth();
        $end = $this->month($to, 'to') ?? $this->calendar->currentMonth();
        if ($start === null || $start->compare($end) > 0) {
            return [];
        }

        return array_map(self::snapshot(...), $calculator->series($start, $end));
    }

    /** @return list<array<string, mixed>> */
    public function accountList(UserId $owner): array
    {
        $month = $this->calendar->currentMonth();
        $valuation = $this->statements->latestValuation($owner, $month);

        return array_map(
            static function (Account $account) use ($valuation, $month): array {
                $value = $valuation->valueAt($account->id, $month);

                return self::account($account) + ['value' => $value === null ? null : ['amount_cents' => $value->cents, 'month' => $value->sourceMonth->toString(), 'carried' => $value->carried]];
            },
            $this->accounts->all($owner),
        );
    }

    /** @return array<string, mixed> */
    public function accountDetail(UserId $owner, int $id): array
    {
        $account = $this->find($owner, $id);

        return self::account($account) + [
            'statements' => $this->statementsOf($owner, $account),
            'shares' => $this->sharesOf($owner, $account),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function accountTypes(): array
    {
        return array_map(static fn(array $type): array => [
            'id' => $type['id'],
            'code' => $type['code'],
            'label' => $type['label'],
            'default_level' => $type['default_level'],
            'nature' => $type['nature'],
        ], $this->types->all());
    }

    /** @return list<array<string, mixed>> */
    public function propertyList(UserId $owner, ?string $month): array
    {
        $at = $this->month($month, 'month') ?? $this->calendar->currentMonth();

        return array_map(static fn(array $line): array => [
            'account_id' => $line['account']->id,
            'name' => $line['account']->name,
            'total_cents' => $line['total'],
            'share_millionths' => $line['share'],
            'retained_cents' => $line['retained'],
            'owed_cents' => $line['owed'],
            'net_cents' => $line['net'],
            'loans' => array_map(static fn(array $loan): array => [
                'account_id' => $loan['account']->id,
                'name' => $loan['account']->name,
                'total_cents' => $loan['total'],
                'retained_cents' => $loan['retained'],
            ], $line['loans']),
        ], $this->properties->lines($owner, $at));
    }

    /** @return array<string, mixed> the account created */
    public function createAccount(UserId $owner, AccountPayload $payload): array
    {
        $form = $this->form($payload);
        if (!$form->isValid()) {
            throw new ValidationFailed($form->errors);
        }
        $initial = is_int($payload->initial_amount_cents) ? $payload->initial_amount_cents : null;
        $id = $this->connection->transactional(function () use ($owner, $form, $initial): int {
            $id = $this->accounts->create($owner, $form->data);
            if ($initial !== null) {
                $month = $this->calendar->currentMonth();
                $opened = Month::fromDate($form->data['opened_on']);
                $this->statements->save($owner, $id, $opened->compare($month) > 0 ? $opened : $month, $initial);
            }

            return $id;
        });

        return $this->accountDetail($owner, $id);
    }

    /** @return array<string, mixed> the account changed */
    public function updateAccount(UserId $owner, int $id, AccountPayload $payload): array
    {
        $account = $this->find($owner, $id);
        $form = $this->form($payload);
        $errors = $form->errors;
        if ($errors === [] && $account->closedOn !== null && $form->data['opened_on'] > $account->closedOn) {
            $errors['opened_on'] = 'La date d\'ouverture doit précéder la date de clôture.';
        }
        if ($errors === []) {
            $errors = $this->properties->conflicts($owner, $account, $form->data);
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }
        $this->accounts->update($owner, $account->id, $form->data);

        return $this->accountDetail($owner, $id);
    }

    public function deleteAccount(UserId $owner, int $id): void
    {
        $this->accounts->delete($owner, $this->find($owner, $id)->id);
    }

    /** @return array<string, mixed> */
    public function archiveAccount(UserId $owner, int $id, ClosePayload $payload): array
    {
        $this->check($payload);
        $account = $this->find($owner, $id);
        if ($payload->closed_on < $account->openedOn) {
            throw new ValidationFailed(['closed_on' => 'Date de clôture invalide (elle doit suivre la date d\'ouverture).']);
        }
        $this->accounts->archive($owner, $account->id, $payload->closed_on);

        return $this->accountDetail($owner, $id);
    }

    /** @return array<string, mixed> */
    public function unarchiveAccount(UserId $owner, int $id): array
    {
        $this->accounts->unarchive($owner, $this->find($owner, $id)->id);

        return $this->accountDetail($owner, $id);
    }

    /** @return list<array{month: string, amount_cents: int}> */
    public function statements(UserId $owner, int $id): array
    {
        return $this->statementsOf($owner, $this->find($owner, $id));
    }

    public function saveStatement(UserId $owner, int $id, string $month, StatementPayload $payload): void
    {
        $this->check($payload);
        $account = $this->find($owner, $id);
        $at = $this->month($month, 'month');
        if ($at === null || !$account->isActiveIn($at)) {
            throw new ValidationFailed(['month' => 'Le compte n\'est pas ouvert ce mois-là.']);
        }
        $this->statements->save($owner, $account->id, $at, self::integer($payload->amount_cents));
    }

    public function deleteStatement(UserId $owner, int $id, string $month): void
    {
        $account = $this->find($owner, $id);
        $at = $this->month($month, 'month');
        if ($at !== null) {
            $this->statements->delete($owner, $account->id, $at);
        }
    }

    /** @return list<array{month: string, share_millionths: int}> */
    public function shares(UserId $owner, int $id): array
    {
        return $this->sharesOf($owner, $this->find($owner, $id));
    }

    public function saveShare(UserId $owner, int $id, string $month, SharePayload $payload): void
    {
        $this->check($payload);
        $account = $this->find($owner, $id);
        $at = $this->month($month, 'month');
        if ($at === null || !PropertyPortfolio::isProperty($account)) {
            throw new ValidationFailed(['month' => 'Part réservée aux biens immobiliers, avec un mois valide.']);
        }
        $this->shares->save($owner, $account->id, $at, self::integer($payload->share_millionths));
    }

    public function deleteShare(UserId $owner, int $id, string $month): void
    {
        $account = $this->find($owner, $id);
        $at = $this->month($month, 'month');
        if ($at !== null) {
            $this->shares->delete($owner, $account->id, $at);
        }
    }

    /** Links a loan to a property, or unlinks it with a null property. */
    public function linkLoan(UserId $owner, int $id, LoanLinkPayload $payload): void
    {
        $this->check($payload);
        $account = $this->find($owner, $id);
        $asset = is_int($payload->asset_id) ? $this->accounts->find($owner, $payload->asset_id) : null;
        if (!$account->isLiability() || ($payload->asset_id !== null && ($asset === null || !PropertyPortfolio::isProperty($asset)))) {
            throw new ValidationFailed(['asset_id' => 'Choisissez un bien, pour un crédit.']);
        }
        $this->accounts->linkLoan($owner, $account->id, $asset?->id, LoanMode::tryFrom($payload->loan_mode ?? '') ?? LoanMode::Prorata);
    }

    /** Value already validated by Type('integer'). */
    private static function integer(mixed $value): int
    {
        return is_int($value) ? $value : throw new \LogicException('Entier attendu après validation.');
    }

    /** Constraints on the data received (the API already checks them; MCP goes through here too). */
    private function check(object $payload): void
    {
        $errors = Violations::byField($this->validator->validate($payload), 1);
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }
    }

    private function find(UserId $owner, int $id): Account
    {
        return $this->accounts->find($owner, $id) ?? throw new NotFound('Compte introuvable.');
    }

    private function calculator(UserId $owner): NetWorthCalculator
    {
        return new NetWorthCalculator($this->accounts->all($owner), $this->statements->valuation($owner), new OwnershipShares($this->shares->all($owner)));
    }

    private function month(?string $value, string $field): ?Month
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Month::tryFromString($value) ?? throw new ValidationFailed([$field => 'Mois invalide (AAAA-MM).']);
    }

    private function form(AccountPayload $payload): AccountForm
    {
        $this->check($payload);

        return AccountForm::validate($this->validator, [
            'name' => $payload->name,
            'type_id' => is_int($payload->type_id) ? (string) $payload->type_id : '',
            'level_code' => $payload->level ?? '',
            'nature' => $payload->nature ?? '',
            'opened_on' => $payload->opened_on,
        ], $this->types->all());
    }

    /** @return list<array{month: string, amount_cents: int}> */
    private function statementsOf(UserId $owner, Account $account): array
    {
        $rows = [];
        foreach ($this->statements->forAccount($owner, $account->id) as $month => $cents) {
            $rows[] = ['month' => $month, 'amount_cents' => $cents];
        }

        return $rows;
    }

    /** @return list<array{month: string, share_millionths: int}> */
    private function sharesOf(UserId $owner, Account $account): array
    {
        $rows = [];
        foreach ($this->shares->all($owner)[$account->id] ?? [] as $month => $share) {
            $rows[] = ['month' => $month, 'share_millionths' => $share];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private static function account(Account $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'type_id' => $account->typeId,
            'type' => $account->typeLabel,
            'level' => $account->level->value,
            'nature' => $account->nature->value,
            'opened_on' => $account->openedOn,
            'closed_on' => $account->closedOn,
            'linked_asset_id' => $account->linkedAssetId,
            'loan_mode' => $account->linkedAssetId === null ? null : $account->loanMode->value,
        ];
    }

    /** @return array<string, mixed> */
    private static function snapshot(NetWorthSnapshot $snapshot): array
    {
        return [
            'month' => $snapshot->month->toString(),
            'has_data' => $snapshot->hasData(),
            'net_cents' => $snapshot->net(),
            'assets_cents' => $snapshot->assets(),
            'liabilities_cents' => $snapshot->liabilities(),
            'levels' => array_map(static fn(Level $level): array => [
                'code' => $level->value,
                'label' => $level->label(),
                'net_cents' => $snapshot->level($level)->net(),
                'assets_cents' => $snapshot->level($level)->assets,
                'liabilities_cents' => $snapshot->level($level)->liabilities,
            ], Level::ordered()),
        ];
    }
}
