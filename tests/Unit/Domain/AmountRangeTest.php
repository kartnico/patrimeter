<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\AmountRange;
use PHPUnit\Framework\TestCase;

final class AmountRangeTest extends TestCase
{
    public function testTheSavingsScaleGoesUpToOneHundredThousandEuros(): void
    {
        self::assertSame(['0-1000', '1000-5000', '5000-20000', '20000-50000', '50000-100000'], array_map(static fn(AmountRange $range): string => $range->key(), AmountRange::savings()));
        self::assertSame(['0-100000', '100000-200000', '200000-300000', '300000-500000', '500000-1000000'], array_map(static fn(AmountRange $range): string => $range->key(), AmountRange::home()));
    }

    public function testARangeIsWorthItsMiddleInCents(): void
    {
        self::assertSame(50_000, AmountRange::tryFromKey('0-1000', false)?->cents());
        self::assertSame(25_000_000, AmountRange::tryFromKey('200000-300000', true)?->cents());
    }

    public function testAKeyIsReadInItsScaleOnly(): void
    {
        self::assertNull(AmountRange::tryFromKey('200000-300000', false));
        self::assertNull(AmountRange::tryFromKey('1000-5000', true));
        self::assertNull(AmountRange::tryFromKey('exact', false));
    }
}
