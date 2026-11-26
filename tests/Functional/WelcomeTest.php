<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Game\SaverStyle;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\GameRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Welcome of a new user: the pyramid built level by level, the express entry of every account on one page, and the
 * simple questions for beginners. The accounts are created with this month's balance, all together or none, flagged
 * "to refine" until the monthly entry saves them again. Clock frozen in November 2026.
 */
final class WelcomeTest extends FunctionalTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logIn();
    }

    public function testThePyramidIsBuiltLevelByLevel(): void
    {
        $crawler = $this->client->request('GET', '/bienvenue/pyramide');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.nav a[aria-current][href="/"]', 'the welcome belongs to the wealth');
        self::assertSame('0 niveau sur 6 rempli', $crawler->filter('.pyramid-count')->text());
        self::assertCount(6, $crawler->filter('.welcome-levels li'));
        self::assertSame('/bienvenue/niveau/residence', $crawler->filter('.welcome-levels a')->first()->attr('href'), 'from the base');

        $crawler = $this->client->request('GET', '/bienvenue/niveau/precaution');
        self::assertResponseIsSuccessful();
        self::assertSame('Livret A', $crawler->filter('#name-livret_a')->attr('value'), 'named after its type');
        self::assertSelectorExists('label[for="amount-livret_a"]');
        self::assertSelectorNotExists('#amount-pea', 'only the level asked for');

        $this->submit('/bienvenue/comptes', [
            'niveau' => 'precaution',
            'comptes' => [
                'livret_a' => ['name' => 'Livret A de Camille', 'amount' => '5 350'],
                'compte_courant' => ['name' => 'Compte courant', 'amount' => '2 035,50'],
                'credit_conso' => ['name' => 'Crédit voiture', 'amount' => '-1 840'],
                'ldds' => ['name' => 'LDDS', 'amount' => ''],
            ],
        ], '/bienvenue/niveau/precaution');
        self::assertResponseRedirects('/bienvenue/pyramide', Response::HTTP_SEE_OTHER);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('3 comptes créés, avec leur solde de ce mois.', $this->flashes());
        self::assertSame('1 niveau sur 6 rempli', $crawler->filter('.pyramid-count')->text());
        self::assertSelectorExists('.welcome-levels li.level-precaution.is-filled');

        $accounts = self::service(AccountRepository::class)->all(self::owner());
        self::assertSame(['Compte courant', 'Livret A de Camille', 'Crédit voiture'], array_map(static fn($account): string => $account->name, $accounts));
        self::assertSame('2026-11-01', $accounts[0]->openedOn, 'opened this month');
        $statements = self::service(StatementRepository::class)->all(self::owner());
        self::assertSame(['2026-11' => 184_000], $statements[$accounts[2]->id], 'a loan: its outstanding principal, positive');
        self::assertSame(['2026-11' => 203_550], $statements[$accounts[0]->id]);
    }

    public function testTheExpressEntryCreatesEverythingOrNothing(): void
    {
        $crawler = $this->client->request('GET', '/bienvenue/express');
        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('fieldset.starter-level'), 'every level');

        $this->submit('/bienvenue/comptes', ['comptes' => [
            'livret_a' => ['name' => 'Livret A', 'amount' => '5 350'],
            'pea' => ['name' => 'PEA', 'amount' => 'beaucoup'],
            'cto' => ['name' => '', 'amount' => '100'],
        ]], '/bienvenue/express');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorExists('#amount-pea[aria-invalid="true"][aria-describedby="err-pea"]');
        self::assertSelectorTextContains('#err-cto', 'Indiquez un nom de 1 à 80 caractères.');
        self::assertSame('5 350', $this->client->getCrawler()->filter('#amount-livret_a')->attr('value'), 'what was typed stays');
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM accounts WHERE user_id = ?', [self::owner()->value]), 'nothing created');

        $this->submit('/bienvenue/comptes', ['comptes' => [
            'livret_a' => ['name' => 'Livret A', 'amount' => '5 350'],
            'residence_principale' => ['name' => 'Maison', 'amount' => '249 000'],
            'credit_immo_rp' => ['name' => 'Crédit maison', 'amount' => '197 200'],
            'inconnu' => ['name' => 'X', 'amount' => '1'],
        ]], '/bienvenue/express');
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('3 comptes créés', $this->flashes());
        self::assertSame(3, self::queryInt('SELECT COUNT(*) FROM accounts WHERE user_id = ?', [self::owner()->value]), 'an unknown type is ignored');
        self::assertSame(3, self::queryInt("SELECT COUNT(*) FROM statements WHERE user_id = ? AND month = '2026-11'", [self::owner()->value]), 'their balances of this month');
    }

    public function testTheSimpleQuestionsCreateTheAccountsAnswered(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertSelectorExists('.welcome-choice[href="/bienvenue/questions"]');

        $crawler = $this->client->request('GET', '/bienvenue/questions');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.nav a[aria-current][href="/"]', 'the welcome belongs to the wealth');
        self::assertCount(8, $crawler->filter('fieldset[data-question]'), 'seven accounts, then the saver style');
        self::assertSame('Avez-vous un compte courant ? Environ combien dessus aujourd\'hui ?', $crawler->filter('fieldset[data-question] legend')->first()->text());
        $choices = $crawler->filter('fieldset[data-question]')->first()->filter('.question-choice')->each(static fn($label): string => trim($label->text()));
        self::assertSame("moins de 1\u{202F}000\u{A0}€", $choices[1]);
        self::assertSame("de 1\u{202F}000\u{A0}€ à 5\u{202F}000\u{A0}€", $choices[2]);
        self::assertSame('Montant exact', end($choices));
        self::assertSelectorTextContains('fieldset[data-question] .question-why', 'épargne de précaution');

        $this->submit('/bienvenue/questions', [
            'reponses' => ['compte_courant' => '1000-5000', 'livret_a' => 'exact', 'residence_principale' => '200000-300000', 'credit_immo_rp' => '', 'cto' => '', 'style' => 'project'],
            'montants' => ['livret_a' => '12 345,67', 'credit_immo_rp' => '180 000'],
        ], '/bienvenue/questions');
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('4 comptes créés', $this->flashes());

        $accounts = self::service(AccountRepository::class)->all(self::owner());
        self::assertSame(['Résidence principale', 'Crédit immobilier', 'Compte courant', 'Livrets'], array_map(static fn(Account $account): string => $account->name, $accounts));
        $valuation = self::service(StatementRepository::class)->valuation(self::owner());
        $month = Month::fromString('2026-11');
        self::assertSame([25_000_000, 18_000_000, 300_000, 1_234_567], array_map(static fn(Account $account): ?int => $valuation->valueAt($account->id, $month)?->cents, $accounts), 'middle of the range, or the amount typed');
        self::assertCount(4, self::service(StatementRepository::class)->approximate(self::owner()), 'all to refine');
        self::assertSame(SaverStyle::Project, self::service(GameRepository::class)->settings(self::owner())['style']);
    }

    public function testTheFirstBalancesAreToRefineUntilTheMonthlyEntrySavesThem(): void
    {
        $this->submit('/bienvenue/comptes', ['comptes' => ['livret_a' => ['name' => 'Livret A', 'amount' => '5 000']]], '/bienvenue/express');
        $account = self::service(AccountRepository::class)->all(self::owner())[0];

        $crawler = $this->client->request('GET', '/saisie');
        self::assertSame('à affiner', $crawler->filter("#amount-{$account->id}")->closest('tr')?->filter('.badge-approximate')->text());
        self::assertSelectorTextContains('.entry-help', 'À affiner');
        // December: the balance carried over from November is still to refine.
        $crawler = $this->client->request('GET', '/saisie?mois=2026-12');
        self::assertCount(1, $crawler->filter('.badge-approximate'));

        $this->submit('/saisie', ['mois' => '2026-11', 'amounts' => [$account->id => '5 120']], '/saisie');
        self::assertResponseRedirects('/?mois=2026-11', Response::HTTP_SEE_OTHER);
        self::assertSame([], self::service(StatementRepository::class)->approximate(self::owner()));
        $this->client->request('GET', '/saisie');
        self::assertSelectorNotExists('.badge-approximate');
    }

    public function testAnExactAmountMissingOrWrongCreatesNothing(): void
    {
        $this->submit('/bienvenue/questions', [
            'reponses' => ['compte_courant' => '0-1000', 'livret_a' => 'exact', 'cto' => 'exact'],
            'montants' => ['cto' => 'beaucoup'],
        ], '/bienvenue/questions');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $crawler = $this->client->getCrawler();
        self::assertSelectorTextContains('#err-livret_a', 'Indiquez le montant, ou choisissez une tranche.');
        self::assertSelectorExists('#exact-cto[aria-invalid="true"][aria-describedby="err-cto"]');
        self::assertSame('beaucoup', $crawler->filter('#exact-cto')->attr('value'), 'what was typed stays');
        self::assertNotNull($crawler->filter('input[name="reponses[compte_courant]"][value="0-1000"]')->attr('checked'), 'the ranges chosen stay');
        self::assertCount(2, $crawler->filter('fieldset[data-question-error]'));
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM accounts WHERE user_id = ?', [self::owner()->value]), 'nothing created');
    }

    public function testNoAccountAnsweredCreatesNothing(): void
    {
        $this->submit('/bienvenue/questions', ['reponses' => ['compte_courant' => '', 'style' => 'ant'], 'montants' => []], '/bienvenue/questions');

        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Aucun compte créé', $this->flashes());
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM accounts WHERE user_id = ?', [self::owner()->value]));
        self::assertSame(SaverStyle::Ant, self::service(GameRepository::class)->settings(self::owner())['style'], 'the style answered is kept');
    }

    public function testAnUnknownLevelIsNotFound(): void
    {
        $this->client->request('GET', '/bienvenue/niveau/lune');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
