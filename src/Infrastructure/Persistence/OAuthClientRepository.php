<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

/**
 * Registered OAuth clients (`oauth_clients` table): public clients, without a secret, identified by a random
 * identifier and their redirect addresses, one per line.
 *
 * @phpstan-type ClientRow array{client_id: string, client_name: string, redirect_uris: list<string>}
 */
final class OAuthClientRepository
{
    public function __construct(private readonly Connection $connection) {}

    /** @param list<string> $redirectUris */
    public function create(string $clientId, string $name, array $redirectUris, string $now): void
    {
        $this->connection->insert('oauth_clients', [
            'client_id' => $clientId,
            'client_name' => $name,
            'redirect_uris' => implode("\n", $redirectUris),
            'created_at' => $now,
        ]);
    }

    /**
     * Saves or updates a client described by a metadata document (client_id = its address).
     *
     * @param list<string> $redirectUris
     */
    public function saveDocumentClient(string $clientId, string $name, array $redirectUris, string $now): void
    {
        $this->connection->executeStatement(
            'INSERT INTO oauth_clients (client_id, client_name, redirect_uris, created_at) VALUES (?, ?, ?, ?)
             ON CONFLICT (client_id) DO UPDATE SET client_name = excluded.client_name, redirect_uris = excluded.redirect_uris',
            [$clientId, $name, implode("\n", $redirectUris), $now],
        );
    }

    /** @return ClientRow|null */
    public function find(string $clientId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT client_id, client_name, redirect_uris FROM oauth_clients WHERE client_id = ?', [$clientId]);
        if ($row === false) {
            return null;
        }

        return [
            'client_id' => Row::string($row, 'client_id'),
            'client_name' => Row::string($row, 'client_name'),
            'redirect_uris' => explode("\n", Row::string($row, 'redirect_uris')),
        ];
    }
}
