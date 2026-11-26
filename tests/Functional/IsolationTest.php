<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Carbure\CarbureLink;
use Patrimeter\Domain\Goal\GoalScope;
use Patrimeter\Domain\Goal\SavingsGoal;
use Patrimeter\Domain\Indicator\CustomIndicator;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\CarbureLinkRepository;
use Patrimeter\Infrastructure\Persistence\CustomIndicatorRepository;
use Patrimeter\Infrastructure\Persistence\SavingsGoalRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FakeCarbure;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\RouteCatalog;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * Isolation robot: a second user goes through every data route with the first one's identifiers, for reading as
 * for writing. They must never see nor change anything: another user's account cannot be found (404), and its data
 * appears in no page (HTML or embedded JSON). Every route of RouteCatalog::OWNED_DATA must be exercised here.
 */
final class IsolationTest extends FunctionalTestCase
{
    /** Unique marks of the victim's data, searched for in each page of the intruder. */
    private const array VICTIM_NAMES = [
        'Livret-Victime-7f3a', 'Pret-Victime-9c1e', 'Ancien-Victime-4b2d',
        'Adulte-Victime-6b1e', 'Categorie-Victime-3d5f', 'Poste-Victime-8a2c',
        'Membre-Victime-2e7b', 'Livret-Membre-Victime-5c9d', 'carbure-victime-6d1a', 'Objectif-Victime-1f4c', 'Indicateur-Victime-3a8e',
    ];
    private const int VICTIM_AMOUNT_CENTS = 7_654_321;
    private const string VICTIM_AMOUNT_TEXT = '76 543,21';

    /** @var array{livret: int, loan: int, archived: int} */
    private array $victim;
    /** @var array{category: int, line: int} */
    private array $victimBudget;
    private int $intruderAccount;
    private int $victimMember;
    private int $victimGoal;

    private int $victimIndicator;

    /** Production error pages: the debug page would show the tests' source code. */
    #[\Override]
    protected static function clientOptions(): array
    {
        return ['debug' => false];
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $owner = self::owner();
        $this->victim = [
            'livret' => self::createAccount(self::VICTIM_NAMES[0], 'livret_a', '2024-01-01', $owner),
            'loan' => self::createAccount(self::VICTIM_NAMES[1], 'credit_immo_rp', '2024-01-01', $owner),
            'archived' => self::createAccount(self::VICTIM_NAMES[2], 'compte_courant', '2024-01-01', $owner),
        ];
        $statements = self::service(StatementRepository::class);
        $statements->save($owner, $this->victim['livret'], Month::fromString('2026-10'), self::VICTIM_AMOUNT_CENTS);
        $statements->save($owner, $this->victim['loan'], Month::fromString('2026-10'), 12_000_000);
        $statements->save($owner, $this->victim['archived'], Month::fromString('2025-06'), 50_000);
        self::service(AccountRepository::class)->archive($owner, $this->victim['archived'], '2025-12-31');
        $this->victimBudget = self::createBudget($owner, self::VICTIM_NAMES[3], self::VICTIM_NAMES[4], self::VICTIM_NAMES[5], self::VICTIM_AMOUNT_CENTS);
        self::service(CarbureLinkRepository::class)->save($owner, new CarbureLink('https://carbure-victime-6d1a.test', FakeCarbure::TOKEN, [1 => 'Logement'], [1 => $this->victimBudget['category']]));
        $this->victimMember = self::createFamilyMember($owner, self::VICTIM_NAMES[6], self::VICTIM_NAMES[7], self::VICTIM_AMOUNT_CENTS);
        $this->victimGoal = self::service(SavingsGoalRepository::class)->create($owner, new SavingsGoal(
            0,
            self::VICTIM_NAMES[9],
            self::VICTIM_AMOUNT_CENTS,
            Month::fromString('2028-06'),
            GoalScope::Accounts,
            null,
            [$this->victim['livret']],
            Month::fromString('2026-10'),
            self::VICTIM_AMOUNT_CENTS - 100,
        ));
        $this->victimIndicator = self::service(CustomIndicatorRepository::class)->create($owner, new CustomIndicator(null, self::VICTIM_NAMES[10], [Level::Precaution], [$this->victim['livret']], true));

        $intruder = TestUsers::create(static::getContainer(), TestUsers::OTHER_EMAIL);
        $this->intruderAccount = self::createAccount('Compte de l\'intrus', 'compte_courant', '2025-01-01', $intruder);
        self::service(StatementRepository::class)->save($intruder, $this->intruderAccount, Month::fromString('2026-10'), 100_00);
        self::createBudget($intruder, 'Intrus', 'Logement', 'Loyer', 100_00);
    }

