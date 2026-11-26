<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain\Budget;

use Patrimeter\Domain\Budget\Reorder;
use PHPUnit\Framework\TestCase;

/** Moving a category or an item one place among its siblings, the others keeping their place. */
final class ReorderTest extends TestCase
{
    public function testAnElementSwapsWithItsNeighbourAmongItsSiblingsOnly(): void
    {
        // 1, 3 and 5 are the siblings (same budget); 2 and 4 belong to another budget.
        self::assertSame([3, 2, 1, 4, 5], Reorder::move([1, 2, 3, 4, 5], [1, 3, 5], 3, -1));
        self::assertSame([1, 2, 5, 4, 3], Reorder::move([1, 2, 3, 4, 5], [1, 3, 5], 3, 1));
    }

    public function testNothingMovesBeyondTheEdgesOrForAnUnknownElement(): void
    {
        self::assertNull(Reorder::move([1, 2, 3], [1, 2, 3], 1, -1));
        self::assertNull(Reorder::move([1, 2, 3], [1, 2, 3], 3, 1));
        self::assertNull(Reorder::move([1, 2, 3], [1, 2, 3], 9, 1));
        self::assertNull(Reorder::move([1, 2, 3], [1, 2, 3], 2, 0));
    }
}
