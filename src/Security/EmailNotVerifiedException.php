<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * Correct password, but email address not confirmed yet. Reported only to whoever knows the
 * password: Symfony therefore does not hide it as "invalid credentials".
 */
final class EmailNotVerifiedException extends CustomUserMessageAccountStatusException
{
    public function __construct()
    {
        parent::__construct('Adresse e-mail pas encore confirmée.');
    }
}