    public function testIntruderCanNeitherSeeNorChangeAnotherUsersData(): void
    {
        $before = $this->victimData();
        $this->logIn(TestUsers::OTHER_EMAIL);

        $exercised = [];
        foreach (RouteCatalog::routes(self::service(RouterInterface::class)) as $name => $route) {
            if (!in_array($name, RouteCatalog::OWNED_DATA, true)) {
                continue;
            }
            // The victim's identifier: an account, a category or an item of their budget, or a member of their family.
            $id = match (true) {
                str_starts_with($name, 'app_budget_category_') => $this->victimBudget['category'],
                str_starts_with($name, 'app_budget_line_') => $this->victimBudget['line'],
                str_starts_with($name, 'app_family_member_') => $this->victimMember,
                str_starts_with($name, 'app_goal_') => $this->victimGoal,
                str_starts_with($name, 'app_indicator_') => $this->victimIndicator,
                default => $this->victim['archived'],
            };
            $path = RouteCatalog::path($route, ['id' => (string) $id, 'month' => '2025-06']);
            foreach ($route->getMethods() as $method) {
                if ($method === 'GET') {
                    // Wealth page with its whole history (by level); the former history page leads there.
                    $this->client->request('GET', $path . ($name === 'app_dashboard' ? '?periode=tout&vue=niveaux' : ''));
                    if ($this->client->getResponse()->isRedirect()) {
                        $this->client->followRedirect();
                    }
                } else {
                    $this->submit($path, $this->payload($name));
                }
                $this->assertOutcome($name, $method, $path, str_contains($route->getPath(), '{id}'));
                $exercised[] = $name;
            }
        }

        self::assertSame([], array_diff(RouteCatalog::OWNED_DATA, $exercised), 'toutes les routes de données sont exercées');
        self::assertSame($before, $this->victimData(), 'aucune donnée de la victime modifiée');
    }

    public function testACreditCannotBeLinkedToAnotherUsersProperty(): void
    {
        $victimHouse = self::createAccount('Maison-Victime-1f8a', 'residence_principale', '2024-01-01', self::owner());
        $intruder = $this->intruderId();
        $loan = self::createAccount('Crédit de l\'intrus', 'credit_immo_rp', '2025-01-01', $intruder);
        $this->logIn(TestUsers::OTHER_EMAIL);

        $this->submit("/comptes/$loan/bien", ['asset_id' => (string) $victimHouse, 'loan_mode' => 'prorata']);
        $this->client->followRedirect();

        self::assertStringContainsString('Choisissez un bien.', $this->flashes());
        self::assertStringNotContainsString('Maison-Victime', $this->body(), 'le bien d\'autrui n\'est pas proposé');
        $this->client->request('GET', '/biens');
        self::assertStringNotContainsString('Maison-Victime', $this->body());
    }

    public function testEveryAccountIdOfTheVictimIsUnreachable(): void
    {
        $this->logIn(TestUsers::OTHER_EMAIL);

        foreach ($this->victim as $id) {
            $this->client->request('GET', "/comptes/$id");
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            self::assertStringNotContainsString('Victime', $this->body());
        }
        $this->client->request('GET', '/comptes/' . $this->intruderAccount);
        self::assertResponseIsSuccessful('son propre compte reste accessible');
    }

