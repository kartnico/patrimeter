<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Integration;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Patrimeter\Domain\Account;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\IntegrationTestCase;

final class StatementRepositoryTest extends IntegrationTestCase
{
    private AccountRepository $accounts;
    private StatementRepository $statements;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->accounts = self::service(AccountRepository::class);
        $this->statements = self::service(StatementRepository::class);
    }

    public function testSaveMonthAndCarryForward(): void
    {
        $current = self::createAccount('Compte courant', 'compte_courant');
        $livret = self::createAccount('Livret A', 'livret_a');

        $this->statements->saveMonth(self::owner(), Month::fromString('2026-01'), [$current => 150000, $livret => 2000000]);
        $this->statements->saveMonth(self::owner(), Month::fromString('2026-02'), [$current => 120000, $livret => null]);

        $valuation = $this->statements->valuation(self::owner());
        $february = Month::fromString('2026-02');
        self::assertSame(120000, $valuation->valueAt($current, $february)?->cents);
        self::assertFalse($valuation->valueAt($current, $february)->carried);
        self::assertSame(2000000, $valuation->valueAt($livret, $february)?->cents);
        self::assertTrue($valuation->valueAt($livret, $february)->carried, 'livret A non saisi en février : valeur reportée');
    }

    public function testLatestValuationLoadsOnlyTheLastEligibleMonth(): void
    {
        $id = self::createAccount('CC', 'compte_courant');
        $at = Month::fromString('2026-03');
        foreach (['2026-01' => 100, '2026-02' => 200, '2026-04' => 400] as $month => $amount) {
            $this->statements->save(self::owner(), $id, Month::fromString($month), $amount);
        }
        $valuation = $this->statements->latestValuation(self::owner(), $at);
        self::assertNull($valuation->valueAt($id, Month::fromString('2026-01')));
        self::assertSame(200, $valuation->valueAt($id, $at)?->cents);
        self::assertSame('2026-02', $valuation->valueAt($id, $at)->sourceMonth->toString());
    }

    public function testEmptyValueRemovesExistingStatement(): void
    {
        $id = self::createAccount('CC', 'compte_courant');
        $january = Month::fromString('2026-01');
        $february = Month::fromString('2026-02');
        $this->statements->saveMonth(self::owner(), $january, [$id => 100]);
        $this->statements->saveMonth(self::owner(), $february, [$id => 200]);
        $this->statements->saveMonth(self::owner(), $february, [$id => null]);

        self::assertSame(['2026-01' => 100], $this->statements->forAccount(self::owner(), $id));
        self::assertTrue($this->statements->valuation(self::owner())->valueAt($id, $february)?->carried);
    }

    public function testSaveOverwritesTheMonth(): void
    {
        $id = self::createAccount('CC', 'compte_courant');
        $january = Month::fromString('2026-01');
        $this->statements->save(self::owner(), $id, $january, 100);
        $this->statements->save(self::owner(), $id, $january, -250);

        self::assertSame(['2026-01' => -250], $this->statements->forAccount(self::owner(), $id));
    }

    public function testForAccountListsMostRecentFirst(): void
    {
        $id = self::createAccount('CC', 'compte_courant');
        $this->statements->save(self::owner(), $id, Month::fromString('2025-12'), 1);
        $this->statements->save(self::owner(), $id, Month::fromString('2026-02'), 3);
        $this->statements->save(self::owner(), $id, Month::fromString('2026-01'), 2);

        self::assertSame(['2026-02' => 3, '2026-01' => 2, '2025-12' => 1], $this->statements->forAccount(self::owner(), $id));
    }

    public function testSaveMonthIsAtomic(): void
    {
        $id = self::createAccount('CC', 'compte_courant');
        try {
            // Account 9999 does not exist: foreign key violation, nothing must be written.
            $this->statements->saveMonth(self::owner(), Month::fromString('2026-01'), [$id => 100, 9999 => 5]);
            self::fail('Exception attendue');
        } catch (ForeignKeyConstraintViolationException) {
        }
        self::assertSame([], $this->statements->forAccount(self::owner(), $id));
    }

    public function testDeletingAnAccountDeletesItsStatements(): void
    {
        $id = self::createAccount('CC', 'compte_courant');
        $this->statements->save(self::owner(), $id, Month::fromString('2026-01'), 100);
        $this->accounts->delete(self::owner(), $id);

        self::assertSame([], $this->statements->all(self::owner()));
    }

    public function testActiveInFiltersOpeningAndClosing(): void
    {
        $old = self::createAccount('Ancien', 'livret_a', '2020-01-01');
        $new = self::createAccount('Nouveau', 'ldds', '2026-03-10');
        $this->accounts->archive(self::owner(), $old, '2026-02-15');

        self::assertSame([$old], self::ids($this->accounts->activeIn(self::owner(), Month::fromString('2026-01'))));
        self::assertSame([], self::ids($this->accounts->activeIn(self::owner(), Month::fromString('2026-02'))));
        self::assertSame([$new], self::ids($this->accounts->activeIn(self::owner(), Month::fromString('2026-03'))));

        $this->accounts->unarchive(self::owner(), $old);
        self::assertCount(2, $this->accounts->activeIn(self::owner(), Month::fromString('2026-03')));
    }

    /**
     * @param list<Account> $accounts
     *
     * @return list<int>
     */
    private static function ids(array $accounts): array
    {
        return array_map(static fn(Account $a): int => $a->id, $accounts);
    }
}
