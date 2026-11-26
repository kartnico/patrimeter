<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Security;

use Patrimeter\Domain\UserId;
use Patrimeter\Security\AuthSecrets;
use PHPUnit\Framework\TestCase;

final class AuthSecretsTest extends TestCase
{
    private const string SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public function testTotpSecretRoundTripIsRandomizedAndBoundToTheUser(): void
    {
        $secrets = self::secrets(self::key(1));
        $alice = UserId::fromString('01900000-0000-7000-8000-000000000001');
        $bob = UserId::fromString('01900000-0000-7000-8000-000000000002');

        $stored = $secrets->encryptTotpSecret($alice, self::SECRET);
        self::assertStringStartsWith('v1.', $stored);
        self::assertStringNotContainsString(self::SECRET, $stored);
        self::assertNotSame($stored, $secrets->encryptTotpSecret($alice, self::SECRET), 'nonce aléatoire');
        self::assertSame(self::SECRET, $secrets->decryptTotpSecret($alice, $stored));

        $this->expectException(\UnexpectedValueException::class);
        $secrets->decryptTotpSecret($bob, $stored);
    }

    public function testAnotherKeyCannotDecrypt(): void
    {
        $alice = UserId::fromString('01900000-0000-7000-8000-000000000001');
        $stored = self::secrets(self::key(1))->encryptTotpSecret($alice, self::SECRET);

        $this->expectException(\UnexpectedValueException::class);
        self::secrets(self::key(2))->decryptTotpSecret($alice, $stored);
    }

    public function testMalformedValuesAreRejected(): void
    {
        $secrets = self::secrets(self::key(1));
        $alice = UserId::fromString('01900000-0000-7000-8000-000000000001');

        foreach (['', 'v2.AAAA', 'v1.!!!', 'v1.' . base64_encode('court')] as $stored) {
            try {
                $secrets->decryptTotpSecret($alice, $stored);
                self::fail("Refus attendu pour « $stored »");
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testBackupCodeHashesDependOnTheUserAndTheKey(): void
    {
        $alice = UserId::fromString('01900000-0000-7000-8000-000000000001');
        $bob = UserId::fromString('01900000-0000-7000-8000-000000000002');
        $secrets = self::secrets(self::key(1));

        $hash = $secrets->backupCodeHash($alice, 'ABCDE23456');
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $hash);
        self::assertSame($hash, $secrets->backupCodeHash($alice, 'ABCDE23456'));
        self::assertNotSame($hash, $secrets->backupCodeHash($bob, 'ABCDE23456'));
        self::assertNotSame($hash, self::secrets(self::key(2))->backupCodeHash($alice, 'ABCDE23456'));
    }

    public function testKeyMustBe32BytesOfBase64(): void
    {
        $this->expectExceptionMessage('AUTH_SECRET_KEY absente ou invalide');
        self::secrets(base64_encode('trop court'));
    }

    public function testMissingKeyIsRefused(): void
    {
        $this->expectExceptionMessage('AUTH_SECRET_KEY absente ou invalide');
        self::secrets('');
    }

    public function testDevelopmentKeyIsRefusedInProduction(): void
    {
        $zero = base64_encode(str_repeat("\0", 32));
        $alice = UserId::fromString('01900000-0000-7000-8000-000000000001');
        self::assertSame(64, strlen((new AuthSecrets($zero, 'test'))->backupCodeHash($alice, 'ABCDE23456')), 'acceptée en test');

        $this->expectExceptionMessage('interdite en production');
        new AuthSecrets($zero, 'prod');
    }

    public function testRotationKeepsTheOldKeyForReadingOnly(): void
    {
        $alice = UserId::fromString('01900000-0000-7000-8000-000000000001');
        $old = self::secrets(self::key(1));
        $stored = $old->encryptTotpSecret($alice, self::SECRET);
        $oldCodeHash = $old->backupCodeHash($alice, 'ABCDEFGHIJ');

        $rotating = new AuthSecrets(self::key(2), 'prod', self::key(1));
        self::assertTrue($rotating->hasPreviousKey());
        self::assertFalse(self::secrets(self::key(2))->hasPreviousKey());
        self::assertSame(self::SECRET, $rotating->decryptTotpSecret($alice, $stored), 'ancien secret encore lisible');
        self::assertContains($oldCodeHash, $rotating->backupCodeHashes($alice, 'ABCDEFGHIJ'), 'ancien code de secours encore valable');

        $rotated = $rotating->reencryptTotpSecret($alice, $stored);
        self::assertSame(self::SECRET, self::secrets(self::key(2))->decryptTotpSecret($alice, $rotated), 'rechiffré avec la nouvelle clé seule');
        self::assertSame([self::secrets(self::key(2))->backupCodeHash($alice, 'ABCDEFGHIJ')], self::secrets(self::key(2))->backupCodeHashes($alice, 'ABCDEFGHIJ'));

        $this->expectException(\UnexpectedValueException::class);
        self::secrets(self::key(1))->decryptTotpSecret($alice, $rotated);
    }

    public function testAnInvalidPreviousKeyIsRefused(): void
    {
        $this->expectExceptionMessage('AUTH_SECRET_KEY_PREVIOUS absente ou invalide');
        new AuthSecrets(self::key(2), 'prod', 'courte');
    }

    private static function secrets(string $key): AuthSecrets
    {
        return new AuthSecrets($key, 'prod');
    }

    private static function key(int $seed): string
    {
        return base64_encode(str_repeat(chr($seed), 32));
    }
}
