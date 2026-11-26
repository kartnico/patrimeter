<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain\Budget;

use Patrimeter\Domain\Budget\PrecautionCoverage;
use Patrimeter\Domain\Budget\PrecautionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Status of the emergency savings: less than one month, below the target, target reached, beyond twice it, no target. */
final class PrecautionStatusTest extends TestCase
{
    /** @return iterable<string, array{int, int, int, PrecautionStatus}> savings, monthly spending, target in months, status */
    public static function cases(): iterable
    {
        yield 'dépenses inconnues' => [500_000, 0, 3, PrecautionStatus::Unknown];
        yield 'rien de côté' => [0, 200_000, 3, PrecautionStatus::Critical];
        yield 'un centime sous un mois' => [199_999, 200_000, 3, PrecautionStatus::Critical];
        yield 'un mois tout juste' => [200_000, 200_000, 3, PrecautionStatus::Low];
        yield 'un centime sous la cible' => [599_999, 200_000, 3, PrecautionStatus::Low];
        yield 'cible tout juste' => [600_000, 200_000, 3, PrecautionStatus::Reached];
        yield 'double de la cible' => [1_200_000, 200_000, 3, PrecautionStatus::Reached];
        yield 'au-delà du double' => [1_200_001, 200_000, 3, PrecautionStatus::Surplus];
        yield 'sans cible' => [300_000, 200_000, 0, PrecautionStatus::NoTarget];
        yield 'sans cible, moins d\'un mois' => [100_000, 200_000, 0, PrecautionStatus::Critical];
        yield 'épargne négative' => [-50_000, 200_000, 3, PrecautionStatus::Critical];
    }

    #[DataProvider('cases')]
    public function testStatus(int $amount, int $expenses, int $target, PrecautionStatus $expected): void
    {
        self::assertSame($expected, (new PrecautionCoverage($amount, $expenses, $target))->status());
    }

    public function testOnlyCriticalAndLowAreAlerts(): void
    {
        $alerts = array_values(array_filter(PrecautionStatus::cases(), static fn(PrecautionStatus $status): bool => $status->isAlert()));

        self::assertSame([PrecautionStatus::Critical, PrecautionStatus::Low], $alerts);
    }
}
