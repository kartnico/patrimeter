<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use OTPHP\TOTP;
use Patrimeter\Application\Budget\HouseholdBudget;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Goal\GoalScope;
use Patrimeter\Domain\Goal\SavingsGoal;
use Patrimeter\Domain\LoanMode;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\OAuthClientRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\SavingsGoalRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Security\Vault\Vault;
use Patrimeter\Tests\Support\FakeCarbure;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\TestUsers;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

/**
 * Renders the main pages (signed in, anonymous and error) as in production and, if the HTML_SNAPSHOT_DIR variable is
 * set, saves them for the W3C validator ("w3c" CI job).
 */
final class PageSnapshotTest extends FunctionalTestCase
{
    #[\Override]
    protected static function clientOptions(): array
    {
        return ['debug' => false];
    }

    /** @return iterable<string, array{string, int, string}> name => address, expected status, session state */
    public static function pages(): iterable
    {
        yield 'connexion' => ['/connexion', 200, 'anonyme'];
        yield 'inscription' => ['/inscription', 200, 'anonyme'];
        yield 'inscription-envoyee' => ['/inscription/envoyee', 200, 'anonyme'];
        yield 'inscription-lien-invalide' => ['/inscription/confirmation/AAAAAAAAAAAAAAAAAAAAAA.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 404, 'anonyme'];
        yield 'mot-de-passe-oublie' => ['/mot-de-passe-oublie', 200, 'anonyme'];
        yield 'mot-de-passe-oublie-envoye' => ['/mot-de-passe-oublie/envoye', 200, 'anonyme'];
        yield 'mot-de-passe-lien-invalide' => ['/mot-de-passe-oublie/AAAAAAAAAAAAAAAAAAAAAA.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 404, 'anonyme'];
        yield 'erreur-404-anonyme' => ['/inexistant', 404, 'anonyme'];
        yield 'code-de-verification' => ['/connexion/code', 200, 'code'];
        yield 'configuration-totp' => ['/securite/application', 200, 'sans-totp'];
        yield 'tableau-de-bord' => ['/?mois=2026-11', 200, 'connecte'];
        yield 'tableau-de-bord-vide' => ['/?mois=2019-01', 200, 'connecte'];
        yield 'comptes' => ['/comptes', 200, 'connecte'];
        yield 'compte-nouveau' => ['/comptes/nouveau', 200, 'connecte'];
        yield 'compte-edition' => ['/comptes/{id}', 200, 'connecte'];
        yield 'compte-bien' => ['/comptes/{house}', 200, 'connecte'];
        yield 'compte-credit' => ['/comptes/{loan}', 200, 'connecte'];
        yield 'saisie' => ['/saisie', 200, 'connecte'];
        yield 'rapport' => ['/rapport', 200, 'connecte'];
        yield 'rapport-sans-montants' => ['/rapport?montants=0', 200, 'connecte'];
        yield 'patrimoine-historique' => ['/?periode=tout&vue=niveaux', 200, 'connecte'];
        yield 'biens' => ['/biens', 200, 'connecte'];
        yield 'bien-nouveau' => ['/biens/nouveau', 200, 'connecte'];
        yield 'securite' => ['/securite', 200, 'connecte'];
        yield 'securite-confirmee' => ['/securite', 200, 'confirme'];
        yield 'securite-connexion' => ['/securite/connexion', 200, 'connecte'];
        yield 'securite-connexion-confirmee' => ['/securite/connexion', 200, 'confirme'];
        yield 'securite-agents-ia' => ['/securite/agents-ia', 200, 'connecte'];
        yield 'securite-agents-ia-confirmee' => ['/securite/agents-ia', 200, 'confirme'];
        yield 'reconfiguration-totp' => ['/securite/application', 200, 'confirme'];
        yield 'budget' => ['/budget?mois=2026-11', 200, 'connecte'];
        yield 'budget-mois-vide' => ['/budget?mois=2019-01', 200, 'connecte'];
        yield 'budget-saisie' => ['/budget/saisie', 200, 'connecte'];
        yield 'budget-foyer' => ['/budget/foyer', 200, 'connecte'];
        yield 'budget-carbure' => ['/budget/carbure', 200, 'connecte'];
        yield 'budget-categorie' => ['/budget/categories/{category}', 200, 'connecte'];
        yield 'budget-poste' => ['/budget/postes/{line}', 200, 'connecte'];
        yield 'erreur-404' => ['/comptes/424242', 404, 'connecte'];
        yield 'erreur-405' => ['/comptes/{id}/supprimer', 405, 'connecte'];
        yield 'defis' => ['/defis', 200, 'connecte'];
        yield 'bienvenue-pyramide' => ['/bienvenue/pyramide', 200, 'connecte'];
        yield 'bienvenue-niveau' => ['/bienvenue/niveau/residence', 200, 'connecte'];
        yield 'bienvenue-express' => ['/bienvenue/express', 200, 'connecte'];
        yield 'bienvenue-questions' => ['/bienvenue/questions', 200, 'connecte'];
    }

    /** A new user, without any account: the welcome choices, the express entry and the questions with their errors, challenges off. */
    public function testWelcomePagesRender(): void
    {
        $this->logIn();
        $this->client->request('GET', '/');
        self::assertSelectorExists('.welcome-choices');
        $this->saveSnapshot('tableau-de-bord-bienvenue');

        $this->submit('/bienvenue/comptes', ['comptes' => ['livret_a' => ['name' => '', 'amount' => 'beaucoup']]], '/bienvenue/express');
        self::assertResponseStatusCodeSame(422);
        $this->saveSnapshot('bienvenue-express-erreurs');

        $this->submit('/bienvenue/questions', ['reponses' => ['livret_a' => 'exact'], 'montants' => ['cto' => 'beaucoup']], '/bienvenue/questions');
        self::assertResponseStatusCodeSame(422);
        $this->saveSnapshot('bienvenue-questions-erreurs');

        $this->submit('/defis/reglages', ['action' => 'disable'], '/defis');
        $this->client->request('GET', '/defis');
        self::assertResponseIsSuccessful();
        $this->saveSnapshot('defis-desactives');
    }

    #[DataProvider('pages')]
    public function testPageRenders(string $path, int $status, string $state): void
    {
        $id = $this->givenSomeData();
        [$category, $line] = $this->givenABudget($id);
        $this->enterState($state);

        $this->client->request('GET', str_replace(['{id}', '{loan}', '{house}', '{category}', '{line}'], [(string) $id, (string) ($id + 1), (string) ($id + 2), (string) $category, (string) $line], $path));

        self::assertResponseStatusCodeSame($status);
        self::assertStringStartsWith('<!doctype html>', $this->body());
        $this->saveSnapshot((string) $this->dataName());
    }

    /** Family account: members page, then wealth (with its history), report and budget of the family. */
    public function testFamilyPagesRender(): void
    {
        $this->logIn();
        $this->client->request('GET', '/famille');
        self::assertResponseIsSuccessful();
        $this->saveSnapshot('famille-vide');

        $this->givenSomeData();
        self::createFamilyMember(self::owner(), 'Alex', 'Livret d\'Alex', 1_000_000, 300_000);
        foreach (['famille' => '/famille', 'tableau-de-bord-famille' => '/?perimetre=famille&periode=tout', 'rapport-famille' => '/rapport', 'budget-famille' => '/budget?mois=2026-11'] as $name => $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($name);
            $this->saveSnapshot($name);
        }
    }

    /** Savings goals: empty list, form and its errors, list with a goal, dashboard card. */
    public function testGoalPagesRender(): void
    {
        $this->logIn();
        $account = $this->givenSomeData();
        foreach (['objectifs-vide' => '/objectifs', 'objectif-nouveau' => '/saisie/objectifs/nouveau'] as $name => $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($name);
            $this->saveSnapshot($name);
        }
        $this->submit('/saisie/objectifs/nouveau', ['name' => '', 'target' => 'x', 'target_month' => '2020-01', 'scope' => 'accounts', 'level' => ''], '/saisie/objectifs/nouveau');
        self::assertResponseStatusCodeSame(422);
        $this->saveSnapshot('objectif-erreurs');
        $this->submit('/saisie/objectifs/nouveau', ['name' => 'Apport', 'target' => '50000', 'target_month' => '2028-06', 'scope' => 'net', 'level' => ''], '/saisie/objectifs/nouveau');
        $this->client->followRedirect();
        self::assertSelectorExists('table.goal-list');
        $this->saveSnapshot('saisie-objectifs');
        $this->client->request('GET', '/objectifs');
        self::assertSelectorExists('section.goal');
        $this->saveSnapshot('objectifs');
        $this->client->request('GET', '/');
        self::assertSelectorExists('.trends-card');
        self::assertSelectorExists('nav.nav a.needs-entry', 'the month is not entered');
        $this->saveSnapshot('tableau-de-bord-tendances');

        // A goal started a year ago: projection chart and history of its status.
        self::service(SavingsGoalRepository::class)->create(self::owner(), new SavingsGoal(0, 'Précaution', 500_000, Month::fromString('2027-06'), GoalScope::Accounts, null, [$account], Month::fromString('2025-11'), 150_000, null));
        $this->client->request('GET', '/objectifs');
        self::assertSelectorExists('figure.goal-chart canvas[data-goal-chart]');
        self::assertSelectorExists('svg.goal-gauge');
        $this->saveSnapshot('objectifs-graphique');

        $this->client->request('GET', '/rappel/desabonnement/' . str_repeat('A', 43));
        $this->saveSnapshot('rappel-desabonnement');
    }

    /** Demonstration account: banner and card of the guided tour, then the banner offering to resume it. */
    public function testDemoPagesRender(): void
    {
        $this->submit('/demo', [], '/connexion');
        $this->client->followRedirect();
        self::assertSelectorExists('.tour-card');
        $this->saveSnapshot('demo-visite');
        $this->client->request('GET', '/budget');
        self::assertSelectorExists('.demo-banner form');
        $this->saveSnapshot('demo-budget');
    }

    /** Carbure: empty settings, refused, then linked; budget with the planned and actual amounts. */
    public function testCarburePagesRender(): void
    {
        $this->logIn();
        $id = $this->givenSomeData();
        $this->givenABudget($id);
        $this->client->request('GET', '/budget/carbure');
        $this->saveSnapshot('budget-carbure');
        $this->submit('/budget/carbure', ['url' => 'ftp://x', 'token' => 'x'], '/budget/carbure');
        self::assertResponseStatusCodeSame(422);
        $this->saveSnapshot('budget-carbure-erreurs');
        $this->submit('/budget/carbure', ['url' => FakeCarbure::URL, 'token' => FakeCarbure::TOKEN], '/budget/carbure');
        $this->client->followRedirect();
        self::assertSelectorExists('select#mapping-1');
        $this->saveSnapshot('budget-carbure-relie');
        $this->client->request('GET', '/budget?mois=2026-11');
        self::assertSelectorExists('.budget-actuals-table');
        $this->saveSnapshot('budget-reel');
    }

    /** Pages obtained in answer to a form (errors, backup codes, confirmation). */
    public function testAuthorizationPagesRender(): void
    {
        self::service(OAuthClientRepository::class)->create('client-de-test', 'Client de test', ['https://client.example/retour'], '2026-01-01T00:00:00Z');
        $this->logIn();
        $query = ['response_type' => 'code', 'client_id' => 'client-de-test', 'redirect_uri' => 'https://client.example/retour', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256', 'scope' => 'patrimeter.read patrimeter.write'];

        $this->client->request('GET', '/oauth/authorize?' . http_build_query($query));
        self::assertResponseIsSuccessful();
        $this->saveSnapshot('oauth-consentement');

        $this->submit('/oauth/authorize', $query + ['decision' => 'allow'], '/securite');
        self::assertResponseIsSuccessful();
        $this->saveSnapshot('oauth-retour-client');

        $this->client->request('GET', '/oauth/authorize?client_id=inconnu');
        self::assertResponseStatusCodeSame(400);
        $this->saveSnapshot('oauth-client-inconnu');

        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => '/securite'], '/securite');
        $this->submit('/securite/jetons', ['name' => 'Script', 'days' => '30'], '/securite');
        self::assertResponseIsSuccessful();
        $this->saveSnapshot('jeton-personnel');

        $this->client->request('GET', '/securite/agents-ia');
        self::assertResponseIsSuccessful();
        $this->saveSnapshot('securite-acces-api');
    }

    public function testInstallationWizardRenders(): void
    {
        // A new instance: no account yet (the test's are rolled back afterwards).
        self::service(Connection::class)->executeStatement('DELETE FROM users');
        $this->client->request('GET', '/installation');
        self::assertResponseIsSuccessful();
        $this->saveSnapshot('installation');

        $this->submit('/installation', ['email' => 'invalide', 'password' => 'court', 'password_confirmation' => 'autre'], '/installation');
        self::assertResponseStatusCodeSame(422);
        $this->saveSnapshot('installation-erreurs');
    }

    public function testIndicatorPagesRender(): void
    {
        $this->logIn();
        $livret = self::createAccount('Livret A', 'livret_a');
        self::service(StatementRepository::class)->save(self::owner(), $livret, Month::fromString('2026-11'), 1_000_000);
        $this->client->request('GET', '/indicateurs');
        self::assertResponseIsSuccessful();
        $this->saveSnapshot('indicateurs-vide');

        $this->submit('/indicateurs', ['name' => '', 'levels' => []], '/indicateurs');
        self::assertResponseStatusCodeSame(422);
        $this->saveSnapshot('indicateurs-erreurs');

        $this->submit('/indicateurs', ['name' => 'Épargne', 'levels' => ['precaution'], 'accounts' => [(string) $livret], 'share' => '1'], '/indicateurs');
        $this->client->followRedirect();
        $this->saveSnapshot('indicateurs');
        $this->client->request('GET', '/indicateurs/' . self::queryInt('SELECT id FROM custom_indicators'));
        self::assertResponseIsSuccessful();
        $this->saveSnapshot('indicateur-edition');
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $this->saveSnapshot('tableau-de-bord-indicateurs');
    }

    public function testBankStatementFileRenders(): void
    {
        $this->logIn();
        $id = self::createAccount('Compte courant', 'livret_a');
        foreach (['compte-releve-lu' => '<OFX><CURDEF>EUR<LEDGERBAL><BALAMT>1234.56<DTASOF>20261031</LEDGERBAL></OFX>', 'compte-releve-illisible' => "!Type:Bank\n"] as $name => $content) {
            $file = tempnam(sys_get_temp_dir(), 'patrimeter-ofx');
            self::assertIsString($file);
            file_put_contents($file, $content);
            $this->client->request('POST', "/comptes/$id/releves/fichier", ['_csrf' => $this->csrfToken("/comptes/$id")], ['releve' => new UploadedFile($file, 'releve.ofx', null, null, true)]);
            $this->saveSnapshot($name);
        }
    }

    public function testFormResponsesRender(): void
    {
        $this->submitPassword(self::EMAIL, 'faux mot de passe');
        self::assertResponseStatusCodeSame(401);
        $this->saveSnapshot('connexion-erreur');

        $this->submit('/inscription', ['email' => 'invalide', 'password' => 'court', 'password_confirmation' => 'autre'], '/inscription');
        self::assertResponseStatusCodeSame(422);
        $this->saveSnapshot('inscription-erreurs');

        $this->submit('/inscription', ['email' => 'nouveau@example.test', 'password' => 'une phrase de passe solide', 'password_confirmation' => 'une phrase de passe solide'], '/inscription');
        $link = null;
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email && preg_match('~http\S+/inscription/confirmation/\S+~', (string) $message->getTextBody(), $match) === 1) {
                $link = $match[0];
            }
        }
        self::assertIsString($link);
        self::clock()->sleep(2); // delay imposed by the wrong password above
        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        $this->saveSnapshot('inscription-confirmation');

