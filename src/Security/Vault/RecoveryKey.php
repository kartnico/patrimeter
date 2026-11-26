<?php

declare(strict_types=1);

namespace Patrimeter\Security\Vault;

use ParagonIE\ConstantTime\Base32;

/**
 * Recovery key: 256 random bits, shown only once in groups of 4 base32 characters (13 groups). Only an
 * envelope derived from this key is kept.
 */
final class RecoveryKey
{
    public const int BYTES = 32;
    private const int GROUP = 4;
    private const int ENCODED_LENGTH = 52;
    /** Groups asked again to check that the key has been written down. */
    private const int CONFIRMED_LENGTH = 8;

    public static function generate(): string
    {
        return random_bytes(self::BYTES);
    }

    public static function format(#[\SensitiveParameter] string $key): string
    {
        return implode(' ', str_split(Base32::encodeUpperUnpadded($key), self::GROUP));
    }

    /** Key entered (case, spaces and dashes ignored), or null if it cannot be one. */
    public static function parse(#[\SensitiveParameter] string $input): ?string
    {
        $encoded = self::normalize($input);
        if (strlen($encoded) !== self::ENCODED_LENGTH || preg_match('/\A[A-Z2-7]+\z/', $encoded) !== 1) {
            return null;
        }

        return Base32::decodeUpper($encoded);
    }

    /** True if the input matches the end of the key (last two groups). */
    public static function endsWith(#[\SensitiveParameter] string $key, #[\SensitiveParameter] string $input): bool
    {
        return hash_equals(substr(Base32::encodeUpperUnpadded($key), -self::CONFIRMED_LENGTH), self::normalize($input));
    }

    private static function normalize(string $input): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', $input));
    }
}
