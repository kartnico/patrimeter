<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Crypto;

/**
 * Authenticated encryption (libsodium's XChaCha20-Poly1305, random nonce). The context (associated data) ties the
 * ciphertext to its place: table, row, user. A ciphertext copied elsewhere, or changed, cannot be decrypted.
 */
final class Aead
{
    public const int KEY_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;
    private const int NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
    private const string PREFIX = 'v1.';

    public static function seal(#[\SensitiveParameter] string $key, #[\SensitiveParameter] string $plaintext, string $context): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);

        return self::PREFIX . sodium_bin2base64(
            $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $key),
            SODIUM_BASE64_VARIANT_ORIGINAL,
        );
    }

    /** @throws DecryptionFailed if the ciphertext is altered, moved or from another key */
    public static function open(#[\SensitiveParameter] string $key, string $stored, string $context): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new DecryptionFailed('Format de chiffré inconnu.');
        }
        try {
            $raw = sodium_base642bin(substr($stored, strlen(self::PREFIX)), SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException) {
            throw new DecryptionFailed('Encodage de chiffré invalide.');
        }
        if (strlen($raw) <= self::NONCE_BYTES) {
            throw new DecryptionFailed('Chiffré tronqué.');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, self::NONCE_BYTES), $context, substr($raw, 0, self::NONCE_BYTES), $key);
        if (!is_string($plain)) {
            throw new DecryptionFailed('Déchiffrement refusé.');
        }

        return $plain;
    }
}
