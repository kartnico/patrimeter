<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use OTPHP\TOTP;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/** Mandatory setup of the authenticator app, then setting it up again. */
final class TwoFactorSetupTest extends FunctionalTestCase
{
    private const string EMAIL_WITHOUT_TOTP = 'sans-totp@example.test';

    public function testFirstSetupShowsAQrCodeAndAStableSecret(): void
    {
        $this->logInWithoutTotp();
        $crawler = $this->client->request('GET', '/securite/application');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('svg.qr-code[role="img"] path.qr-code-modules'));
        $secret = $this->secretShown();
        self::assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/', $secret, '160 bits en base32');
        self::assertSelectorNotExists('nav.nav', 'pas de navigation avant la configuration');

        $this->client->request('GET', '/securite/application');
        self::assertSame($secret, $this->secretShown(), 'même secret tant qu\'il n\'est pas validé');
    }

    public function testWrongCodeLeavesTotpDisabled(): void
    {
        $this->logInWithoutTotp();
        $this->client->request('GET', '/securite/application');
        $this->submit('/securite/application', ['code' => '000000'], '/securite/application');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('.flash-error', 'Code incorrect');
        self::assertNull(self::service(Connection::class)->fetchOne('SELECT totp_enabled_at FROM users WHERE email = ?', [self::EMAIL_WITHOUT_TOTP]));
    }

    public function testValidCodeEnablesTotpAndShowsTheBackupCodesOnce(): void
    {
        $this->logInWithoutTotp();
        $this->client->request('GET', '/securite/application');
        $secret = $this->secretShown();

        $crawler = $this->submitSetupCode($secret);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Codes de secours');
        $codes = $crawler->filter('ul.backup-codes code')->each(static fn($node): string => $node->text());
        self::assertCount(10, $codes);
        self::assertMatchesRegularExpression('/\A[2-9A-HJKMNP-Z]{5}-[2-9A-HJKMNP-Z]{5}\z/', $codes[0]);
        self::assertCount(10, array_unique($codes));
        // Copy button of the codes, hidden until app.js finds a clipboard it may write to.
        self::assertSame('backup-codes', $crawler->filter('button[data-copy]')->attr('data-copy'));
        self::assertCount(1, $crawler->filter('ul#backup-codes'));

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/securite/cle-de-recuperation', Response::HTTP_SEE_OTHER, 'étape suivante : la clé de récupération');
        $stored = self::service(Connection::class)->fetchAssociative('SELECT totp_secret, totp_enabled_at FROM users WHERE email = ?', [self::EMAIL_WITHOUT_TOTP]);
        self::assertIsArray($stored);
        self::assertNotNull($stored['totp_enabled_at']);
        self::assertIsString($stored['totp_secret']);
        self::assertStringNotContainsString($secret, $stored['totp_secret'], 'secret chiffré en base');
        self::assertSame(10, self::queryInt('SELECT COUNT(*) FROM backup_codes WHERE used_at IS NULL AND user_id = (SELECT id FROM users WHERE email = ?)', [self::EMAIL_WITHOUT_TOTP]));

        // Next sign-in: the code is now asked for.
        $this->submit('/deconnexion', [], '/securite/cle-de-recuperation');
        self::clock()->sleep(60);
        $this->submitPassword(self::EMAIL_WITHOUT_TOTP);
        $this->submitCode(TOTP::create($secret, clock: self::clock())->now());
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
    }

    public function testReconfigurationNeedsARecentIdentityConfirmation(): void
    {
        $this->logIn();

        $this->client->request('GET', '/securite/application');
        self::assertResponseRedirects('/securite/connexion', Response::HTTP_SEE_OTHER);
        $this->submit('/securite/application', ['code' => TestUsers::totpCode(static::getContainer())]);
        self::assertResponseRedirects('/securite/connexion', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Confirmez d\'abord votre identité', $this->flashes());
    }

    public function testReconfigurationReplacesTheSecretAndKeepsTheBackupCodes(): void
    {
        $this->logIn();
        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => '/securite/connexion'], '/securite');
        $this->client->request('GET', '/securite/application');
        self::assertResponseIsSuccessful();
        $newSecret = $this->secretShown();
        self::assertNotSame(TestUsers::TOTP_SECRET, $newSecret);

        $this->submitSetupCode($newSecret);
        self::assertResponseRedirects('/securite/connexion', Response::HTTP_SEE_OTHER);
        self::assertEmailCount(1);
        self::assertEmailTextBodyContains(self::getMailerMessage() ?? self::fail('e-mail attendu'), 'nouvelle application');
        $this->client->followRedirect();
        self::assertStringContainsString('Nouvelle application d\'authentification enregistrée', $this->flashes());
        self::assertSame(10, self::queryInt('SELECT COUNT(*) FROM backup_codes WHERE user_id = ?', [self::owner()->value]));

        $this->submit('/deconnexion');
        self::clock()->sleep(60);
        $this->submitPassword();
        $this->submitCode(TestUsers::totpCode(static::getContainer()));
        self::assertResponseRedirects('/connexion/code', Response::HTTP_SEE_OTHER, 'l\'ancienne application ne fonctionne plus');
    }

    private function logInWithoutTotp(): void
    {
        TestUsers::create(static::getContainer(), self::EMAIL_WITHOUT_TOTP, totp: false);
        $this->submitPassword(self::EMAIL_WITHOUT_TOTP);
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertResponseRedirects('/securite/application', Response::HTTP_SEE_OTHER);
    }

    /** @return non-empty-string */
    private function secretShown(): string
    {
        $secret = str_replace(' ', '', $this->client->getCrawler()->filter('.secret-key code')->text());
        if ($secret === '') {
            self::fail('Aucun secret affiché');
        }

        return $secret;
    }

    /** @param non-empty-string $secret */
    private function submitSetupCode(string $secret): Crawler
    {
        $code = TOTP::create($secret, clock: self::clock())->now();

        return $this->client->request('POST', '/securite/application', ['_csrf' => $this->csrfToken('/securite/application'), 'code' => $code]);
    }
}
