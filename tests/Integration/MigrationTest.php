<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Integration;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\Migrations\Exception\AbortMigration;
use Patrimeter\Tests\Support\MigrationRunner;
use PHPUnit\Framework\TestCase;

/** Migrations applied to an empty database: schema, rollback, composite foreign keys. */
final class MigrationTest extends TestCase
{
    private const string INITIAL = 'DoctrineMigrations\Version20261108000000';
    private const string MULTI_USER = 'DoctrineMigrations\Version20261108120000';
    private const string ENCRYPTION = 'DoctrineMigrations\Version20261109090000';
    private const string WEBAUTHN = 'DoctrineMigrations\Version20261109120000';
    private const string OAUTH = 'DoctrineMigrations\Version20261109150000';
    private const string SHARES = 'DoctrineMigrations\Version20261109170000';
    private const string API = 'DoctrineMigrations\Version20261110090000';
    private const string SECURITY_CACHE = 'DoctrineMigrations\Version20261110120000';
    private const string WITHOUT_SETTINGS = 'DoctrineMigrations\Version20261110140000';
    private const string BUDGET = 'DoctrineMigrations\Version20261113100000';
    private const string FAMILY = 'DoctrineMigrations\Version20261113200000';
    private const string CARBURE = 'DoctrineMigrations\Version20261113220000';
    private const string SECOND_HOME = 'DoctrineMigrations\Version20261115140000';
    private const string WITHOUT_EXTERNAL_SIGN_IN = 'DoctrineMigrations\Version20261115220000';
    private const string USER_SESSIONS = 'DoctrineMigrations\Version20261116080000';
    private const string DEMO_ACCOUNTS = 'DoctrineMigrations\Version20261116140000';
    private const string SAVINGS_GOALS = 'DoctrineMigrations\Version20261116150000';
    private const string BUDGET_ORDER = 'DoctrineMigrations\Version20261117080000';
    private const string ENTRY_REMINDERS = 'DoctrineMigrations\Version20261117090000';
    private const string TRUSTED_BROWSERS = 'DoctrineMigrations\Version20261118080000';
    private const string EMAIL_LOCALE = 'DoctrineMigrations\Version20261118140000';
    private const string GAME = 'DoctrineMigrations\Version20261123080000';
    private const string APPROXIMATE = 'DoctrineMigrations\Version20261124080000';
    private const string SAVINGS_RATE = 'DoctrineMigrations\Version20261124090000';
    private const string CUSTOM_INDICATORS = 'DoctrineMigrations\Version20261124150000';
    private const string USER = '01900000-0000-7000-8000-000000000001';

    private ?MigrationRunner $runner = null;

    #[\Override]
    protected function tearDown(): void
    {
        $this->runner?->delete();
    }

    public function testFreshInstallCreatesTheSchemaAndReferenceDataWithoutAnyUser(): void
    {
        $runner = $this->runner = MigrationRunner::temporary();
        $runner->migrateToLatest();

        self::assertContains(self::ENCRYPTION, $runner->executedVersions());
        self::assertCount(6, $runner->rows('levels'));
        self::assertCount(38, $runner->rows('account_types'));
        self::assertSame([], $runner->rows('accounts'));
        self::assertSame([], $runner->rows('users'), 'aucun compte fantôme');
        self::assertSame(0, $runner->newMigrationsCount());
        self::assertSame([], $runner->connection->fetchAllAssociative('PRAGMA foreign_key_check'));
    }

    public function testNoPatrimonialColumnIsLeftInClear(): void
    {
        $runner = $this->runner = MigrationRunner::temporary();
        $runner->migrateToLatest();
        $schema = $runner->normalizedSchema();

        foreach (['name', 'amount_cents', 'opened_on', 'nature'] as $column) {
            self::assertStringNotContainsString($column, $schema['accounts'] . $schema['statements']);
        }
        // Budget: first names, names, groups, incomes and amounts only in the ciphertext.
        $budget = $schema['budget_households'] . $schema['budget_categories'] . $schema['budget_lines'] . $schema['budget_months'];
        foreach (['name', 'amount', 'income', 'group', 'adult', 'child', 'account_id'] as $column) {
            self::assertStringNotContainsString($column, $budget);
        }
        // Family account: names of the members and content of their files only in the ciphertext.
        foreach (['name', 'amount', 'account', 'budget', 'exported'] as $column) {
            self::assertStringNotContainsString($column, $schema['family_members']);
        }
        // Carbure: address, token and mapping only in the ciphertext.
        foreach (['url', 'token', 'mapping', 'categor'] as $column) {
            self::assertStringNotContainsString($column, $schema['carbure_links']);
        }
    }

