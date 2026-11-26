<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\Account;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\LoanMode;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Crypto\Aead;
use Patrimeter\Security\Vault\Vault;

/**
 * Accounts and loans of a user, encrypted with their data key.
 *
 * Isolation: each method requires the owner and each query filters on `user_id` (checked by
 * tests/Architecture/OwnedDataQueriesTest); another user's account cannot be found, exactly like a non-existent
 * account. The ciphertext is tied to its row and to its owner. The sorting (level, nature, name) is therefore done
 * after decryption.
 *
 * @phpstan-type AccountData array{name: string, type_id: int, level_code: string, nature: string, opened_on: string}
 * @phpstan-type AccountPayload array{name: string, type_id: int, level_code: string, nature: string, opened_on: string, closed_on: ?string, linked_asset_id: ?int, loan_mode: string}
 */
final class AccountRepository
{
    private const string PLACEHOLDER = 'en-cours';

    public function __construct(
        private readonly Connection $connection,
        private readonly Vault $vault,
        private readonly AccountTypeRepository $types,
    ) {}

    /** @return list<Account> all the owner's accounts, sorted by level, nature then name */
    public function all(UserId $owner): array
    {
        $accounts = array_map(
            fn(array $row): Account => $this->hydrate($owner, $row),
            $this->connection->fetchAllAssociative('SELECT id, ciphertext FROM accounts WHERE user_id = ?', [$owner->value]),
        );
        usort($accounts, self::compare(...));

        return $accounts;
    }

    /** @return list<Account> accounts counted for the given month */
    public function activeIn(UserId $owner, Month $month): array
    {
        return array_values(array_filter($this->all($owner), static fn(Account $a): bool => $a->isActiveIn($month)));
    }

    public function find(UserId $owner, int $id): ?Account
    {
        $row = $this->connection->fetchAssociative('SELECT id, ciphertext FROM accounts WHERE user_id = ? AND id = ?', [$owner->value, $id]);

        return $row === false ? null : $this->hydrate($owner, $row);
    }

    /** @param AccountData $data */
    public function create(UserId $owner, array $data): int
    {
        return $this->connection->transactional(function () use ($owner, $data): int {
            // The identifier is part of the ciphertext's context: the row is reserved first.
            $this->connection->executeStatement('INSERT INTO accounts (user_id, ciphertext) VALUES (?, ?)', [$owner->value, self::PLACEHOLDER]);
            $id = (int) $this->connection->lastInsertId();
            $this->store($owner, $id, $data + ['closed_on' => null, 'linked_asset_id' => null, 'loan_mode' => LoanMode::Prorata->value]);

            return $id;
        });
    }

    /** @param AccountData $data */
    public function update(UserId $owner, int $id, array $data): void
    {
        $account = $this->find($owner, $id);
        if ($account !== null) {
            $this->store($owner, $id, $data + ['closed_on' => $account->closedOn, 'linked_asset_id' => $account->linkedAssetId, 'loan_mode' => $account->loanMode->value]);
        }
    }

    /**
     * Moves an account's opening date back (never forward): entry of a past month taken from a month where the
     * account already existed in Patrimeter.
     */
    public function advanceOpening(UserId $owner, int $id, string $openedOn): void
    {
        $account = $this->find($owner, $id);
        if ($account !== null && $openedOn < $account->openedOn) {
            $this->store($owner, $id, ['opened_on' => $openedOn] + self::payload($account));
        }
    }

    public function archive(UserId $owner, int $id, string $closedOn): void
    {
        $this->setClosedOn($owner, $id, $closedOn);
    }

    public function unarchive(UserId $owner, int $id): void
    {
        $this->setClosedOn($owner, $id, null);
    }

    /** Deletes the account and, by cascade, its statements and its shares; its linked loans are unlinked. */
    public function delete(UserId $owner, int $id): void
    {
        $this->connection->transactional(function () use ($owner, $id): void {
            foreach ($this->all($owner) as $account) {
                if ($account->linkedAssetId === $id) {
                    $this->linkLoan($owner, $account->id, null, LoanMode::Prorata);
                }
            }
            $this->connection->executeStatement('DELETE FROM accounts WHERE id = ? AND user_id = ?', [$id, $owner->value]);
        });
    }

