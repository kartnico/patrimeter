<?php

declare(strict_types=1);

namespace Patrimeter\Application\Transfer;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Calendar;
use Patrimeter\Application\Payload\AccountPayload;
use Patrimeter\Application\Payload\ClosePayload;
use Patrimeter\Application\Payload\LoanLinkPayload;
use Patrimeter\Application\Payload\SharePayload;
use Patrimeter\Application\Payload\StatementPayload;
use Patrimeter\Application\PortfolioService;
use Patrimeter\Application\ValidationFailed;
use Patrimeter\Domain\Goal\GoalScope;
use Patrimeter\Domain\Goal\SavingsGoal;
use Patrimeter\Domain\Indicator\CustomIndicator;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\CustomIndicatorRepository;
use Patrimeter\Infrastructure\Persistence\SavingsGoalRepository;

/**
 * Export and import of a user's data, from one Patrimeter instance to another: accounts, statements, shares and links
 * between properties and loans, the household budget (BudgetTransfer), the savings goals and the custom indicators, in
 * clear in a versioned JSON file
 * ("patrimeter" format, docs/export.md). The import goes through PortfolioService, like the API: same validations,
 * data encrypted with the key of the importing user. All or nothing, into a still empty account.
 * A file of version 1 (without budget), 2 (without savings goals) or 3 (without indicators) can still be imported.
 */
final class DataTransfer
{
    public const string FORMAT = 'patrimeter';
    public const int VERSION = 4;
    /** Readable versions: 1 is 2 without budget, 2 is 3 without savings goals, 3 is 4 without custom indicators. */
    public const array READABLE_VERSIONS = [1, 2, 3, 4];
    public const int MAX_BYTES = 2 * 1024 * 1024;
    public const int MAX_ACCOUNTS = 500;
    public const int MAX_ROWS_PER_ACCOUNT = 1200;

    public function __construct(
        private readonly PortfolioService $portfolio,
        private readonly Connection $connection,
        private readonly Calendar $calendar,
        private readonly BudgetTransfer $budget,
        private readonly SavingsGoalRepository $goals,
        private readonly CustomIndicatorRepository $indicators,
    ) {}

    /** @return array<string, mixed> */
    public function export(UserId $owner): array
    {
        $codes = array_column($this->portfolio->accountTypes(), 'code', 'id');
        $accounts = [];
        foreach ($this->portfolio->accountList($owner) as $line) {
            $detail = $this->portfolio->accountDetail($owner, self::int($line['id'] ?? null));
            $typeId = self::int($detail['type_id'] ?? null);
            $accounts[] = [
                'ref' => $detail['id'],
                'name' => $detail['name'],
                'type' => $codes[$typeId] ?? null,
                'level' => $detail['level'],
                'nature' => $detail['nature'],
                'opened_on' => $detail['opened_on'],
                'closed_on' => $detail['closed_on'],
                'linked_asset_ref' => $detail['linked_asset_id'],
                'loan_mode' => $detail['loan_mode'],
                'statements' => $detail['statements'],
                'shares' => $detail['shares'],
            ];
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_on' => $this->calendar->today(),
            'accounts' => $accounts,
            'budget' => $this->budget->export($owner),
            'goals' => array_map(static fn(SavingsGoal $goal): array => [
                'name' => $goal->name,
                'target_cents' => $goal->targetCents,
                'target_month' => $goal->targetMonth->toString(),
                'scope' => $goal->scope->value,
                'level' => $goal->level?->value,
                'account_refs' => $goal->accountIds,
                'start_month' => $goal->startMonth->toString(),
                'start_cents' => $goal->startCents,
                'spending_months' => $goal->spendingMonths,
            ], $this->goals->all($owner)),
            'indicators' => array_map(static fn(CustomIndicator $indicator): array => [
                'name' => $indicator->name,
                'levels' => array_map(static fn(Level $level): string => $level->value, $indicator->levels),
                'account_refs' => $indicator->accountIds,
                'share_of_net_worth' => $indicator->shareOfNetWorth,
            ], $this->indicators->all($owner)),
        ];
    }

