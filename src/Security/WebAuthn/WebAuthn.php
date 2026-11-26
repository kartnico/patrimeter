<?php

declare(strict_types=1);

namespace Patrimeter\Security\WebAuthn;

use Patrimeter\Application\Calendar;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\WebAuthnCredentialRepository;
use Patrimeter\Security\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * WebAuthn ceremonies (web-auth/webauthn-lib library): options sent to the browser, challenge kept in the
 * session and single-use, check of the answers.
 */
final class WebAuthn
{
    public const string PASSKEY = 'passkey';
    public const string SECURITY_KEY = 'security_key';

    private const int CHALLENGE_BYTES = 32;
    private const int TIMEOUT_MS = 120000;
    private const int ES256 = -7;
    private const int RS256 = -257;
    private const string SESSION_CREATION = '_patrimeter_webauthn_creation';
    // One challenge per ceremony: the second factor's, without user verification, must never serve for a
    // passkey sign-in, which requires it.
    private const string SESSION_PASSKEY_REQUEST = '_patrimeter_webauthn_passkey_request';
    private const string SESSION_SECOND_FACTOR_REQUEST = '_patrimeter_webauthn_second_factor_request';
    private const array JSON_CONTEXT = [AbstractObjectNormalizer::SKIP_NULL_VALUES => true, JsonEncode::OPTIONS => JSON_THROW_ON_ERROR];

    private readonly SerializerInterface $serializer;
    private readonly string $host;
    private readonly string $origin;

    public function __construct(
        private readonly WebAuthnCredentialRepository $credentials,
        private readonly Calendar $calendar,
        #[Autowire('%env(DEFAULT_URI)%')]
        string $publicUri,
    ) {
        $this->serializer = (new WebauthnSerializerFactory(AttestationStatementSupportManager::create()))->create();
        $host = parse_url($publicUri, PHP_URL_HOST);
        $this->host = is_string($host) ? $host : 'localhost';
        // Only the configured public address is accepted as the origin of the ceremonies.
        $port = parse_url($publicUri, PHP_URL_PORT);
        $scheme = parse_url($publicUri, PHP_URL_SCHEME);
        $this->origin = (is_string($scheme) ? $scheme : 'https') . '://' . $this->host . (is_int($port) ? ':' . $port : '');
    }

    /** JSON options to create a credential for this user. */
    public function creationOptions(Request $request, User $user, string $usage): string
    {
        $passkey = $usage === self::PASSKEY;
        $existing = array_map(
            static fn(array $c): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, self::decodeId($c['credential_id'])),
            $this->credentials->all($user->id),
        );
        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create('', $this->host),
            PublicKeyCredentialUserEntity::create((string) $user->email, $user->id->value, (string) $user->email),
            random_bytes(self::CHALLENGE_BYTES),
            [PublicKeyCredentialParameters::createPk(self::ES256), PublicKeyCredentialParameters::createPk(self::RS256)],
            AuthenticatorSelectionCriteria::create(
                null,
                $passkey ? AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED : AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_DISCOURAGED,
                $passkey ? AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED : AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_DISCOURAGED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $existing,
            self::TIMEOUT_MS,
        );
        $json = $this->serializer->serialize($options, 'json', self::JSON_CONTEXT);
        // The name is imposed on the browser by the standard, but deprecated when reading the options back.
        $request->getSession()->set(self::SESSION_CREATION, preg_replace('/"rp":\{"id":"([^"]*)","name":"[^"]*"/', '"rp":{"id":"$1"', $json, 1));

