<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Patrimeter\Tests\Support\VirtualAuthenticator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

/** Defects found by the review of 09/11/2026, reproduced through real requests. */
final class ReviewFindingsTest extends FunctionalTestCase
{
    private const string PRF = 'sortie-prf-de-32-octets-exactement!!';
    private const string REGISTRATION_PASSWORD = 'une phrase de passe assez longue';

    /** Without debug: an error gives a real 500 page, not an exception in the test. */
    #[\Override]
    protected static function clientOptions(): array
    {
        return ['debug' => false];
    }

    public function testASecondFactorChallengeCannotServeAPasskeyLoginWithoutUserVerification(): void
    {
        $this->logIn();
        $passkey = $this->addPasskey('');
        $this->submit('/deconnexion');

        // The attacker, on their own account, at the code step, with the stolen key without its PIN.
        TestUsers::create(static::getContainer(), TestUsers::OTHER_EMAIL);
        $this->submitPassword(TestUsers::OTHER_EMAIL);
        $token = $this->csrfToken('/connexion/code');
        $this->client->request('POST', '/connexion/code/cle/options', ['_csrf' => $token]);
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/connexion/passkey', ['_csrf' => $token, 'credential' => $passkey->authenticate($this->options(), userVerified: false)]);

        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM auth_events WHERE type = ? AND user_id = ?', ['login', self::owner()->value]) - 1);
    }

    public function testKeyRotationLeavesNoEnvelopeOfTheOldKey(): void
    {
        $marker = 'Livret-Secret-5e2c';
        self::createAccount($marker, 'livret_a');
        $this->logIn();
        $passkey = $this->addPasskey(VirtualAuthenticator::b64(self::PRF));
        $this->submit('/deconnexion');

        $rotation = new CommandTester((new Application(static::getContainer()->get('kernel')))->find('app:user:rotate-key'));
        $rotation->setInputs([self::PASSWORD]);
        self::assertSame(0, $rotation->execute(['email' => self::EMAIL], ['interactive' => false]), $rotation->getDisplay());
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM key_envelopes'), 'seule celle du mot de passe reste');

        self::clock()->sleep(60);
        $this->client->request('POST', '/connexion/passkey/options', ['_csrf' => $this->csrfToken('/connexion')]);
        $options = $this->options();
        $this->client->request('POST', '/connexion/passkey', ['_csrf' => $this->csrfToken('/connexion'), 'credential' => $passkey->authenticate($options), 'prf_output' => VirtualAuthenticator::b64(self::PRF)]);
        $this->client->request('GET', '/comptes');
        self::assertResponseRedirects('/deverrouiller', Response::HTTP_SEE_OTHER, 'la passkey ne déverrouille plus, sans erreur 500');

        $this->submit('/deverrouiller', ['password' => self::PASSWORD], '/deverrouiller');
        $this->client->request('GET', '/securite/cle-de-recuperation');
        self::assertResponseIsSuccessful();
        $key = preg_split('/[\s-]+/', trim($this->client->getCrawler()->filter('.secret-key code')->text()));
        self::assertIsArray($key);
        $this->submit('/securite/cle-de-recuperation', ['confirmation' => implode(' ', array_slice($key, -2))], '/securite/cle-de-recuperation');
        $this->client->request('GET', '/comptes');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($marker, $this->body());
    }

    public function testPasswordResetRefusesACodeAlreadyUsedToLogIn(): void
    {
        $this->logIn();
        $code = TestUsers::totpCode(static::getContainer());
        $this->submit('/deconnexion');

        $link = $this->mailedLink('/mot-de-passe-oublie', ['email' => self::EMAIL], '~https?://\S+/mot-de-passe-oublie/\S+~');
        $password = 'une toute nouvelle phrase de passe';
        $this->submit($link, ['password' => $password, 'password_confirmation' => $password, 'recovery_key' => '', 'code' => $code, 'accept_data_loss' => '1'], $link);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('Code incorrect', $this->body());
    }

    public function testMonthlyEntryLeavesAnAccountMissingFromTheFormUntouched(): void
    {
        $this->logIn();
        $shown = self::createAccount('Affiché', 'livret_a');
        $added = self::createAccount('Créé depuis', 'ldds');
        self::service(StatementRepository::class)->save(self::owner(), $added, Month::fromString('2026-11'), 123_400);

        $this->submit('/saisie', ['mois' => '2026-11', 'amounts' => [$shown => '100']]);

        self::assertResponseRedirects();
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM statements WHERE account_id = ?', [$added]));

        $this->submit('/saisie', ['mois' => '2026-11', 'amounts' => [$added => '']]);
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM statements WHERE account_id = ?', [$added]), 'un champ vidé supprime toujours le relevé');
    }

    public function testAPropertyWithAShareCannotStopBeingAProperty(): void
    {
        $this->logIn();
        $house = self::createAccount('Maison', 'lmnp');
        self::service(OwnershipShareRepository::class)->save(self::owner(), $house, Month::fromString('2025-01'), 500_000);

        $this->submit("/comptes/$house", ['name' => 'Maison', 'type_id' => $this->typeOf($house), 'level_code' => 'exotique', 'nature' => 'asset', 'opened_on' => '2025-01-01'], "/comptes/$house");
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('retirez-les d&#039;abord', $this->body());

        $this->submit("/comptes/$house/parts/2025-01/supprimer", [], "/comptes/$house");
        $this->submit("/comptes/$house", ['name' => 'Maison', 'type_id' => $this->typeOf($house), 'level_code' => 'exotique', 'nature' => 'asset', 'opened_on' => '2025-01-01'], "/comptes/$house");
        self::assertResponseRedirects();
    }

