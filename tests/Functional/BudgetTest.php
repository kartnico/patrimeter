<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Budget\HouseholdBudget;
use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\SplitRule;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\QueryCounter;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\HttpFoundation\Response;

/**
 * Monthly household budget: creation of the household, entry of a month's amounts and carrying them over to the
 * following ones, joint account and each adult's share, categories and items, cost of the children, comparison with
 * the net worth.
 */
final class BudgetTest extends FunctionalTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logIn();
    }

    public function testFirstVisitProposesToCreateTheHousehold(): void
    {
        $crawler = $this->client->request('GET', '/budget');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Budget du foyer');
        self::assertCount(1, $crawler->filter('form[action="/budget/foyer"] input[name="adult_names[1]"]'));
        self::assertSelectorExists('nav.nav a[href="/budget"][aria-current="page"]');
        // The budget part of the monthly entry says so, with a single way forward.
        $crawler = $this->client->request('GET', '/budget/saisie');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state h2', 'Pas encore de budget');
        self::assertSame(['/budget'], $crawler->filter('main .btn')->each(static fn($link): ?string => $link->attr('href')));
        self::assertSelectorExists('main a.btn-primary[href="/budget"]');
        self::assertSelectorNotExists('form.copy-form');
        $this->client->request('GET', '/budget/saisie?mois=2026-05');
        self::assertResponseRedirects('/budget/saisie', Response::HTTP_SEE_OTHER);
    }

    public function testCreatingTheHouseholdProposesCategoriesAndItems(): void
    {
        $this->submit('/budget/foyer', ['adults' => '2', 'adult_names' => [1 => 'Flo', 2 => 'Nico'], 'adult_years' => [1 => '1985', 2 => '1983'], 'children_count' => '2', 'child_names' => ['Eva', 'Tom'], 'child_years' => ['2009', '2015'], 'precaution_months' => '4'], '/budget');

        self::assertResponseRedirects('/budget/saisie', Response::HTTP_SEE_OTHER);
        $household = self::service(BudgetRepository::class)->household(self::owner());
        self::assertNotNull($household);
        self::assertSame([1 => 'Flo', 2 => 'Nico'], $household->adults);
        self::assertSame([1 => 'Eva', 2 => 'Tom'], $household->children);
        self::assertSame([1 => 1985, 2 => 1983], $household->adultBirthYears);
        self::assertSame([1 => 2009, 2 => 2015], $household->childBirthYears);
        self::assertSame(4, $household->precautionMonths);
        $categories = self::service(BudgetRepository::class)->categories(self::owner());
        self::assertCount(4 + 3 + 3, $categories, 'quatre catégories communes, trois par adulte');
        self::assertSame(['Logement', 'Vie courante', 'Loisirs', 'Épargne du foyer'], array_map(static fn(BudgetCategory $c): string => $c->name, array_slice($categories, 0, 4)));
        self::assertCount(14 + 6 + 6, self::service(BudgetRepository::class)->lines(self::owner()));

        $this->client->followRedirect();
        self::assertSelectorTextContains('label[for="income-2"]', 'Revenus nets de Nico');
        // Eva is 17 in 2026: she can have incomes of her own; Tom (11) cannot.
        self::assertSelectorTextContains('label[for="child-income-1"]', 'Revenus nets de Eva');
        self::assertSelectorNotExists('#child-income-2');
        self::assertStringContainsString('Foyer fiscal créé', $this->flashes());
    }

    public function testTheNumbersOfAdultsAndChildrenShowTheirRows(): void
    {
        $this->givenAHousehold();
        $crawler = $this->client->request('GET', '/budget/foyer');

        // Lists of the numbers, then one row per person (app.js hides those beyond the numbers chosen).
        self::assertSame('2', $crawler->filter('select#adults-count option[selected]')->attr('value'));
        self::assertSame('1', $crawler->filter('select#children-count option[selected]')->attr('value'));
        self::assertCount(2, $crawler->filter('[data-household-row="adult"]'));
        self::assertCount(12, $crawler->filter('[data-household-row="child"]'));
        self::assertInputValueSame('child_names[0]', 'Eva');
        self::assertInputValueSame('child_years[0]', '2015');
        self::assertInputValueSame('child_ids[0]', '1');
        self::assertInputValueSame('child_names[1]', '');
    }

    public function testAChildKeepsTheirNumberAndTheirItemsWhileOthersAreAddedOrRemoved(): void
    {
        $lines = $this->givenAHousehold();
        $budgets = self::service(BudgetRepository::class);
        $groceries = $budgets->findLine(self::owner(), $lines['groceries']);
        self::assertNotNull($groceries);
        $budgets->updateLine(self::owner(), new BudgetLine($groceries->id, $groceries->categoryId, $groceries->name, 1));
        $household = ['adults' => '2', 'adult_names' => [1 => 'Flo', 2 => 'Nico'], 'adult_years' => [1 => '1985', 2 => '1983'], 'precaution_months' => '4'];

        // Eva renamed in her row, two children added.
        $this->submit('/budget/foyer', $household + ['children_count' => '3', 'child_ids' => ['1', '', ''], 'child_names' => ['Éva', 'Tom', 'Lou'], 'child_years' => ['2015', '2018', '2020']], '/budget/foyer');
        self::assertResponseRedirects('/budget/foyer', Response::HTTP_SEE_OTHER);
        self::assertSame([1 => 'Éva', 2 => 'Tom', 3 => 'Lou'], $budgets->household(self::owner())?->children);
        self::assertSame(1, $budgets->findLine(self::owner(), $lines['groceries'])?->childId, 'her items stay hers');

        // Back to one child: the rows beyond the number are ignored; Tom's emptied row (above the number) is not read.
        $this->submit('/budget/foyer', $household + ['children_count' => '1', 'child_ids' => ['1', '2', '3'], 'child_names' => ['Éva', 'Tom', 'Lou'], 'child_years' => ['2015', '2018', '2020']], '/budget/foyer');
        self::assertSame([1 => 'Éva'], $budgets->household(self::owner())?->children);

        // An emptied first name removes the child; a new child never takes a used number again.
        $this->submit('/budget/foyer', $household + ['children_count' => '2', 'child_ids' => ['1', ''], 'child_names' => ['', 'Zoé'], 'child_years' => ['2015', '2021']], '/budget/foyer');
        $household = $budgets->household(self::owner());
        self::assertNotNull($household);
        self::assertSame([4 => 'Zoé'], $household->children);
        self::assertSame([4 => 2021], $household->childBirthYears);
    }

    public function testAForgedChildNumberAddsAChildInstead(): void
    {
        $this->givenAHousehold();
        $household = ['adults' => '1', 'adult_names' => [1 => 'Flo'], 'adult_years' => [1 => '1985'], 'precaution_months' => '4'];

        $this->submit('/budget/foyer', $household + ['children_count' => '2', 'child_ids' => ['1', '1'], 'child_names' => ['Eva', 'Sosie'], 'child_years' => ['2015', '2016']], '/budget/foyer');

        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'Nico still has his own categories');
        $this->submit('/budget/foyer', ['adults' => '2', 'adult_names' => [1 => 'Flo', 2 => 'Nico'], 'adult_years' => [1 => '1985', 2 => '1983'], 'precaution_months' => '4', 'children_count' => '2', 'child_ids' => ['1', '1'], 'child_names' => ['Eva', 'Sosie'], 'child_years' => ['2015', '2016']], '/budget/foyer');
        self::assertSame([1 => 'Eva', 2 => 'Sosie'], self::service(BudgetRepository::class)->household(self::owner())?->children, 'the same number once only');
    }

    public function testInvalidHouseholdChangesNothing(): void
    {
        $this->submit('/budget/foyer', ['adults' => '2', 'adult_names' => [1 => 'Flo', 2 => ''], 'adult_years' => [1 => '85'], 'children_count' => '2', 'child_names' => ['Eva', ''], 'child_years' => ['', '2015'], 'precaution_months' => '40'], '/budget');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorExists('#err-adult_names-2');
        self::assertSelectorExists('#err-adult_years-1');
        self::assertSelectorExists('#err-adult_years-2');
        self::assertSelectorExists('#err-child_years-0');
        self::assertSelectorExists('#err-child_names-1');
        self::assertSelectorNotExists('#err-child_names-2', 'beyond the number of children: ignored');
        self::assertSelectorExists('#err-precaution_months');
        self::assertInputValueSame('adult_names[1]', 'Flo');
        self::assertNull(self::service(BudgetRepository::class)->household(self::owner()));
    }

    public function testMonthlyBudgetComputesTheJointAccountAndWhatEachOneHasLeft(): void
    {
        $lines = $this->givenAHousehold();
        $this->submit('/budget/saisie', [
            'mois' => '2026-11',
            'incomes' => [1 => '1 860,00', 2 => '4143'],
            'split_rule' => 'fixed',
            'first_share' => '27,5',
            'amounts' => [$lines['rent'] => '2 333,74', $lines['groceries'] => '1298', $lines['outings'] => '923', $lines['flo_sport'] => '592,30', $lines['nico_savings'] => '836'],
        ], '/budget/saisie');

        self::assertResponseRedirects('/budget?mois=2026-11', Response::HTTP_SEE_OTHER);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Budget de novembre 2026 enregistré.', $this->flashes());
        $joint = self::plain($crawler->filter('#budget-joint-title')->ancestors()->first()->text());
        self::assertStringContainsString('1 252,55', $joint);
        self::assertStringContainsString('3 302,19', $joint);
        self::assertStringContainsString('4 554,74', $joint);
        $left = self::plain($crawler->filter('.budget-adults tfoot')->text());
        self::assertStringContainsString('15,15', $left);
        self::assertStringContainsString('4,81', $left);
        self::assertSelectorExists('svg.sankey[role="img"]');
        self::assertSelectorTextContains('.budget-status', 'saisi ce mois-ci');

        $budget = self::service(BudgetRepository::class)->budgetAt(self::owner(), Month::fromString('2026-11'));
        self::assertNotNull($budget);
        self::assertSame(SplitRule::Fixed, $budget->splitRule);
        self::assertSame(275_000, $budget->firstAdultShare);
        self::assertSame([1 => 186_000, 2 => 414_300], $budget->incomes);
    }

    public function testIncomesTitleIsOutsideItsBoxLikeTheBudgetScopes(): void
    {
        $this->givenAHousehold();
        $crawler = $this->client->request('GET', '/budget/saisie');

        self::assertSame(['Revenus du mois', 'Budget commun, payé par le compte commun', 'Budget propre à Flo', 'Budget propre à Nico'], $crawler->filter('form.budget-form > h2.budget-scope')->each(static fn($title): string => $title->text()));
        self::assertCount(0, $crawler->filter('section.budget-incomes h2'));
        self::assertSame('budget-incomes-title', $crawler->filter('section.budget-incomes')->attr('aria-labelledby'));
    }

    public function testTheBudgetOfAnotherMonthIsCopiedIntoTheFormWithoutSavingAnything(): void
    {
        $lines = $this->givenAHousehold();
        $this->submit('/budget/saisie', ['mois' => '2026-09', 'incomes' => [1 => '2000'], 'amounts' => [$lines['rent'] => '800']], '/budget/saisie');
        $this->submit('/budget/saisie', ['mois' => '2026-10', 'incomes' => [1 => '2500'], 'amounts' => [$lines['rent'] => '900']], '/budget/saisie');

        $crawler = $this->client->request('GET', '/budget/saisie?mois=2026-11&depuis=2026-09');

        self::assertSame('800,00', $crawler->filter('input[name="amounts[' . $lines['rent'] . ']"]')->attr('value'));
        self::assertSame('2000,00', $crawler->filter('input[name="incomes[1]"]')->attr('value'));
        self::assertStringContainsString('Budget de septembre 2026 copié', $this->flashes());
        self::assertSame('2026-09', $crawler->filter('form.copy-form input[name="depuis"]')->attr('value'));
        self::assertCount(2, self::service(BudgetRepository::class)->budgets(self::owner()), 'nothing saved for November');

        $this->client->request('GET', '/budget/saisie?mois=2026-11&depuis=2026-01');
        self::assertStringContainsString('Aucun budget en janvier 2026 : rien n\'a été copié.', $this->flashes());
        // A month after next, or the month itself, is ignored.
        foreach (['2027-01', '2026-11', 'n-importe'] as $source) {
            $this->client->request('GET', '/budget/saisie?mois=2026-11&depuis=' . $source);
            self::assertStringNotContainsString('copié', $this->flashes(), $source);
        }
    }

    public function testNextMonthsBudgetCanBeEnteredAndTheEntryLinksToWhatToAdd(): void
    {
        $lines = $this->givenAHousehold();
        $crawler = $this->client->request('GET', '/budget/saisie?mois=2026-12');
        self::assertResponseIsSuccessful();
        self::assertSame(['/budget/foyer', '/comptes/nouveau', '/budget/foyer#household-title'], $crawler->filter('form.copy-form .copy-links a')->each(static fn($link): ?string => $link->attr('href')));

        $this->submit('/budget/saisie', ['mois' => '2026-12', 'incomes' => [1 => '3000'], 'amounts' => [$lines['rent'] => '950']], '/budget/saisie');
        self::assertResponseRedirects('/budget?mois=2026-12', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame(95_000, self::service(BudgetRepository::class)->budgetAt(self::owner(), Month::fromString('2026-12'))?->amount($lines['rent']));
    }

    public function testTheCostOfTheChildrenNeedsALineAttachedToAChild(): void
    {
        $lines = $this->givenAHousehold();
        foreach (self::service(BudgetRepository::class)->lines(self::owner()) as $line) {
            if ($line->childId !== null) {
                self::service(BudgetRepository::class)->updateLine(self::owner(), new BudgetLine($line->id, $line->categoryId, $line->name, null, $line->accountId));
            }
        }
        $this->submit('/budget/saisie', ['mois' => '2026-11', 'incomes' => [1 => '2000'], 'amounts' => [$lines['rent'] => '800']], '/budget/saisie');

        $this->client->request('GET', '/budget?mois=2026-11');
        self::assertSelectorNotExists('#budget-children-title', 'a child, but no line attached to her');
    }

    public function testAmountsCarryOverToTheFollowingMonths(): void
    {
        $lines = $this->givenAHousehold();
        $this->submit('/budget/saisie', ['mois' => '2026-09', 'incomes' => [1 => '2000'], 'amounts' => [$lines['rent'] => '800']], '/budget/saisie');

        $this->client->request('GET', '/budget?mois=2026-11');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.budget-status', 'reporté');
        self::assertStringContainsString('Montants du budget de septembre 2026', $this->body());
        $crawler = $this->client->request('GET', '/budget/saisie?mois=2026-11');
        self::assertStringContainsString('Montants repris du budget de septembre 2026', $this->flashes());
        self::assertSame('800,00', $crawler->filter('input[name="amounts[' . $lines['rent'] . ']"]')->attr('value'));

        $this->client->request('GET', '/budget?mois=2026-08');
        self::assertSelectorTextContains('.empty-state', 'Aucun budget en août 2026.');
        self::assertSelectorExists('.empty-state a[href="/budget?mois=2026-09"]');
        // Laid out as the savings goals page: title and muted month, settings at the top, a single "+" entry button.
        self::assertSame('Budget Août 2026', $this->client->getCrawler()->filter('h1')->text());
        self::assertSelectorTextSame('h1 .muted', 'Août 2026');
        self::assertSelectorTextSame('.page-head a.btn-small[href="/budget/foyer"]', 'Configurer foyer fiscal et catégories');
        self::assertSame(['+ Saisir le budget d’août 2026'], $this->client->getCrawler()->filter('.empty-state a.btn-primary')->each(static fn($a): string => $a->text()));
        self::assertSelectorNotExists('.empty-state a[href="/budget/foyer"]');

        // Deleting the month's amounts: those of an earlier month apply again.
        $this->submit('/budget/saisie', ['mois' => '2026-11', 'incomes' => [1 => '2100'], 'amounts' => [$lines['rent'] => '850']], '/budget/saisie');
        $this->submit('/budget/saisie/2026-11/supprimer', [], '/budget');
        self::assertResponseRedirects('/budget?mois=2026-11', Response::HTTP_SEE_OTHER);
        self::assertSame(80_000, self::service(BudgetRepository::class)->budgetAt(self::owner(), Month::fromString('2026-11'))?->amount($lines['rent']));
    }

    public function testInvalidAmountsChangeNothingAndKeepTheInput(): void
    {
        $lines = $this->givenAHousehold();
        $this->submit('/budget/saisie', [
            'mois' => '2026-11',
            'incomes' => [1 => '1 860', 2 => 'beaucoup'],
            'split_rule' => 'fixed',
            'first_share' => '120',
            'amounts' => [$lines['rent'] => '-5', $lines['groceries'] => '12,345'],
        ], '/budget/saisie');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorExists('#err-incomes-2');
        self::assertSelectorExists('#err-first_share');
        self::assertSelectorTextContains('#err-amounts-' . $lines['rent'], 'Le montant ne peut pas être négatif.');
        self::assertSelectorExists('#err-amounts-' . $lines['groceries']);
        self::assertInputValueSame('incomes[2]', 'beaucoup');
        self::assertSame([], self::service(BudgetRepository::class)->budgets(self::owner()));
    }

    public function testFutureMonthAndUnknownMonthAreRefused(): void
    {
        $this->givenAHousehold();
        $this->client->request('GET', '/budget?mois=2027-01');
        self::assertResponseRedirects('/budget', Response::HTTP_SEE_OTHER);
        $this->submit('/budget/saisie', ['mois' => '2027-01', 'incomes' => [1 => '1']], '/budget/saisie');
        self::assertResponseRedirects('/budget', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Mois invalide.', $this->flashes());
        self::assertSame([], self::service(BudgetRepository::class)->budgets(self::owner()));
    }

    public function testAmountsAreReadInTheLanguageOfThePage(): void
    {
        $lines = $this->givenAHousehold();
        $this->client->request('POST', '/langue', ['_csrf' => $this->csrfToken('/budget'), 'locale' => 'en', '_return' => '/budget']);

        $this->submit('/budget/saisie', ['mois' => '2026-11', 'incomes' => [1 => '3,000', 2 => '1,234.50'], 'amounts' => [$lines['rent'] => '1,000']], '/budget/saisie');

        self::assertResponseRedirects('/budget?mois=2026-11', Response::HTTP_SEE_OTHER);
        $budget = self::service(BudgetRepository::class)->budgetAt(self::owner(), Month::fromString('2026-11'));
        self::assertSame([1 => 300_000, 2 => 123_450], $budget?->incomes);
        self::assertSame(100_000, $budget->amount($lines['rent']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Budget November 2026');
        self::assertSelectorTextContains('#budget-rule-title', '50/30/20 rule');
    }

    public function testCategoriesAndItemsCanBeOrganised(): void
    {
        $lines = $this->givenAHousehold();
        $this->submit('/budget/categories', ['name' => 'Animaux', 'group' => 'wants', 'adult' => '2'], '/budget/foyer');
        self::assertResponseRedirects('/budget/foyer', Response::HTTP_SEE_OTHER);
        $category = $this->category('Animaux');
        self::assertSame(BudgetGroup::Wants, $category->group);
        self::assertSame(2, $category->adult);

        $this->submit('/budget/postes', ['name' => 'Vétérinaire', 'category_id' => (string) $category->id], '/budget/foyer');
        self::assertResponseRedirects('/budget/foyer', Response::HTTP_SEE_OTHER);
        $line = $this->line('Vétérinaire');

        // The item moves into the shared savings, tied to a child and to a savings account.
        $livret = self::createAccount('Livret A', 'livret_a', '2024-01-01');
        $savings = $this->category('Épargne du foyer');
        $this->submit("/budget/postes/{$line->id}", ['name' => 'Épargne Eva', 'category_id' => (string) $savings->id, 'child' => '1', 'account' => (string) $livret], "/budget/postes/{$line->id}");
        self::assertResponseRedirects('/budget/foyer', Response::HTTP_SEE_OTHER);
        $moved = self::service(BudgetRepository::class)->findLine(self::owner(), $line->id);
        self::assertEquals(new BudgetLine($line->id, $savings->id, 'Épargne Eva', 1, $livret), $moved);

        // An account is only kept for a savings item.
        $this->submit("/budget/postes/{$line->id}", ['name' => 'Épargne Eva', 'category_id' => (string) $category->id, 'child' => '', 'account' => (string) $livret], "/budget/postes/{$line->id}");
        self::assertNull(self::service(BudgetRepository::class)->findLine(self::owner(), $line->id)?->accountId);

        $this->submit("/budget/categories/{$category->id}", ['name' => 'Animaux de compagnie', 'group' => 'needs', 'adult' => ''], "/budget/categories/{$category->id}");
        self::assertSame('Animaux de compagnie', $this->category('Animaux de compagnie')->name);

        $this->submit("/budget/categories/{$category->id}/supprimer", [], "/budget/categories/{$category->id}");
        self::assertResponseRedirects('/budget/foyer', Response::HTTP_SEE_OTHER);
        self::assertNull(self::service(BudgetRepository::class)->findCategory(self::owner(), $category->id));
        self::assertNull(self::service(BudgetRepository::class)->findLine(self::owner(), $line->id), 'ses postes disparaissent avec elle');

        $this->submit("/budget/postes/{$lines['rent']}/supprimer", [], '/budget/foyer');
        self::assertNull(self::service(BudgetRepository::class)->findLine(self::owner(), $lines['rent']));
    }

    public function testInvalidCategoryOrItemIsRefused(): void
    {
        $this->givenAHousehold();
        $this->submit('/budget/categories', ['name' => '', 'group' => 'luxe', 'adult' => '3'], '/budget/foyer');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorExists('#err-name');
        self::assertSelectorExists('#err-group');
        self::assertSelectorExists('#err-adult');

        $before = count(self::service(BudgetRepository::class)->lines(self::owner()));
        $this->submit('/budget/postes', ['name' => 'Poste', 'category_id' => '424242'], '/budget/foyer');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertCount($before, self::service(BudgetRepository::class)->lines(self::owner()));
        $this->client->request('GET', '/budget/postes/424242');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnAdultWithOwnCategoriesCannotLeaveTheHousehold(): void
    {
        $this->givenAHousehold();
        $this->submit('/budget/foyer', ['adults' => '1', 'adult_names' => [1 => 'Flo', 2 => 'Nico'], 'adult_years' => [1 => '1985', 2 => '1983'], 'precaution_months' => '3'], '/budget/foyer');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('#err-adults', 'Nico a encore des catégories propres');
        self::assertTrue(self::service(BudgetRepository::class)->household(self::owner())?->isCouple());
    }

    public function testASecondAdultReceivesTheSuggestedCategories(): void
    {
        TestUsers::unlock(static::getContainer(), self::owner());
        self::service(HouseholdBudget::class)->saveHousehold(self::owner(), new Household([1 => 'Alex']), 'fr');
        self::assertCount(4 + 3, self::service(BudgetRepository::class)->categories(self::owner()));

        $this->submit('/budget/foyer', ['adults' => '2', 'adult_names' => [1 => 'Alex', 2 => 'Sam'], 'adult_years' => [1 => '1990', 2 => '1991'], 'precaution_months' => '3'], '/budget/foyer');

        self::assertResponseRedirects('/budget/foyer', Response::HTTP_SEE_OTHER);
        $categories = self::service(BudgetRepository::class)->categories(self::owner());
        self::assertCount(4 + 3 + 3, $categories);
        self::assertCount(3, array_filter($categories, static fn(BudgetCategory $category): bool => $category->adult === 2));
    }

    public function testBudgetIsReadWithTheNetWorthOfTheSameMonth(): void
    {
        $lines = $this->givenAHousehold();
        $livret = self::createAccount('Livret A', 'livret_a', '2024-01-01');
        $statements = self::service(StatementRepository::class);
        $statements->save(self::owner(), $livret, Month::fromString('2026-10'), 1_000_000);
        $statements->save(self::owner(), $livret, Month::fromString('2026-11'), 1_030_000);
        $budgets = self::service(BudgetRepository::class);
        $saving = $budgets->findLine(self::owner(), $lines['family_savings']);
        self::assertNotNull($saving);
        $budgets->updateLine(self::owner(), new BudgetLine($saving->id, $saving->categoryId, $saving->name, 1, $livret));
        $this->submit('/budget/saisie', [
            'mois' => '2026-11',
            'incomes' => [1 => '2000', 2 => '3000'],
            'amounts' => [$lines['rent'] => '1500', $lines['groceries'] => '1000', $lines['family_savings'] => '250'],
        ], '/budget/saisie');

        $crawler = $this->client->request('GET', '/budget?mois=2026-11');
        self::assertResponseIsSuccessful();
        $section = $crawler->filter('#budget-wealth-title')->ancestors()->first();
        $wealth = self::plain($section->text());
        // Key figures: net worth (+€300 over the month), €10,300 of emergency savings for €2,500 of spending per month.
        $tiles = $section->filter('.wealth-tile')->each(static fn($tile): string => trim(self::plain($tile->text())));
        self::assertSame(['Patrimoine net 10 300,00 € +300,00 € sur le mois', 'Épargne de précaution 10 300,00 € 4,1 mois de dépenses', 'Dépenses du mois 2 500,00 € besoins et envies, épargne non comprise'], $tiles);
        self::assertSame('/?mois=2026-11', $section->filter('.wealth-tile a')->attr('href'));
        // Gauge and finding: target of 4 months reached, without exceeding twice it.
        self::assertCount(1, $section->filter('svg.precaution-gauge.is-reached'));
        self::assertStringContainsString('Bon point Cible de 4 mois de dépenses atteinte (10 000,00 €), avec une marge de 300,00 €.', $wealth);
        // Planned payment of €250 against a balance gone from €10,000 to €10,300.
        $saving = $section->filter('.savings-item');
        self::assertCount(1, $saving);
        self::assertStringContainsString('is-reached', (string) $saving->attr('class'));
        self::assertSame('100', $saving->filter('.bar-change')->attr('width'), 'le plus grand montant donne l\'échelle');
        self::assertSame('83.3', $saving->filter('.bar-planned')->attr('width'));
        self::assertStringContainsString('Prévu 250,00 € Évolution du solde +300,00 €', self::plain($saving->text()));
        self::assertStringContainsString('Versement atteint.', $wealth);
        self::assertStringContainsString('10 300,00', self::plain($crawler->filter('.budget-networth')->text()), 'patrimoine net du mois');
        // Cost of the child: the savings item is tied to them.
        self::assertStringContainsString('250,00', self::plain($crawler->filter('#budget-children-title')->ancestors()->first()->text()));
    }

    public function testNothingIsStoredInClear(): void
    {
        $lines = $this->givenAHousehold();
        $this->submit('/budget/saisie', ['mois' => '2026-11', 'incomes' => [1 => '7 654,32'], 'amounts' => [$lines['rent'] => '1 234,56']], '/budget/saisie');

        $dump = '';
        foreach (['budget_households', 'budget_categories', 'budget_lines', 'budget_months'] as $table) {
            $rows = self::service(Connection::class)->fetchAllAssociative("SELECT * FROM $table");
            self::assertNotSame([], $rows, $table);
            foreach ($rows as $i => $row) {
                // Encrypted envelope, then masked: short clear words (« Eva ») randomly occur in base64.
                $ciphertext = $row['ciphertext'] ?? null;
                self::assertIsString($ciphertext, $table);
                self::assertStringStartsWith('v1.', $ciphertext, $table);
                $rows[$i]['ciphertext'] = 'v1.(masked)';
            }
            $dump .= json_encode($rows, JSON_THROW_ON_ERROR);
        }
        foreach (['Flo', 'Nico', 'Eva', 'Logement', 'Loyer', '765432', '123456', 'needs', 'fixed', 'income'] as $clear) {
            self::assertStringNotContainsString($clear, $dump, $clear);
        }
    }

    public function testQueryCountDoesNotDependOnTheNumberOfItems(): void
    {
        $lines = $this->givenAHousehold();
        $this->submit('/budget/saisie', ['mois' => '2026-11', 'incomes' => [1 => '2000'], 'amounts' => [$lines['rent'] => '800']], '/budget/saisie');
        $pages = ['/budget', '/budget/saisie', '/budget/foyer'];
        $few = array_map($this->queriesOf(...), $pages);

        $budgets = self::service(BudgetRepository::class);
        $category = $budgets->createCategory(self::owner(), 'Divers', BudgetGroup::Wants, 1);
        for ($i = 0; $i < 12; ++$i) {
            $budgets->createLine(self::owner(), $category, "Poste $i", 1);
        }
        self::createAccount('Autre livret', 'livret_a', '2024-01-01');

        self::assertSame($few, array_map($this->queriesOf(...), $pages));
    }

    public function testAChildOfWorkingAgeHasIncomesCountedInTheBudget(): void
    {
        $lines = $this->givenAHousehold();
        TestUsers::unlock(static::getContainer(), self::owner());
        self::service(HouseholdBudget::class)->saveHousehold(self::owner(), new Household([1 => 'Flo', 2 => 'Nico'], [1 => 'Eva'], 4, 2, [1 => 1985, 2 => 1983], [1 => 2009]), 'fr');

        $this->submit('/budget/saisie', [
            'mois' => '2026-11',
            'incomes' => [1 => '2000', 2 => '3000'],
            'child_incomes' => [1 => '450'],
            'amounts' => [$lines['rent'] => '1000'],
        ], '/budget/saisie');

        self::assertResponseRedirects('/budget?mois=2026-11', Response::HTTP_SEE_OTHER);
        $budget = self::service(HouseholdBudget::class)->view(self::owner(), Month::fromString('2026-11'))->budget;
        self::assertNotNull($budget);
        self::assertSame([1 => 45_000], $budget->childIncomes);
        $this->client->request('GET', '/budget?mois=2026-11');
        self::assertStringContainsString("5\u{202F}450,00\u{A0}€", $this->body(), 'household incomes, Eva\'s included');
        self::assertStringContainsString('Revenus de Eva', $this->body(), 'in the flow of the incomes');
        $this->client->request('GET', '/budget/saisie?mois=2026-11');
        self::assertInputValueSame('child_incomes[1]', '450,00');
    }

    /**
     * "Flo et Nico" household (one child, Eva; four months of emergency savings aimed at) with the suggested categories.
     *
     * @return array{rent: int, groceries: int, outings: int, family_savings: int, flo_sport: int, nico_savings: int}
     */
    private function givenAHousehold(): array
    {
        TestUsers::unlock(static::getContainer(), self::owner());
        self::service(HouseholdBudget::class)->saveHousehold(self::owner(), new Household([1 => 'Flo', 2 => 'Nico'], [1 => 'Eva'], 4, 2, [1 => 1985, 2 => 1983], [1 => 2015]), 'fr');

        return [
            'rent' => $this->line('Loyer ou crédit')->id,
            'groceries' => $this->line('Courses')->id,
            'outings' => $this->line('Sorties et restaurants')->id,
            'family_savings' => $this->line('Épargne de précaution')->id,
            'flo_sport' => $this->lineOf('Sport', 1)->id,
            'nico_savings' => $this->lineOf('PEA', 2)->id,
        ];
    }

    private function category(string $name): BudgetCategory
    {
        foreach (self::service(BudgetRepository::class)->categories(self::owner()) as $category) {
            if ($category->name === $name) {
                return $category;
            }
        }
        self::fail("Catégorie absente : $name");
    }

    private function line(string $name): BudgetLine
    {
        foreach (self::service(BudgetRepository::class)->lines(self::owner()) as $line) {
            if ($line->name === $name) {
                return $line;
            }
        }
        self::fail("Poste absent : $name");
    }

    /** Item of a category belonging to the adult. */
    private function lineOf(string $name, int $adult): BudgetLine
    {
        $budgets = self::service(BudgetRepository::class);
        foreach ($budgets->lines(self::owner()) as $line) {
            if ($line->name === $name && $budgets->findCategory(self::owner(), $line->categoryId)?->adult === $adult) {
                return $line;
            }
        }
        self::fail("Poste absent : $name ($adult)");
    }

    private function queriesOf(string $page): int
    {
        $this->client->request('GET', $page);
        $before = QueryCounter::$count;
        $this->client->request('GET', $page);
        self::assertResponseIsSuccessful();

        return QueryCounter::$count - $before;
    }

    /** French amounts carry no-break spaces. */
    private static function plain(string $text): string
    {
        return (string) preg_replace('/\s+/u', ' ', str_replace(["\u{202F}", "\u{00A0}"], ' ', $text));
    }
}
