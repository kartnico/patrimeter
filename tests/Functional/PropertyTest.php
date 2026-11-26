<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\LoanMode;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;

/** Properties: unified form, net value, ownership shares, linked loans. */
final class PropertyTest extends FunctionalTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logIn();
    }

    public function testAccountsAndPropertiesAreTabsOfMyAccount(): void
    {
        $this->client->request('GET', '/biens');

        // Not among the main entries: opened from the wealth page, they are tabs of My account.
        self::assertSelectorNotExists('nav.nav > a[href="/biens"]');
        self::assertSelectorNotExists('nav.nav > a[href="/comptes"]');
        self::assertSelectorExists('summary.account-toggle[data-current]');
        self::assertSelectorExists('nav.account-tabs a[href="/comptes"]');
        self::assertSelectorTextContains('nav.account-tabs a[href="/biens"][aria-current="page"]', 'Biens immobiliers');
        self::assertSelectorExists('.tab-head a.btn-primary[href="/biens/nouveau"]');

        // A page under the tab (new property) keeps the tab current, without claiming to be its page.
        $this->client->request('GET', '/biens/nouveau');
        self::assertSelectorNotExists('nav.account-tabs');
        self::assertSelectorExists('summary.account-toggle[data-current]');
    }

    public function testCreatingAPropertyWithItsLoansInOneForm(): void
    {
        $this->submit('/biens/nouveau', [
            'name' => 'Maison de Nantes',
            'type_id' => $this->type('residence_principale'),
            'opened_on' => '2024-03-10',
            'value' => '300 000',
            'share' => '50',
            'loans' => [
                ['name' => 'Prêt commun', 'amount' => '100 000', 'mode' => 'prorata'],
                ['name' => 'Prêt personnel', 'amount' => '20 000', 'mode' => 'own'],
                ['name' => '', 'amount' => '', 'mode' => 'prorata'],
            ],
        ]);
        self::assertResponseRedirects('/biens', Response::HTTP_SEE_OTHER);

        $accounts = self::service(AccountRepository::class)->all(self::owner());
        self::assertCount(3, $accounts);
        $house = $this->named($accounts, 'Maison de Nantes');
        $common = $this->named($accounts, 'Prêt commun');
        $personal = $this->named($accounts, 'Prêt personnel');
        self::assertSame($house->id, $common->linkedAssetId);
        self::assertSame(LoanMode::Prorata, $common->loanMode);
        self::assertSame(LoanMode::Own, $personal->loanMode);
        self::assertSame(Level::Residence, $common->level);
        self::assertTrue($common->isLiability());
        self::assertSame(['2026-11' => 30_000_000], self::service(StatementRepository::class)->forAccount(self::owner(), $house->id));
        self::assertSame([$house->id => ['2024-03' => 500_000]], self::service(OwnershipShareRepository::class)->all(self::owner()));

        $crawler = $this->client->followRedirect();
        $figures = self::plain($crawler->filter('.sheet.property')->text());
        self::assertStringContainsString('Votre part : 50 % de 300 000,00', $figures);
        self::assertStringContainsString('150 000,00', $figures, 'valeur retenue');
        self::assertStringContainsString('−50 000,00', $figures, 'prêt commun, au prorata');
        self::assertStringContainsString('−20 000,00', $figures, 'prêt personnel, en propre');
        self::assertSame('80 000,00 €', self::plain($crawler->filter('.sheet.property .property-net td')->text()), 'valeur nette : 150 000 − 70 000');
        // How each loan is counted, recalled under its name.
        self::assertStringContainsString('au prorata, sur 100 000,00', $figures);
        self::assertStringContainsString('montant propre', $figures);

        // On the loan's page, the mode saved is the one offered.
        $crawler = $this->client->request('GET', "/comptes/{$personal->id}");
        self::assertSame('own', $crawler->filter('select#loan_mode option[selected]')->attr('value'));
        $crawler = $this->client->request('GET', "/comptes/{$common->id}");
        self::assertSame('prorata', $crawler->filter('select#loan_mode option[selected]')->attr('value'));
        self::assertStringContainsString('80 000,00', $figures, 'valeur nette');

        $this->client->request('GET', '/?mois=2026-11');
        self::assertStringContainsString('80 000,00', $this->total(), 'pyramide et total suivent les parts');
    }

    public function testInvalidFormChangesNothingAndKeepsTheInput(): void
    {
        $this->submit('/biens/nouveau', [
            'name' => 'Maison',
            'type_id' => $this->type('residence_principale'),
            'opened_on' => '2024-03-10',
            'value' => '300 000',
            'share' => '150',
            'loans' => [['name' => 'Prêt', 'amount' => 'abc', 'mode' => 'own']],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorExists('#err-share');
        self::assertSelectorExists('#err-loans-0');
        self::assertInputValueSame('name', 'Maison');
        self::assertInputValueSame('loans[0][name]', 'Prêt');
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM accounts'));
    }

    public function testOnlyPropertyTypesAreAccepted(): void
    {
        $this->submit('/biens/nouveau', ['name' => 'Livret', 'type_id' => $this->type('livret_a'), 'opened_on' => '2024-03-10', 'value' => '10', 'share' => '100']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorExists('#err-type_id');
    }

    public function testShareHistoryChangesTheNetWorthFromItsEffectiveMonth(): void
    {
        $house = self::createAccount('Maison', 'residence_principale', '2024-01-01');
        self::service(StatementRepository::class)->save(self::owner(), $house, Month::fromString('2026-01'), 10_000_000);

        $this->submit("/comptes/$house/parts", ['mois' => '2026-06', 'pourcentage' => '33,3333']);
        self::assertResponseRedirects("/comptes/$house", Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('33,3333 %', self::plain($this->client->getCrawler()->filter('.shares')->text()));

        $this->client->request('GET', '/?mois=2026-05');
        self::assertStringContainsString('100 000,00', $this->total());
        $this->client->request('GET', '/?mois=2026-06');
        self::assertStringContainsString('33 333,30', $this->total());

        $this->submit("/comptes/$house/parts/2026-06/supprimer");
        $this->client->request('GET', '/?mois=2026-06');
        self::assertStringContainsString('100 000,00', $this->total());
    }

    public function testInvalidShareAndNonPropertyAreRefused(): void
    {
        $house = self::createAccount('Maison', 'residence_principale');
        $livret = self::createAccount('Livret', 'livret_a');

        $this->submit("/comptes/$house/parts", ['mois' => '2026-06', 'pourcentage' => '0']);
        $this->client->followRedirect();
        self::assertStringContainsString('part invalide', $this->flashes());
        $this->submit("/comptes/$livret/parts", ['mois' => '2026-06', 'pourcentage' => '50']);
        self::assertSame([], self::service(OwnershipShareRepository::class)->all(self::owner()));
    }

    public function testAnExistingLoanCanBeLinkedAndDetached(): void
    {
        $house = self::createAccount('Maison', 'residence_principale');
        $loan = self::createAccount('Prêt immo', 'credit_immo_rp');

        $crawler = $this->client->request('GET', "/comptes/$loan");
        self::assertCount(1, $crawler->filter("select[name=asset_id] option[value=\"$house\"]"));
        $this->submit("/comptes/$loan/bien", ['asset_id' => (string) $house, 'loan_mode' => 'own']);
        $account = self::service(AccountRepository::class)->find(self::owner(), $loan);
        self::assertNotNull($account);
        self::assertSame($house, $account->linkedAssetId);
        self::assertSame(LoanMode::Own, $account->loanMode);

        $this->submit("/comptes/$loan", ['name' => 'Prêt renommé', 'type_id' => (string) $account->typeId, 'level_code' => 'residence', 'nature' => 'liability', 'opened_on' => '2025-01-01']);
        self::assertSame($house, self::service(AccountRepository::class)->find(self::owner(), $loan)?->linkedAssetId, 'modifier le compte conserve le lien');

        $this->submit("/comptes/$loan/bien", ['asset_id' => '0']);
        self::assertNull(self::service(AccountRepository::class)->find(self::owner(), $loan)?->linkedAssetId);
    }

    public function testOnlyALoanCanBeLinkedAndOnlyToAProperty(): void
    {
        $house = self::createAccount('Maison', 'residence_principale');
        $loan = self::createAccount('Prêt', 'credit_immo_rp');
        $livret = self::createAccount('Livret', 'livret_a');

        $this->submit("/comptes/$house/bien", ['asset_id' => (string) $house]);
        $this->client->followRedirect();
        self::assertStringContainsString('Choisissez un bien.', $this->flashes());
        $this->submit("/comptes/$loan/bien", ['asset_id' => (string) $livret]);
        self::assertNull(self::service(AccountRepository::class)->find(self::owner(), $loan)?->linkedAssetId);
    }

    public function testDeletingAPropertyDetachesItsLoans(): void
    {
        $house = self::createAccount('Maison', 'residence_principale');
        $loan = self::createAccount('Prêt', 'credit_immo_rp');
        self::service(AccountRepository::class)->linkLoan(self::owner(), $loan, $house, LoanMode::Prorata);
        self::service(OwnershipShareRepository::class)->save(self::owner(), $house, Month::fromString('2026-01'), 500_000);

        $this->submit("/comptes/$house/supprimer");

        self::assertNull(self::service(AccountRepository::class)->find(self::owner(), $loan)?->linkedAssetId);
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM ownership_shares'));
    }

    public function testSharesAreEncryptedInTheDatabase(): void
    {
        $house = self::createAccount('Maison', 'residence_principale');
        self::service(OwnershipShareRepository::class)->save(self::owner(), $house, Month::fromString('2026-01'), 333_333);

        $rows = json_encode(self::service(\Doctrine\DBAL\Connection::class)->fetchAllAssociative('SELECT * FROM ownership_shares'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('333333', $rows);
    }

    private function total(): string
    {
        return self::plain($this->client->getCrawler()->filter('.summary-total')->text());
    }

    /** French amounts carry no-break spaces. */
    private static function plain(string $text): string
    {
        return str_replace(["\u{202F}", "\u{00A0}"], ' ', $text);
    }

    public function testASecondHomeIsRealEstateWithItsOwnLoanType(): void
    {
        $crawler = $this->client->request('GET', '/biens/nouveau');
        self::assertContains('Résidence secondaire', $crawler->filter('#type_id option')->each(static fn($option): string => $option->text()));

        $this->submit('/biens/nouveau', [
            'name' => 'Maison de vacances',
            'type_id' => $this->type('residence_secondaire'),
            'opened_on' => '2025-06-01',
            'value' => '200 000',
            'share' => '100',
            'loans' => [['name' => 'Prêt vacances', 'amount' => '80 000', 'mode' => 'prorata']],
        ]);
        self::assertResponseRedirects('/biens', Response::HTTP_SEE_OTHER);

        $accounts = self::service(AccountRepository::class)->all(self::owner());
        $home = $this->named($accounts, 'Maison de vacances');
        $loan = $this->named($accounts, 'Prêt vacances');
        self::assertSame(Level::Immobilier, $home->level, 'real estate level, not the main residence');
        self::assertSame((int) $this->type('credit_immo_rs'), $loan->typeId);
        self::assertSame(Level::Immobilier, $loan->level);
        self::assertSame($home->id, $loan->linkedAssetId);

        // Rental property: its loans keep the rental loan type.
        $this->submit('/biens/nouveau', [
            'name' => 'Studio', 'type_id' => $this->type('location_nue'), 'opened_on' => '2025-06-01', 'value' => '90 000',
            'share' => '100', 'loans' => [['name' => 'Prêt studio', 'amount' => '50 000', 'mode' => 'prorata']],
        ]);
        $studioLoan = $this->named(self::service(AccountRepository::class)->all(self::owner()), 'Prêt studio');
        self::assertSame((int) $this->type('credit_immo_locatif'), $studioLoan->typeId);
    }

    private function type(string $code): string
    {
        return (string) self::queryInt('SELECT id FROM account_types WHERE code = ?', [$code]);
    }

    /** @param list<Account> $accounts */
    private function named(array $accounts, string $name): Account
    {
        foreach ($accounts as $account) {
            if ($account->name === $name) {
                return $account;
            }
        }
        self::fail("Compte absent : $name");
    }
}
