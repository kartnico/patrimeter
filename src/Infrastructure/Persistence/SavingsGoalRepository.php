<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\Goal\GoalScope;
use Patrimeter\Domain\Goal\SavingsGoal;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Crypto\Aead;
use Patrimeter\Security\Vault\Vault;

/**
 * Savings goals: everything encrypted with the owner's data key (context tied to the row and to the owner). Left in
 * clear: the number of goals. Isolation: each query filters on `user_id` (tests/Architecture/OwnedDataQueriesTest).
 */
final class SavingsGoalRepository
{
    private const string PLACEHOLDER = 'en-cours';
    /** At most this many goals per user. */
    public const int MAX_GOALS = 20;

    public function __construct(
        private readonly Connection $connection,
        private readonly Vault $vault,
    ) {}

    /** @return list<SavingsGoal> sorted by target month, then by name */
    public function all(UserId $owner): array
    {
        $goals = array_map(
            fn(array $row): SavingsGoal => $this->hydrate($owner, $row),
            $this->connection->fetchAllAssociative('SELECT id, ciphertext FROM savings_goals WHERE user_id = ? ORDER BY id', [$owner->value]),
        );
        usort($goals, static fn(SavingsGoal $a, SavingsGoal $b): int => [$a->targetMonth->toString(), $a->name] <=> [$b->targetMonth->toString(), $b->name]);

        return $goals;
    }

    public function find(UserId $owner, int $id): ?SavingsGoal
    {
        $row = $this->connection->fetchAssociative('SELECT id, ciphertext FROM savings_goals WHERE user_id = ? AND id = ?', [$owner->value, $id]);

        return $row === false ? null : $this->hydrate($owner, $row);
    }

    public function count(UserId $owner): int
    {
        return Row::int(['n' => $this->connection->fetchOne('SELECT COUNT(*) FROM savings_goals WHERE user_id = ?', [$owner->value])], 'n');
    }

    /** @return int identifier of the goal added */
    public function create(UserId $owner, SavingsGoal $goal): int
    {
        return $this->connection->transactional(function () use ($owner, $goal): int {
            // The identifier is part of the ciphertext's context: the row is reserved first.
            $this->connection->executeStatement('INSERT INTO savings_goals (user_id, ciphertext) VALUES (?, ?)', [$owner->value, self::PLACEHOLDER]);
            $id = (int) $this->connection->lastInsertId();
            $this->store($owner, $goal->withId($id));

            return $id;
        });
    }

    public function update(UserId $owner, SavingsGoal $goal): void
    {
        $this->store($owner, $goal);
    }

    public function delete(UserId $owner, int $id): void
    {
        $this->connection->executeStatement('DELETE FROM savings_goals WHERE id = ? AND user_id = ?', [$id, $owner->value]);
    }

    /** Encrypts all the owner's rows again: the current key is already open, $newKey replaces it. */
    public function reencryptAll(UserId $owner, #[\SensitiveParameter] string $newKey): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, ciphertext FROM savings_goals WHERE user_id = ?', [$owner->value]) as $row) {
            $id = Row::int($row, 'id');
            $plain = Aead::open($this->vault->key($owner), Row::string($row, 'ciphertext'), self::context($owner, $id));
            $this->connection->executeStatement(
                'UPDATE savings_goals SET ciphertext = ? WHERE id = ? AND user_id = ?',
                [Aead::seal($newKey, $plain, self::context($owner, $id)), $id, $owner->value],
            );
        }
    }

    private function store(UserId $owner, SavingsGoal $goal): void
    {
        $payload = [
            'name' => $goal->name,
            'target_cents' => $goal->targetCents,
            'target_month' => $goal->targetMonth->toString(),
            'scope' => $goal->scope->value,
            'level' => $goal->level?->value,
            'accounts' => $goal->accountIds,
            'start_month' => $goal->startMonth->toString(),
            'start_cents' => $goal->startCents,
            'spending_months' => $goal->spendingMonths,
        ];
        $sealed = Aead::seal($this->vault->key($owner), json_encode($payload, JSON_THROW_ON_ERROR), self::context($owner, $goal->id));
        $this->connection->executeStatement('UPDATE savings_goals SET ciphertext = ? WHERE id = ? AND user_id = ?', [$sealed, $goal->id, $owner->value]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(UserId $owner, array $row): SavingsGoal
    {
        $id = Row::int($row, 'id');
        $payload = json_decode(Aead::open($this->vault->key($owner), Row::string($row, 'ciphertext'), self::context($owner, $id)), true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \UnexpectedValueException('Objectif déchiffré invalide.');
        }
        /** @var array<string, mixed> $payload */
        $level = Row::nullableString($payload, 'level');

        return new SavingsGoal(
            $id,
            Row::string($payload, 'name'),
            Row::int($payload, 'target_cents'),
            Month::fromString(Row::string($payload, 'target_month')),
            GoalScope::from(Row::string($payload, 'scope')),
            $level === null ? null : Level::from($level),
            self::ids($payload['accounts'] ?? []),
            Month::fromString(Row::string($payload, 'start_month')),
            Row::int($payload, 'start_cents'),
            isset($payload['spending_months']) && is_int($payload['spending_months']) ? $payload['spending_months'] : null,
        );
    }

    /** @return list<int> */
    private static function ids(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_int(...))) : [];
    }

    private static function context(UserId $owner, int $id): string
    {
        return "savings_goals:$id:{$owner->value}:1";
    }
}
