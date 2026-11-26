<?php

declare(strict_types=1);

namespace Patrimeter\Application\Family;

use Patrimeter\Domain\Budget\Household;

/**
 * Member of a family account: their name, the dates of their last import and of the imported export, and the content
 * of their export file (accounts and budget, in the "patrimeter" format, already validated), kept encrypted with the
 * family account's key. A new import replaces this content.
 */
final class FamilyMember
{
    /** Name of a member: like a first name of the household (40 characters at most, no control character). */
    public const string NAME_PATTERN = Household::NAME_PATTERN;

    /**
     * @param list<mixed>       $accounts accounts of the export file
     * @param array<mixed>|null $budget   budget of the export file
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $importedOn,
        public readonly ?string $exportedOn,
        public readonly array $accounts,
        public readonly ?array $budget,
    ) {}

    public static function isValidName(string $name): bool
    {
        return Household::isValidName($name);
    }

    /** @return array{name: string, imported_on: string, exported_on: ?string, accounts: list<mixed>, budget: array<mixed>|null} */
    public function payload(): array
    {
        return [
            'name' => $this->name,
            'imported_on' => $this->importedOn,
            'exported_on' => $this->exportedOn,
            'accounts' => $this->accounts,
            'budget' => $this->budget,
        ];
    }
}
