<?php

declare(strict_types=1);

namespace Patrimeter\Application\Transfer;

use Patrimeter\Application\PropertyPortfolio;
use Patrimeter\Application\ValidationFailed;
use Patrimeter\Domain\Account;
use Patrimeter\Domain\DateInput;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\LoanMode;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Domain\OwnershipShares;
use Patrimeter\Infrastructure\Persistence\AccountTypeRepository;
use Patrimeter\Infrastructure\Persistence\CustomIndicatorRepository;
use Patrimeter\Infrastructure\Persistence\SavingsGoalRepository;

/**
 * In-memory reading of an export file ("patrimeter" format, versions 1 to 3, docs/export.md), without saving
 * anything: accounts, statements, shares, links between properties and loans, household budget (the savings goals of
 * a member are not read: a family account has its own). Same rules as the
 * import into an empty account (DataTransfer, which goes through PortfolioService), checked here without a database.
 * Used by the family account, which keeps the data imported from each of its members apart, encrypted.
 *
 * The identifiers are derived from the member's rank ($slot), so as never to mix with those of another member or of
 * the importing account: negative accounts (−(rank × 1,000,000 + n)), budget categories and items from
 * rank × 1,000,000 + 1.
 */
final class ExportReader
{
    private const int SLOT = 1_000_000;

    public function __construct(private readonly AccountTypeRepository $types) {}

