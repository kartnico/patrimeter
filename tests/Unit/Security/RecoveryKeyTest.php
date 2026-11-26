<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Security;

use Patrimeter\Security\Vault\RecoveryKey;
use PHPUnit\Framework\TestCase;

final class RecoveryKeyTest extends TestCase
{
    public function testFormatAndParseAreInverse(): void
    {
        $key = RecoveryKey::generate();
        $formatted = RecoveryKey::format($key);

        self::assertSame(RecoveryKey::BYTES, strlen($key));
        self::assertMatchesRegularExpression('/\A([A-Z2-7]{4} ){12}[A-Z2-7]{4}\z/', $formatted);
        self::assertSame($key, RecoveryKey::parse($formatted));
        self::assertSame($key, RecoveryKey::parse(strtolower(str_replace(' ', '-', $formatted))), 'casse et séparateurs indifférents');
    }

    public function testRefusesAnythingElse(): void
    {
        self::assertNull(RecoveryKey::parse('trop courte'));
        self::assertNull(RecoveryKey::parse(str_repeat('1', 52)), 'hors alphabet base32');
    }

    public function testConfirmationChecksTheLastTwoGroups(): void
    {
        $key = RecoveryKey::generate();
        $groups = explode(' ', RecoveryKey::format($key));
        $end = $groups[11] . ' ' . $groups[12];

        self::assertTrue(RecoveryKey::endsWith($key, $end));
        self::assertTrue(RecoveryKey::endsWith($key, strtolower($end)));
        self::assertFalse(RecoveryKey::endsWith($key, $groups[12]));
        self::assertFalse(RecoveryKey::endsWith($key, ''));
    }
}
