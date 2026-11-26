<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Security;

use Patrimeter\Security\TwoFactor\BackupCodes;
use PHPUnit\Framework\TestCase;

final class BackupCodesTest extends TestCase
{
    public function testNormalizationIgnoresCaseSpacesAndDashes(): void
    {
        self::assertSame('ABCDE23456', BackupCodes::normalize('abcde-23456'));
        self::assertSame('ABCDE23456', BackupCodes::normalize(' ABCDE 23456 '));
    }

    public function testRejectsAnythingThatCannotBeACode(): void
    {
        self::assertNull(BackupCodes::normalize('123456'), 'code TOTP');
        self::assertNull(BackupCodes::normalize('ABCDE-2345I'), 'lettre exclue (I)');
        self::assertNull(BackupCodes::normalize('ABCDE-234560'), 'trop long');
        self::assertNull(BackupCodes::normalize(''));
    }
}