    public function testAnEmptyDatabaseCanBeRolledBackAndMigratedAgain(): void
    {
        $runner = $this->runner = MigrationRunner::temporary();
        $runner->migrateToLatest();
        $latest = $runner->normalizedSchema();
        self::assertArrayNotHasKey('settings', $latest, 'table de la v1 retirée');

        $runner->migrateDown('DoctrineMigrations\\Version20261126220000');
        self::assertStringNotContainsString('announcement_pending', $runner->normalizedSchema()['game_stars']);
        $runner->migrateDown('DoctrineMigrations\\Version20261126190000');
        self::assertStringNotContainsString('claim_hash', $runner->normalizedSchema()['entry_reminders']);
        self::assertArrayHasKey('custom_indicators', $latest);
        $runner->migrateDown(self::CUSTOM_INDICATORS);
        self::assertArrayNotHasKey('custom_indicators', $runner->normalizedSchema());
        self::assertStringContainsString('savings_rate_target', $latest['game_settings']);
        $runner->migrateDown(self::SAVINGS_RATE);
        self::assertStringNotContainsString('savings_rate_target', $runner->normalizedSchema()['game_settings']);
        self::assertArrayHasKey('approximate_statements', $latest);
        $runner->migrateDown(self::APPROXIMATE);
        self::assertArrayNotHasKey('approximate_statements', $runner->normalizedSchema());
        self::assertArrayHasKey('statements', $runner->normalizedSchema());
        self::assertArrayHasKey('game_stars', $latest);
        $runner->migrateDown(self::GAME);
        self::assertArrayNotHasKey('game_stars', $runner->normalizedSchema());
        self::assertArrayNotHasKey('game_settings', $runner->normalizedSchema());
        self::assertStringContainsString('email_locale', $latest['users']);
        $runner->migrateDown(self::EMAIL_LOCALE);
        self::assertStringNotContainsString('email_locale', $runner->normalizedSchema()['users']);
        self::assertArrayHasKey('trusted_browsers', $latest);
        $runner->migrateDown(self::TRUSTED_BROWSERS);
        self::assertArrayNotHasKey('trusted_browsers', $runner->normalizedSchema());
        self::assertArrayHasKey('entry_reminders', $latest);
        $runner->migrateDown(self::ENTRY_REMINDERS);
        self::assertArrayNotHasKey('entry_reminders', $runner->normalizedSchema());
        self::assertStringContainsString('position', $latest['budget_categories']);
        $runner->migrateDown(self::BUDGET_ORDER);
        self::assertStringNotContainsString('position', $runner->normalizedSchema()['budget_lines']);
        self::assertArrayHasKey('savings_goals', $latest);
        $runner->migrateDown(self::SAVINGS_GOALS);
        self::assertArrayNotHasKey('savings_goals', $runner->normalizedSchema());
        self::assertArrayHasKey('demo_accounts', $latest);
        $runner->migrateDown(self::DEMO_ACCOUNTS);
        self::assertArrayNotHasKey('demo_accounts', $runner->normalizedSchema());
        self::assertArrayHasKey('user_sessions', $latest);
        $runner->migrateDown(self::USER_SESSIONS);
        self::assertArrayNotHasKey('user_sessions', $runner->normalizedSchema());
        self::assertArrayNotHasKey('oauth_identities', $latest, 'external sign-in removed');
        $runner->migrateDown(self::WITHOUT_EXTERNAL_SIGN_IN);
        self::assertArrayHasKey('oauth_identities', $runner->normalizedSchema());
        $runner->migrateDown(self::SECOND_HOME);
        self::assertCount(28, $runner->rows('account_types'), 'second home and tangible asset types removed');
        self::assertSame(['Or et métaux précieux'], $runner->connection->fetchFirstColumn("SELECT label FROM account_types WHERE code = 'or'"));
        $runner->migrateDown(self::CARBURE);
        self::assertArrayNotHasKey('carbure_links', $runner->normalizedSchema());
        $runner->migrateDown(self::FAMILY);
        self::assertArrayNotHasKey('family_members', $runner->normalizedSchema());
        self::assertArrayHasKey('budget_months', $runner->normalizedSchema());
        $runner->migrateDown(self::BUDGET);
        foreach (['budget_households', 'budget_categories', 'budget_lines', 'budget_months'] as $table) {
            self::assertArrayNotHasKey($table, $runner->normalizedSchema());
        }
        $runner->migrateDown(self::WITHOUT_SETTINGS);
        self::assertArrayHasKey('settings', $runner->normalizedSchema());
        $runner->migrateDown(self::SECURITY_CACHE);
        self::assertArrayHasKey('login_throttle', $runner->normalizedSchema());
        self::assertArrayNotHasKey('cache_items', $runner->normalizedSchema());
        $runner->migrateDown(self::API);
        self::assertArrayNotHasKey('api_tokens', $runner->normalizedSchema());
        $runner->migrateDown(self::SHARES);
        $runner->migrateDown(self::OAUTH);
        self::assertArrayNotHasKey('oauth_identities', $runner->normalizedSchema());
        $runner->migrateDown(self::WEBAUTHN);
        self::assertArrayNotHasKey('webauthn_credentials', $runner->normalizedSchema());
        $runner->migrateDown(self::ENCRYPTION);
        self::assertStringContainsString('amount_cents', $runner->normalizedSchema()['statements']);
        self::assertArrayNotHasKey('key_envelopes', $runner->normalizedSchema());
        $runner->migrateDown(self::MULTI_USER);
        self::assertArrayNotHasKey('users', $runner->normalizedSchema());
        self::assertSame([self::INITIAL], $runner->executedVersions());

        $runner->migrateToLatest();
        self::assertSame($latest, $runner->normalizedSchema());
    }

