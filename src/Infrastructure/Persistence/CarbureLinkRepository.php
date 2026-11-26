<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Carbure\CarbureLink;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Crypto\Aead;
use Patrimeter\Security\Vault\Vault;

/**
 * Link of each user with their Carbure instance: address, API token and category mapping, encrypted with their data
 * key (context tied to the owner). One row at most per user.
 */
final class CarbureLinkRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Vault $vault,
    ) {}

    public function find(UserId $owner): ?CarbureLink
    {
        $sealed = $this->sealed($owner);
        if ($sealed === null) {
            return null;
        }
        $payload = json_decode(Aead::open($this->vault->key($owner), $sealed, self::context($owner)), true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \UnexpectedValueException('Lien Carbure déchiffré invalide.');
        }
        /** @var array<string, mixed> $payload */
        return new CarbureLink(
            Row::string($payload, 'url'),
            Row::string($payload, 'token'),
            self::integerKeys($payload['categories'] ?? null, static fn(mixed $name): ?string => is_string($name) ? $name : null),
            self::integerKeys($payload['mapping'] ?? null, static fn(mixed $id): ?int => is_int($id) ? $id : null),
        );
    }

    public function save(UserId $owner, CarbureLink $link): void
    {
        $payload = json_encode([
            'url' => $link->url,
            'token' => $link->token,
            'categories' => (object) $link->categories,
            'mapping' => (object) $link->mapping,
        ], JSON_THROW_ON_ERROR);
        $this->connection->executeStatement(
            "INSERT INTO carbure_links (user_id, ciphertext) VALUES (?, ?)
             ON CONFLICT (user_id) DO UPDATE SET ciphertext = excluded.ciphertext, updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')",
            [$owner->value, Aead::seal($this->vault->key($owner), $payload, self::context($owner))],
        );
    }

    public function delete(UserId $owner): void
    {
        $this->connection->executeStatement('DELETE FROM carbure_links WHERE user_id = ?', [$owner->value]);
    }

    /** Encrypts the owner's link again: the current key is already open, $newKey replaces it. */
    public function reencryptAll(UserId $owner, #[\SensitiveParameter] string $newKey): void
    {
        $sealed = $this->sealed($owner);
        if ($sealed !== null) {
            $plain = Aead::open($this->vault->key($owner), $sealed, self::context($owner));
            $this->connection->executeStatement('UPDATE carbure_links SET ciphertext = ? WHERE user_id = ?', [Aead::seal($newKey, $plain, self::context($owner)), $owner->value]);
        }
    }

    /**
     * JSON object with numeric keys (Carbure identifiers), values filtered by $value.
     *
     * @template T of int|string
     *
     * @param callable(mixed): (T|null) $value
     *
     * @return array<int, T>
     */
    private static function integerKeys(mixed $data, callable $value): array
    {
        $result = [];
        if (is_array($data)) {
            foreach (array_keys($data) as $key) {
                $kept = $value($data[$key]);
                if ($kept !== null && is_numeric($key)) {
                    $result[(int) $key] = $kept;
                }
            }
        }

        return $result;
    }

    private function sealed(UserId $owner): ?string
    {
        $rows = $this->connection->fetchFirstColumn('SELECT ciphertext FROM carbure_links WHERE user_id = ?', [$owner->value]);

        return isset($rows[0]) && is_string($rows[0]) ? $rows[0] : null;
    }

    private static function context(UserId $owner): string
    {
        return "carbure_links:{$owner->value}:1";
    }
}
