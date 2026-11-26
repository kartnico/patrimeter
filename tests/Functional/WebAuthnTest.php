<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Patrimeter\Tests\Support\VirtualAuthenticator;
use Symfony\Component\HttpFoundation\Response;

/** Passkeys (FIDO2) and security keys, with a software authenticator and real checks. */
final class WebAuthnTest extends FunctionalTestCase
{
    private const string MARKER = 'Livret-Secret-5e2c';
    private const string PRF = 'sortie-prf-de-32-octets-exactement!!';

    public function testPasskeyLoginWithPrfOpensTheVaultWithoutSecondFactor(): void
    {
        self::createAccount(self::MARKER, 'livret_a');
        $this->logIn();
        $passkey = $this->addCredential('passkey', self::PRF);
        self::assertSame(1, self::queryInt("SELECT COUNT(*) FROM key_envelopes WHERE type = 'passkey'"));
        $this->submit('/deconnexion');

        $this->loginWithPasskey($passkey, self::PRF);
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->client->request('GET', '/comptes');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::MARKER, $this->body());
        self::assertSame(2, self::queryInt("SELECT COUNT(*) FROM auth_events WHERE type = 'login'"));
    }

    public function testPasskeyWithoutPrfRequiresThePasswordToUnlock(): void
    {
        self::createAccount(self::MARKER, 'livret_a');
        $this->logIn();
        $passkey = $this->addCredential('passkey', null);
        $this->submit('/deconnexion');

        $this->loginWithPasskey($passkey, null);
        $this->client->request('GET', '/comptes');
        self::assertResponseRedirects('/deverrouiller', Response::HTTP_SEE_OTHER);

        $this->submit('/deverrouiller', ['password' => self::PASSWORD], '/deverrouiller');
        $this->client->request('GET', '/comptes');
        self::assertStringContainsString(self::MARKER, $this->body());
    }

    public function testWrongPrfOutputLeavesTheVaultLocked(): void
    {
        $this->logIn();
        $passkey = $this->addCredential('passkey', self::PRF);
        $this->submit('/deconnexion');

        $this->loginWithPasskey($passkey, 'une-autre-sortie-prf-de-32-octets-ok');
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/deverrouiller', Response::HTTP_SEE_OTHER);
    }

    public function testPasskeyLoginRequiresUserVerificationAndAKnownCredential(): void
    {
        $this->logIn();
        $passkey = $this->addCredential('passkey', null);
        $this->submit('/deconnexion');

        $options = $this->loginOptions();
        $this->client->request('POST', '/connexion/passkey', ['_csrf' => $this->csrfToken('/connexion'), 'credential' => $passkey->authenticate($options, userVerified: false)]);
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Connexion par passkey (FIDO2) refusée.', $this->flashes());

        self::clock()->sleep(2);
        $options = $this->loginOptions();
        $this->client->request('POST', '/connexion/passkey', ['_csrf' => $this->csrfToken('/connexion'), 'credential' => (new VirtualAuthenticator())->authenticate($options)]);
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER, 'authentificateur inconnu');
    }

    public function testAChallengeServesOnlyOnce(): void
    {
        $this->logIn();
        $passkey = $this->addCredential('passkey', null);
        $this->submit('/deconnexion');
        $options = $this->loginOptions();
        $response = $passkey->authenticate($options);
        $token = $this->csrfToken('/connexion');
        $this->client->request('POST', '/connexion/passkey', ['_csrf' => $token, 'credential' => $response]);
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->submit('/deconnexion', [], '/deverrouiller');

        self::clock()->sleep(2);
        $this->client->request('POST', '/connexion/passkey', ['_csrf' => $this->csrfToken('/connexion'), 'credential' => $response]);
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
    }

    public function testSecurityKeyServesAsSecondFactorButNotAsPasswordlessLogin(): void
    {
        $this->logIn();
        $key = $this->addCredential('security_key', null);
        $this->submit('/deconnexion');

        $options = $this->loginOptions();
        $this->client->request('POST', '/connexion/passkey', ['_csrf' => $this->csrfToken('/connexion'), 'credential' => $key->authenticate($options)]);
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER, 'pas de connexion sans mot de passe');

        self::clock()->sleep(2);
        $this->submitPassword();
        $crawler = $this->client->request('GET', '/connexion/code');
        self::assertCount(1, $crawler->filter('form[data-webauthn="authenticate"]'), 'clé proposée');
        $token = $crawler->filter('input[name="_csrf"]')->first()->attr('value');
        $this->client->request('POST', '/connexion/code/cle/options', ['_csrf' => $token]);
        $options = $this->optionsFromResponse();
        self::assertIsArray($options['allowCredentials'] ?? null);
        self::assertCount(1, $options['allowCredentials']);

        $this->client->request('POST', '/connexion/code', ['_csrf' => $token, 'code' => $key->authenticate($options, userVerified: false)]);
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    /** Bug: a FIDO2 key without a resident credential returns no userHandle, and was refused. */
    public function testSecurityKeyWithoutUserHandleServesAsSecondFactor(): void
    {
        $this->logIn();
        $key = $this->addCredential('security_key', null);
        $this->submit('/deconnexion');

        self::clock()->sleep(2);
        $this->submitPassword();
        $token = $this->client->request('GET', '/connexion/code')->filter('input[name="_csrf"]')->first()->attr('value');
        $this->client->request('POST', '/connexion/code/cle/options', ['_csrf' => $token]);

        $this->client->request('POST', '/connexion/code', ['_csrf' => $token, 'code' => $key->authenticate($this->optionsFromResponse(), userVerified: false, withUserHandle: false)]);
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
    }

    public function testAPasskeyWithoutUserHandleIsRefusedForPasswordlessLogin(): void
    {
        $this->logIn();
        $key = $this->addCredential('passkey', null);
        $this->submit('/deconnexion');

        $options = $this->loginOptions();
        $this->client->request('POST', '/connexion/passkey', ['_csrf' => $this->csrfToken('/connexion'), 'credential' => $key->authenticate($options, withUserHandle: false)]);
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
    }

    public function testAnotherUsersKeyIsRefusedAsSecondFactor(): void
    {
        $this->logIn();
        $key = $this->addCredential('security_key', null);
        $this->submit('/deconnexion');
        TestUsers::create(static::getContainer(), TestUsers::OTHER_EMAIL);

        $this->submitPassword(TestUsers::OTHER_EMAIL);
        $crawler = $this->client->request('GET', '/connexion/code');
        self::assertCount(0, $crawler->filter('form[data-webauthn]'), 'aucune clé sur ce compte');
        $token = $crawler->filter('input[name="_csrf"]')->first()->attr('value');
        $this->client->request('POST', '/connexion/code/cle/options', ['_csrf' => $token]);
        $this->client->request('POST', '/connexion/code', ['_csrf' => $token, 'code' => $key->authenticate($this->optionsFromResponse())]);

        self::assertResponseRedirects('/connexion/code', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Code incorrect.');
    }

    public function testManagingCredentialsRequiresARecentIdentityConfirmation(): void
    {
        $this->logIn();
        $this->client->request('POST', '/securite/passkeys/options', ['_csrf' => $this->csrfToken('/securite'), 'usage' => 'passkey']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $passkey = $this->addCredential('passkey', self::PRF);
        self::clock()->sleep(5 * 60 + 1);
        $id = $passkey->encodedId();
        $this->submit("/securite/passkeys/$id/supprimer", [], '/securite');
        self::assertResponseRedirects('/securite/connexion', Response::HTTP_SEE_OTHER);
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM webauthn_credentials'));

        $this->confirmIdentity();
        $this->submit("/securite/passkeys/$id/renommer", ['label' => 'Mon téléphone'], '/securite');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.credential-list', 'Mon téléphone');

        $this->submit("/securite/passkeys/$id/supprimer", [], '/securite');
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM webauthn_credentials'));
        self::assertSame(0, self::queryInt("SELECT COUNT(*) FROM key_envelopes WHERE type = 'passkey'"), 'enveloppe retirée avec l\'appareil');
        self::assertEmailCount(1);
    }

    public function testAnotherUsersCredentialIsUnreachable(): void
    {
        $this->logIn();
        $passkey = $this->addCredential('passkey', null);
        $id = $passkey->encodedId();
        $this->submit('/deconnexion');
        TestUsers::create(static::getContainer(), TestUsers::OTHER_EMAIL);
        self::clock()->sleep(60);
        $this->logIn(TestUsers::OTHER_EMAIL);
        $this->confirmIdentity();

        $this->submit("/securite/passkeys/$id/supprimer", [], '/securite');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->submit("/securite/passkeys/$id/renommer", ['label' => 'Piraté'], '/securite');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM webauthn_credentials'));
    }

    public function testInvalidRegistrationResponseIsRefused(): void
    {
        $this->logIn();
        $this->confirmIdentity();
        $this->client->request('POST', '/securite/passkeys/options', ['_csrf' => $this->csrfToken('/securite'), 'usage' => 'passkey']);
        $this->submit('/securite/passkeys', ['credential' => '{"pas":"valide"}', 'label' => 'x', 'usage' => 'passkey'], '/securite');
        $this->client->followRedirect();

        self::assertStringContainsString('n\'a pas pu être enregistré', $this->flashes());
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM webauthn_credentials'));
    }

    private function addCredential(string $usage, ?string $prf): VirtualAuthenticator
    {
        $this->confirmIdentity();
        $authenticator = new VirtualAuthenticator();
        $this->client->request('POST', '/securite/passkeys/options', ['_csrf' => $this->csrfToken('/securite'), 'usage' => $usage]);
        self::assertResponseIsSuccessful();
        $this->submit('/securite/passkeys', [
            'credential' => $authenticator->register($this->optionsFromResponse()),
            'label' => 'Appareil de test',
            'usage' => $usage,
            'prf_output' => $prf === null ? '' : VirtualAuthenticator::b64($prf),
        ], '/securite');
        self::assertResponseRedirects('/securite/connexion', Response::HTTP_SEE_OTHER);
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM webauthn_credentials WHERE credential_id = ?', [$authenticator->encodedId()]), 'appareil enregistré');

        return $authenticator;
    }

    private function confirmIdentity(): void
    {
        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => '/securite/connexion'], '/securite');
    }

    /** @return array<string, mixed> */
    private function loginOptions(): array
    {
        $this->client->request('POST', '/connexion/passkey/options', ['_csrf' => $this->csrfToken('/connexion')]);
        self::assertResponseIsSuccessful();

        return $this->optionsFromResponse();
    }

    /** @return array<string, mixed> */
    private function optionsFromResponse(): array
    {
        $decoded = json_decode($this->body(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['options'] ?? null);

        /** @var array<string, mixed> */
        return $decoded['options'];
    }

    private function loginWithPasskey(VirtualAuthenticator $passkey, ?string $prf): void
    {
        $options = $this->loginOptions();
        $this->client->request('POST', '/connexion/passkey', [
            '_csrf' => $this->csrfToken('/connexion'),
            'credential' => $passkey->authenticate($options),
            'prf_output' => $prf === null ? '' : VirtualAuthenticator::b64($prf),
        ]);
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
    }
}