    public function testRollbackIsRefusedOnceDataIsEncrypted(): void
    {
        $runner = $this->runner = MigrationRunner::temporary();
        $runner->migrateToLatest();
        $this->insertUser($runner, self::USER);
        $runner->connection->executeStatement("INSERT INTO accounts (id, user_id, ciphertext) VALUES (1, ?, 'v1.chiffré')", [self::USER]);

        try {
            $runner->migrateDown(self::ENCRYPTION);
            self::fail('Retour arrière refusé attendu');
        } catch (AbortMigration $e) {
            self::assertStringContainsString('ne peuvent pas être remises en clair', $e->getMessage());
        }
        self::assertCount(1, $runner->rows('accounts'));
    }

    public function testDatabaseRefusesAStatementPointingToAnotherUsersAccount(): void
    {
        $runner = $this->runner = MigrationRunner::temporary();
        $runner->migrateToLatest();
        $intruder = '01900000-0000-7000-8000-000000000002';
        $this->insertUser($runner, self::USER);
        $this->insertUser($runner, $intruder);
        $runner->connection->executeStatement("INSERT INTO accounts (id, user_id, ciphertext) VALUES (1, ?, 'v1.chiffré')", [self::USER]);

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $runner->connection->executeStatement("INSERT INTO statements (account_id, user_id, month, ciphertext) VALUES (1, ?, '2026-11', 'v1.chiffré')", [$intruder]);
    }

    public function testDatabaseRefusesABudgetLineInAnotherUsersCategory(): void
    {
        $runner = $this->runner = MigrationRunner::temporary();
        $runner->migrateToLatest();
        $intruder = '01900000-0000-7000-8000-000000000002';
        $this->insertUser($runner, self::USER);
        $this->insertUser($runner, $intruder);
        $runner->connection->executeStatement("INSERT INTO budget_categories (id, user_id, ciphertext) VALUES (1, ?, 'v1.chiffré')", [self::USER]);

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $runner->connection->executeStatement("INSERT INTO budget_lines (user_id, category_id, ciphertext) VALUES (?, 1, 'v1.chiffré')", [$intruder]);
    }

    public function testDeletingAUserDeletesAllItsDataByCascade(): void
    {
        $runner = $this->runner = MigrationRunner::temporary();
        $runner->migrateToLatest();
        $this->insertUser($runner, self::USER);
        $runner->connection->executeStatement("INSERT INTO accounts (id, user_id, ciphertext) VALUES (1, ?, 'v1.chiffré')", [self::USER]);
        $runner->connection->executeStatement("INSERT INTO statements (account_id, user_id, month, ciphertext) VALUES (1, ?, '2026-11', 'v1.chiffré')", [self::USER]);
        $runner->connection->executeStatement("INSERT INTO budget_households (user_id, ciphertext) VALUES (?, 'v1.chiffré')", [self::USER]);
        $runner->connection->executeStatement("INSERT INTO budget_categories (id, user_id, ciphertext) VALUES (1, ?, 'v1.chiffré')", [self::USER]);
        $runner->connection->executeStatement("INSERT INTO budget_lines (user_id, category_id, ciphertext) VALUES (?, 1, 'v1.chiffré')", [self::USER]);
        $runner->connection->executeStatement("INSERT INTO budget_months (user_id, month, ciphertext) VALUES (?, '2026-11', 'v1.chiffré')", [self::USER]);
        $runner->connection->executeStatement("INSERT INTO family_members (user_id, ciphertext) VALUES (?, 'v1.chiffré')", [self::USER]);
        $runner->connection->executeStatement("INSERT INTO carbure_links (user_id, ciphertext) VALUES (?, 'v1.chiffré')", [self::USER]);

        $runner->connection->executeStatement('DELETE FROM users WHERE id = ?', [self::USER]);

        foreach (['accounts', 'statements', 'budget_households', 'budget_categories', 'budget_lines', 'budget_months', 'family_members', 'carbure_links'] as $table) {
            self::assertSame([], $runner->rows($table), $table);
        }
    }

    private function insertUser(MigrationRunner $runner, string $id): void
    {
        $runner->connection->executeStatement(
            "INSERT INTO users (id, email, session_stamp, created_at) VALUES (?, ?, 'x', '2026-11-08T12:00:00Z')",
            [$id, "u$id@example.test"],
        );
    }
}
