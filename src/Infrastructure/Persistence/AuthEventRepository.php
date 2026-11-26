<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Patrimeter\Domain\UserId;

/**
 * Log of sign-ins and security actions (`auth_events` table).
 *
 * @phpstan-type AuthEvent array{type: string, success: bool, ip: ?string, user_agent: ?string, created_at: string}
 */
final class AuthEventRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function add(UserId $user, string $type, bool $success, ?string $ip, ?string $userAgent, string $now): void
    {
        $this->connection->insert('auth_events', [
            'user_id' => $user->value,
            'type' => $type,
            'success' => $success ? 1 : 0,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'created_at' => $now,
        ]);
    }

    /** @return list<AuthEvent> the most recent events first */
    public function recent(UserId $user, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT type, success, ip, user_agent, created_at FROM auth_events WHERE user_id = ?
             ORDER BY created_at DESC, id DESC LIMIT ?',
            [$user->value, $limit],
            [ParameterType::STRING, ParameterType::INTEGER],
        );

        return array_map(static fn(array $row): array => [
            'type' => Row::string($row, 'type'),
            'success' => Row::int($row, 'success') === 1,
            'ip' => Row::nullableString($row, 'ip'),
            'user_agent' => Row::nullableString($row, 'user_agent'),
            'created_at' => Row::string($row, 'created_at'),
        ], $rows);
    }

    /** Deletes the events before the given date (retention period). */
    public function purge(string $before): void
    {
        $this->connection->executeStatement('DELETE FROM auth_events WHERE created_at < ?', [$before]);
    }
}
