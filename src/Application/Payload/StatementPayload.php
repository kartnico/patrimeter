<?php

declare(strict_types=1);

namespace Patrimeter\Application\Payload;

use Patrimeter\Domain\Money;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Statement of a month, in cents; for a loan, the outstanding principal, positive. The numbers received are
 * declared mixed: the serializer keeps them as they are, and Type('integer') refuses a decimal instead of it being
 * silently truncated. After validation, they are integers.
 */
final class StatementPayload
{
    public function __construct(
        #[Assert\NotNull(message: 'Montant en centimes attendu.')]
        #[Assert\Range(min: -Money::MAX_CENTS, max: Money::MAX_CENTS, notInRangeMessage: 'Montant trop grand.')]
        #[Assert\Type('integer', message: 'Montant en centimes attendu.')]
        public readonly mixed $amount_cents = null,
    ) {}
}
