<?php

declare(strict_types=1);

namespace Patrimeter\Application\Form;

use Patrimeter\Domain\Budget\Household;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Form of the tax household, in rows: the number of adults (one or two), then for each adult their first name and
 * year of birth; the number of children (0 to 12), then for each child their first name and year of birth; the
 * emergency savings target, in months of spending. Only the rows up to the numbers chosen count (app.js hides the
 * others). A child row carries the number of the child it shows (child_ids): an existing child keeps it, and with it
 * the budget items tied to them; an emptied first name removes that child; a row without a number adds a child.
 */
final class HouseholdForm
{
    /** @var array<string, TranslatableInterface> errors by field (adult_names.2, child_names.0, children_count…) */
    public array $errors = [];
    public ?Household $household = null;

    /**
     * @param array<int, string> $adultNames adult => first name entered
     * @param array<int, string> $adultYears adult => year of birth entered
     * @param array<int, string> $childIds   row => number of the existing child it shows ('' for a new child)
     * @param array<int, string> $childNames row => first name entered
     * @param array<int, string> $childYears row => year of birth entered
     */
    private function __construct(
        public readonly string $adults,
        public readonly array $adultNames,
        public readonly array $adultYears,
        public readonly string $childrenCount,
        public readonly array $childIds,
        public readonly array $childNames,
        public readonly array $childYears,
        public readonly string $precautionMonths,
        ?Household $current,
    ) {
        $count = match ($adults) {
            '1' => 1,
            '2' => 2,
            default => 0,
        };
        if ($count === 0) {
            $this->errors['adults'] = t('Choisissez un ou deux adultes.');
        }
        $names = [];
        $adultBirthYears = [];
        foreach ($count === 2 ? [Household::FIRST_ADULT, Household::SECOND_ADULT] : [Household::FIRST_ADULT] as $adult) {
            $name = $adultNames[$adult] ?? '';
            if (!Household::isValidName($name)) {
                $this->errors["adult_names.$adult"] = t('Indiquez un prénom de 1 à 40 caractères.');
            }
            $names[$adult] = $name;
            $adultBirthYears[$adult] = $this->year($adultYears[$adult] ?? '', "adult_years.$adult");
        }

        // No number of children sent: none.
        $rows = $childrenCount === '' ? 0 : filter_var($childrenCount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => Household::MAX_CHILDREN]]);
        if (!is_int($rows)) {
            $this->errors['children_count'] = t('Choisissez un nombre d\'enfants de 0 à 12.');
            $rows = 0;
        }
        $existing = $current->children ?? [];
        $nextChildId = $current->nextChildId ?? 1;
        $children = [];
        $childBirthYears = [];
        for ($row = 0; $row < $rows; ++$row) {
            // The number of an existing child, once only; anything else is a new child.
            $id = filter_var($childIds[$row] ?? '', FILTER_VALIDATE_INT);
            $id = is_int($id) && isset($existing[$id]) && !isset($children[$id]) ? $id : null;
            $name = $childNames[$row] ?? '';
            if ($id !== null && $name === '') {
                continue;
            }
            if (!Household::isValidName($name)) {
                $this->errors["child_names.$row"] = t('Indiquez un prénom de 1 à 40 caractères.');
            }
            $year = $this->year($childYears[$row] ?? '', "child_years.$row");
            $id ??= $nextChildId++;
            $children[$id] = $name;
            $childBirthYears[$id] = $year;
        }

        $months = filter_var($precautionMonths, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => Household::MAX_PRECAUTION_MONTHS]]);
        if (!is_int($months)) {
            $this->errors['precaution_months'] = t('Indiquez un nombre de mois de 0 à 36.');
        }

        if ($this->errors === [] && is_int($months)) {
            $this->household = new Household($names, $children, $months, $nextChildId, $adultBirthYears, $childBirthYears);
        }
    }

    /**
     * @param array<mixed> $adultNames first names entered, by adult
     * @param array<mixed> $adultYears years of birth entered, by adult
     * @param array<mixed> $childIds   numbers of the existing children, by row
     * @param array<mixed> $childNames first names entered, by row
     * @param array<mixed> $childYears years of birth entered, by row
     */
    public static function validate(
        string $adults,
        array $adultNames,
        array $adultYears,
        string $childrenCount,
        array $childIds,
        array $childNames,
        array $childYears,
        string $precautionMonths,
        ?Household $current,
    ): self {
        return new self(
            trim($adults),
            self::texts($adultNames),
            self::texts($adultYears),
            trim($childrenCount),
            self::texts($childIds),
            self::texts($childNames),
            self::texts($childYears),
            trim($precautionMonths),
            $current,
        );
    }

    /**
     * Rows of the children shown by the form, always Household::MAX_CHILDREN of them: those entered (form shown again
     * after an error), otherwise the children of the household in order, then empty rows.
     *
     * @return list<array{id: string, name: string, year: string}>
     */
    public static function childRows(?Household $household, ?self $form): array
    {
        $rows = [];
        if ($form !== null) {
            for ($row = 0; $row < Household::MAX_CHILDREN; ++$row) {
                $rows[] = ['id' => $form->childIds[$row] ?? '', 'name' => $form->childNames[$row] ?? '', 'year' => $form->childYears[$row] ?? ''];
            }

            return $rows;
        }
        foreach ($household->children ?? [] as $id => $name) {
            $rows[] = ['id' => (string) $id, 'name' => $name, 'year' => (string) ($household?->childBirthYear($id) ?? '')];
        }
        while (count($rows) < Household::MAX_CHILDREN) {
            $rows[] = ['id' => '', 'name' => '', 'year' => ''];
        }

        return $rows;
    }

    public function isValid(): bool
    {
        return $this->errors === [] && $this->household !== null;
    }

    /** Year of birth entered, required (0 and an error if invalid). */
    private function year(string $input, string $field): int
    {
        $year = filter_var($input, FILTER_VALIDATE_INT, ['options' => ['min_range' => Household::MIN_BIRTH_YEAR, 'max_range' => Household::MAX_BIRTH_YEAR]]);
        if (!is_int($year)) {
            $this->errors[$field] = t('Indiquez l\'année de naissance, sur quatre chiffres (1985).');

            return 0;
        }

        return $year;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<int, string>
     */
    private static function texts(array $values): array
    {
        $texts = [];
        foreach (array_keys($values) as $key) {
            if (is_int($key) && is_string($values[$key])) {
                $texts[$key] = trim($values[$key]);
            }
        }

        return $texts;
    }
}
