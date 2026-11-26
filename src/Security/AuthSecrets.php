<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Domain\UserId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Authentication secrets protected by the AUTH_SECRET_KEY server key (32 bytes in base64, only in
 * the environment):
 * - encrypted TOTP secrets (XChaCha20-Poly1305, associated data = user: a secret copied onto
 *   another account cannot be decrypted);
 * - HMAC-SHA256 hashes of the backup codes.
 *
 * A copy of the database alone therefore allows neither generating TOTP codes nor recovering the
 * backup codes. Distinct subkeys are derived from the server key (libsodium KDF).
 *
 * Rotation (docs/installation.md, § 12.5): the old key, in AUTH_SECRET_KEY_PREVIOUS, still serves to
 * decrypt and to check the backup codes; everything is encrypted with the new one, and
 * app:auth-secrets:rotate encrypts the existing TOTP secrets again.
 */
final class AuthSecrets
{
    private const string VERSION_PREFIX = 'v1.';
    private const int KEY_BYTES = SODIUM_CRYPTO_KDF_KEYBYTES;
    private const int TOTP_SUBKEY_ID = 1;
    private const int BACKUP_SUBKEY_ID = 2;
    private const string KDF_CONTEXT = 'patrimtr';

    /** @var list<array{totp: string, backup: string}> current key, then the old one */
    private readonly array $keys;

    public function __construct(
        #[Autowire('%env(AUTH_SECRET_KEY)%')]
        #[\SensitiveParameter]
        string $encodedKey,
        #[Autowire('%kernel.environment%')]
        string $environment,
        #[Autowire('%env(default::AUTH_SECRET_KEY_PREVIOUS)%')]
        #[\SensitiveParameter]
        ?string $previousEncodedKey = null,
    ) {
        $keys = [self::derive($encodedKey, 'AUTH_SECRET_KEY', $environment)];
        if ($previousEncodedKey !== null && $previousEncodedKey !== '') {
            $keys[] = self::derive($previousEncodedKey, 'AUTH_SECRET_KEY_PREVIOUS', $environment);
        }
        $this->keys = $keys;
    }

    /** True if an old key is still set (rotation in progress). */
    public function hasPreviousKey(): bool
    {
        return count($this->keys) > 1;
    }

    public function encryptTotpSecret(UserId $user, #[\SensitiveParameter] string $secret): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($secret, self::totpContext($user), $nonce, $this->keys[0]['totp']);

        return self::VERSION_PREFIX . sodium_bin2base64($nonce . $cipher, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    /** @throws \UnexpectedValueException if the ciphertext is altered, from another user or from another key */
    public function decryptTotpSecret(UserId $user, string $stored): string
    {
        if (!str_starts_with($stored, self::VERSION_PREFIX)) {
            throw new \UnexpectedValueException('Secret TOTP : format inconnu.');
        }
        try {
            $raw = sodium_base642bin(substr($stored, strlen(self::VERSION_PREFIX)), SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException) {
            throw new \UnexpectedValueException('Secret TOTP : encodage invalide.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        if (strlen($nonce) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            foreach ($this->keys as $key) {
                $secret = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, self::totpContext($user), $nonce, $key['totp']);
                if (is_string($secret)) {
                    return $secret;
                }
            }
        }

        throw new \UnexpectedValueException('Secret TOTP indéchiffrable (clé AUTH_SECRET_KEY différente ou donnée altérée).');
    }

    /** Same TOTP secret, encrypted with the current key (rotation). */
    public function reencryptTotpSecret(UserId $user, string $stored): string
    {
        $secret = $this->decryptTotpSecret($user, $stored);
        try {
            return $this->encryptTotpSecret($user, $secret);
        } finally {
            sodium_memzero($secret);
        }
    }

    /** Hash of a normalised backup code, specific to the user (current key). */
    public function backupCodeHash(UserId $user, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $user->value . ':' . $code, $this->keys[0]['backup']);
    }

    /**
     * Possible hashes of an entered code: current key, then the old one during a rotation (an HMAC hash cannot
     * be recomputed without the code: the codes issued before the rotation depend on it).
     *
     * @return list<string>
     */
    public function backupCodeHashes(UserId $user, #[\SensitiveParameter] string $code): array
    {
        return array_map(static fn(array $key): string => hash_hmac('sha256', $user->value . ':' . $code, $key['backup']), $this->keys);
    }

    /** @return array{totp: string, backup: string} */
    private static function derive(#[\SensitiveParameter] string $encodedKey, string $name, string $environment): array
    {
        $key = base64_decode($encodedKey, true);
        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new \RuntimeException("$name absente ou invalide : 32 octets encodés en base64 attendus (voir docs/installation.md).");
        }
        if ($key === str_repeat("\0", self::KEY_BYTES) && !in_array($environment, ['dev', 'test'], true)) {
            throw new \RuntimeException("$name : la clé de développement est interdite en production.");
        }
        $derived = [
            'totp' => sodium_crypto_kdf_derive_from_key(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, self::TOTP_SUBKEY_ID, self::KDF_CONTEXT, $key),
            'backup' => sodium_crypto_kdf_derive_from_key(self::KEY_BYTES, self::BACKUP_SUBKEY_ID, self::KDF_CONTEXT, $key),
        ];
        sodium_memzero($key);

        return $derived;
    }

    private static function totpContext(UserId $user): string
    {
        return 'users.totp_secret:' . $user->value;
    }
}
