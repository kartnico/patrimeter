<?php

declare(strict_types=1);

namespace Patrimeter\Application\Payload;

use Symfony\Component\Validator\Constraints as Assert;

/** Closing of an account. */
final class ClosePayload
{
    public function __construct(
        #[Assert\NotBlank(message: 'Date de clôture invalide (elle doit suivre la date d\'ouverture).')]
        #[Assert\Date(message: 'Date de clôture invalide (elle doit suivre la date d\'ouverture).')]
        public readonly string $closed_on = '',
    ) {}
}
