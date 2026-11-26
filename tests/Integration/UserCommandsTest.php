<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Integration;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Carbure\CarbureLink;
use Patrimeter\Application\Registration;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\CarbureLinkRepository;
use Patrimeter\Infrastructure\Persistence\FamilyMemberRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\Throttle\AuthThrottle;
use Patrimeter\Security\Vault\DataKeys;
use Patrimeter\Security\Vault\Vault;
use Patrimeter\Tests\Support\FakeCarbure;
use Patrimeter\Tests\Support\IntegrationTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Account administration commands (password read from the standard input in non-interactive mode). */
final class UserCommandsTest extends IntegrationTestCase
{
    private const string PASSWORD = 'une phrase de passe automatisée';

    private Application $application;

    #[\Override]
    protected function setUp(): void
    {
        $this->application = new Application(self::bootKernel());
    }

    public function testCreatesAConfirmedAccountWithoutTotp(): void
    {
        $command = $this->execute('app:user:create', ['email' => ' Nouvelle@Example.TEST '], [self::PASSWORD]);

        self::assertSame(Command::SUCCESS, $command->getStatusCode(), $command->getDisplay());
        $user = self::service(UserRepository::class)->findByEmail('nouvelle@example.test');
        self::assertNotNull($user);
        self::assertTrue($user['email_verified']);
        self::assertFalse($user['totp_enabled'], 'TOTP configuré à la première connexion');
        self::assertTrue(password_verify(self::PASSWORD, (string) $user['password_hash']));
        self::assertStringNotContainsString(self::PASSWORD, $command->getDisplay(), 'le mot de passe n\'est jamais affiché');
    }

    public function testCreationRefusesAnInvalidOrExistingAddressAndAWeakPassword(): void
    {
        self::owner();
        self::assertSame(Command::FAILURE, $this->execute('app:user:create', ['email' => 'pas-une-adresse'], [self::PASSWORD])->getStatusCode());
        $existing = $this->execute('app:user:create', ['email' => TestUsers::EMAIL], [self::PASSWORD]);
        self::assertSame(Command::FAILURE, $existing->getStatusCode());
        self::assertStringContainsString('existe déjà', $existing->getDisplay());
        $weak = $this->execute('app:user:create', ['email' => 'nouvelle@example.test'], ['court']);
        self::assertSame(Command::FAILURE, $weak->getStatusCode());
        self::assertStringContainsString('au moins 12 caractères', $weak->getDisplay());
        self::assertSame(Command::FAILURE, $this->execute('app:user:create', ['email' => 'vide@example.test'], [])->getStatusCode());
        self::assertNull(self::service(UserRepository::class)->findByEmail('nouvelle@example.test'));
    }

    public function testSetPasswordEndsSessionsAndForgetsTheAccountThrottle(): void
    {
        $id = self::owner();
        $stampBefore = self::service(UserRepository::class)->find($id)['session_stamp'] ?? null;
        $throttle = self::service(AuthThrottle::class);
        $throttle->recordPasswordFailure('192.0.2.1', TestUsers::EMAIL);

        $refused = $this->execute('app:user:set-password', ['email' => TestUsers::EMAIL], [self::PASSWORD]);
        self::assertSame(Command::FAILURE, $refused->getStatusCode(), 'données chiffrées : --erase-data exigé');
        $account = self::createAccount('Livret', 'livret_a');

        $command = $this->execute('app:user:set-password', ['email' => TestUsers::EMAIL, '--erase-data' => true], [self::PASSWORD]);

        self::assertSame(Command::SUCCESS, $command->getStatusCode(), $command->getDisplay());
        $user = self::service(UserRepository::class)->find($id);
        self::assertTrue(password_verify(self::PASSWORD, $user['password_hash'] ?? ''));
        self::assertNotSame($stampBefore, $user['session_stamp'] ?? null);
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM accounts WHERE id = ?', [$account]), 'données effacées');
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM key_envelopes WHERE user_id = ?', [$id->value]));
        self::assertSame(0, $throttle->passwordRetryAfter('198.51.100.1', TestUsers::EMAIL));
        self::assertSame(Command::FAILURE, $this->execute('app:user:set-password', ['email' => 'inconnu@example.test'], [self::PASSWORD])->getStatusCode());
    }

    public function testResetTwoFactorRemovesTotpAndBackupCodesAfterConfirmation(): void
    {
        $id = self::owner();
        $refused = $this->execute('app:user:reset-2fa', ['email' => TestUsers::EMAIL], ['non'], interactive: true);
        self::assertSame(Command::FAILURE, $refused->getStatusCode());
        self::assertTrue(self::service(UserRepository::class)->find($id)['totp_enabled'] ?? false);

        $command = $this->execute('app:user:reset-2fa', ['email' => TestUsers::EMAIL], ['oui'], interactive: true);

        self::assertSame(Command::SUCCESS, $command->getStatusCode(), $command->getDisplay());
        self::assertFalse(self::service(UserRepository::class)->find($id)['totp_enabled'] ?? true);
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM backup_codes WHERE user_id = ?', [$id->value]));
    }

