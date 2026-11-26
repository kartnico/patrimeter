<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Goal\GoalScope;
use Patrimeter\Domain\Goal\SavingsGoal;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\SavingsGoalRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Savings goals (My account > Goals): creation with its starting point, validation, change, deletion, card of the
 * dashboard, and goals whose status changes with the monthly entry. Clock frozen in November 2026.
 */
final class GoalsTest extends FunctionalTestCase
{
    private int $livret;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->livret = self::createAccount('Livret A', 'livret_a', '2025-01-01');
        $statements = self::service(StatementRepository::class);
        foreach (Month::range(Month::fromString('2026-05'), Month::fromString('2026-11')) as $index => $month) {
            $statements->save(self::owner(), $this->livret, $month, 600_000 + $index * 10_000);
        }
        $this->logIn();
    }

    public function testAGoalIsCreatedFromThisMonthsValue(): void
    {
        $crawler = $this->client->request('GET', '/objectifs');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aucun objectif pour l\'instant', $crawler->filter('.empty-state')->text());
        self::assertSelectorExists('nav.nav a[aria-current][href="/objectifs"]', 'Goals in the main navigation');
        $crawler = $this->client->request('GET', '/saisie/objectifs');
        self::assertSelectorExists('nav.nav a[aria-current][href="/saisie"]', 'the entry of the goals belongs to the monthly entry');
        self::assertSelectorExists('nav.entry-parts a[aria-current][href="/saisie/objectifs"]');
        self::assertCount(1, $crawler->filter('a[href="/saisie/objectifs/nouveau"]'));

        $this->submit('/saisie/objectifs/nouveau', ['name' => 'Précaution', 'target' => '12 000', 'target_month' => '2027-11', 'scope' => 'level', 'level' => 'precaution'], '/saisie/objectifs/nouveau');
        self::assertResponseRedirects('/saisie/objectifs', Response::HTTP_SEE_OTHER);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Objectif « Précaution » créé', $this->flashes());
        self::assertStringContainsString('Précaution', $crawler->filter('table.goal-list')->text());

        $goal = $this->client->request('GET', '/objectifs')->filter('section.goal');
        self::assertStringContainsString('Mesuré sur le niveau Épargne de précaution', self::plain($goal->text()));
        self::assertStringContainsString('départ : 6 600,00 € en novembre 2026', self::plain($goal->text()));
        self::assertStringContainsString('Trop tôt pour juger', $goal->filter('.goal-status')->text());
        self::assertStringContainsString('Il faut mettre de côté 450,00 € par mois jusqu\'en novembre 2027.', self::plain($goal->text()));
        self::assertStringContainsString('Le rythme se mesurera à partir du mois prochain.', self::plain($goal->text()));
        // Gauge: 55 % reached; the planned trajectory gives 55 % too in its first month.
        self::assertSame('55 %', self::plain($goal->filter('svg.goal-gauge .goal-gauge-value')->text()));
        self::assertStringContainsString('la trajectoire prévue en donne 55 % ce mois-ci', (string) $goal->filter('svg.goal-gauge')->attr('aria-label'));
        self::assertSame(['Montant actuel', 'Cible', 'Effort nécessaire', 'Rythme constaté'], $goal->filter('.goal-stats dt')->each(static fn($term): string => $term->text()));
        self::assertSame('à mesurer', $goal->filter('.goal-stats dd')->last()->text());
    }

    public function testAGoalStartsFromAChosenPastMonth(): void
    {
        $crawler = $this->client->request('GET', '/saisie/objectifs/nouveau');
        self::assertSame('2026-11', $crawler->filter('#start_month')->attr('value'), 'this month by default');
        self::assertSame(['amount', 'level'], [$crawler->filter('select#kind option[selected]')->attr('value'), $crawler->filter('select#scope option[selected]')->attr('value')], 'two lists');
        self::assertSame('kind', $crawler->filter('[data-goal-when][data-goal-value="amount"]')->attr('data-goal-when'), 'fields shown according to the lists');

        $this->submit('/saisie/objectifs/nouveau', ['name' => 'X', 'target' => '10 000', 'start_month' => '2026-12', 'target_month' => '2027-11', 'scope' => 'net', 'level' => ''], '/saisie/objectifs/nouveau');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorExists('#err-start_month');
        $this->submit('/saisie/objectifs/nouveau', ['name' => 'X', 'target' => '10 000', 'start_month' => '2026-07', 'target_month' => '2026-07', 'scope' => 'net', 'level' => ''], '/saisie/objectifs/nouveau');
        self::assertSelectorExists('#err-target_month', 'the target comes after the start');

        $this->submit('/saisie/objectifs/nouveau', ['name' => 'Depuis juillet', 'target' => '10 000', 'start_month' => '2026-07', 'target_month' => '2027-11', 'scope' => 'net', 'level' => ''], '/saisie/objectifs/nouveau');
        self::assertResponseRedirects('/saisie/objectifs', Response::HTTP_SEE_OTHER);
        $goals = self::service(SavingsGoalRepository::class)->all(self::owner());
        self::assertSame(['2026-07', 620_000], [$goals[0]->startMonth->toString(), $goals[0]->startCents]);
    }

    public function testInvalidFieldsAreExplained(): void
    {
        $this->submit('/saisie/objectifs/nouveau', ['name' => '', 'target' => '-5', 'target_month' => '2026-11', 'scope' => 'accounts', 'level' => '', 'accounts' => ['999999']], '/saisie/objectifs/nouveau');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        foreach (['name', 'target', 'target_month', 'accounts'] as $field) {
            self::assertSelectorExists("#err-$field", $field);
        }
        $this->submit('/saisie/objectifs/nouveau', ['name' => 'X', 'target' => '10', 'target_month' => '2027-01', 'scope' => 'ailleurs', 'level' => ''], '/saisie/objectifs/nouveau');
        self::assertSelectorExists('#err-scope');
        $this->submit('/saisie/objectifs/nouveau', ['name' => 'X', 'target' => '10', 'target_month' => '2027-01', 'scope' => 'level', 'level' => 'lune'], '/saisie/objectifs/nouveau');
        self::assertSelectorExists('#err-level');
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM savings_goals'));
    }

    public function testAGoalIsChangedThenDeleted(): void
    {
        $id = $this->givenAGoal();
        $crawler = $this->client->request('GET', "/saisie/objectifs/$id");
        self::assertResponseIsSuccessful();
        self::assertSame('12000,00', $crawler->filter('#target')->attr('value'));
        self::assertCount(1, $crawler->filter('input[name="accounts[]"][checked]'));

        $this->submit("/saisie/objectifs/$id", ['name' => 'Apport maison', 'target' => '20000', 'target_month' => '2028-06', 'scope' => 'net', 'level' => ''], "/saisie/objectifs/$id");
        self::assertResponseRedirects('/saisie/objectifs', Response::HTTP_SEE_OTHER);
        $goal = self::service(SavingsGoalRepository::class)->find(self::owner(), $id);
        self::assertNotNull($goal);
        self::assertSame(['Apport maison', 2_000_000, '2028-06', GoalScope::NetWorth, '2026-05', 600_000], [$goal->name, $goal->targetCents, $goal->targetMonth->toString(), $goal->scope, $goal->startMonth->toString(), $goal->startCents], 'starting point kept');

        $this->submit("/saisie/objectifs/$id", ['name' => 'Apport maison', 'target' => 'beaucoup', 'target_month' => '2028-06', 'scope' => 'net', 'level' => ''], "/saisie/objectifs/$id");
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->submit("/saisie/objectifs/$id/supprimer", [], "/saisie/objectifs/$id");
        $this->client->followRedirect();
        self::assertStringContainsString('Objectif « Apport maison » supprimé.', $this->flashes());
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM savings_goals'));
        $this->client->request('GET', "/saisie/objectifs/$id");
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheGoalsPageTellsWhetherTheGoalIsOnTrack(): void
    {
        $this->givenAGoal();

        $crawler = $this->client->request('GET', '/objectifs');
        $card = $crawler->filter('section.goal');
        self::assertCount(1, $card);
        self::assertStringContainsString('En retard', $card->filter('.goal-status')->text());
        self::assertStringContainsString('Il faut mettre de côté 450,00 € par mois jusqu\'en novembre 2027.', self::plain($card->text()));
        self::assertStringContainsString('Rythme constaté : 100,00 € par mois (moyenne des 6 derniers mois).', self::plain($card->text()));
        self::assertStringContainsString('Il manque 350,00 € par mois.', self::plain($card->text()));
        self::assertStringContainsString('À ce rythme, objectif atteint en mai 2031, 42 mois après la date prévue.', self::plain($card->text()));

        self::assertCount(6, $crawler->filter('.goal-history li'));
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.goals-card', 'no longer on the wealth page');
    }

    public function testAGoalWhoseStatusChangesIsToldAfterTheEntry(): void
    {
        $this->givenAGoal();

        $this->submit('/saisie', ['mois' => '2026-11', 'amounts' => [$this->livret => '11 500']], '/saisie');
        $this->client->followRedirect();

        self::assertStringContainsString('Objectif « Apport » : En retard → En avance.', $this->flashes());
    }

    public function testAGoalInMonthsOfSpendingFollowsTheBudget(): void
    {
        $this->submit('/saisie/objectifs/nouveau', ['name' => 'Précaution', 'kind' => 'spending', 'spending_months' => '6', 'target' => '', 'target_month' => '2027-11', 'scope' => 'accounts', 'level' => '', 'accounts' => [(string) $this->livret]], '/saisie/objectifs/nouveau');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('Saisissez d\'abord le budget du foyer', $this->client->getCrawler()->filter('#err-spending_months')->text());

        $owner = self::owner();
        $ids = self::createBudget($owner, 'Camille', 'Logement', 'Loyer', 100_000, '2026-11');
        $this->submit('/saisie/objectifs/nouveau', ['name' => 'Précaution', 'kind' => 'spending', 'spending_months' => '6', 'target' => '', 'target_month' => '2027-11', 'scope' => 'accounts', 'level' => '', 'accounts' => [(string) $this->livret]], '/saisie/objectifs/nouveau');
        self::assertResponseRedirects('/saisie/objectifs', Response::HTTP_SEE_OTHER);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('6 mois de dépenses', self::plain($crawler->filter('table.goal-list')->text()));
        $crawler = $this->client->request('GET', '/objectifs');
        self::assertStringContainsString('cible : 6 mois de dépenses du budget', self::plain($crawler->filter('section.goal')->text()));
        self::assertStringContainsString('Cible 6 000,00 €', self::plain($crawler->filter('section.goal .goal-stats')->text()));

        // The spending rises: the target follows.
        $budgets = self::service(BudgetRepository::class);
        $budgets->saveBudget($owner, new MonthlyBudget(Month::fromString('2026-11'), [1 => 300_000], [$ids['line'] => 150_000]));
        $crawler = $this->client->request('GET', '/objectifs');
        self::assertStringContainsString('Cible 9 000,00 €', self::plain($crawler->filter('section.goal .goal-stats')->text()));

        $this->submit('/saisie/objectifs/nouveau', ['name' => 'X', 'kind' => 'spending', 'spending_months' => '99', 'target' => '', 'target_month' => '2027-11', 'scope' => 'net', 'level' => ''], '/saisie/objectifs/nouveau');
        self::assertStringContainsString('de 1 à 36', $this->client->getCrawler()->filter('#err-spending_months')->text());
    }

    public function testTheBudgetsPlannedSavingsAreComparedWithTheEffortNeeded(): void
    {
        $owner = self::owner();
        $ids = self::createBudget($owner, 'Camille', 'Épargne', 'Divers', 1_000, '2026-11');
        $budgets = self::service(BudgetRepository::class);
        $saving = $budgets->createLine($owner, $ids['category'], 'Livret', null, $this->livret);
        $budgets->saveBudget($owner, new MonthlyBudget(Month::fromString('2026-11'), [1 => 300_000], [$ids['line'] => 1_000, $saving => 30_000]));
        $this->givenAGoal();

        $crawler = $this->client->request('GET', '/objectifs');

        self::assertStringContainsString('Votre budget prévoit 300,00 € par mois vers ces comptes : il en faudrait 450,00 €.', self::plain($crawler->filter('section.goal')->text()));
        $chart = $crawler->filter('figure.goal-chart');
        self::assertCount(1, $chart);
        $canvas = $chart->filter('canvas[data-goal-chart]');
        self::assertSame('Projection de l\'objectif Apport', $canvas->attr('aria-label'));
        self::assertStringContainsString('Au rythme actuel : 7 800 € en novembre 2027.', self::plain($chart->filter('figcaption')->text()));
        $data = json_decode((string) $canvas->attr('data-goal-chart'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        // From 2026-05 (first statement) to 2027-11 (target): 19 months.
        self::assertCount(19, (array) ($data['labels'] ?? []));
        self::assertSame([600_000, 610_000], array_slice((array) ($data['actual'] ?? []), 0, 2), 'reached, month after month');
        self::assertSame(780_000, ((array) ($data['projection'] ?? []))[18] ?? null, 'at the current pace by the target month');
        self::assertSame(1_200_000, ((array) ($data['plan'] ?? []))[18] ?? null, 'planned trajectory up to the target');
    }

    public function testTheGoalsPageInvitesToSetAGoal(): void
    {
        $crawler = $this->client->request('GET', '/objectifs');

        self::assertStringContainsString('Voir vos objectifs', $crawler->filter('.page-head')->text());
        self::assertCount(1, $crawler->filter('.empty-state a[href="/saisie/objectifs/nouveau"]'));
    }

    public function testTheNumberOfGoalsIsLimited(): void
    {
        for ($i = 0; $i < SavingsGoalRepository::MAX_GOALS; ++$i) {
            $this->givenAGoal();
        }
        $this->client->request('GET', '/saisie/objectifs');
        self::assertSelectorNotExists('a[href="/saisie/objectifs/nouveau"]');

        $this->submit('/saisie/objectifs/nouveau', ['name' => 'Encore', 'target' => '10', 'target_month' => '2027-01', 'scope' => 'net', 'level' => ''], '/objectifs');
        $this->client->followRedirect();
        self::assertStringContainsString('Vous avez déjà 20 objectifs', $this->flashes());
    }

    /** Text with the spaces of the amounts (narrow no-break, no-break) as plain spaces. */
    private static function plain(string $text): string
    {
        return str_replace(["\u{202F}", "\u{A0}"], ' ', $text);
    }

    /** Goal on the Livret A: 12 000 € by 2027-11, started at 6 000 € in 2026-05 (the Livret grows by 100 € a month). */
    private function givenAGoal(): int
    {
        return self::service(SavingsGoalRepository::class)->create(self::owner(), new SavingsGoal(
            0,
            'Apport',
            1_200_000,
            Month::fromString('2027-11'),
            GoalScope::Accounts,
            null,
            [$this->livret],
            Month::fromString('2026-05'),
            600_000,
        ));
    }
}
