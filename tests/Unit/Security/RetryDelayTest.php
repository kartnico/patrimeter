<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Security;

use Patrimeter\Security\RetryDelay;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

final class RetryDelayTest extends TestCase
{
    public function testSentence(): void
    {
        self::assertSame('Réessayez dans 1 seconde.', RetryDelay::sentence(1)->trans(self::french()));
        self::assertSame('Réessayez dans 30 secondes.', RetryDelay::sentence(30)->trans(self::french()));
        self::assertSame('Réessayez dans 119 secondes.', RetryDelay::sentence(119)->trans(self::french()));
        self::assertSame('Réessayez dans 2 minutes.', RetryDelay::sentence(120)->trans(self::french()));
        self::assertSame('Réessayez dans 15 minutes.', RetryDelay::sentence(870)->trans(self::french()));
    }

    private static function french(): IdentityTranslator
    {
        $translator = new IdentityTranslator();
        $translator->setLocale('fr');

        return $translator;
    }
}