    public function testALinkedLoanCannotBecomeAnAsset(): void
    {
        $this->logIn();
        $house = self::createAccount('Maison', 'lmnp');
        $loan = self::createAccount('Prêt', 'credit_immo_rp');
        $this->submit("/comptes/$loan/bien", ['asset_id' => $house, 'loan_mode' => 'prorata'], "/comptes/$loan");

        $this->submit("/comptes/$loan", ['name' => 'Prêt', 'type_id' => $this->typeOf($loan), 'level_code' => 'immobilier', 'nature' => 'asset', 'opened_on' => '2025-01-01'], "/comptes/$loan");

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('détachez-le', $this->body());
    }

    public function testPropertyPageFollowsTheSameMonthRuleAsTheDashboard(): void
    {
        $this->logIn();
        $house = self::createAccount('Maison à vendre', 'lmnp');
        $loan = self::createAccount('Prêt en cours', 'credit_immo_rp');
        $this->submit("/comptes/$loan/bien", ['asset_id' => $house, 'loan_mode' => 'prorata'], "/comptes/$loan");
        self::service(StatementRepository::class)->save(self::owner(), $loan, Month::fromString('2026-11'), 5_000_000);
        // Closed next month: they still count in November.
        $this->submit("/comptes/$house/archiver", ['closed_on' => '2026-12-15'], "/comptes/$house");
        $this->submit("/comptes/$loan/archiver", ['closed_on' => '2026-12-15'], "/comptes/$loan");

        $this->client->request('GET', '/biens');

        self::assertStringContainsString('Maison à vendre', $this->body());
        self::assertStringContainsString('Prêt en cours', $this->body());
    }

    public function testARegistrationOverTheEmailQuotaKeepsTheLastLinkValid(): void
    {
        $email = 'nouvelle@example.test';
        $sent = [];
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $sent[] = $this->registrationMail($email);
        }
        self::assertCount(3, array_filter($sent, static fn(?string $link): bool => $link !== null), 'trois e-mails par heure, le premier compris');

        $link = $sent[2];
        self::assertIsString($link);
        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Mot de passe choisi', $this->body());
    }

    public function testFirstRepresentableMonthDoesNotBreakTheMonthPicker(): void
    {
        // With an account: without any, the pages stay on the current month.
        self::createAccount('Livret A', 'livret_a', '2020-01-01');
        $this->logIn();
        foreach (['/?mois=1900-01', '/?mois=1900-03', '/saisie?mois=1900-03', '/?mois=1900-03&periode=tout&vue=niveaux'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }
    }

    public function testAnAddressRefusedByTheMailerIsRefusedAtRegistration(): void
    {
        $this->submit('/inscription', ['email' => 'a..b@example.com', 'password' => self::REGISTRATION_PASSWORD, 'password_confirmation' => self::REGISTRATION_PASSWORD], '/inscription');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(0, self::queryInt("SELECT COUNT(*) FROM users WHERE email = 'a..b@example.com'"));
    }

    public function testInvalidTextInAPropertyOrPasskeyNameIsNotAServerError(): void
    {
        $this->logIn();
        $type = $this->client->request('GET', '/biens/nouveau')->filter('select#type_id option')->last()->attr('value');

        $this->submit('/biens/nouveau', ['name' => "\xFF", 'type_id' => $type, 'opened_on' => '2025-01-01', 'value' => '1000', 'share' => '100'], '/biens/nouveau');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $loan = ['name' => "\xFF", 'amount' => '10', 'mode' => 'own'];
        $this->submit('/biens/nouveau', ['name' => 'Maison', 'type_id' => $type, 'opened_on' => '2025-01-01', 'value' => '1000', 'share' => '100', 'loans' => [$loan]], '/biens/nouveau');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->addPasskey('', "\xFF");
        $this->client->request('GET', '/securite');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Passkey', $this->body());
    }

    private function addPasskey(string $prf, string $label = 'Appareil de test'): VirtualAuthenticator
    {
        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => '/securite/connexion'], '/securite');
        $passkey = new VirtualAuthenticator();
        $this->client->request('POST', '/securite/passkeys/options', ['_csrf' => $this->csrfToken('/securite'), 'usage' => 'passkey']);
        $this->submit('/securite/passkeys', ['credential' => $passkey->register($this->options()), 'label' => $label, 'usage' => 'passkey', 'prf_output' => $prf], '/securite');
        self::assertResponseRedirects('/securite/connexion', Response::HTTP_SEE_OTHER);

        return $passkey;
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        $decoded = json_decode($this->body(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['options'] ?? null);

        /** @var array<string, mixed> */
        return $decoded['options'];
    }

    private function typeOf(int $account): string
    {
        $type = $this->client->request('GET', "/comptes/$account")->filter('select#type_id option[selected]')->attr('value');
        self::assertIsString($type);

        return $type;
    }

    /**
     * @param array<string, string> $fields
     * @param non-empty-string      $pattern
     */
    private function mailedLink(string $path, array $fields, string $pattern): string
    {
        $this->submit($path, $fields, $path);
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email && preg_match($pattern, (string) $message->getTextBody(), $match) === 1) {
                return $match[0];
            }
        }
        self::fail("Aucun e-mail reçu après $path.");
    }

    /** Link received after a registration, or null if no email left. */
    private function registrationMail(string $email): ?string
    {
        $this->submit('/inscription', ['email' => $email, 'password' => self::REGISTRATION_PASSWORD, 'password_confirmation' => self::REGISTRATION_PASSWORD], '/inscription');
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email && preg_match('~https?://\S+/inscription/confirmation/\S+~', (string) $message->getTextBody(), $match) === 1) {
                return $match[0];
            }
        }

        return null;
    }
}