    public function testMonthlyEntryIgnoresAnotherUsersAccountIds(): void
    {
        $before = $this->victimData();
        $this->logIn(TestUsers::OTHER_EMAIL);

        $this->submit('/saisie', ['mois' => '2026-11', 'amounts' => [
            $this->victim['livret'] => '1',
            $this->victim['loan'] => '',
            $this->intruderAccount => '200',
        ]]);

        self::assertResponseRedirects('/?mois=2026-11', Response::HTTP_SEE_OTHER);
        self::assertSame($before, $this->victimData());
        self::assertSame(['2026-11' => 20_000, '2026-10' => 10_000], self::service(StatementRepository::class)->forAccount($this->intruderId(), $this->intruderAccount));
    }

    public function testRepositoriesReturnNothingOfAnotherUser(): void
    {
        $intruder = $this->intruderId();
        $accounts = self::service(AccountRepository::class);

        self::assertNull($accounts->find($intruder, $this->victim['livret']));
        self::assertSame([$this->intruderAccount], array_map(static fn($a): int => $a->id, $accounts->all($intruder)));
        self::assertSame([$this->intruderAccount], array_keys(self::service(StatementRepository::class)->all($intruder)));

        // Writes aimed at someone else's account: without effect.
        $accounts->update($intruder, $this->victim['livret'], ['name' => 'Piraté', 'type_id' => 4, 'level_code' => 'precaution', 'nature' => 'asset', 'opened_on' => '2024-01-01']);
        $accounts->delete($intruder, $this->victim['livret']);
        self::service(StatementRepository::class)->delete($intruder, $this->victim['livret'], Month::fromString('2026-10'));
        self::assertSame(self::VICTIM_NAMES[0], $accounts->find(self::owner(), $this->victim['livret'])?->name);
        self::assertSame(['2026-10' => self::VICTIM_AMOUNT_CENTS], self::service(StatementRepository::class)->forAccount(self::owner(), $this->victim['livret']));
    }

    public function testDatabaseRefusesAStatementOfAnotherUsersAccount(): void
    {
        $before = $this->victimData();

        try {
            self::service(StatementRepository::class)->save($this->intruderId(), $this->victim['livret'], Month::fromString('2026-11'), 1);
            self::fail('Clé étrangère composite attendue');
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
            self::assertSame($before, $this->victimData());
        }
    }

    /** @return array<string, mixed> plausible fields for each form */
    private function payload(string $route): array
    {
        return match ($route) {
            'app_account_create', 'app_account_update' => ['name' => 'Piraté', 'type_id' => '4', 'level_code' => 'precaution', 'nature' => 'asset', 'opened_on' => '2024-01-01', 'initial_amount' => '1'],
            'app_account_archive' => ['closed_on' => '2026-11-01'],
            'app_property_create' => ['name' => 'Bien', 'type_id' => (string) self::queryInt("SELECT id FROM account_types WHERE code = 'lmnp'"), 'opened_on' => '2024-01-01', 'value' => '1000', 'share' => '100', 'loans' => [['name' => 'Crédit', 'amount' => '10', 'mode' => 'prorata']]],
            'app_share_save' => ['mois' => '2026-01', 'pourcentage' => '50'],
            'app_account_link' => ['asset_id' => (string) $this->victim['livret'], 'loan_mode' => 'own'],
            'app_statement_save' => ['mois' => '2025-06', 'montant' => '999'],
            'app_entry_save' => ['mois' => '2025-06', 'amounts' => [$this->victim['archived'] => '999', $this->victim['livret'] => '999']],
            'app_budget_entry_save' => ['mois' => '2025-06', 'incomes' => [1 => '999'], 'amounts' => [$this->victimBudget['line'] => '999']],
            'app_budget_household_save' => ['adults' => '1', 'adult_names' => [1 => 'Piraté'], 'adult_years' => [1 => '1980'], 'precaution_months' => '3'],
            'app_budget_category_create', 'app_budget_category_update' => ['name' => 'Piraté', 'group' => 'wants', 'adult' => ''],
            // Item filed in the victim's category: refused like an unknown category.
            'app_budget_line_create' => ['name' => 'Piraté', 'category_id' => (string) $this->victimBudget['category']],
            'app_budget_line_update' => ['name' => 'Piraté', 'category_id' => (string) $this->victimBudget['category'], 'child' => '', 'account' => (string) $this->victim['livret']],
            'app_family_member_add' => ['name' => 'Piraté'],
            // The victim's account is left out: nothing to add up, refused like an empty indicator.
            'app_indicator_create', 'app_indicator_update' => ['name' => 'Piraté', 'accounts' => [(string) $this->victim['livret']]],
            'app_goal_create', 'app_goal_update' => ['name' => 'Piraté', 'target' => '1000', 'target_month' => '2028-01', 'scope' => 'accounts', 'level' => '', 'accounts' => [(string) $this->victim['livret']]],
            'app_budget_carbure_save' => ['url' => FakeCarbure::URL, 'token' => FakeCarbure::TOKEN],
            'app_budget_carbure_mapping' => ['mapping' => [1 => (string) $this->victimBudget['category']]],
            default => [],
        };
    }

