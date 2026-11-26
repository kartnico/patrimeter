<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/** Identifier of a user (UUID, canonical lowercase form). */
final class UserId
{
    private const string PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /** @param non-empty-string $value */
    private function __construct(public readonly string $value) {}

    /** @throws \InvalidArgumentException if the value is not a canonical UUID */
    public static function fromString(string $value): self
    {
        if ($value === '' || preg_match(self::PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException('Identifiant d\'utilisateur invalide.');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
