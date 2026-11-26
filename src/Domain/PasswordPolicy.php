<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/**
 * Bounds of a new password, shared by the forms and the rules of
 * Patrimeter\Security\PasswordRules (Symfony constraints), which apply them.
 */
final class PasswordPolicy
{
    public const int MIN_LENGTH = 12;
    public const int MAX_BYTES = 4096;
}
