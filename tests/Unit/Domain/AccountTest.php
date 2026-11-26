<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\LoanMode;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    public function testActivePeriod(): void
    {
        $a = new Account(1, 'Livret A', 1, 'Livret A', Level::Precaution, Nature::Asset, '2025-03-15', '2026-02-10');
        self::assertFalse($a->isActiveIn(Month::fromString('2025-02')));
        self::assertTrue($a->isActiveIn(Month::fromString('2025-03')), 'actif dès le mois d\'ouverture');
        self::assertTrue($a->isActiveIn(Month::fromString('2026-01')));
        self::assertFalse($a->isActiveIn(Month::fromString('2026-02')), 'inactif dès le mois de clôture');
        self::assertTrue($a->isArchived());
    }

    public function testOpenAccountStaysActive(): void
    {
        $a = new Account(1, 'CC', 1, 'Compte courant', Level::Precaution, Nature::Asset, '2020-01-01');
        self::assertTrue($a->isActiveIn(Month::fromString('2099-12')));
        self::assertFalse($a->isArchived());
    }

    public function testLiabilitySign(): void
    {
        $credit = new Account(2, 'Prêt', 2, 'Crédit', Level::Residence, Nature::Liability, '2020-01-01');
        self::assertSame(-1000, $credit->signed(1000));
        $asset = new Account(3, 'Maison', 3, 'RP', Level::Residence, Nature::Asset, '2020-01-01');
        self::assertSame(1000, $asset->signed(1000));
    }

    /** Values saved (encrypted) with each account: renaming them would make the existing accounts unreadable. */
    public function testStoredValuesOfNatureAndLoanModeNeverChange(): void
    {
        self::assertSame(['asset', 'liability'], Nature::values());
        self::assertSame(['prorata', 'own'], LoanMode::values());
        self::assertSame(LoanMode::Prorata, (new Account(1, 'X', 1, 'X', Level::Exotique, Nature::Asset, '2020-01-01'))->loanMode);
    }
}
