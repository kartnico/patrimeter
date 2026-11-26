<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Support;

/**
 * Software authenticator for the tests: produces real WebAuthn answers ("none" attestation, ES256 signatures) that the
 * library fully checks.
 */
final class VirtualAuthenticator
{
    private const string ORIGIN = 'http://localhost';
    private const string RP_ID = 'localhost';
    private const int FLAG_USER_PRESENT = 0x01;
    private const int FLAG_USER_VERIFIED = 0x04;
    private const int FLAG_ATTESTED = 0x40;

    private \OpenSSLAsymmetricKey $key;
    public readonly string $credentialId;
    private int $counter = 0;
    private string $userHandle = '';

    public function __construct()
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new \RuntimeException('Clé de test impossible à créer.');
        }
        $this->key = $key;
        $this->credentialId = random_bytes(32);
    }

    public function encodedId(): string
    {
        return self::b64($this->credentialId);
    }

    /**
     * Answer to creation options.
     *
     * @param array<string, mixed> $options
     */
    public function register(array $options): string
    {
        $user = $options['user'] ?? null;
        if (!is_array($user) || !is_string($user['id'] ?? null) || !is_string($options['challenge'] ?? null)) {
            throw new \InvalidArgumentException('Options de création inattendues.');
        }
        $this->userHandle = $user['id'];
        $details = openssl_pkey_get_details($this->key);
        if ($details === false || !is_array($details['ec'] ?? null) || !is_string($details['ec']['x'] ?? null) || !is_string($details['ec']['y'] ?? null)) {
            throw new \RuntimeException('Clé de test illisible.');
        }
        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        // EC2 COSE key: kty=2, alg=-7 (ES256), crv=1 (P-256), x, y.
        $cose = "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . $x . "\x22\x58\x20" . $y;
        $authData = hash('sha256', self::RP_ID, true)
            . chr(self::FLAG_USER_PRESENT | self::FLAG_USER_VERIFIED | self::FLAG_ATTESTED)
            . pack('N', $this->counter)
            . str_repeat("\0", 16)
            . pack('n', strlen($this->credentialId)) . $this->credentialId
            . $cose;
        // Objet d'attestation CBOR : {"fmt": "none", "attStmt": {}, "authData": …}.
        $attestation = "\xA3\x63fmt\x64none\x67attStmt\xA0\x68authData" . self::cborBytes($authData);

        return json_encode([
            'id' => $this->encodedId(),
            'rawId' => $this->encodedId(),
            'type' => 'public-key',
            'clientExtensionResults' => new \stdClass(),
            'response' => [
                'clientDataJSON' => self::b64($this->clientData('webauthn.create', $options['challenge'])),
                'attestationObject' => self::b64($attestation),
                'transports' => ['internal'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Answer to authentication options.
     *
     * @param array<string, mixed> $options
     */
    public function authenticate(array $options, bool $userVerified = true, bool $withUserHandle = true): string
    {
        if (!is_string($options['challenge'] ?? null)) {
            throw new \InvalidArgumentException('Options d\'authentification inattendues.');
        }
        ++$this->counter;
        $flags = self::FLAG_USER_PRESENT | ($userVerified ? self::FLAG_USER_VERIFIED : 0);
        $authData = hash('sha256', self::RP_ID, true) . chr($flags) . pack('N', $this->counter);
        $clientData = $this->clientData('webauthn.get', $options['challenge']);
        $signature = '';
        openssl_sign($authData . hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);
        if (!is_string($signature)) {
            throw new \RuntimeException('Signature impossible.');
        }

        return json_encode([
            'id' => $this->encodedId(),
            'rawId' => $this->encodedId(),
            'type' => 'public-key',
            'clientExtensionResults' => new \stdClass(),
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'authenticatorData' => self::b64($authData),
                'signature' => self::b64($signature),
                'userHandle' => $this->userHandle === '' || !$withUserHandle ? null : $this->userHandle,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function clientData(string $type, string $challenge): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => self::ORIGIN, 'crossOrigin' => false], JSON_THROW_ON_ERROR);
    }

    private static function cborBytes(string $bytes): string
    {
        return strlen($bytes) < 256 ? "\x58" . chr(strlen($bytes)) . $bytes : "\x59" . pack('n', strlen($bytes)) . $bytes;
    }

    public static function b64(string $bytes): string
    {
        return sodium_bin2base64($bytes, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }
}
