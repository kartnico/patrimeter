<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Integration;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Budget\SplitRule;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Crypto\DecryptionFailed;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Tests\Support\IntegrationTestCase;
use Patrimeter\Tests\Support\TestUsers;

/** Encrypted budget: faithful reading back, carried-over amounts, ciphertext tied to its row, item moved from a category. */
final class BudgetRepositoryTest extends IntegrationTestCase
{
    private BudgetRepository $budgets;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        TestUsers::unlock(static::getContainer(), self::owner());
        $this->budgets = self::service(BudgetRepository::class);
    }

    public function testEveryRecordIsReadBackAsSaved(): void
    {
        $household = new Household([1 => 'Flo', 2 => 'Nico'], [2 => 'Eva'], 5, 3);
        $this->budgets->saveHousehold(self::owner(), $household);
        $category = $this->budgets->createCategory(self::owner(), 'Loisirs « Nico »', BudgetGroup::Wants, 2);
        $line = $this->budgets->createLine(self::owner(), $category, 'Padel', 2, 42);
        $budget = new MonthlyBudget(Month::fromString('2026-10'), [1 => 186_000, 2 => 414_300], [$line => 5_000], SplitRule::Fixed, 275_000);
        $this->budgets->saveBudget(self::owner(), $budget);

        self::assertEquals($household, $this->budgets->household(self::owner()));
        self::assertEquals([new BudgetCategory($category, 'Loisirs « Nico »', BudgetGroup::Wants, 2)], $this->budgets->categories(self::owner()));
        self::assertEquals([new BudgetLine($line, $category, 'Padel', 2, 42)], $this->budgets->lines(self::owner()));
        self::assertEquals([$budget], $this->budgets->budgets(self::owner()));
    }

    public function testAmountsInForceAreTheLastSavedOnOrBeforeTheMonth(): void
    {
        $this->budgets->saveHousehold(self::owner(), new Household([1 => 'Alex']));
        foreach (['2026-03' => 1_000, '2026-07' => 2_000] as $month => $income) {
            $this->budgets->saveBudget(self::owner(), new MonthlyBudget(Month::fromString($month), [1 => $income]));
        }

        self::assertNull($this->budgets->budgetAt(self::owner(), Month::fromString('2026-02')));
        self::assertSame('2026-03', $this->budgets->budgetAt(self::owner(), Month::fromString('2026-06'))?->month->toString());
        self::assertSame(2_000, $this->budgets->budgetAt(self::owner(), Month::fromString('2026-11'))?->income(1));
        self::assertSame('2026-03', $this->budgets->firstMonth(self::owner())?->toString());

        $this->budgets->deleteBudget(self::owner(), Month::fromString('2026-07'));
        self::assertSame(1_000, $this->budgets->budgetAt(self::owner(), Month::fromString('2026-11'))?->income(1));
    }

    public function testACiphertextMovedToAnotherRowCannotBeRead(): void
    {
        $this->budgets->saveHousehold(self::owner(), new Household([1 => 'Alex']));
        $this->budgets->saveBudget(self::owner(), new MonthlyBudget(Month::fromString('2026-10'), [1 => 1_000]));
        $this->budgets->saveBudget(self::owner(), new MonthlyBudget(Month::fromString('2026-11'), [1 => 9_999]));
        $connection = self::service(Connection::class);
        $connection->executeStatement(
            "UPDATE budget_months SET ciphertext = (SELECT ciphertext FROM budget_months WHERE month = '2026-10') WHERE month = '2026-11'",
        );

        $this->expectException(DecryptionFailed::class);
        $this->budgets->budgetAt(self::owner(), Month::fromString('2026-11'));
    }

    public function testALineMovedToAnotherCategoryIsSealedForIt(): void
    {
        $this->budgets->saveHousehold(self::owner(), new Household([1 => 'Alex']));
        $housing = $this->budgets->createCategory(self::owner(), 'Logement', BudgetGroup::Needs, null);
        $savings = $this->budgets->createCategory(self::owner(), 'Épargne', BudgetGroup::Savings, 1);
        $line = $this->budgets->createLine(self::owner(), $housing, 'Travaux');

        $this->budgets->updateLine(self::owner(), new BudgetLine($line, $savings, 'Réserve travaux', null, 7));

        self::assertEquals(new BudgetLine($line, $savings, 'Réserve travaux', null, 7), $this->budgets->findLine(self::owner(), $line));
        $this->budgets->deleteCategory(self::owner(), $savings);
        self::assertNull($this->budgets->findLine(self::owner(), $line), 'supprimé avec sa nouvelle catégorie');
        self::assertNotNull($this->budgets->findCategory(self::owner(), $housing));
    }
}
