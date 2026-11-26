<?php

declare(strict_types=1);

namespace Patrimeter\Application\Family;

/**
 * Perimeter shown by the dashboard, the history, the report and the budget of a family account: the family (its
 * members and this account), one member alone, or this account's own data. Query and session key: "famille", "compte"
 * or "membre-<identifier>".
 */
final class Perimeter
{
    public const string FAMILY = 'famille';
    public const string OWN = 'compte';
    private const string MEMBER_PREFIX = 'membre-';

    private function __construct(
        public readonly string $key,
        public readonly ?int $memberId,
    ) {}

    public static function own(): self
    {
        return new self(self::OWN, null);
    }

    public static function family(): self
    {
        return new self(self::FAMILY, null);
    }

    public static function member(int $id): self
    {
        return new self(self::MEMBER_PREFIX . $id, $id);
    }

    /** Perimeter of a received key; null if malformed (whether it exists is checked separately). */
    public static function fromKey(string $key): ?self
    {
        return match (true) {
            $key === self::OWN => self::own(),
            $key === self::FAMILY => self::family(),
            preg_match('/\A' . self::MEMBER_PREFIX . '([1-9]\d{0,17})\z/', $key, $m) === 1 => self::member((int) $m[1]),
            default => null,
        };
    }

    public function isOwn(): bool
    {
        return $this->key === self::OWN;
    }

    public function isFamily(): bool
    {
        return $this->key === self::FAMILY;
    }
}
