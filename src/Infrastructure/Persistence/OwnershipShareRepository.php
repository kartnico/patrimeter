<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Crypto\Aead;
use Patrimeter\Security\Vault\Vault;

/** Ownership shares of a user's properties: percentage encrypted, account and month of effect in clear. */
final class OwnershipShareRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Vault $vault,
    ) {}

    /** @return array<int, array<string, int>> account => (month of effect => millionths), ascending months */
    public function all(UserId $owner): array
    {
        $result = [];
        foreach ($this->connection->fetchAllAssociative('SELECT account_id, effective_month, ciphertext FROM ownership_shares WHERE user_id = ? ORDER BY account_id, effective_month', [$owner->value]) as $row) {
            $accountId = Row::int($row, 'account_id');
            $month = Row::string($row, 'effective_month');
            $result[$accountId][$month] = Row::int(['share' => Aead::open($this->vault->key($owner), Row::string($row, 'ciphertext'), self::context($owner, $accountId, $month))], 'share');
        }

        return $result;
    }

    public function save(UserId $owner, int $accountId, Month $month, int $share): void
    {
        $sealed = Aead::seal($this->vault->key($owner), (string) $share, self::context($owner, $accountId, $month->toString()));
        $this->connection->executeStatement(
            'INSERT INTO ownership_shares (account_id, user_id, effective_month, ciphertext) VALUES (?, ?, ?, ?)
             ON CONFLICT(account_id, effective_month) DO UPDATE SET ciphertext = excluded.ciphertext WHERE ownership_shares.user_id = excluded.user_id',
            [$accountId, $owner->value, $month->toString(), $sealed],
        );
    }

    public function delete(UserId $owner, int $accountId, Month $month): void
    {
        $this->connection->executeStatement('DELETE FROM ownership_shares WHERE user_id = ? AND account_id = ? AND effective_month = ?', [$owner->value, $accountId, $month->toString()]);
    }

    /** Encrypts all the owner's shares again: the current key is already open, $newKey replaces it. */
    public function reencryptAll(UserId $owner, #[\SensitiveParameter] string $newKey): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT account_id, effective_month, ciphertext FROM ownership_shares WHERE user_id = ?', [$owner->value]) as $row) {
            $accountId = Row::int($row, 'account_id');
            $month = Row::string($row, 'effective_month');
            $context = self::context($owner, $accountId, $month);
            $this->connection->executeStatement(
                'UPDATE ownership_shares SET ciphertext = ? WHERE user_id = ? AND account_id = ? AND effective_month = ?',
                [Aead::seal($newKey, Aead::open($this->vault->key($owner), Row::string($row, 'ciphertext'), $context), $context), $owner->value, $accountId, $month],
            );
        }
    }

    private static function context(UserId $owner, int $accountId, string $month): string
    {
        return "ownership_shares:$accountId:$month:{$owner->value}:1";
    }
}