        $this->submit($link, ['password' => 'une phrase de passe solide'], $link);
        $this->client->request('GET', '/securite/application');
        $secret = str_replace(' ', '', $this->client->getCrawler()->filter('.secret-key code')->text());
        self::assertNotSame('', $secret);
        $this->client->request('POST', '/securite/application', [
            '_csrf' => $this->csrfToken('/securite/application'),
            'code' => TOTP::create($secret, clock: self::clock())->now(),
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Codes de secours');
        $this->saveSnapshot('codes-de-secours');

        $this->client->request('GET', '/securite/cle-de-recuperation');
        self::assertResponseIsSuccessful();
        $this->saveSnapshot('cle-de-recuperation');

        $session = $this->client->getCookieJar()->get(Vault::COOKIE);
        self::assertNotNull($session);
        $this->client->getCookieJar()->expire(Vault::COOKIE);
        $this->client->request('GET', '/deverrouiller');
        self::assertResponseIsSuccessful();
        $this->saveSnapshot('deverrouillage');
        $this->submit('/deconnexion', [], '/deverrouiller');

        $this->submit('/mot-de-passe-oublie', ['email' => self::EMAIL], '/mot-de-passe-oublie');
        $link = null;
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email && preg_match('~http\S+/mot-de-passe-oublie/\S+~', (string) $message->getTextBody(), $match) === 1) {
                $link = $match[0];
            }
        }
        self::assertIsString($link);
        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        $this->saveSnapshot('mot-de-passe-nouveau');
    }

    /** Budget forms: creation of the household, then refused inputs. */
    public function testBudgetFormsRender(): void
    {
        $this->logIn();
        $this->client->request('GET', '/budget');
        self::assertResponseIsSuccessful();
        $this->saveSnapshot('budget-creation');

        $this->submit('/budget/foyer', ['adults' => '2', 'adult_names' => [1 => 'Flo', 2 => ''], 'precaution_months' => 'x'], '/budget');
        self::assertResponseStatusCodeSame(422);
        $this->saveSnapshot('budget-creation-erreurs');

        $this->submit('/budget/foyer', ['adults' => '2', 'adult_names' => [1 => 'Flo', 2 => 'Nico'], 'adult_years' => [1 => '1985', 2 => '1983'], 'children_count' => '1', 'child_names' => ['Eva'], 'child_years' => ['2008'], 'precaution_months' => '4'], '/budget');
        $this->submit('/budget/saisie', ['mois' => '2026-11', 'incomes' => [1 => 'beaucoup'], 'split_rule' => 'fixed', 'first_share' => '200'], '/budget/saisie');
        self::assertResponseStatusCodeSame(422);
        $this->saveSnapshot('budget-saisie-erreurs');

        $this->submit('/budget/categories', ['name' => '', 'group' => 'x', 'adult' => '9'], '/budget/foyer');
        self::assertResponseStatusCodeSame(422);
        $this->saveSnapshot('budget-foyer-erreurs');
    }

    private function enterState(string $state): void
    {
        match ($state) {
            'anonyme' => null,
            'code' => $this->submitPassword(),
            'sans-totp' => $this->logInWithoutTotp(),
            'connecte' => $this->logIn(),
            'confirme' => $this->logInAndConfirmIdentity(),
            default => self::fail("État inconnu : $state"),
        };
    }

    private function logInWithoutTotp(): void
    {
        TestUsers::create(static::getContainer(), 'sans-totp@example.test', totp: false);
        $this->submitPassword('sans-totp@example.test');
    }

    private function logInAndConfirmIdentity(): void
    {
        $this->logIn();
        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => '/securite'], '/securite');
    }

    private function givenSomeData(): int
    {
        $current = self::createAccount('Compte courant', 'compte_courant', '2024-01-01');
        $loan = self::createAccount('Prêt immobilier', 'credit_immo_rp', '2024-01-01');
        $house = self::createAccount('Maison', 'residence_principale', '2024-01-01');
        self::service(AccountRepository::class)->linkLoan(self::owner(), $loan, $house, LoanMode::Prorata);
        self::service(StatementRepository::class)->save(self::owner(), $house, Month::fromString('2026-10'), 30_000_000);
        self::service(OwnershipShareRepository::class)->save(self::owner(), $house, Month::fromString('2024-01'), 500_000);
        $statements = self::service(StatementRepository::class);
        foreach (['2025-11' => [150_000, 9_000_000], '2026-10' => [180_000, 8_600_000]] as $month => [$cash, $debt]) {
            $statements->save(self::owner(), $current, Month::fromString($month), $cash);
            $statements->save(self::owner(), $loan, Month::fromString($month), $debt);
        }

        return $current;
    }

    /**
     * Household budget of November 2026: two adults, one child, the suggested categories, a savings item tied to the
     * current account.
     *
     * @return array{int, int} category and item to show
     */
    private function givenABudget(int $account): array
    {
        TestUsers::unlock(static::getContainer(), self::owner());
        self::service(HouseholdBudget::class)->saveHousehold(self::owner(), new Household([1 => 'Flo', 2 => 'Nico'], [1 => 'Eva'], 4, 2), 'fr');
        $budgets = self::service(BudgetRepository::class);
        $amounts = [];
        foreach ($budgets->lines(self::owner()) as $index => $line) {
            $amounts[$line->id] = 1_000 * ($index + 1);
        }
        $lines = $budgets->lines(self::owner());
        $saving = $lines[11];
        $budgets->updateLine(self::owner(), new BudgetLine($saving->id, $saving->categoryId, $saving->name, 1, $account));
        $budgets->saveBudget(self::owner(), new MonthlyBudget(Month::fromString('2026-11'), [1 => 186_000, 2 => 414_300], $amounts));

        return [$saving->categoryId, $saving->id];
    }

    private function saveSnapshot(string $name): void
    {
        $directory = getenv('HTML_SNAPSHOT_DIR');
        if (!is_string($directory) || $directory === '') {
            return;
        }
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Impossible de créer $directory");
        }
        file_put_contents("$directory/$name.html", $this->body());
    }
}
