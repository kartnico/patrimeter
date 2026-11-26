<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;

/** Order of the budget's categories and items, chosen with the arrows of the household and categories page. */
final class BudgetOrderTest extends FunctionalTestCase
{
    public function testCategoriesAndItemsMoveAmongTheirSiblings(): void
    {
        $owner = self::owner();
        $ids = self::createBudget($owner, 'Camille', 'Logement', 'Loyer', 100_000);
        $budgets = self::service(BudgetRepository::class);
        $leisure = $budgets->createCategory($owner, 'Loisirs', BudgetGroup::Wants, null);
        $budgets->createCategory($owner, 'Mes dépenses', BudgetGroup::Needs, 1);
        $charges = $budgets->createLine($owner, $ids['category'], 'Charges');
        $this->logIn();

        $crawler = $this->client->request('GET', '/budget/foyer');
        self::assertCount(0, $crawler->filter('form[action="/budget/categories/' . $ids['category'] . '/deplacer"] button[value="haut"]'), 'first: nothing above');
        self::assertCount(1, $crawler->filter('button[aria-label="Descendre Logement"]'));

        $this->submit('/budget/categories/' . $leisure . '/deplacer', ['sens' => 'haut'], '/budget/foyer');
        self::assertResponseRedirects('/budget/foyer#categories-title', Response::HTTP_SEE_OTHER);
        self::assertSame(['Loisirs', 'Logement', 'Mes dépenses'], array_map(static fn(BudgetCategory $c): string => $c->name, $budgets->categories($owner)));

        // The adult's own category has no sibling in the shared budget: it does not move.
        $this->submit('/budget/categories/' . $leisure . '/deplacer', ['sens' => 'haut'], '/budget/foyer');
        self::assertSame(['Loisirs', 'Logement', 'Mes dépenses'], array_map(static fn(BudgetCategory $c): string => $c->name, $budgets->categories($owner)));

        $this->submit('/budget/postes/' . $charges . '/deplacer', ['sens' => 'haut'], '/budget/foyer');
        self::assertSame(['Charges', 'Loyer'], array_map(static fn(BudgetLine $l): string => $l->name, $budgets->lines($owner)));

        // A new category comes last.
        $budgets->createCategory($owner, 'Vacances', BudgetGroup::Wants, null);
        self::assertSame('Vacances', $budgets->categories($owner)[3]->name);
        $crawler = $this->client->request('GET', '/budget');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Loisirs', $crawler->filter('details.budget-detail')->first()->filter('caption')->first()->text(), 'the budget follows the order');
    }
}
