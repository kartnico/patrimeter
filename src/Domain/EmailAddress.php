<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/**
 * Email address entered by the user: validation (browsers' rule for
 * `<input type="email">`, maximum length of 254 characters) and normalised form
 * (no surrounding spaces, lowercase), which serves as the sign-in identifier.
 */
final class EmailAddress
{
    public const int MAX_LENGTH = 254;

    /**
     * Rule of the HTML specification for type="email" (ASCII only), without a leading, trailing or
     * doubled dot in the local part: such addresses, which the mailer refuses, cannot receive any email.
     */
    private const string PATTERN = '/\A[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*@[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/';

    /** Normalised address, or null if it is invalid. */
    public static function normalize(string $input): ?string
    {
        $email = strtolower(trim($input));
        if ($email === '' || strlen($email) > self::MAX_LENGTH || preg_match(self::PATTERN, $email) !== 1) {
            return null;
        }

        return $email;
    }
}
