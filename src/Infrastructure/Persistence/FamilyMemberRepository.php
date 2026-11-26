<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Family\FamilyMember;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Crypto\Aead;
use Patrimeter\Security\Vault\Vault;

/**
 * Members of a family account: name, dates and content of the last export file imported, encrypted with the family
 * account's data key (context tied to the row and to the owner). Left in clear: the number of members.
 * Isolation: each query filters on `user_id` (tests/Architecture/OwnedDataQueriesTest).
 */
final class FamilyMemberRepository
{
    private const string PLACEHOLDER = 'en-cours';

    public function __construct(
        private readonly Connection $connection,
        private readonly Vault $vault,
    ) {}

    /** @return list<FamilyMember> in the order they were added */
    public function all(UserId $owner): array
    {
        return array_map(
            fn(array $row): FamilyMember => $this->hydrate($owner, $row),
            $this->connection->fetchAllAssociative('SELECT id, ciphertext FROM family_members WHERE user_id = ? ORDER BY id', [$owner->value]),
        );
    }

    public function find(UserId $owner, int $id): ?FamilyMember
    {
        $row = $this->connection->fetchAssociative('SELECT id, ciphertext FROM family_members WHERE user_id = ? AND id = ?', [$owner->value, $id]);

        return $row === false ? null : $this->hydrate($owner, $row);
    }

    public function count(UserId $owner): int
    {
        return self::integer($this->connection->fetchOne('SELECT COUNT(*) FROM family_members WHERE user_id = ?', [$owner->value]));
    }

    /** @return int identifier of the member added */
    public function create(UserId $owner, FamilyMember $member): int
    {
        return $this->connection->transactional(function () use ($owner, $member): int {
            // The identifier is part of the ciphertext's context: the row is reserved first.
            $this->connection->executeStatement('INSERT INTO family_members (user_id, ciphertext) VALUES (?, ?)', [$owner->value, self::PLACEHOLDER]);
            $id = (int) $this->connection->lastInsertId();
            $this->store($owner, $id, $member);

            return $id;
        });
    }

    /** Replaces the content of a member (new import). */
    public function update(UserId $owner, int $id, FamilyMember $member): void
    {
        $this->store($owner, $id, $member);
    }

    public function delete(UserId $owner, int $id): void
    {
        $this->connection->executeStatement('DELETE FROM family_members WHERE id = ? AND user_id = ?', [$id, $owner->value]);
    }

    /** Encrypts all the owner's rows again: the current key is already open, $newKey replaces it. */
    public function reencryptAll(UserId $owner, #[\SensitiveParameter] string $newKey): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, ciphertext FROM family_members WHERE user_id = ?', [$owner->value]) as $row) {
            $id = Row::int($row, 'id');
            $plain = Aead::open($this->vault->key($owner), Row::string($row, 'ciphertext'), self::context($owner, $id));
            $this->connection->executeStatement(
                'UPDATE family_members SET ciphertext = ? WHERE id = ? AND user_id = ?',
                [Aead::seal($newKey, $plain, self::context($owner, $id)), $id, $owner->value],
            );
        }
    }

    private function store(UserId $owner, int $id, FamilyMember $member): void
    {
        $sealed = Aead::seal($this->vault->key($owner), json_encode($member->payload(), JSON_THROW_ON_ERROR), self::context($owner, $id));
        $this->connection->executeStatement('UPDATE family_members SET ciphertext = ? WHERE id = ? AND user_id = ?', [$sealed, $id, $owner->value]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(UserId $owner, array $row): FamilyMember
    {
        $id = Row::int($row, 'id');
        $payload = json_decode(Aead::open($this->vault->key($owner), Row::string($row, 'ciphertext'), self::context($owner, $id)), true, 16, JSON_THROW_ON_ERROR);
        $accounts = is_array($payload) ? ($payload['accounts'] ?? null) : null;
        if (!is_array($payload) || !is_array($accounts) || !array_is_list($accounts)) {
            throw new \UnexpectedValueException('Membre déchiffré invalide.');
        }
        /** @var array<string, mixed> $payload */
        return new FamilyMember(
            $id,
            Row::string($payload, 'name'),
            Row::string($payload, 'imported_on'),
            Row::nullableString($payload, 'exported_on'),
            $accounts,
            isset($payload['budget']) && is_array($payload['budget']) ? $payload['budget'] : null,
        );
    }

    private static function integer(mixed $value): int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
    }

    private static function context(UserId $owner, int $id): string
    {
        return "family_members:$id:{$owner->value}:1";
    }
}