    private function assertOutcome(string $name, string $method, string $path, bool $victimId): void
    {
        $label = "$method $path ($name)";
        if ($victimId) {
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $label);
        } elseif (in_array($name, ['app_budget_line_create', 'app_goal_create', 'app_indicator_create'], true)) {
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $label);
        } elseif ($method === 'POST') {
            self::assertResponseRedirects(null, Response::HTTP_SEE_OTHER, $label);
            $this->client->followRedirect();
        } else {
            self::assertResponseIsSuccessful($label);
        }
        $body = $this->body();
        foreach (self::VICTIM_NAMES as $marker) {
            self::assertStringNotContainsString($marker, $body, "$label : nom d'un compte d'autrui affiché");
        }
        self::assertStringNotContainsString(self::VICTIM_AMOUNT_TEXT, $body, "$label : montant d'autrui affiché");
        self::assertStringNotContainsString((string) self::VICTIM_AMOUNT_CENTS, $body, "$label : montant d'autrui dans les données du graphique");
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function victimData(): array
    {
        $connection = self::service(Connection::class);

        return [
            'accounts' => $connection->fetchAllAssociative('SELECT * FROM accounts WHERE user_id = ? ORDER BY id', [self::owner()->value]),
            'statements' => $connection->fetchAllAssociative('SELECT * FROM statements WHERE user_id = ? ORDER BY account_id, month', [self::owner()->value]),
            'shares' => $connection->fetchAllAssociative('SELECT * FROM ownership_shares WHERE user_id = ? ORDER BY account_id, effective_month', [self::owner()->value]),
            'budget_households' => $connection->fetchAllAssociative('SELECT * FROM budget_households WHERE user_id = ?', [self::owner()->value]),
            'budget_categories' => $connection->fetchAllAssociative('SELECT * FROM budget_categories WHERE user_id = ? ORDER BY id', [self::owner()->value]),
            'budget_lines' => $connection->fetchAllAssociative('SELECT * FROM budget_lines WHERE user_id = ? ORDER BY id', [self::owner()->value]),
            'budget_months' => $connection->fetchAllAssociative('SELECT * FROM budget_months WHERE user_id = ? ORDER BY month', [self::owner()->value]),
            'family_members' => $connection->fetchAllAssociative('SELECT * FROM family_members WHERE user_id = ? ORDER BY id', [self::owner()->value]),
            'carbure_links' => $connection->fetchAllAssociative('SELECT * FROM carbure_links WHERE user_id = ?', [self::owner()->value]),
            'savings_goals' => $connection->fetchAllAssociative('SELECT * FROM savings_goals WHERE user_id = ? ORDER BY id', [self::owner()->value]),
            'custom_indicators' => $connection->fetchAllAssociative('SELECT * FROM custom_indicators WHERE user_id = ? ORDER BY id', [self::owner()->value]),
        ];
    }

    private function intruderId(): UserId
    {
        return TestUsers::findOrCreate(static::getContainer(), TestUsers::OTHER_EMAIL);
    }
}
