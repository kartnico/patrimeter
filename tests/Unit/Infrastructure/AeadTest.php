<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Infrastructure;

use Patrimeter\Infrastructure\Crypto\Aead;
use Patrimeter\Infrastructure\Crypto\DecryptionFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AeadTest extends TestCase
{
    public function testRoundTripIsRandomizedAndHidesThePlaintext(): void
    {
        $key = str_repeat('k', Aead::KEY_BYTES);
        $sealed = Aead::seal($key, 'Livret A', 'accounts:1');

        self::assertStringNotContainsString('Livret', $sealed);
        self::assertNotSame($sealed, Aead::seal($key, 'Livret A', 'accounts:1'), 'nonce aléatoire');
        self::assertSame('Livret A', Aead::open($key, $sealed, 'accounts:1'));
    }

    public function testAnotherContextOrKeyCannotOpen(): void
    {
        $key = str_repeat('k', Aead::KEY_BYTES);
        $sealed = Aead::seal($key, 'Livret A', 'accounts:1');

        foreach ([[$key, 'accounts:2'], [str_repeat('x', Aead::KEY_BYTES), 'accounts:1']] as [$otherKey, $context]) {
            try {
                Aead::open($otherKey, $sealed, $context);
                self::fail('Refus attendu');
            } catch (DecryptionFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'vide' => [''];
        yield 'version inconnue' => ['v2.AAAA'];
        yield 'encodage invalide' => ['v1.!!!'];
        yield 'tronqué' => ['v1.' . base64_encode('court')];
        yield 'altéré' => ['v1.' . base64_encode(str_repeat('a', 60))];
    }

    #[DataProvider('malformed')]
    public function testMalformedValuesAreRefused(string $stored): void
    {
        $this->expectException(DecryptionFailed::class);
        Aead::open(str_repeat('k', Aead::KEY_BYTES), $stored, 'contexte');
    }

    /** A ciphertext of another version is refused straight away, before any decoding attempt. */
    public function testUnknownVersionIsRefusedAsSuch(): void
    {
        $this->expectExceptionObject(new DecryptionFailed('Format de chiffré inconnu.'));
        Aead::open(str_repeat('k', Aead::KEY_BYTES), 'v2.' . substr(Aead::seal(str_repeat('k', Aead::KEY_BYTES), 'x', 'contexte'), 3), 'contexte');
    }
}
