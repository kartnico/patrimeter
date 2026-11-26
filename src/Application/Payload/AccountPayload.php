<?php

declare(strict_types=1);

namespace Patrimeter\Application\Payload;

use Patrimeter\Domain\Money;
use Symfony\Component\Validator\Constraints as Assert;

/** Account to create or change through the API or MCP. Level and nature missing: those of the type. */
final class AccountPayload
{
    public function __construct(
        #[Assert\NotBlank(message: 'Indiquez un nom de 1 à 80 caractères.')]
        public readonly string $name = '',
        #[Assert\NotNull(message: 'Choisissez un type de compte.')]
        #[Assert\Positive(message: 'Choisissez un type de compte.')]
        #[Assert\Type('integer', message: 'Choisissez un type de compte.')]
        public readonly mixed $type_id = null,
        public readonly ?string $level = null,
        public readonly ?string $nature = null,
        #[Assert\NotBlank(message: 'Date d\'ouverture invalide (AAAA-MM-JJ).')]
        public readonly string $opened_on = '',
        #[Assert\Range(min: -Money::MAX_CENTS, max: Money::MAX_CENTS, notInRangeMessage: 'Montant trop grand.')]
        #[Assert\Type('integer', message: 'Montant en centimes attendu.')]
        public readonly mixed $initial_amount_cents = null,
    ) {}
}
