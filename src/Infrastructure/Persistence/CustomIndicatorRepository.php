<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\Indicator\CustomIndicator;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Crypto\Aead;
use Patrimeter\Security\Vault\Vault;

/**
 * Custom indicators: everything encrypted with the owner's data key (context tied to the row and to the owner), as the
 * savings goals. Left in clear: the number of indicators. Isolation: each query filters on `user_id`
 * (tests/Architecture/OwnedDataQueriesTest).
 */
final class CustomIndicatorRepository
{
    private const string PLACEHOLDER = 'en-cours';
    /** At most this many indicators per user. */
    public const int MAX_INDICATORS = 10;

    public function __construct(
        private readonly Connection $connection,
        private readonly Vault $vault,
    ) {}

    /** @return list<CustomIndicator> in the order of creation */
    public function all(UserId $owner): array
    {
        return array_map(
            fn(array $row): CustomIndicator => $this->hydrate($owner, $row),
            $this->connection->fetchAllAssociative('SELECT id, ciphertext FROM custom_indicators WHERE user_id = ? ORDER BY id', [$owner->value]),
        );
    }

    public function find(UserId $owner, int $id): ?CustomIndicator
    {
        $row = $this->connection->fetchAssociative('SELECT id, ciphertext FROM custom_indicators WHERE user_id = ? AND id = ?', [$owner->value, $id]);

        return $row === false ? null : $this->hydrate($owner, $row);
    }

    public function count(UserId $owner): int
    {
        return Row::int(['n' => $this->connection->fetchOne('SELECT COUNT(*) FROM custom_indicators WHERE user_id = ?', [$owner->value])], 'n');
    }

    /** @return int identifier of the indicator added */
    public function create(UserId $owner, CustomIndicator $indicator): int
    {
        return $this->connection->transactional(function () use ($owner, $indicator): int {
            // The identifier is part of the ciphertext's context: the row is reserved first.
            $this->connection->executeStatement('INSERT INTO custom_indicators (user_id, ciphertext) VALUES (?, ?)', [$owner->value, self::PLACEHOLDER]);
            $id = (int) $this->connection->lastInsertId();
            $this->store($owner, $indicator->withId($id));

            return $id;
        });
    }

    public function update(UserId $owner, CustomIndicator $indicator): void
    {
        $this->store($owner, $indicator);
    }

    public function delete(UserId $owner, int $id): void
    {
        $this->connection->executeStatement('DELETE FROM custom_indicators WHERE id = ? AND user_id = ?', [$id, $owner->value]);
    }

    /** Encrypts all the owner's rows again: the current key is already open, $newKey replaces it. */
    public function reencryptAll(UserId $owner, #[\SensitiveParameter] string $newKey): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, ciphertext FROM custom_indicators WHERE user_id = ?', [$owner->value]) as $row) {
            $id = Row::int($row, 'id');
            $plain = Aead::open($this->vault->key($owner), Row::string($row, 'ciphertext'), self::context($owner, $id));
            $this->connection->executeStatement(
                'UPDATE custom_indicators SET ciphertext = ? WHERE id = ? AND user_id = ?',
                [Aead::seal($newKey, $plain, self::context($owner, $id)), $id, $owner->value],
            );
        }
    }

    private function store(UserId $owner, CustomIndicator $indicator): void
    {
        $payload = [
            'name' => $indicator->name,
            'levels' => array_map(static fn(Level $level): string => $level->value, $indicator->levels),
            'accounts' => $indicator->accountIds,
            'share_of_net_worth' => $indicator->shareOfNetWorth,
        ];
        $sealed = Aead::seal($this->vault->key($owner), json_encode($payload, JSON_THROW_ON_ERROR), self::context($owner, (int) $indicator->id));
        $this->connection->executeStatement('UPDATE custom_indicators SET ciphertext = ? WHERE id = ? AND user_id = ?', [$sealed, $indicator->id, $owner->value]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(UserId $owner, array $row): CustomIndicator
    {
        $id = Row::int($row, 'id');
        $payload = json_decode(Aead::open($this->vault->key($owner), Row::string($row, 'ciphertext'), self::context($owner, $id)), true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \UnexpectedValueException('Indicateur déchiffré invalide.');
        }
        /** @var array<string, mixed> $payload */
        return new CustomIndicator(
            $id,
            Row::string($payload, 'name'),
            self::levels($payload['levels'] ?? null),
            self::ids($payload['accounts'] ?? null),
            ($payload['share_of_net_worth'] ?? false) === true,
        );
    }

    /** @return list<Level> */
    private static function levels(mixed $codes): array
    {
        if (!is_array($codes)) {
            return [];
        }
        $levels = [];
        foreach (array_keys($codes) as $key) {
            $level = is_string($codes[$key]) ? Level::tryFrom($codes[$key]) : null;
            if ($level !== null) {
                $levels[] = $level;
            }
        }

        return $levels;
    }

    /** @return list<int> */
    private static function ids(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_int(...))) : [];
    }

    private static function context(UserId $owner, int $id): string
    {
        return "custom_indicators:$id:{$owner->value}:1";
    }
}
