<?php

declare(strict_types=1);

namespace Patrimeter\Application;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\AccountValue;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\LoanMode;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Domain\OwnershipShares;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;

/**
 * Properties of a user and the net value of each for a month.
 *
 * @phpstan-type LoanLine array{account: Account, value: ?AccountValue, total: int, retained: int}
 * @phpstan-type PropertyLine array{account: Account, value: ?AccountValue, total: ?int, share: int, retained: int, loans: list<LoanLine>, owed: int, net: int}
 */
final class PropertyPortfolio
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly StatementRepository $statements,
        private readonly OwnershipShareRepository $shares,
    ) {}

    /** A property: an asset, not archived, of the Main residence or Real estate levels. */
    public static function isProperty(Account $account): bool
    {
        return self::isPropertyKind($account->level, $account->nature);
    }

    public static function isPropertyKind(Level $level, Nature $nature): bool
    {
        return $nature === Nature::Asset && ($level === Level::Residence || $level === Level::Immobilier);
    }

    /**
     * A property that would stop being one would keep its share and its loans, applied to the calculation without
     * being editable any more; likewise for a linked loan that would become an asset.
     *
     * @param array{name:string,type_id:int,level_code:string,nature:string,opened_on:string} $data
     *
     * @return array<string, string>
     */
    public function conflicts(UserId $owner, Account $account, array $data): array
    {
        if ($data['opened_on'] > $account->openedOn && $this->statements->hasBefore($owner, $account->id, Month::fromDate($data['opened_on']))) {
            return ['opened_on' => 'Un relevé existe avant ce mois d’ouverture.'];
        }
        if ($account->linkedAssetId !== null && $data['nature'] !== Nature::Liability->value) {
            return ['nature' => 'Ce crédit est rattaché à un bien : détachez-le d\'abord.'];
        }
        if (!self::isProperty($account) || self::isPropertyKind(Level::from($data['level_code']), Nature::from($data['nature']))) {
            return [];
        }
        $hasShares = ($this->shares->all($owner)[$account->id] ?? []) !== [];
        foreach ($this->accounts->all($owner) as $other) {
            if ($hasShares || $other->linkedAssetId === $account->id) {
                return ['level_code' => 'Ce bien a une part ou des crédits rattachés : retirez-les d\'abord.'];
            }
        }

        return [];
    }

    /** @return list<PropertyLine> */
    public function lines(UserId $owner, Month $month): array
    {
        $accounts = $this->accounts->all($owner);
        $valuation = $this->statements->valuation($owner);
        $shares = new OwnershipShares($this->shares->all($owner));
        $lines = [];
        foreach ($accounts as $account) {
            if (!self::isProperty($account) || !$account->isActiveIn($month)) {
                continue;
            }
            $share = $shares->at($account->id, $month);
            $value = $valuation->valueAt($account->id, $month);
            $total = $value?->cents;
            $retained = $total === null ? 0 : OwnershipShares::apply($total, $share);
            $loans = [];
            $owed = 0;
            foreach ($accounts as $loan) {
                if ($loan->linkedAssetId !== $account->id || !$loan->isActiveIn($month)) {
                    continue;
                }
                $loanValue = $valuation->valueAt($loan->id, $month);
                $loanTotal = $loanValue->cents ?? 0;
                $loanRetained = $loan->loanMode === LoanMode::Own ? $loanTotal : OwnershipShares::apply($loanTotal, $share);
                $owed += $loanRetained;
                $loans[] = ['account' => $loan, 'value' => $loanValue, 'total' => $loanTotal, 'retained' => $loanRetained];
            }
            $lines[] = ['account' => $account, 'value' => $value, 'total' => $total, 'share' => $share, 'retained' => $retained, 'loans' => $loans, 'owed' => $owed, 'net' => $retained - $owed];
        }

        return $lines;
    }
}
