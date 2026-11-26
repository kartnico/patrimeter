<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;

final class MonthlyEntryTest extends FunctionalTestCase
{
    private int $current;
    private int $livret;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logIn();
        $this->current = self::createAccount('Compte courant', 'compte_courant');
        $this->livret = self::createAccount('Livret A', 'livret_a');
        $statements = self::service(StatementRepository::class);
        $statements->save(self::owner(), $this->current, Month::fromString('2026-10'), 150000);
        $statements->save(self::owner(), $this->livret, Month::fromString('2026-09'), 2000000);
    }

    public function testFormIsPrefilledWithTheLastKnownBalance(): void
    {
        $crawler = $this->client->request('GET', '/saisie');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Saisie Novembre 2026');
        self::assertSame('1500,00', $crawler->filter("#amount-{$this->current}")->attr('value'));
        self::assertSame('20000,00', $crawler->filter("#amount-{$this->livret}")->attr('value'));
        self::assertSelectorTextContains('.entry-counter', '0/2 saisis');
        self::assertSame('2', $crawler->filter('progress.entry-progress')->attr('max'));
        self::assertAnySelectorTextContains('.entry-row .badge-carried', 'reporté · oct. 2026');
    }

    public function testEachLevelShowsLastMonthsBalanceAndItsLiveTotal(): void
    {
        $crawler = $this->client->request('GET', '/saisie');

        // October column: €1,500 entered, €20,000 carried over from September; level total and month's net.
        self::assertSame('Octobre 2026', $crawler->filter('.entry-table thead th.entry-previous')->first()->text());
        self::assertSame("1\u{202F}500,00\u{A0}€", $crawler->filter("#amount-{$this->current}")->closest('tr')?->filter('td.entry-previous')->text());
        self::assertSame("20\u{202F}000,00\u{A0}€", $crawler->filter("#amount-{$this->livret}")->closest('tr')?->filter('td.entry-previous')->text());
        self::assertSame("21\u{202F}500,00\u{A0}€", $crawler->filter('.level-precaution [data-level-total]')->text());
        self::assertSame("21\u{202F}500,00\u{A0}€", $crawler->filter('[data-entry-net]')->text());
        self::assertSame('1000000', $crawler->filter("#amount-{$this->livret}")->closest('tr')?->attr('data-share'));
        self::assertSame('150000', $crawler->filter("#amount-{$this->current}")->closest('tr')?->attr('data-previous'));
        // Help folded behind "?": keyboard shortcuts and loans.
        self::assertSelectorExists('details.entry-help-toggle > summary[aria-label="Aide de la saisie"]');
        self::assertSelectorTextContains('.entry-help', 'Raccourcis clavier');
    }

    public function testLevelTotalUsesThePropertyShareAndSubtractsLoans(): void
    {
        $house = self::createAccount('Maison', 'residence_principale');
        $loan = self::createAccount('Prêt maison', 'credit_immo_rp');
        $statements = self::service(StatementRepository::class);
        $statements->save(self::owner(), $house, Month::fromString('2026-11'), 30_000_000);
        $statements->save(self::owner(), $loan, Month::fromString('2026-11'), 10_000_000);
        self::service(OwnershipShareRepository::class)->save(self::owner(), $house, Month::fromString('2025-01'), 500_000);

        $crawler = $this->client->request('GET', '/saisie');

        // €300,000 at 50 %, minus the unlinked loan counted in full.
        self::assertSame("50\u{202F}000,00\u{A0}€", $crawler->filter('.level-residence [data-level-total]')->text());
        self::assertSame('500000', $crawler->filter("#amount-{$house}")->closest('tr')?->attr('data-share'));
        self::assertSame('1', $crawler->filter("#amount-{$loan}")->closest('tr')?->attr('data-liability'));
        self::assertSelectorTextContains('.level-residence .badge-due', 'dû');
    }

    public function testALevelEnteredThisMonthIsFoldedWithItsNet(): void
    {
        $house = self::createAccount('Maison', 'residence_principale');
        $loan = self::createAccount('Prêt maison', 'credit_immo_rp');
        $statements = self::service(StatementRepository::class);
        $statements->save(self::owner(), $house, Month::fromString('2026-11'), 30_000_000);
        $statements->save(self::owner(), $loan, Month::fromString('2026-11'), 10_000_000);

        $crawler = $this->client->request('GET', '/saisie');

        // Residence: both balances entered in November, folded with "entered" and its net in the summary.
        $residence = $crawler->filter('.level-residence details.entry-fold');
        self::assertNull($residence->attr('open'));
        self::assertSame('saisi', $residence->filter('summary .status-entered')->text());
        self::assertSame("200\u{202F}000,00\u{A0}€", $residence->filter('summary [data-level-sum]')->text());
        // Precaution: balances carried over, left open; its fields stay in the form either way.
        self::assertNotNull($crawler->filter('.level-precaution details.entry-fold')->attr('open'));
        self::assertCount(0, $crawler->filter('.level-precaution summary .status-entered'));
        self::assertCount(1, $crawler->filter("details.entry-fold #amount-{$house}"));
    }

    public function testALevelWithAnInvalidAmountStaysOpen(): void
    {
        $house = self::createAccount('Maison', 'residence_principale');
        self::service(StatementRepository::class)->save(self::owner(), $house, Month::fromString('2026-11'), 30_000_000);

        $this->submit('/saisie', ['mois' => '2026-11', 'amounts' => [$house => 'abc', $this->current => '1500', $this->livret => '20000']]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNotNull($this->client->getCrawler()->filter('.level-residence details.entry-fold')->attr('open'));
    }

    public function testUnchangedButtonOffersLastMonthsBalance(): void
    {
        $new = self::createAccount('LDDS', 'ldds');

        $crawler = $this->client->request('GET', '/saisie');

        // Hidden until app.js shows it; one per account with a balance in October (entered or carried over).
        $button = $crawler->filter("#amount-{$this->current}")->closest('td')?->filter('button[data-entry-same]');
        self::assertNotNull($button);
        self::assertSame('= oct. 2026 : solde inchangé', $button->text(), 'the visible text starts the accessible name (WCAG 2.5.3)');
        self::assertNull($button->attr('aria-label'));
        // The account is named by its label, not by the button's: "Livret A" stays the label of a single field.
        self::assertSame("amount-{$this->current}-name", $button->attr('aria-describedby'));
        self::assertSame('Compte courant', $crawler->filter("#amount-{$this->current}-name")->text());
        self::assertSame('button', $button->attr('type'));
        self::assertNotNull($button->attr('hidden'));
        self::assertCount(1, $crawler->filter("#amount-{$this->livret}")->closest('td')?->filter('[data-entry-same]') ?? []);
        self::assertCount(0, $crawler->filter("#amount-{$new}")->closest('td')?->filter('[data-entry-same]') ?? []);
        self::assertSelectorTextContains('.entry-keys', 'reprend le solde du mois précédent');
    }

    public function testNetWorthChangeWithLastMonthFollowsTheEntry(): void
    {
        $crawler = $this->client->request('GET', '/saisie');

        // October: €1,500 + €20,000 carried over; November unchanged so far.
        $change = $crawler->filter('[data-entry-change]');
        self::assertSame('2150000', $change->attr('data-previous-net'));
        self::assertSame('%amount% vs oct. 2026', $change->attr('data-label'));
        self::assertStringEndsWith('vs oct. 2026', $change->text());

        // September: nothing known in August, no change shown.
        $this->client->request('GET', '/saisie?mois=2026-09');
        self::assertSelectorExists('[data-entry-net]');
        self::assertSelectorNotExists('[data-entry-change]');
    }

    public function testLoanAmountIsShownWithItsMinusSignAndSavedAsTheOutstandingPrincipal(): void
    {
        $loan = self::createAccount('Crédit auto', 'credit_conso');
        self::service(StatementRepository::class)->save(self::owner(), $loan, Month::fromString('2026-10'), 1_200_000);

        $crawler = $this->client->request('GET', '/saisie');
        self::assertSame("\u{2212}12000,00", $crawler->filter("#amount-{$loan}")->attr('value'), 'le « − » reste collé au montant');
        self::assertSame("\u{2212}12\u{202F}000,00\u{A0}€", $crawler->filter("#amount-{$loan}")->closest('tr')?->filter('td.entry-previous')->text());

        // As shown (typographic "−"), with a hyphen or without a sign: always the outstanding principal, positive.
        foreach (["\u{2212}11 500,00" => 1_150_000, '-11 400' => 1_140_000, '11 300' => 1_130_000] as $input => $expected) {
            $this->submit('/saisie', ['mois' => '2026-11', 'amounts' => [$loan => $input]]);
            self::assertResponseRedirects('/?mois=2026-11', Response::HTTP_SEE_OTHER, $input);
            self::assertSame($expected, self::service(StatementRepository::class)->forAccount(self::owner(), $loan)['2026-11'] ?? null, $input);
        }
    }

    public function testAnotherMonthIsCopiedIntoTheFormWithoutSavingAnything(): void
    {
        // October 2026: current account €1,500 (entered), savings account €20,000 (carried over from September).
        $crawler = $this->client->request('GET', '/saisie?mois=2025-03&depuis=2026-10');

        self::assertResponseIsSuccessful();
        self::assertSame('1500,00', $crawler->filter("#amount-{$this->current}")->attr('value'));
        self::assertSame('20000,00', $crawler->filter("#amount-{$this->livret}")->attr('value'));
        self::assertSelectorTextContains('p.flash', 'Soldes d’octobre 2026 copiés (2 comptes)');
        self::assertCount(2, $crawler->filter('.entry-row.is-copied .badge-copied'));
        self::assertSame('2026-10', $crawler->filter('form.copy-form input[name="depuis"]')->attr('value'));
        self::assertNull(
            self::service(StatementRepository::class)->valuation(self::owner())->valueAt($this->current, Month::fromString('2025-03')),
            'rien n\'est enregistré avant la validation',
        );
    }

    public function testCopyIgnoresAFutureOrIdenticalSourceMonth(): void
    {
        foreach (['/saisie?mois=2025-03&depuis=2027-01', '/saisie?mois=2025-03&depuis=2025-03', '/saisie?mois=2025-03&depuis=n-importe'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('.badge-copied'), $url);
            self::assertSelectorNotExists('p.flash');
        }
    }

    public function testMonthFieldsAreTextWithAPatternForTheCalendar(): void
    {
        $crawler = $this->client->request('GET', '/saisie');
        $field = $crawler->filter('form.copy-form [data-month-field]');

        self::assertSame('2026-11', $field->attr('data-today'));
        self::assertStringStartsWith('janv.|févr.', (string) $field->attr('data-short-names'));
        self::assertSame('[0-9]{4}-(0[1-9]|1[0-2])', $field->filter('input')->attr('pattern'));
    }

    public function testNextMonthCanBeEnteredButNotTheOneAfter(): void
    {
        $crawler = $this->client->request('GET', '/saisie');
        self::assertSame('/saisie?mois=2026-12', $crawler->filter('.month-nav a[href^="/saisie?mois="]')->last()->attr('href'));
        $picker = $crawler->filter('[data-month-picker]');
        self::assertSame('2026-12', $picker->attr('data-max'));
        self::assertSame('2026-11', $picker->attr('data-today'), 'the calendar still marks the current month');

        $crawler = $this->client->request('GET', '/saisie?mois=2026-12');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Décembre 2026');
        self::assertCount(0, $crawler->filter('.month-nav a[href="/saisie?mois=2027-01"]'), 'no month after next');

        $this->submit('/saisie', ['mois' => '2026-12', 'amounts' => [$this->current => '1 700', $this->livret => '']]);
        self::assertResponseRedirects('/?mois=2026-12', Response::HTTP_SEE_OTHER);
        self::assertSame(170000, self::service(StatementRepository::class)->forAccount(self::owner(), $this->current)['2026-12'] ?? null);

        $this->client->request('GET', '/saisie?mois=2027-01');
        self::assertResponseRedirects('/saisie', Response::HTTP_SEE_OTHER);
    }

    public function testTheCopyCardLinksToNewAccountsAndProperties(): void
    {
        $crawler = $this->client->request('GET', '/saisie');

        $links = $crawler->filter('form.copy-form .copy-links a');
        self::assertSame(['/comptes/nouveau', '/biens/nouveau'], $links->each(static fn($link): ?string => $link->attr('href')));
        self::assertSame(['Ajouter un compte', 'Ajouter un bien immobilier'], $links->each(static fn($link): string => trim($link->text())));
    }

    public function testOneInvalidAmountSavesNothing(): void
    {
        $this->submit('/saisie', ['mois' => '2026-11', 'amounts' => [$this->current => '1 600', $this->livret => 'douze']]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('.flash-error', 'rien n\'a été enregistré');
        self::assertSelectorExists("#amount-{$this->livret}[aria-invalid=\"true\"]");
        self::assertSame('douze', $this->client->getCrawler()->filter("#amount-{$this->livret}")->attr('value'), 'saisie réaffichée');
        self::assertArrayNotHasKey('2026-11', self::service(StatementRepository::class)->forAccount(self::owner(), $this->current));
    }

    public function testValidEntryIsSavedAndEmptyFieldsKeepTheCarriedBalance(): void
    {
        $this->submit('/saisie', ['mois' => '2026-11', 'amounts' => [$this->current => '1 600,50', $this->livret => '']]);

        self::assertResponseRedirects('/?mois=2026-11', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Saisie de novembre 2026 enregistrée (1 compte).', $this->flashes());

        $statements = self::service(StatementRepository::class);
        self::assertSame(160050, $statements->forAccount(self::owner(), $this->current)['2026-11'] ?? null);
        self::assertArrayNotHasKey('2026-11', $statements->forAccount(self::owner(), $this->livret));
        self::assertTrue($statements->valuation(self::owner())->valueAt($this->livret, Month::fromString('2026-11'))?->carried);
    }

    /** Bug: on the English page, "1,000" (a thousand, English style) was refused as an amount with three decimals. */
    public function testAmountsAreReadInTheLanguageOfThePage(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'en-GB,en;q=0.9');
        $this->submit('/saisie', ['mois' => '2026-11', 'amounts' => [$this->current => '1,000', $this->livret => '12,345.67']]);

        self::assertResponseRedirects('/?mois=2026-11', Response::HTTP_SEE_OTHER);
        $statements = self::service(StatementRepository::class);
        self::assertSame(100000, $statements->forAccount(self::owner(), $this->current)['2026-11'] ?? null);
        self::assertSame(1234567, $statements->forAccount(self::owner(), $this->livret)['2026-11'] ?? null);
    }

    public function testInvalidMonthIsRejected(): void
    {
        $this->submit('/saisie', ['mois' => '2027-01', 'amounts' => [$this->current => '1']]);

        self::assertResponseRedirects('/saisie', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Mois invalide.', $this->flashes());
    }

    public function testMonthWithoutOpenAccountOffersToCopyALaterMonth(): void
    {
        $crawler = $this->client->request('GET', '/saisie?mois=2024-12');

        self::assertSelectorTextContains('.empty-state', 'Aucun compte ouvert en décembre 2024.');
        self::assertSelectorTextContains('.empty-state', 'Copiez les soldes d\'un mois suivant');
        self::assertSame('2026-11', $crawler->filter('form.copy-form input[name="depuis"]')->attr('value'), 'mois courant proposé');
        self::assertSelectorNotExists('form.entry-form');
    }

    public function testCopyingALaterMonthBringsInAccountsOpenedSince(): void
    {
        // Accounts opened on 01/01/2025, balances known in November 2026: December 2024 has none.
        $crawler = $this->client->request('GET', '/saisie?mois=2024-12&depuis=2026-11');

        self::assertSame('1500,00', $crawler->filter("#amount-{$this->current}")->attr('value'));
        self::assertSame('20000,00', $crawler->filter("#amount-{$this->livret}")->attr('value'));
        self::assertCount(2, $crawler->filter('form.entry-form input[name="advance[]"]'));
        self::assertSame('2026-11', $crawler->filter('form.entry-form input[name="depuis"]')->attr('value'));
        self::assertSelectorTextContains('p.flash', '2 comptes ouverts après décembre 2024 sont repris : la validation avancera leur ouverture au 01/12/2024.');
        self::assertAnySelectorTextContains('.entry-advance', 'ouvert le 01/01/2025 : ouverture avancée au 01/12/2024');
    }

    public function testSavingAdvancesTheOpeningOfFilledAccountsOnly(): void
    {
        $this->submit('/saisie', [
            'mois' => '2024-12',
            'depuis' => '2026-11',
            'advance' => [(string) $this->current, (string) $this->livret],
            'amounts' => [$this->current => '1 400', $this->livret => ''],
        ]);

        self::assertResponseRedirects('/?mois=2024-12', Response::HTTP_SEE_OTHER);
        $accounts = self::service(AccountRepository::class);
        self::assertSame('2024-12-01', $accounts->find(self::owner(), $this->current)?->openedOn, 'ouverture avancée au 1er décembre');
        self::assertSame('2025-01-01', $accounts->find(self::owner(), $this->livret)?->openedOn, 'champ vidé : ouverture inchangée');
        $statements = self::service(StatementRepository::class);
        self::assertSame(140000, $statements->forAccount(self::owner(), $this->current)['2024-12'] ?? null);
        self::assertArrayNotHasKey('2024-12', $statements->forAccount(self::owner(), $this->livret));
    }

    public function testAdvanceIsIgnoredWithoutASourceMonth(): void
    {
        $this->submit('/saisie', [
            'mois' => '2024-12',
            'advance' => [(string) $this->current],
            'amounts' => [$this->current => '1 400'],
        ]);

        self::assertResponseRedirects('/?mois=2024-12', Response::HTTP_SEE_OTHER);
        self::assertSame('2025-01-01', self::service(AccountRepository::class)->find(self::owner(), $this->current)?->openedOn);
        self::assertArrayNotHasKey('2024-12', self::service(StatementRepository::class)->forAccount(self::owner(), $this->current));
    }
}
