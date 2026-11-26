<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Nature;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AccountManagementTest extends FunctionalTestCase
{
    private const string LIVRET_A = '4';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logIn();
    }

    public function testEmptyList(): void
    {
        $this->client->request('GET', '/comptes');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state', 'Aucun compte pour l\'instant.');
    }

    public function testCreationFormDefaultsToToday(): void
    {
        $crawler = $this->client->request('GET', '/comptes/nouveau');

        self::assertSame('2026-11-15', $crawler->filter('#opened_on')->attr('value'));
        self::assertAnySelectorTextContains('.hint', 'relevé de novembre 2026');
    }

    public function testPropertiesAreNotCreatedFromTheAccountForm(): void
    {
        $crawler = $this->client->request('GET', '/comptes/nouveau');
        self::assertCount(0, $crawler->filter('#type_id option')->reduce(static fn($o): bool => str_contains($o->text(), 'Résidence principale') || $o->text() === 'SCPI'));
        self::assertSelectorExists('.hint a[href="/biens/nouveau"]');

        // Neither a property type, nor another type disguised as a property (Real estate level, asset nature).
        foreach (['lmnp', 'livret_a'] as $code) {
            $this->submit('/comptes/nouveau', ['name' => 'Bien caché', 'type_id' => (string) self::queryInt('SELECT id FROM account_types WHERE code = ?', [$code]), 'level_code' => 'immobilier', 'nature' => 'asset', 'opened_on' => '2025-01-01'], '/comptes/nouveau');
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $code);
            self::assertStringContainsString('se crée depuis Biens immobiliers', $this->body());
        }
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM accounts'));

        // An ordinary account does not become a property by changing it; an existing property remains editable.
        $livret = self::createAccount('Livret', 'livret_a');
        $this->submit("/comptes/$livret", ['name' => 'Livret', 'type_id' => (string) self::queryInt("SELECT id FROM account_types WHERE code = 'livret_a'"), 'level_code' => 'residence', 'nature' => 'asset', 'opened_on' => '2025-01-01'], "/comptes/$livret");
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $house = self::createAccount('Maison', 'lmnp');
        $this->submit("/comptes/$house", ['name' => 'Maison louée', 'type_id' => (string) self::queryInt("SELECT id FROM account_types WHERE code = 'lmnp'"), 'level_code' => 'immobilier', 'nature' => 'asset', 'opened_on' => '2025-01-01'], "/comptes/$house");
        self::assertResponseRedirects();
    }

    public function testTypeListBecomesATypeToFilterListWithJavaScript(): void
    {
        $crawler = $this->client->request('GET', '/comptes/nouveau');

        // Without JavaScript, the full list (select); app.js turns it into a filtered typing list (combobox).
        $select = $crawler->filter('select#type_id[data-combobox]');
        self::assertCount(1, $select);
        self::assertSame('Filtrer : livret, PEA, crédit…', $select->attr('data-combobox-placeholder'));
        self::assertSame('Aucun type ne correspond.', $select->attr('data-status-none'));
        self::assertSelectorExists('label[for="type_id"]');
        self::assertCount(31, $crawler->filter('#type_id optgroup option'), '38 types, minus the 7 property types');

        $this->client->request('GET', '/biens/nouveau');
        self::assertSelectorExists('select#type_id[data-combobox][data-combobox-placeholder]');
    }

    public function testTangibleAssetsAreAlternativeTypes(): void
    {
        $crawler = $this->client->request('GET', '/comptes/nouveau');

        $exotic = $crawler->filter('#type_id optgroup')->reduce(static fn($group): bool => $group->attr('label') === 'Exotique');
        self::assertSame(
            ['Crowdfunding', 'Cryptomonnaies', 'GFI / GFV', 'Or', 'Argent (métal)', 'Platine, palladium et autres métaux précieux', 'Bijoux', 'Montres', 'Œuvres d\'art et objets de collection', 'Vins et spiritueux', 'Voiture', 'Moto', 'Autre actif'],
            $exotic->filter('option')->each(static fn($option): string => $option->text()),
        );
        $watch = $exotic->filter('option')->reduce(static fn($option): bool => $option->text() === 'Montres');
        self::assertSame('exotique', $watch->attr('data-level'));
        self::assertSame('asset', $watch->attr('data-nature'));
    }

    public function testAccountsAreListedByLevelForALoggedInUser(): void
    {
        self::createAccount('Mon livret', 'livret_a');

        $this->client->request('GET', '/comptes');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-name', 'Mon livret');
        self::assertSelectorNotExists('.empty-state');
    }

    public function testInvalidFormIsShownAgainWithErrors(): void
    {
        $this->submit('/comptes/nouveau', ['name' => '', 'type_id' => '999', 'opened_on' => '2026-02-30', 'initial_amount' => '12,345']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('#err-name', 'Indiquez un nom de 1 à 80 caractères.');
        self::assertSelectorTextContains('#err-type_id', 'Choisissez un type de compte.');
        self::assertSelectorTextContains('#err-opened_on', 'Date d\'ouverture invalide');
        self::assertSelectorTextContains('#err-initial_amount', '2 décimales maximum');
        self::assertSelectorExists('#name[aria-invalid="true"][aria-describedby="err-name"]');
        self::assertSame([], self::service(AccountRepository::class)->all(self::owner()));
    }

    public function testCreateWithInitialBalanceRecordsTheCurrentMonth(): void
    {
        $this->submit('/comptes/nouveau', [
            'name' => 'Livret A <script>', 'type_id' => self::LIVRET_A, 'level_code' => '', 'nature' => '',
            'opened_on' => '2024-01-15', 'initial_amount' => '12 345,67',
        ]);

        self::assertResponseRedirects('/comptes', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Compte « Livret A <script> » créé.', $this->flashes());
        self::assertStringNotContainsString('<script>', $this->body(), 'nom échappé');

        $account = self::onlyAccount();
        self::assertSame(Level::Precaution, $account->level, 'niveau par défaut du type');
        self::assertSame(Nature::Asset, $account->nature);
        self::assertSame(['2026-11' => 1234567], self::service(StatementRepository::class)->forAccount(self::owner(), $account->id));
    }

    public function testInitialBalanceOfAnAccountOpenedLaterUsesItsOpeningMonth(): void
    {
        $this->submit('/comptes/nouveau', ['name' => 'PEA', 'type_id' => '11', 'opened_on' => '2027-02-01', 'initial_amount' => '100']);

        self::assertSame(['2027-02' => 10000], self::service(StatementRepository::class)->forAccount(self::owner(), self::onlyAccount()->id));
    }

    public function testUpdate(): void
    {
        $id = $this->createLivretA();
        $this->submit("/comptes/$id", ['name' => 'Livret A renommé', 'type_id' => self::LIVRET_A, 'level_code' => 'fonds_euros', 'nature' => 'asset', 'opened_on' => '2024-01-01']);

        self::assertResponseRedirects("/comptes/$id", Response::HTTP_SEE_OTHER);
        self::assertSame('Livret A renommé', self::onlyAccount()->name);
        self::assertSame(Level::FondsEuros, self::onlyAccount()->level);
    }

    public function testOpeningDateMustPrecedeClosingDate(): void
    {
        $id = $this->createLivretA();
        $this->submit("/comptes/$id/archiver", ['closed_on' => '2025-06-30']);
        $this->submit("/comptes/$id", ['name' => 'Livret A', 'type_id' => self::LIVRET_A, 'opened_on' => '2025-07-01']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('#err-opened_on', 'doit précéder la date de clôture');
    }

    public function testArchiveRequiresAValidClosingDate(): void
    {
        $id = $this->createLivretA();
        $this->submit("/comptes/$id/archiver", ['closed_on' => '2023-12-31']);

        self::assertResponseRedirects("/comptes/$id", Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Date de clôture invalide', $this->flashes());
        self::assertNull(self::onlyAccount()->closedOn);
    }

    public function testArchiveAndReactivate(): void
    {
        $id = $this->createLivretA();
        $this->submit("/comptes/$id/archiver", ['closed_on' => '2026-10-31']);
        self::assertResponseRedirects('/comptes', Response::HTTP_SEE_OTHER);
        self::assertSame('2026-10-31', self::onlyAccount()->closedOn);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.archived', 'clôturé le 31/10/2026');

        $this->submit("/comptes/$id/reactiver");
        self::assertNull(self::onlyAccount()->closedOn);
    }

    public function testDeleteRemovesTheAccountAndItsStatements(): void
    {
        $id = $this->createLivretA();
        $this->submit("/comptes/$id/supprimer");

        self::assertResponseRedirects('/comptes', Response::HTTP_SEE_OTHER);
        self::assertSame([], self::service(AccountRepository::class)->all(self::owner()));
        self::assertSame([], self::service(StatementRepository::class)->all(self::owner()));
    }

    public function testStatementCorrection(): void
    {
        $id = $this->createLivretA();

        $this->submit("/comptes/$id/releves", ['mois' => '2025-06', 'montant' => '9 000']);
        self::assertResponseRedirects("/comptes/$id", Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Relevé de juin 2025 enregistré.', $this->flashes());
        self::assertSelectorTextContains('table.statements', 'juin 2025');

        $this->submit("/comptes/$id/releves/2025-06/supprimer");
        self::assertArrayNotHasKey('2025-06', self::service(StatementRepository::class)->forAccount(self::owner(), $id));
    }

    public function testStatementValidation(): void
    {
        $id = $this->createLivretA();

        foreach ([
            ['mois' => '2025-13', 'montant' => '1', 'message' => 'Mois invalide (AAAA-MM).'],
            ['mois' => '2023-12', 'montant' => '1', 'message' => 'Le compte n\'est pas ouvert en décembre 2023.'],
            ['mois' => '2025-06', 'montant' => 'abc', 'message' => 'Montant invalide'],
        ] as $case) {
            $this->submit("/comptes/$id/releves", ['mois' => $case['mois'], 'montant' => $case['montant']]);
            $this->client->followRedirect();
            self::assertStringContainsString($case['message'], $this->flashes());
        }
        self::assertSame(['2026-11' => 100000], self::service(StatementRepository::class)->forAccount(self::owner(), $id));
    }

    public function testUnknownAccountIsNotFound(): void
    {
        $this->client->request('GET', '/comptes/424242');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->submit('/comptes/424242/supprimer');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function createLivretA(): int
    {
        $this->submit('/comptes/nouveau', ['name' => 'Livret A', 'type_id' => self::LIVRET_A, 'opened_on' => '2024-01-01', 'initial_amount' => '1000']);

        return self::onlyAccount()->id;
    }

    private static function onlyAccount(): Account
    {
        $accounts = self::service(AccountRepository::class)->all(self::owner());
        self::assertCount(1, $accounts);

        return $accounts[0];
    }
}