    /**
     * Decoded file, format and version checked.
     *
     * @return array{accounts: list<mixed>, budget: array<mixed>|null, goals: list<mixed>, indicators: list<mixed>, exported_on: ?string}
     *
     * @throws ValidationFailed
     */
    public static function decode(string $json): array
    {
        if (strlen($json) > DataTransfer::MAX_BYTES) {
            throw new ValidationFailed(['file' => 'Fichier trop volumineux (2 Mo au plus).']);
        }
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationFailed(['file' => 'Ce fichier n\'est pas un export de Patrimeter (JSON illisible).']);
        }
        if (!is_array($data) || ($data['format'] ?? null) !== DataTransfer::FORMAT || !in_array($data['version'] ?? null, DataTransfer::READABLE_VERSIONS, true)
            || !isset($data['accounts']) || !is_array($data['accounts']) || !array_is_list($data['accounts'])) {
            throw new ValidationFailed(['file' => 'Ce fichier n\'est pas un export de Patrimeter (format ou version inconnus).']);
        }
        if (count($data['accounts']) > DataTransfer::MAX_ACCOUNTS) {
            throw new ValidationFailed(['file' => 'Trop de comptes dans ce fichier.']);
        }
        if (isset($data['budget']) && !is_array($data['budget'])) {
            throw new ValidationFailed(['file' => 'Budget du foyer illisible ou invalide.']);
        }
        $goals = $data['goals'] ?? [];
        if (!is_array($goals) || !array_is_list($goals) || count($goals) > SavingsGoalRepository::MAX_GOALS) {
            throw new ValidationFailed(['file' => 'Objectifs d\'épargne illisibles ou trop nombreux.']);
        }
        $indicators = $data['indicators'] ?? [];
        if (!is_array($indicators) || !array_is_list($indicators) || count($indicators) > CustomIndicatorRepository::MAX_INDICATORS) {
            throw new ValidationFailed(['file' => 'Indicateurs illisibles ou trop nombreux.']);
        }
        return [
            'accounts' => $data['accounts'],
            'budget' => $data['budget'] ?? null,
            'goals' => $goals,
            'indicators' => $indicators,
            'exported_on' => isset($data['exported_on']) && is_string($data['exported_on']) && DateInput::isValid($data['exported_on']) ? $data['exported_on'] : null,
        ];
    }

    /**
     * @param list<mixed>       $accounts accounts of the file
     * @param array<mixed>|null $budget   budget of the file
     *
     * @throws ValidationFailed refused data (the message names the account concerned)
     */
    public function read(array $accounts, ?array $budget, int $slot): ExportedData
    {
        $types = [];
        foreach ($this->types->all() as $type) {
            $types[$type['code']] = $type;
        }
        $base = $slot * self::SLOT;
        /** @var array<string, Account> $byRef */
        $byRef = [];
        $refs = [];
        $statements = [];
        $shares = [];
        $records = [];
        foreach (array_keys($accounts) as $index) {
            $record = self::record($accounts[$index], $index);
            $label = self::label($record, $index);
            $type = $types[self::text($record['type'] ?? null)] ?? throw new ValidationFailed(['file' => "Compte « $label » : type de compte inconnu."]);
            $name = trim(self::text($record['name'] ?? null));
            $level = Level::tryFrom(self::orDefault($record['level'] ?? null, $type['default_level']));
            $nature = Nature::tryFrom(self::orDefault($record['nature'] ?? null, $type['nature']));
            $opened = self::text($record['opened_on'] ?? null);
            $closed = $record['closed_on'] ?? null;
            if (preg_match(Account::NAME_PATTERN, $name) !== 1 || $level === null || $nature === null || !DateInput::isValid($opened)
                || ($closed !== null && (!is_string($closed) || !DateInput::isValid($closed) || $closed < $opened))) {
                throw new ValidationFailed(['file' => "Compte « $label » : nom, niveau, nature ou dates invalides."]);
            }
            $id = -($base + $index + 1);
            $account = new Account($id, $name, $type['id'], $type['label'], $level, $nature, $opened, $closed);
            $ref = self::key($record['ref'] ?? $index);
            $byRef[$ref] = $account;
            $refs[$ref] = $id;
            $records[$index] = [$record, $label, $account];

            foreach (self::rows($record['statements'] ?? [], $label) as $row) {
                $month = Month::tryFromString(self::text($row['month'] ?? null));
                $cents = $row['amount_cents'] ?? null;
                if ($month === null || !$account->isActiveIn($month) || !is_int($cents) || abs($cents) > Money::MAX_CENTS) {
                    throw new ValidationFailed(['file' => "Compte « $label » : relevé invalide ou hors de la période d'ouverture."]);
                }
                $statements[$id][$month->toString()] = $cents;
            }
            foreach (self::rows($record['shares'] ?? [], $label) as $row) {
                $month = Month::tryFromString(self::text($row['month'] ?? null));
                $share = $row['share_millionths'] ?? null;
                if ($month === null || !PropertyPortfolio::isProperty($account) || !is_int($share) || $share < 1 || $share > OwnershipShares::FULL) {
                    throw new ValidationFailed(['file' => "Compte « $label » : part invalide (réservée aux biens immobiliers)."]);
                }
                $shares[$id][$month->toString()] = $share;
            }
        }

        // Links once all the accounts are read: a loan can come before its property in the file.
        $linked = [];
        foreach ($records as [$record, $label, $account]) {
            if (($record['linked_asset_ref'] ?? null) === null) {
                $linked[] = $account;
                continue;
            }
            $asset = $byRef[self::key($record['linked_asset_ref'])] ?? null;
            $mode = LoanMode::tryFrom(self::orDefault($record['loan_mode'] ?? null, LoanMode::Prorata->value));
            if (!$account->isLiability() || $asset === null || !PropertyPortfolio::isProperty($asset) || $mode === null) {
                throw new ValidationFailed(['file' => "Compte « $label » : Choisissez un bien, pour un crédit."]);
            }
            $linked[] = new Account($account->id, $account->name, $account->typeId, $account->typeLabel, $account->level, $account->nature, $account->openedOn, $account->closedOn, null, $asset->id, $mode);
        }

        return new ExportedData($linked, $statements, $shares, $budget === null ? null : BudgetTransfer::read($budget, $refs, $base));
    }

    /** @return array<mixed> */
    private static function record(mixed $account, int $index): array
    {
        return is_array($account) ? $account : throw new ValidationFailed(['file' => 'Compte n° ' . ($index + 1) . ' illisible.']);
    }

    /** @param array<mixed> $record */
    private static function label(array $record, int $index): string
    {
        $name = self::text($record['name'] ?? null);

        return $name !== '' ? mb_substr($name, 0, 80) : 'n° ' . ($index + 1);
    }

    /** @return list<array<mixed>> */
    private static function rows(mixed $rows, string $label): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > DataTransfer::MAX_ROWS_PER_ACCOUNT) {
            throw new ValidationFailed(['file' => "Compte « $label » : relevés ou parts illisibles."]);
        }
        return array_map(
            static fn(mixed $row): array => is_array($row) ? $row : throw new ValidationFailed(['file' => "Compte « $label » : relevés ou parts illisibles."]),
            $rows,
        );
    }

    private static function key(mixed $ref): string
    {
        return is_int($ref) || is_string($ref) ? (string) $ref : '';
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** Value from the file, or the default one if it is missing or empty. */
    private static function orDefault(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
