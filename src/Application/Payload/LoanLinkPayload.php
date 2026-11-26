<?php

declare(strict_types=1);

namespace Patrimeter\Application\Payload;

use Patrimeter\Domain\LoanMode;
use Symfony\Component\Validator\Constraints as Assert;

/** Linking of a loan to a property, or unlinking with a null property. */
final class LoanLinkPayload
{
    public function __construct(
        #[Assert\Positive(message: 'Choisissez un bien, pour un crédit.')]
        #[Assert\Type('integer', message: 'Choisissez un bien, pour un crédit.')]
        public readonly mixed $asset_id = null,
        #[Assert\Choice(callback: [LoanMode::class, 'values'], message: 'Mode : prorata ou own.')]
        public readonly ?string $loan_mode = null,
    ) {}
}
