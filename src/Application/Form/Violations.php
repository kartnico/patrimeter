<?php

declare(strict_types=1);

namespace Patrimeter\Application\Form;

use Symfony\Component\Validator\ConstraintViolationListInterface;

/** Violations of Symfony's validator, reduced to one message per field for the templates and the API. */
final class Violations
{
    /** @return array<string, string> field => first message ("[loans][0][name]" becomes "loans.0") */
    public static function byField(ConstraintViolationListInterface $violations, int $depth = 2): array
    {
        $errors = [];
        foreach ($violations as $violation) {
            $split = preg_split('/[\[\].]+/', $violation->getPropertyPath());
            $parts = array_values(array_filter($split === false ? [] : $split, static fn(string $part): bool => $part !== ''));
            $field = implode('.', array_slice($parts, 0, $depth));
            $errors[$field] ??= (string) $violation->getMessage();
        }

        return $errors;
    }
}
