<?php

declare(strict_types=1);

namespace Patrimeter\Application;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * An action refused by a business rule, with the reason to show the user (translated on display, parameters
 * included). For the fields of a form, ValidationFailed gives one message per field instead.
 */
final class Refused extends \DomainException
{
    public function __construct(public readonly TranslatableInterface $reason)
    {
        parent::__construct('Action refusée.');
    }
}
