<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Budget\HouseholdBudget;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\HttpFoundation\Response;

/** Deletion of one's own account from My account > Profile: confirmations, then nothing of the user is left. */
final class AccountDeletionTest extends FunctionalTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logIn();
    }

    public function testTheFormAppearsOnlyAfterTheIdentityIsConfirmed(): void
    {
        $this->client->request('GET', '/securite');
        self::assertSelectorTextContains('#delete-account-title', 'Supprimer mon compte');
        self::assertSelectorNotExists('form[action="/securite/supprimer-le-compte"]');
        self::assertSelectorExists('.danger-zone input[name="_return"][value="/securite"]');

        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => '/securite'], '/securite');
        $crawler = $this->client->followRedirect();
        $form = $crawler->filter('form[action="/securite/supprimer-le-compte"]');
        self::assertCount(1, $form);
        self::assertNotNull($form->attr('data-confirm'), 'last dialog before deleting');
        $email = $form->filter('input#delete_email[type="email"][name="confirm_email"][required]');
        self::assertCount(1, $email, 'the address of the account to type again');
        self::assertStringContainsString(self::EMAIL, $form->filter('label[for="delete_email"]')->text());
    }

    public function testDeletionNeedsARecentIdentityConfirmationAndTheAddressOfTheAccount(): void
    {
        $this->submit('/securite/supprimer-le-compte', ['confirm_email' => self::EMAIL], '/securite');
        self::assertResponseRedirects('/securite', Response::HTTP_SEE_OTHER);
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM users WHERE id = ?', [self::owner()->value]));

        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => '/securite'], '/securite');
        foreach (['', 'autre@example.test', 'confirm'] as $typed) {
            $this->submit('/securite/supprimer-le-compte', ['confirm_email' => $typed, 'confirm' => '1'], '/securite');
            self::assertResponseRedirects('/securite', Response::HTTP_SEE_OTHER);
            $this->client->followRedirect();
            self::assertStringContainsString('L\'adresse saisie n\'est pas celle du compte', $this->flashes(), $typed);
            self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM users WHERE id = ?', [self::owner()->value]));
        }
    }

    public function testTheAddressIsComparedWithoutCaseNorSurroundingSpaces(): void
    {
        $owner = self::owner(); // before the deletion: owner() creates the user again when missing
        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => '/securite'], '/securite');
        $this->submit('/securite/supprimer-le-compte', ['confirm_email' => '  ' . strtoupper(self::EMAIL) . ' '], '/securite');

        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM users WHERE id = ?', [$owner->value]));
    }

    public function testDeletionRemovesTheUserAndAllTheirDataAndSignsOut(): void
    {
        $owner = self::owner();
        $account = self::createAccount('Livret A', 'livret_a');
        self::service(StatementRepository::class)->save($owner, $account, Month::fromString('2026-11'), 100_000);
        TestUsers::unlock(static::getContainer(), $owner);
        self::service(HouseholdBudget::class)->saveHousehold($owner, new Household([1 => 'Flo'], [], 3, 1), 'fr');
        self::createFamilyMember($owner, 'Alex', 'Livret d\'Alex', 100_000);
        $neighbour = TestUsers::create(static::getContainer(), TestUsers::OTHER_EMAIL);
        self::createAccount('Compte du voisin', 'compte_courant', '2025-01-01', $neighbour);

        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => '/securite'], '/securite');
        $this->submit('/securite/supprimer-le-compte', ['confirm_email' => self::EMAIL], '/securite');

        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHeaderSame($email, 'Subject', 'Patrimeter : compte supprimé');
        self::assertEmailTextBodyContains($email, 'Votre compte et toutes ses données ont été supprimés.');

        // Nothing of the user is left, in any table; the neighbour keeps their data.
        $connection = self::service(Connection::class);
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM users WHERE id = ?', [$owner->value]));
        $tables = $connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table'");
        $checked = 0;
        foreach ($tables as $table) {
            self::assertIsString($table);
            $columns = $connection->fetchFirstColumn('SELECT name FROM pragma_table_info(?)', [$table]);
            if (in_array('user_id', $columns, true)) {
                self::assertSame(0, self::queryInt("SELECT COUNT(*) FROM \"$table\" WHERE user_id = ?", [$owner->value]), $table);
                ++$checked;
            }
        }
        self::assertGreaterThan(10, $checked);
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM accounts WHERE user_id = ?', [$neighbour->value]));

        // Signed out: the message on the sign-in page, then the pages ask to sign in again.
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Votre compte et toutes ses données ont été supprimés.');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/connexion');
    }
}
