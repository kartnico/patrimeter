<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use OTPHP\TOTP;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Crypto\DecryptionFailed;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\Row;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Security\Vault\Vault;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Encryption: no wealth data in clear in the database, data key available only with the session's cookie,
 * mandatory recovery key.
 */
final class EncryptionTest extends FunctionalTestCase
{
    private const string MARKER = 'Livret-Secret-5e2c';

    public function testNoPatrimonialDataIsStoredInClear(): void
    {
        $this->logIn();
        $this->submit('/comptes/nouveau', ['name' => self::MARKER, 'type_id' => $this->typeId('livret_a'), 'opened_on' => '2026-01-01', 'initial_amount' => '76 543,21']);
        self::assertResponseRedirects('/comptes', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString(self::MARKER, $this->body(), 'affiché à son propriétaire');

        $dump = '';
        foreach (['accounts', 'statements'] as $table) {
            $rows = self::service(Connection::class)->fetchAllAssociative("SELECT * FROM $table");
            self::assertNotSame([], $rows);
            $dump .= json_encode($rows, JSON_THROW_ON_ERROR);
        }
        self::assertStringNotContainsString(self::MARKER, $dump);
        self::assertStringNotContainsString('7654321', $dump);
        self::assertStringNotContainsString('livret', strtolower($dump));
    }

    public function testVaultCookieIsHttpOnlyStrictAndTheSessionHoldsNoClearKey(): void
    {
        $this->submitPassword();

        $cookies = $this->client->getResponse()->headers->getCookies();
        $vaultCookies = array_values(array_filter($cookies, static fn($c): bool => $c->getName() === Vault::COOKIE));
        self::assertCount(1, $vaultCookies);
        self::assertTrue($vaultCookies[0]->isHttpOnly());
        self::assertSame('strict', $vaultCookies[0]->getSameSite());
        self::assertSame(0, $vaultCookies[0]->getExpiresTime(), 'cookie de session');

        $stored = serialize($this->client->getRequest()->getSession()->all());
        self::assertStringNotContainsString((string) $vaultCookies[0]->getValue(), $stored, 'la clé d\'enveloppe n\'est pas en session');
    }

    public function testLosingTheCookieLocksTheDataUntilThePasswordIsGiven(): void
    {
        $id = self::createAccount(self::MARKER, 'livret_a');
        $this->logIn();
        $this->client->getCookieJar()->expire(Vault::COOKIE);

        foreach (['/', '/comptes', "/comptes/$id", '/saisie', '/securite'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/deverrouiller', Response::HTTP_SEE_OTHER, $path);
        }

        $this->submit('/deverrouiller', ['password' => 'mauvais mot de passe'], '/deverrouiller');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSelectorTextContains('.flash-error', 'Mot de passe incorrect.');

        self::clock()->sleep(2);
        $this->submit('/deverrouiller', ['password' => self::PASSWORD], '/deverrouiller');
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->client->request('GET', '/comptes');
        self::assertStringContainsString(self::MARKER, $this->body());
        self::assertSame(1, self::queryInt("SELECT COUNT(*) FROM auth_events WHERE type = 'vault_unlocked'"));
    }

    public function testACookieFromAnotherSessionDoesNotOpenTheVault(): void
    {
        $this->logIn();
        $this->client->getCookieJar()->expire(Vault::COOKIE);
        $this->client->getCookieJar()->set(new Cookie(Vault::COOKIE, sodium_bin2base64(str_repeat('x', 32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)));

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/deverrouiller', Response::HTTP_SEE_OTHER);
    }

    public function testLogoutErasesTheCookie(): void
    {
        $this->logIn();
        $this->submit('/deconnexion');

        self::assertNull($this->client->getCookieJar()->get(Vault::COOKIE));
    }

    public function testNewAccountMustNoteItsRecoveryKeyBeforeAnythingElse(): void
    {
        TestUsers::create(static::getContainer(), 'neuve@example.test', totp: false);
        $this->submitPassword('neuve@example.test');
        $this->client->request('GET', '/securite/application');
        $secret = str_replace(' ', '', $this->client->getCrawler()->filter('.secret-key code')->text());
        self::assertSame(1, preg_match(self::smokePattern('TOTP_SECRET_PATTERN'), $this->body(), $totp));
        self::assertSame($secret, str_replace(' ', '', html_entity_decode($totp[1])));
        self::assertNotSame('', $secret);
        $this->submit('/securite/application', ['code' => TOTP::create($secret, clock: self::clock())->now()], '/securite/application');
        self::assertSelectorTextContains('h1', 'Codes de secours');

        $this->client->request('GET', '/comptes');
        self::assertResponseRedirects('/securite/cle-de-recuperation', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        $groups = explode(' ', $this->client->getCrawler()->filter('.secret-key code')->text());
        self::assertCount(13, $groups);
        // Copy button of the key, hidden until app.js finds a clipboard it may write to.
        $copy = $this->client->getCrawler()->filter('button[data-copy="recovery-key"]');
        self::assertCount(1, $copy);
        self::assertNotNull($copy->attr('hidden'));
        self::assertCount(1, $this->client->getCrawler()->filter('code#recovery-key'));
        // The smoke test of the real deployment reads the key with its own pattern: it must still find it (2.26.0
        // broke it by adding the id of the copy button).
        self::assertSame(1, preg_match(self::smokePattern('RECOVERY_KEY_PATTERN'), $this->body(), $match));
        self::assertCount(13, explode(' ', trim($match[1])));

        $this->submit('/securite/cle-de-recuperation', ['confirmation' => 'AAAA AAAA'], '/securite/cle-de-recuperation');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(implode(' ', $groups), $this->client->getCrawler()->filter('.secret-key code')->text(), 'même clé tant qu\'elle n\'est pas confirmée');

        $this->submit('/securite/cle-de-recuperation', ['confirmation' => $groups[11] . ' ' . $groups[12]], '/securite/cle-de-recuperation');
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->client->request('GET', '/comptes');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/securite/cle-de-recuperation');
        self::assertResponseRedirects('/securite/connexion', Response::HTTP_SEE_OTHER, 'jamais réaffichée');
    }

    public function testRecoveryKeyCanBeRenewedAfterConfirmingTheIdentity(): void
    {
        $this->logIn();
        $this->submit('/securite/cle-de-recuperation/nouvelle', [], '/securite');
        self::assertResponseRedirects('/securite/connexion', Response::HTTP_SEE_OTHER, 'confirmation d\'identité exigée');

        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => '/securite/connexion'], '/securite');
        $this->submit('/securite/cle-de-recuperation/nouvelle', [], '/securite');
        self::assertResponseRedirects('/securite/cle-de-recuperation', Response::HTTP_SEE_OTHER);
        $before = self::service(Connection::class)->fetchOne("SELECT wrapped_key FROM key_envelopes WHERE type = 'recovery' AND user_id = ?", [self::owner()->value]);
        $this->client->followRedirect();
        $groups = explode(' ', $this->client->getCrawler()->filter('.secret-key code')->text());

        $this->submit('/securite/cle-de-recuperation', ['confirmation' => $groups[11] . $groups[12]], '/securite/cle-de-recuperation');

        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        self::assertEmailCount(1);
        self::assertNotSame($before, self::service(Connection::class)->fetchOne("SELECT wrapped_key FROM key_envelopes WHERE type = 'recovery' AND user_id = ?", [self::owner()->value]));
    }

    public function testPasswordChangeOnlyReplacesTheEnvelope(): void
    {
        $id = self::createAccount(self::MARKER, 'livret_a');
        $this->logIn();
        $before = self::service(Connection::class)->fetchOne('SELECT ciphertext FROM accounts WHERE id = ?', [$id]);
        $new = 'une toute nouvelle phrase de passe';

        $this->submit('/securite/mot-de-passe', ['current_password' => self::PASSWORD, 'new_password' => $new, 'new_password_confirmation' => $new], '/securite');
        $this->client->request('GET', '/comptes');
        self::assertStringContainsString(self::MARKER, $this->body(), 'cette session reste déverrouillée');
        $this->submit('/deconnexion');

        self::clock()->sleep(60);
        $this->logIn(self::EMAIL, $new);
        $this->client->request('GET', '/comptes');
        self::assertStringContainsString(self::MARKER, $this->body());
        self::assertSame($before, self::service(Connection::class)->fetchOne('SELECT ciphertext FROM accounts WHERE id = ?', [$id]), 'données non rechiffrées');
    }

    public function testACiphertextMovedToAnotherRowCannotBeRead(): void
    {
        $owner = self::owner();
        $first = self::createAccount('Premier', 'livret_a');
        $second = self::createAccount('Second', 'livret_a');
        self::service(StatementRepository::class)->save($owner, $first, Month::fromString('2026-10'), 100);
        $connection = self::service(Connection::class);
        $row = $connection->fetchAssociative('SELECT ciphertext FROM accounts WHERE id = ?', [$first]);
        self::assertIsArray($row);
        $connection->executeStatement('UPDATE accounts SET ciphertext = ? WHERE id = ?', [Row::string($row, 'ciphertext'), $second]);

        $this->expectException(DecryptionFailed::class);
        self::service(AccountRepository::class)->find($owner, $second);
    }

    private function typeId(string $code): string
    {
        return (string) self::queryInt('SELECT id FROM account_types WHERE code = ?', [$code]);
    }

    /** Pattern of tests/deployment/smoke.php (a script, not loaded here: read from its source). */
    private static function smokePattern(string $name): string
    {
        $source = (string) file_get_contents(__DIR__ . '/../deployment/smoke.php');
        self::assertSame(1, preg_match("/^const $name = '(.+)';$/m", $source, $match), $name);

        return $match[1];
    }
}