    /**
     * @return int number of accounts imported
     *
     * @throws ValidationFailed unreadable file, non-empty account or refused data (nothing is imported)
     */
    public function import(UserId $owner, string $json): int
    {
        $data = ExportReader::decode($json);
        if ($this->portfolio->accountList($owner) !== []) {
            throw new ValidationFailed(['file' => 'L\'import se fait dans un compte encore vide : celui-ci contient déjà des comptes.']);
        }
        $types = array_column($this->portfolio->accountTypes(), 'id', 'code');
        $accounts = $data['accounts'];
        $budget = $data['budget'];
        $goals = $data['goals'];
        $indicators = $data['indicators'];

        return $this->connection->transactional(function () use ($owner, $accounts, $types, $budget, $goals, $indicators): int {
            $ids = [];
            foreach (array_keys($accounts) as $index) {
                $account = self::record($accounts[$index], $index);
                $label = self::name($account, $index);
                $type = $types[self::text($account['type'] ?? null)] ?? null;
                if (!is_int($type)) {
                    throw new ValidationFailed(['file' => "Compte « $label » : type de compte inconnu."]);
                }
                $created = $this->guard($label, fn(): array => $this->portfolio->createAccount($owner, new AccountPayload(
                    name: self::text($account['name'] ?? null),
                    type_id: $type,
                    level: self::nullableText($account['level'] ?? null),
                    nature: self::nullableText($account['nature'] ?? null),
                    opened_on: self::text($account['opened_on'] ?? null),
                )));
                $id = self::int($created['id'] ?? null);
                $ids[self::key($account['ref'] ?? $index)] = $id;
                foreach (self::rows($account['statements'] ?? [], $label) as $row) {
                    $this->guard($label, fn() => $this->portfolio->saveStatement($owner, $id, self::text($row['month'] ?? null), new StatementPayload($row['amount_cents'] ?? null)));
                }
                foreach (self::rows($account['shares'] ?? [], $label) as $row) {
                    $this->guard($label, fn() => $this->portfolio->saveShare($owner, $id, self::text($row['month'] ?? null), new SharePayload($row['share_millionths'] ?? null)));
                }
            }
            // Links and closings once all the accounts are created: a loan can come before its property in the file.
            foreach (array_keys($accounts) as $index) {
                $account = self::record($accounts[$index], $index);
                $label = self::name($account, $index);
                $id = $ids[self::key($account['ref'] ?? $index)];
                if (($account['linked_asset_ref'] ?? null) !== null) {
                    $asset = $ids[self::key($account['linked_asset_ref'])] ?? null;
                    $this->guard($label, fn() => $this->portfolio->linkLoan($owner, $id, new LoanLinkPayload($asset, self::nullableText($account['loan_mode'] ?? null))));
                }
                $closed = self::nullableText($account['closed_on'] ?? null);
                if ($closed !== null) {
                    $this->guard($label, fn(): array => $this->portfolio->archiveAccount($owner, $id, new ClosePayload($closed)));
                }
            }
            $this->budget->import($owner, $budget, $ids);
            foreach (array_keys($goals) as $index) {
                $this->goals->create($owner, self::goal($goals[$index], $index, $ids));
            }
            foreach (array_keys($indicators) as $index) {
                $this->indicators->create($owner, self::indicator($indicators[$index], $index, $ids));
            }

            return count($accounts);
        });
    }

    /**
     * PortfolioService operation; its refusal names the account concerned.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function guard(string $label, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationFailed $failure) {
            throw new ValidationFailed(['file' => "Compte « $label » : " . implode(' ', $failure->errors)]);
        }
    }

    /** @return array<mixed> */
    private static function record(mixed $account, int $index): array
    {
        if (!is_array($account)) {
            throw new ValidationFailed(['file' => 'Compte n° ' . ($index + 1) . ' illisible.']);
        }

        return $account;
    }

    /** @param array<mixed> $account */
    private static function name(array $account, int $index): string
    {
        $name = self::text($account['name'] ?? null);

        return $name !== '' ? mb_substr($name, 0, 80) : 'n° ' . ($index + 1);
    }

