<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Application\Budget\HouseholdBudget;
use Patrimeter\Application\Payload\ClosePayload;
use Patrimeter\Application\Payload\LoanLinkPayload;
use Patrimeter\Application\Payload\SharePayload;
use Patrimeter\Application\Payload\StatementPayload;
use Patrimeter\Application\PortfolioService;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Budget\SplitRule;
use Patrimeter\Domain\Goal\GoalScope;
use Patrimeter\Domain\Goal\SavingsGoal;
use Patrimeter\Domain\Indicator\CustomIndicator;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\CustomIndicatorRepository;
use Patrimeter\Infrastructure\Persistence\SavingsGoalRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/** Export of a user's data and import into the empty account of another instance. */
final class DataTransferTest extends FunctionalTestCase
{
    private const string NEWCOMER = 'nouvelle-instance@example.test';

    public function testAnExportImportedIntoAnEmptyAccountGivesTheSameData(): void
    {
        $owner = self::owner();
        $portfolio = self::service(PortfolioService::class);
        $house = self::createAccount('Maison', 'residence_principale', '2020-03-01');
        $loan = self::createAccount('Prêt de la maison', 'credit_immo_rp', '2020-03-01');
        $savings = self::createAccount('Livret clôturé', 'livret_a', '2021-01-01');
        $portfolio->saveStatement($owner, $house, '2026-10', new StatementPayload(32_000_000));
        $portfolio->saveStatement($owner, $loan, '2026-10', new StatementPayload(15_000_000));
        $portfolio->saveStatement($owner, $savings, '2022-06', new StatementPayload(500_012));
        $portfolio->saveShare($owner, $house, '2020-03', new SharePayload(500_000));
        $portfolio->linkLoan($owner, $loan, new LoanLinkPayload($house, 'own'));
        $portfolio->archiveAccount($owner, $savings, new ClosePayload('2023-01-15'));

        $this->logIn();
        $export = $this->export();
        self::assertSame('patrimeter', $export['format']);
        self::assertIsArray($export['accounts']);
        self::assertCount(3, $export['accounts']);

        TestUsers::create(static::getContainer(), self::NEWCOMER);
        $this->restoreSession([]);
        $this->logIn(self::NEWCOMER);
        $this->import(json_encode($export, JSON_THROW_ON_ERROR));
        self::assertResponseRedirects('/comptes', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('3 comptes importés, avec leurs relevés', $this->flashes());

        self::assertSame(self::comparable($export), self::comparable($this->export()), 'mêmes comptes, relevés, parts et liens');
    }

    public function testTheHouseholdBudgetTravelsWithTheExport(): void
    {
        $owner = self::owner();
        $livret = self::createAccount('Livret A', 'livret_a', '2024-01-01');
        TestUsers::unlock(static::getContainer(), $owner);
        self::service(HouseholdBudget::class)->saveHousehold($owner, new Household([1 => 'Flo', 2 => 'Nico'], [1 => 'Eva'], 4, 2, [1 => 1985, 2 => 1983], [1 => 2008]), 'fr');
        $budgets = self::service(BudgetRepository::class);
        $lines = $budgets->lines($owner);
        $saving = $lines[11];
        $budgets->updateLine($owner, new BudgetLine($saving->id, $saving->categoryId, $saving->name, 1, $livret));
        $budgets->saveBudget($owner, new MonthlyBudget(Month::fromString('2026-09'), [1 => 186_000, 2 => 414_300], [$lines[0]->id => 150_801, $saving->id => 30_000], SplitRule::Fixed, 275_000, [1 => 45_000]));
        $budgets->saveBudget($owner, new MonthlyBudget(Month::fromString('2026-11'), [1 => 190_000], [$lines[0]->id => 151_000]));

        $this->logIn();
        $export = $this->export();
        self::assertSame(4, $export['version']);
        self::assertIsArray($export['budget']);
        self::assertSame(['Flo', 'Nico'], $export['budget']['adults']);
        self::assertSame([1985, 1983], $export['budget']['adult_birth_years']);
        self::assertSame([['ref' => 1, 'name' => 'Eva', 'birth_year' => 2008]], $export['budget']['children']);

        TestUsers::create(static::getContainer(), self::NEWCOMER);
        $this->restoreSession([]);
        $this->logIn(self::NEWCOMER);
        $this->import(json_encode($export, JSON_THROW_ON_ERROR));
        self::assertResponseRedirects('/comptes', Response::HTTP_SEE_OTHER);

        $copy = $this->export();
        self::assertSame(self::comparableBudget($export), self::comparableBudget($copy), 'même foyer, mêmes catégories, postes et montants');
        $this->client->request('GET', '/budget?mois=2026-10');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('reporté', $this->body());
    }

    public function testTheSavingsGoalsTravelWithTheExport(): void
    {
        $owner = self::owner();
        $livret = self::createAccount('Livret A', 'livret_a', '2024-01-01');
        TestUsers::unlock(static::getContainer(), $owner);
        $goals = self::service(SavingsGoalRepository::class);
        $goals->create($owner, new SavingsGoal(0, 'Apport', 2_000_000, Month::fromString('2028-06'), GoalScope::Accounts, null, [$livret], Month::fromString('2026-05'), 600_000));
        $goals->create($owner, new SavingsGoal(0, 'Précaution', 1_500_000, Month::fromString('2027-12'), GoalScope::Level, Level::Precaution, [], Month::fromString('2026-11'), 0));

        $this->logIn();
        $export = $this->export();
        self::assertIsList($export['goals']);
        self::assertCount(2, $export['goals']);

        TestUsers::create(static::getContainer(), self::NEWCOMER);
        $this->restoreSession([]);
        $this->logIn(self::NEWCOMER);
        $this->import(json_encode($export, JSON_THROW_ON_ERROR));
        self::assertResponseRedirects('/comptes', Response::HTTP_SEE_OTHER);

        $copy = $this->export();
        self::assertIsList($copy['goals']);
        $strip = static fn(array $goals): array => array_map(static function (mixed $goal): mixed {
            self::assertIsArray($goal);
            $goal['account_refs'] = is_array($goal['account_refs']) ? count($goal['account_refs']) : null;

            return $goal;
        }, $goals);
        self::assertSame($strip($export['goals']), $strip($copy['goals']), 'mêmes objectifs, mêmes points de départ');
    }

    public function testTheCustomIndicatorsTravelWithTheExport(): void
    {
        $owner = self::owner();
        $livret = self::createAccount('Livret A', 'livret_a', '2024-01-01');
        TestUsers::unlock(static::getContainer(), $owner);
        self::service(CustomIndicatorRepository::class)->create($owner, new CustomIndicator(null, 'Retraite', [Level::Actions], [$livret], true));

        $this->logIn();
        $export = $this->export();
        self::assertSame([['name' => 'Retraite', 'levels' => ['actions'], 'account_refs' => [$livret], 'share_of_net_worth' => true]], $export['indicators']);

        TestUsers::create(static::getContainer(), self::NEWCOMER);
        $this->restoreSession([]);
        $this->logIn(self::NEWCOMER);
        $this->import(json_encode($export, JSON_THROW_ON_ERROR));
        self::assertResponseRedirects('/comptes', Response::HTTP_SEE_OTHER);

        $copy = $this->export();
        self::assertIsList($copy['indicators']);
        self::assertCount(1, $copy['indicators']);
        self::assertIsArray($copy['indicators'][0]);
        self::assertSame(['Retraite', ['actions'], true], [$copy['indicators'][0]['name'], $copy['indicators'][0]['levels'], $copy['indicators'][0]['share_of_net_worth']]);
        self::assertCount(1, (array) $copy['indicators'][0]['account_refs'], 'its account, under its new identifier');
    }

    public function testAFaultyGoalImportsNothing(): void
    {
        TestUsers::create(static::getContainer(), self::NEWCOMER);
        $this->logIn(self::NEWCOMER);
        $accounts = [['ref' => 1, 'name' => 'Livret', 'type' => 'livret_a', 'opened_on' => '2025-01-01', 'statements' => [], 'shares' => []]];
        $goals = [['name' => 'Apport', 'target_cents' => 1000, 'target_month' => '2028-01', 'scope' => 'accounts', 'level' => null, 'account_refs' => [7], 'start_month' => '2026-01', 'start_cents' => 0]];
        $this->import(json_encode(['format' => 'patrimeter', 'version' => 3, 'accounts' => $accounts, 'budget' => null, 'goals' => $goals], JSON_THROW_ON_ERROR));

        $this->client->followRedirect();
        self::assertStringContainsString('Objectif 1 : compte inconnu du fichier.', $this->flashes());
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM accounts'), 'tout ou rien');

        $goals[0]['account_refs'] = [1];
        $goals[0]['target_cents'] = -5;
        $this->import(json_encode(['format' => 'patrimeter', 'version' => 3, 'accounts' => $accounts, 'budget' => null, 'goals' => $goals], JSON_THROW_ON_ERROR));
        $this->client->followRedirect();
        self::assertStringContainsString('Objectif 1 : nom, montant, mois ou périmètre invalides.', $this->flashes());

        $this->import(json_encode(['format' => 'patrimeter', 'version' => 3, 'accounts' => $accounts, 'budget' => null, 'goals' => 'beaucoup'], JSON_THROW_ON_ERROR));
        $this->client->followRedirect();
        self::assertStringContainsString('Objectifs d\'épargne illisibles ou trop nombreux.', $this->flashes());
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM savings_goals'));
    }

    public function testAFaultyBudgetImportsNothing(): void
    {
        TestUsers::create(static::getContainer(), self::NEWCOMER);
        $this->logIn(self::NEWCOMER);
        $budget = ['adults' => ['Flo'], 'children' => [], 'precaution_months' => 3, 'categories' => [
            ['name' => 'Logement', 'group' => 'needs', 'adult' => null, 'lines' => [['ref' => 1, 'name' => 'Loyer', 'child_ref' => 9, 'account_ref' => null]]],
        ], 'months' => []];
        $accounts = [['ref' => 1, 'name' => 'Livret', 'type' => 'livret_a', 'opened_on' => '2025-01-01', 'statements' => [], 'shares' => []]];
        $this->import(json_encode(['format' => 'patrimeter', 'version' => 2, 'accounts' => $accounts, 'budget' => $budget], JSON_THROW_ON_ERROR));

        $this->client->followRedirect();
        self::assertStringContainsString('Rien n\'a été importé. Budget du foyer illisible ou invalide.', $this->flashes());
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM accounts'), 'tout ou rien, comptes compris');
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM budget_households'));
    }

    public function testExportedTextCanNeverBeTakenForHtml(): void
    {
        self::createAccount('Livret <script>', 'livret_a');
        $this->logIn();

        $export = $this->export();

        self::assertStringNotContainsString('<script>', $this->body());
        self::assertStringContainsString('\\u003Cscript\\u003E', $this->body());
        $accounts = $export['accounts'];
        self::assertIsArray($accounts);
        self::assertIsArray($accounts[0] ?? null);
        self::assertSame('Livret <script>', $accounts[0]['name'] ?? null, 'même texte une fois lu');
    }

    public function testImportIsRefusedIntoAnAccountThatHasData(): void
    {
        self::createAccount('Déjà là', 'livret_a');
        $this->logIn();
        $this->import('{"format": "patrimeter", "version": 1, "accounts": []}');

        self::assertResponseRedirects('/donnees', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('compte encore vide', $this->flashes());
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM accounts'));
    }

    public function testAFaultyFileImportsNothing(): void
    {
        TestUsers::create(static::getContainer(), self::NEWCOMER);
        $this->logIn(self::NEWCOMER);
        $accounts = [
            ['ref' => 1, 'name' => 'Correct', 'type' => 'livret_a', 'opened_on' => '2025-01-01', 'statements' => [['month' => '2025-02', 'amount_cents' => 100]], 'shares' => []],
            ['ref' => 2, 'name' => 'Inconnu', 'type' => 'type_inexistant', 'opened_on' => '2025-01-01', 'statements' => [], 'shares' => []],
        ];
        $this->import(json_encode(['format' => 'patrimeter', 'version' => 1, 'accounts' => $accounts], JSON_THROW_ON_ERROR));

        $this->client->followRedirect();
        self::assertStringContainsString('Rien n\'a été importé. Compte « Inconnu » : type de compte inconnu.', $this->flashes());
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM accounts'), 'tout ou rien');

        foreach (['pas du JSON', '{"format": "autre", "version": 1, "accounts": []}'] as $content) {
            $this->import($content);
            $this->client->followRedirect();
            self::assertStringContainsString('pas un export de Patrimeter', $this->flashes());
        }
    }

    public function testExportNeedsARecentConfirmation(): void
    {
        $this->logIn();
        $this->submit('/donnees/export', [], '/donnees');

        self::assertResponseRedirects('/donnees', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('confirmez d\'abord votre identité', $this->flashes());
    }

    /**
     * Budget comparable from one export to the other: without the references, specific to each instance.
     *
     * @param array<mixed> $export
     *
     * @return array<mixed>
     */
    private static function comparableBudget(array $export): array
    {
        $budget = $export['budget'];
        self::assertIsArray($budget);
        $json = json_encode($budget, JSON_THROW_ON_ERROR);
        $decoded = json_decode((string) preg_replace('/"(ref|line_ref|child_ref|account_ref)":\d+/', '"$1":0', $json), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<mixed> */
    private function export(): array
    {
        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => '/donnees'], '/donnees');
        $this->submit('/donnees/export', [], '/donnees');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment; filename=patrimeter-export-', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $export = json_decode($this->body(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($export);
        self::assertIsList($export['accounts']);

        return $export;
    }

    private function import(string $content): void
    {
        $file = tempnam(sys_get_temp_dir(), 'patrimeter-export');
        self::assertIsString($file);
        file_put_contents($file, $content);
        $this->client->request('POST', '/donnees/import', ['_csrf' => $this->csrfToken('/donnees')], ['fichier' => new UploadedFile($file, 'export.json', 'application/json', null, true)]);
    }

    /**
     * Export without what changes from one instance to the other: account identifiers (replaced by the names) and
     * date of the export.
     *
     * @param array<mixed> $export
     *
     * @return list<array<mixed>>
     */
    private static function comparable(array $export): array
    {
        self::assertIsList($export['accounts']);
        $names = [];
        foreach ($export['accounts'] as $account) {
            self::assertIsArray($account);
            $names[self::key($account['ref'] ?? null)] = $account['name'];
        }
        $accounts = [];
        foreach ($export['accounts'] as $account) {
            self::assertIsArray($account);
            $account['linked_asset_ref'] = $account['linked_asset_ref'] === null ? null : $names[self::key($account['linked_asset_ref'])];
            unset($account['ref']);
            $accounts[] = $account;
        }

        return $accounts;
    }

    private static function key(mixed $ref): string
    {
        self::assertIsInt($ref);

        return (string) $ref;
    }
}
