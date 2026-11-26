<?php

declare(strict_types=1);

namespace Patrimeter\Application\Payload;

use Patrimeter\Domain\OwnershipShares;
use Symfony\Component\Validator\Constraints as Assert;

/** Share of a property from a month, in millionths. */
final class SharePayload
{
    public function __construct(
        #[Assert\NotNull(message: 'Part en millionièmes attendue, de 1 à 1 000 000.')]
        #[Assert\Range(min: 1, max: OwnershipShares::FULL, notInRangeMessage: 'Part en millionièmes attendue, de 1 à 1 000 000.')]
        #[Assert\Type('integer', message: 'Part en millionièmes attendue, de 1 à 1 000 000.')]
        public readonly mixed $share_millionths = null,
    ) {}
}
