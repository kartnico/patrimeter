<?php

declare(strict_types=1);

namespace Patrimeter\Application\Family;

use Patrimeter\Application\Calendar;
use Patrimeter\Application\NotFound;
use Patrimeter\Application\Transfer\ExportedData;
use Patrimeter\Application\Transfer\ExportReader;
use Patrimeter\Application\ValidationFailed;
use Patrimeter\Domain\Budget\BudgetBook;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\FamilyMemberRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;

/**
 * Family account: each of the two members of a couple enters their data in their own account and exports its file
 * every month; the family account imports it for that member (the new import replaces the previous one). The
 * imported data stays apart, encrypted with the family account's key, read-only.
 *
 * "Family" view: the sum of everything, each member having entered their share of what is shared (half of the joint
 * account, share of the house, their share of the shared spending). Net worth: the members' accounts and the family
 * account's own accounts; budget: the members' budgets (BudgetBook::family()). View of one member alone, or of this
 * account alone.
 */
final class FamilyPortfolio
{
    public const int MAX_MEMBERS = 2;

    public function __construct(
        private readonly FamilyMemberRepository $members,
        private readonly AccountRepository $accounts,
        private readonly StatementRepository $statements,
        private readonly OwnershipShareRepository $shares,
        private readonly BudgetRepository $budgets,
        private readonly ExportReader $reader,
        private readonly Calendar $calendar,
    ) {}

    /** @return list<FamilyMember> in the order they were added */
    public function members(UserId $owner): array
    {
        return $this->members->all($owner);
    }

    public function find(UserId $owner, int $memberId): ?FamilyMember
    {
        return $this->members->find($owner, $memberId);
    }

    /**
     * Adds a member, or replaces the data of a member ($memberId) with that of a new export file. The file is fully
     * validated before being kept.
     *
     * @param string|null $name name of the member; null to keep that of an existing member
     *
     * @throws ValidationFailed invalid name, one member too many, unreadable file or refused data (nothing is kept)
     * @throws NotFound         unknown member
     */
    public function import(UserId $owner, ?int $memberId, ?string $name, string $json): FamilyMember
    {
        $existing = $memberId === null ? null : ($this->members->find($owner, $memberId) ?? throw new NotFound('Membre introuvable.'));
        $name = trim($name ?? $existing->name ?? '');
        if (!FamilyMember::isValidName($name)) {
            throw new ValidationFailed(['name' => 'Indiquez un nom de 1 à 40 caractères.']);
        }
        if ($existing === null && $this->members->count($owner) >= self::MAX_MEMBERS) {
            throw new ValidationFailed(['file' => 'Un compte famille réunit deux membres au plus.']);
        }
        $data = ExportReader::decode($json);
        $this->reader->read($data['accounts'], $data['budget'], 1);
        $member = new FamilyMember($existing->id ?? 0, $name, $this->calendar->today(), $data['exported_on'], $data['accounts'], $data['budget']);
        if ($existing === null) {
            $id = $this->members->create($owner, $member);
        } else {
            $id = $existing->id;
            $this->members->update($owner, $id, $member);
        }

        return new FamilyMember($id, $member->name, $member->importedOn, $member->exportedOn, $member->accounts, $member->budget);
    }

    /**
     * @return FamilyMember the member removed
     *
     * @throws NotFound unknown member
     */
    public function remove(UserId $owner, int $memberId): FamilyMember
    {
        $member = $this->members->find($owner, $memberId) ?? throw new NotFound('Membre introuvable.');
        $this->members->delete($owner, $member->id);

        return $member;
    }

    /**
     * Perimeters offered: none without a member (the account only shows its own data); otherwise the family, each
     * member, and this account if it has its own accounts or its own budget.
     *
     * @param list<FamilyMember> $members
     *
     * @return list<array{perimeter: Perimeter, name: ?string}> name of the member, null for the family and this account
     */
    public function perimeters(UserId $owner, array $members): array
    {
        if ($members === []) {
            return [];
        }
        $choices = [['perimeter' => Perimeter::family(), 'name' => null]];
        foreach ($members as $member) {
            $choices[] = ['perimeter' => Perimeter::member($member->id), 'name' => $member->name];
        }
        if ($this->accounts->all($owner) !== [] || $this->budgets->household($owner) !== null) {
            $choices[] = ['perimeter' => Perimeter::own(), 'name' => null];
        }

        return $choices;
    }

    /** Accounts, statements and shares of the perimeter. */
    public function portfolio(UserId $owner, Perimeter $perimeter): Portfolio
    {
        $accounts = [];
        $statements = [];
        $shares = [];
        $names = [];
        if ($perimeter->isOwn() || $perimeter->isFamily()) {
            $accounts = $this->accounts->all($owner);
            $statements = $this->statements->all($owner);
            $shares = $this->shares->all($owner);
        }
        foreach ($this->read($owner, $perimeter) as [$member, $data]) {
            foreach ($data->accounts as $account) {
                $accounts[] = $account;
                $names[$account->id] = $member->name;
            }
            $statements += $data->statements;
            $shares += $data->shares;
        }

        return new Portfolio($accounts, $statements, $shares, $names);
    }

    /**
     * Budget of the perimeter, except "this account" (read by HouseholdBudget): that of a member, or that of the family
     * for the month (sum of the members' budgets), and the first known month.
     *
     * @return array{book: ?BudgetBook, first: ?Month}
     */
    public function budget(UserId $owner, Perimeter $perimeter, Month $month): array
    {
        $named = [];
        $books = [];
        foreach ($this->read($owner, $perimeter) as [$member, $data]) {
            if ($data->budget !== null) {
                $named[] = [$member->name, $data->budget];
                $books[] = $data->budget;
            }
        }
        if (!$perimeter->isFamily()) {
            return ['book' => $books[0] ?? null, 'first' => ($books[0] ?? null)?->firstMonth()];
        }

        return ['book' => BudgetBook::family($named, $month), 'first' => BudgetBook::firstMonthOf(...$books)];
    }

    /**
     * Data of the perimeter's members, read from their file: all for the family, one for a member, none for this
     * account. The rank of each member (1, 2) keeps their identifiers apart.
     *
     * @return list<array{FamilyMember, ExportedData}>
     */
    private function read(UserId $owner, Perimeter $perimeter): array
    {
        if ($perimeter->isOwn()) {
            return [];
        }
        $read = [];
        foreach ($this->members->all($owner) as $rank => $member) {
            if ($perimeter->isFamily() || $perimeter->memberId === $member->id) {
                $read[] = [$member, $this->reader->read($member->accounts, $member->budget, $rank + 1)];
            }
        }

        return $read;
    }
}
