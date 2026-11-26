<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Budget;

/**
 * Item of a category ("Groceries", "School canteen"…), whose amount is set each month. It can be tied to a child (cost
 * of each child) and, in a savings category, to the account that receives the payment (compared with the change in
 * its balance).
 */
final class BudgetLine
{
    public function __construct(
        public readonly int $id,
        public readonly int $categoryId,
        public readonly string $name,
        public readonly ?int $childId = null,
        public readonly ?int $accountId = null,
    ) {}
}
