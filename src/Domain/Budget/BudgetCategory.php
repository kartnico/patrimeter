<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Budget;

/**
 * Budget category ("Housing", "Leisure"…): shared by the household, paid from the joint account, or belonging to one
 * adult ($adult), paid from what they have left after their transfer to the joint account.
 */
final class BudgetCategory
{
    /** Name of a category or an item: valid UTF-8, no control character, 60 characters at most. */
    public const string NAME_PATTERN = '/\A[^\p{Cc}]{1,60}\z/u';

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly BudgetGroup $group,
        public readonly ?int $adult = null,
    ) {}

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1 && trim($name) !== '';
    }

    public function isShared(): bool
    {
        return $this->adult === null;
    }
}