        return $json;
    }

    /**
     * Checks the creation answer and saves the credential.
     *
     * @return string|null saved credential (base64url), null if the answer is refused
     */
    public function register(Request $request, User $user, string $json, string $usage, string $label): ?string
    {
        $stored = $this->takeFromSession($request, self::SESSION_CREATION);
        if ($stored === null) {
            return null;
        }
        try {
            $credential = $this->serializer->deserialize($json, PublicKeyCredential::class, 'json');
            $options = $this->serializer->deserialize($stored, PublicKeyCredentialCreationOptions::class, 'json');
            if (!$credential->response instanceof AuthenticatorAttestationResponse) {
                return null;
            }
            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonies()->creationCeremony())
                ->check($credential->response, $options, $this->host);
        } catch (\Throwable) {
            // Answer from the browser, hence untrusted: any failure means refusal.
            return null;
        }
        $id = self::encodeId($record->publicKeyCredentialId);
        if ($this->credentials->findById($id) !== null) {
            return null;
        }
        $this->credentials->add($user->id, $id, $usage, $label, $this->serializer->serialize($record, 'json', self::JSON_CONTEXT), $this->calendar->timestamp());

        return $id;
    }

    /**
     * JSON authentication options. Without a user: passkey (discoverable, user verification required);
     * with one: second factor, among their credentials.
     */
    public function requestOptions(Request $request, ?User $user): string
    {
        $allowed = $user === null ? [] : array_map(
            static fn(array $c): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, self::decodeId($c['credential_id'])),
            $this->credentials->all($user->id),
        );
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(self::CHALLENGE_BYTES),
            $this->host,
            $allowed,
            $user === null ? PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED : PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_DISCOURAGED,
            self::TIMEOUT_MS,
        );
        $json = $this->serializer->serialize($options, 'json', self::JSON_CONTEXT);
        $request->getSession()->set($user === null ? self::SESSION_PASSKEY_REQUEST : self::SESSION_SECOND_FACTOR_REQUEST, $json);

        return $json;
    }

    /**
     * Checks an authentication answer. With $expected, the credential must belong to it; without, it must
     * be a passkey.
     *
     * @return array{user: UserId, credential_id: string}|null
     */
    public function verify(Request $request, string $json, ?UserId $expected): ?array
    {
        $stored = $this->takeFromSession($request, $expected === null ? self::SESSION_PASSKEY_REQUEST : self::SESSION_SECOND_FACTOR_REQUEST);
        if ($stored === null) {
            return null;
        }
        try {
            $credential = $this->serializer->deserialize($json, PublicKeyCredential::class, 'json');
            $options = $this->serializer->deserialize($stored, PublicKeyCredentialRequestOptions::class, 'json');
            if (!$credential->response instanceof AuthenticatorAssertionResponse) {
                return null;
            }
            $id = self::encodeId($credential->rawId);
            $row = $this->credentials->findById($id);
            if ($row === null || ($expected !== null && !$row['user_id']->equals($expected)) || ($expected === null && $row['usage'] !== self::PASSKEY)) {
                return null;
            }
            $record = $this->serializer->deserialize($row['record'], CredentialRecord::class, 'json');
            $updated = AuthenticatorAssertionResponseValidator::create($this->ceremonies()->requestCeremony())
                // User already identified (second factor): their identifier is expected, and a key without a
                // resident credential returns none. Otherwise, the answer must provide it.
                ->check($record, $credential->response, $options, $this->host, $expected === null ? null : $record->userHandle);
        } catch (\Throwable) {
            // Answer from the browser, hence untrusted: any failure means refusal.
            return null;
        }
        $this->credentials->recordUse($row['user_id'], $id, $this->serializer->serialize($updated, 'json', self::JSON_CONTEXT), $this->calendar->timestamp());

        return ['user' => $row['user_id'], 'credential_id' => $id];
    }

    private function ceremonies(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$this->origin]);

        return $factory;
    }

    private function takeFromSession(Request $request, string $key): ?string
    {
        $session = $request->getSession();
        $stored = self::text($session->get($key));
        $session->remove($key);

        return $stored;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    public static function encodeId(string $binary): string
    {
        return sodium_bin2base64($binary, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    private static function decodeId(string $encoded): string
    {
        return sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }
}