    public function testTheListGivesTheUsersAndTheirNumberOfAccounts(): void
    {
        self::owner();
        self::createAccount('Livret', 'livret_a');
        self::createAccount('PEA', 'pea');
        TestUsers::findOrCreate(self::getContainer(), TestUsers::OTHER_EMAIL);
        self::service(Registration::class)->register('pas-encore@example.test', self::PASSWORD);

        $list = $this->execute('app:user:list', [], []);
        self::assertSame(Command::SUCCESS, $list->getStatusCode());
        $display = $list->getDisplay();
        foreach ([TestUsers::EMAIL, TestUsers::OTHER_EMAIL, 'pas-encore@example.test'] as $email) {
            self::assertStringContainsString($email, $display);
        }
        self::assertStringContainsString('3 utilisateurs (dont 0 de démonstration, 1 non confirmé), 2 comptes au total.', $display);
        self::assertStringNotContainsString('Livret', $display, 'nothing decrypted');

        $json = json_decode($this->execute('app:user:list', ['--json' => true], [])->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame(['users' => 3, 'demo' => 0, 'unconfirmed' => 1, 'accounts' => 2], $json['totals'] ?? null);
        $users = array_column((array) ($json['users'] ?? []), 'accounts', 'email');
        ksort($users);
        self::assertSame(['pas-encore@example.test' => 0, TestUsers::EMAIL => 2, TestUsers::OTHER_EMAIL => 0], $users);
    }

    /**
     * @param array<string, string|true> $arguments
     * @param list<string>          $inputs
     */
    private function execute(string $name, array $arguments, array $inputs, bool $interactive = false): CommandTester
    {
        $tester = new CommandTester($this->application->find($name));
        $tester->setInputs($inputs);
        $tester->execute($arguments, ['interactive' => $interactive]);

        return $tester;
    }

    public function testRotatingTheKeyKeepsTheDataAndCancelsTheRecoveryKey(): void
    {
        $owner = self::owner();
        $id = self::createAccount('Livret', 'livret_a');
        self::service(StatementRepository::class)->save($owner, $id, Month::fromString('2026-10'), 12_345);
        self::service(OwnershipShareRepository::class)->save($owner, $id, Month::fromString('2026-01'), 500_000);
        $budget = self::createBudget($owner, 'Alex', 'Logement', 'Loyer', 80_000);
        $member = self::createFamilyMember($owner, 'Sam', 'Livret de Sam', 54_321);
        self::service(CarbureLinkRepository::class)->save($owner, new CarbureLink(FakeCarbure::URL, FakeCarbure::TOKEN, [1 => 'Logement'], [1 => 0]));
        $before = self::service(Connection::class)->fetchOne('SELECT ciphertext FROM accounts WHERE id = ?', [$id]);

        self::assertSame(Command::FAILURE, $this->execute('app:user:rotate-key', ['email' => TestUsers::EMAIL], ['faux mot de passe'])->getStatusCode());
        $command = $this->execute('app:user:rotate-key', ['email' => TestUsers::EMAIL], [TestUsers::PASSWORD]);

        self::assertSame(Command::SUCCESS, $command->getStatusCode(), $command->getDisplay());
        self::assertNotSame($before, self::service(Connection::class)->fetchOne('SELECT ciphertext FROM accounts WHERE id = ?', [$id]));
        self::assertSame(0, self::queryInt("SELECT COUNT(*) FROM key_envelopes WHERE user_id = ? AND type = 'recovery'", [$owner->value]));
        $vault = self::service(Vault::class);
        $vault->reset();
        $key = self::service(DataKeys::class)->keyFromPassword($owner, TestUsers::PASSWORD);
        self::assertNotNull($key);
        $vault->unlock($owner, $key);
        self::assertSame('Livret', self::service(AccountRepository::class)->find($owner, $id)?->name);
        self::assertSame(['2026-10' => 12_345], self::service(StatementRepository::class)->forAccount($owner, $id));
        self::assertSame([$id => ['2026-01' => 500_000]], self::service(OwnershipShareRepository::class)->all($owner));
        $budgets = self::service(BudgetRepository::class);
        self::assertSame([1 => 'Alex'], $budgets->household($owner)?->adults);
        self::assertSame('Logement', $budgets->findCategory($owner, $budget['category'])?->name);
        self::assertSame('Loyer', $budgets->findLine($owner, $budget['line'])?->name);
        self::assertSame(80_000, $budgets->budgetAt($owner, Month::fromString('2026-10'))?->amount($budget['line']));
        $imported = self::service(FamilyMemberRepository::class)->find($owner, $member);
        self::assertSame('Sam', $imported?->name, 'membre du compte famille rechiffré');
        self::assertSame('Livret de Sam', is_array($imported->accounts[0] ?? null) ? $imported->accounts[0]['name'] : null);
        self::assertSame(FakeCarbure::TOKEN, self::service(CarbureLinkRepository::class)->find($owner)?->token, 'lien Carbure rechiffré');
    }
}