    public function deleteAll(UserId $owner): void
    {
        $this->connection->executeStatement('DELETE FROM accounts WHERE user_id = ?', [$owner->value]);
    }

    /**
     * Encrypts all the owner's rows again: the current key is already open, $newKey replaces it.
     */
    public function reencryptAll(UserId $owner, #[\SensitiveParameter] string $newKey): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, ciphertext FROM accounts WHERE user_id = ?', [$owner->value]);
        foreach ($rows as $row) {
            $id = Row::int($row, 'id');
            $plain = Aead::open($this->vault->key($owner), Row::string($row, 'ciphertext'), self::context($owner, $id));
            $this->connection->executeStatement(
                'UPDATE accounts SET ciphertext = ? WHERE id = ? AND user_id = ?',
                [Aead::seal($newKey, $plain, self::context($owner, $id)), $id, $owner->value],
            );
        }
    }

    /** Links a loan to a property, or unlinks it ($assetId null). */
    public function linkLoan(UserId $owner, int $id, ?int $assetId, LoanMode $mode): void
    {
        $account = $this->find($owner, $id);
        if ($account !== null) {
            $this->store($owner, $id, ['linked_asset_id' => $assetId, 'loan_mode' => $mode->value] + self::payload($account));
        }
    }

    private function setClosedOn(UserId $owner, int $id, ?string $closedOn): void
    {
        $account = $this->find($owner, $id);
        if ($account !== null) {
            $this->store($owner, $id, ['closed_on' => $closedOn] + self::payload($account));
        }
    }

    /** @return AccountPayload */
    private static function payload(Account $account): array
    {
        return [
            'name' => $account->name,
            'type_id' => $account->typeId,
            'level_code' => $account->level->value,
            'nature' => $account->nature->value,
            'opened_on' => $account->openedOn,
            'closed_on' => $account->closedOn,
            'linked_asset_id' => $account->linkedAssetId,
            'loan_mode' => $account->loanMode->value,
        ];
    }

    /** @param AccountPayload $payload */
    private function store(UserId $owner, int $id, array $payload): void
    {
        $sealed = Aead::seal($this->vault->key($owner), json_encode($payload, JSON_THROW_ON_ERROR), self::context($owner, $id));
        $this->connection->executeStatement('UPDATE accounts SET ciphertext = ? WHERE id = ? AND user_id = ?', [$sealed, $id, $owner->value]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(UserId $owner, array $row): Account
    {
        $id = Row::int($row, 'id');
        $payload = json_decode(Aead::open($this->vault->key($owner), Row::string($row, 'ciphertext'), self::context($owner, $id)), true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \UnexpectedValueException('Compte déchiffré invalide.');
        }
        /** @var array<string, mixed> $payload */
        $typeId = Row::int($payload, 'type_id');

        return new Account(
            $id,
            Row::string($payload, 'name'),
            $typeId,
            $this->types->label($typeId),
            Level::from(Row::string($payload, 'level_code')),
            Nature::from(Row::string($payload, 'nature')),
            Row::string($payload, 'opened_on'),
            Row::nullableString($payload, 'closed_on'),
            $owner,
            self::linkedAsset($payload['linked_asset_id'] ?? null),
            ($payload['loan_mode'] ?? null) === LoanMode::Own->value ? LoanMode::Own : LoanMode::Prorata,
        );
    }

    private static function linkedAsset(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /** Level, nature, name regardless of case, identifier. */
    private static function compare(Account $a, Account $b): int
    {
        return [$a->level->position(), strcmp($a->nature->value, $b->nature->value), strcmp(strtolower($a->name), strtolower($b->name)), $a->id]
            <=> [$b->level->position(), 0, 0, $b->id];
    }

    private static function context(UserId $owner, int $id): string
    {
        return "accounts:$id:{$owner->value}:1";
    }
}
