<?php

declare(strict_types=1);

namespace Patrimeter\Application;

/** Data refused by the business rules, field by field. */
final class ValidationFailed extends \DomainException
{
    /** @param array<string, string> $errors field => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
