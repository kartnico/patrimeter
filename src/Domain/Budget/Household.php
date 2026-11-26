<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Budget;

use Patrimeter\Domain\Month;

/**
 * Tax household of the budget: one adult, or two, and children, named by their first name, with the year of birth of
 * each one (unknown for a household saved before 2.26.0); emergency savings target, in months of spending. Adults are
 * numbered 1 and 2; a child keeps their number, never reused, even after being removed from the household. A child of
 * working age (16 in the year of the month) can have incomes of their own.
 */
final class Household
{
    /** Age from which a child can have incomes of their own (France: 16, the end of compulsory schooling). */
    public const int WORKING_AGE = 16;
    public const int MIN_BIRTH_YEAR = 1900;
    public const int MAX_BIRTH_YEAR = 2100;
    public const int FIRST_ADULT = 1;
    public const int SECOND_ADULT = 2;
    public const int MAX_CHILDREN = 12;
    public const int MAX_PRECAUTION_MONTHS = 36;
    public const int DEFAULT_PRECAUTION_MONTHS = 3;

    /** First name: valid UTF-8, no control character, 40 characters at most. */
    public const string NAME_PATTERN = '/\A[^\p{Cc}]{1,40}\z/u';

    /**
     * @param array<int, string> $adults           adult (1, and 2 for a couple) => first name
     * @param array<int, string> $children         child => first name
     * @param array<int, int>    $adultBirthYears  adult => year of birth (missing: unknown)
     * @param array<int, int>    $childBirthYears  child => year of birth (missing: unknown)
     */
    public function __construct(
        public readonly array $adults,
        public readonly array $children = [],
        public readonly int $precautionMonths = self::DEFAULT_PRECAUTION_MONTHS,
        public readonly int $nextChildId = 1,
        public readonly array $adultBirthYears = [],
        public readonly array $childBirthYears = [],
    ) {
        $ids = array_keys($adults);
        if ($ids !== [self::FIRST_ADULT] && $ids !== [self::FIRST_ADULT, self::SECOND_ADULT]) {
            throw new \InvalidArgumentException('Un foyer compte un ou deux adultes.');
        }
        foreach ([...array_values($adults), ...array_values($children)] as $name) {
            if (!self::isValidName($name)) {
                throw new \InvalidArgumentException('Prénom invalide.');
            }
        }
        if (count($children) > self::MAX_CHILDREN) {
            throw new \InvalidArgumentException('Trop d\'enfants.');
        }
        foreach (array_keys($children) as $id) {
            if ($id < 1 || $id >= $nextChildId) {
                throw new \InvalidArgumentException('Numéro d\'enfant invalide.');
            }
        }
        if ($precautionMonths < 0 || $precautionMonths > self::MAX_PRECAUTION_MONTHS) {
            throw new \InvalidArgumentException('Cible de précaution invalide.');
        }
        foreach ([[$adultBirthYears, $adults], [$childBirthYears, $children]] as [$years, $people]) {
            foreach ($years as $id => $year) {
                if (!isset($people[$id]) || !self::isValidBirthYear($year)) {
                    throw new \InvalidArgumentException('Année de naissance invalide.');
                }
            }
        }
    }

    public static function isValidBirthYear(int $year): bool
    {
        return $year >= self::MIN_BIRTH_YEAR && $year <= self::MAX_BIRTH_YEAR;
    }

    public function adultBirthYear(int $adult): ?int
    {
        return $this->adultBirthYears[$adult] ?? null;
    }

    public function childBirthYear(int $child): ?int
    {
        return $this->childBirthYears[$child] ?? null;
    }

    /** A child of the household old enough to work in the year of this month (year of birth known). */
    public function canWork(int $child, Month $month): bool
    {
        $year = $this->childBirthYear($child);

        return isset($this->children[$child]) && $year !== null && $month->year - $year >= self::WORKING_AGE;
    }

    /**
     * Children old enough to work this month, who can have incomes of their own.
     *
     * @return array<int, string> child => first name
     */
    public function workingChildren(Month $month): array
    {
        return array_filter($this->children, fn(int $child): bool => $this->canWork($child, $month), ARRAY_FILTER_USE_KEY);
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1 && trim($name) !== '';
    }

    public function isCouple(): bool
    {
        return isset($this->adults[self::SECOND_ADULT]);
    }

    /** @return list<int> numbers of the adults */
    public function adultIds(): array
    {
        return array_keys($this->adults);
    }

    public function hasAdult(int $adult): bool
    {
        return isset($this->adults[$adult]);
    }

    public function adultName(int $adult): string
    {
        return $this->adults[$adult] ?? '';
    }

    public function hasChild(int $child): bool
    {
        return isset($this->children[$child]);
    }

    public function childName(int $child): ?string
    {
        return $this->children[$child] ?? null;
    }
}