    /** @return list<array<mixed>> */
    private static function rows(mixed $rows, string $label): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_ROWS_PER_ACCOUNT) {
            throw new ValidationFailed(['file' => "Compte « $label » : relevés ou parts illisibles."]);
        }
        $checked = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new ValidationFailed(['file' => "Compte « $label » : relevés ou parts illisibles."]);
            }
            $checked[] = $row;
        }

        return $checked;
    }

    private static function key(mixed $ref): string
    {
        return is_int($ref) || is_string($ref) ? (string) $ref : '';
    }

    /**
     * Savings goal of the file, its accounts given by their reference in the file.
     *
     * @param array<int|string, int> $ids identifier of each account created, by reference
     *
     * @throws ValidationFailed
     */
    private static function goal(mixed $goal, int $index, array $ids): SavingsGoal
    {
        $record = is_array($goal) ? $goal : [];
        $name = trim(self::text($record['name'] ?? null));
        $target = $record['target_cents'] ?? null;
        $start = $record['start_cents'] ?? null;
        $targetMonth = Month::tryFromString(self::text($record['target_month'] ?? null));
        $startMonth = Month::tryFromString(self::text($record['start_month'] ?? null));
        $scope = GoalScope::tryFrom(self::text($record['scope'] ?? null));
        $level = Level::tryFrom(self::text($record['level'] ?? null));
        $accounts = self::goalAccounts($record['account_refs'] ?? null, $ids, $index);
        $spending = $record['spending_months'] ?? null;
        if (!SavingsGoal::isValidName($name) || !is_int($target) || $target < 1 || $target > SavingsGoal::TARGET_MAX || !is_int($start) || abs($start) > Money::MAX_CENTS
            || $targetMonth === null || $startMonth === null || $scope === null
            || ($scope === GoalScope::Level && $level === null) || ($scope === GoalScope::Accounts && $accounts === [])
            || ($spending !== null && (!is_int($spending) || $spending < 1 || $spending > SavingsGoal::SPENDING_MONTHS_MAX))) {
            throw new ValidationFailed(['file' => sprintf('Objectif %d : nom, montant, mois ou périmètre invalides.', $index + 1)]);
        }

        return new SavingsGoal(0, $name, $target, $targetMonth, $scope, $scope === GoalScope::Level ? $level : null, $scope === GoalScope::Accounts ? $accounts : [], $startMonth, $start, is_int($spending) ? $spending : null);
    }

    /**
     * Custom indicator of the file, its accounts given by their reference in the file.
     *
     * @param array<int|string, int> $ids identifier of each account created, by reference
     *
     * @throws ValidationFailed
     */
    private static function indicator(mixed $indicator, int $index, array $ids): CustomIndicator
    {
        $record = is_array($indicator) ? $indicator : [];
        $name = trim(self::text($record['name'] ?? null));
        $levels = self::levels($record['levels'] ?? null);
        $accounts = self::goalAccounts($record['account_refs'] ?? null, $ids, $index);
        if (!CustomIndicator::isValidName($name) || $levels === null || ($levels === [] && $accounts === [])) {
            throw new ValidationFailed(['file' => sprintf('Indicateur %d : nom, niveaux ou comptes invalides.', $index + 1)]);
        }

        return new CustomIndicator(null, $name, $levels, $accounts, ($record['share_of_net_worth'] ?? false) === true);
    }

    /**
     * Levels of an indicator of the file, by their code; null when one of them is unknown.
     *
     * @return list<Level>|null
     */
    private static function levels(mixed $codes): ?array
    {
        if ($codes === null) {
            return [];
        }
        if (!is_array($codes)) {
            return null;
        }
        $levels = [];
        foreach (array_keys($codes) as $key) {
            $levels[] = Level::tryFrom(self::text($codes[$key])) ?? throw new ValidationFailed(['file' => 'Niveau de la pyramide inconnu.']);
        }

        return $levels;
    }

    /**
     * Identifiers of the accounts of a goal or of an indicator, from their references in the file.
     *
     * @param array<int|string, int> $ids
     *
     * @return list<int>
     *
     * @throws ValidationFailed a reference unknown in the file
     */
    private static function goalAccounts(mixed $refs, array $ids, int $index): array
    {
        if (!is_array($refs)) {
            return [];
        }
        $accounts = [];
        foreach (array_keys($refs) as $key) {
            $accounts[] = $ids[self::key($refs[$key])] ?? throw new ValidationFailed(['file' => sprintf('Objectif %d : compte inconnu du fichier.', $index + 1)]);
        }

        return $accounts;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function nullableText(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function int(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \UnexpectedValueException('Identifiant de compte attendu.');
        }

        return $value;
    }
}
