<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Domain\PasswordPolicy;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\PasswordStrength;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Sequentially;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Rules of a new password, with Symfony's constraints: length, without the email address, unpredictable enough
 * (PasswordStrength, entropy of at least 80 bits), absent from known leaks (NotCompromisedPassword). Stops at the
 * first broken rule: a password that is too short is not sent to the leak service.
 *
 * NotCompromisedPassword queries the Have I Been Pwned API with k-anonymity: only the first 5 characters of the
 * SHA-1 fingerprint are sent. Service unreachable: the rule is skipped.
 */
final class PasswordRules
{
    public function __construct(private readonly ValidatorInterface $validator) {}

    /** Reason for the refusal, or null if the password is acceptable. */
    public function violation(#[\SensitiveParameter] string $password, ?string $email = null): ?string
    {
        $rules = [
            new Length(min: PasswordPolicy::MIN_LENGTH, minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères.'),
            new Length(max: PasswordPolicy::MAX_BYTES, countUnit: Length::COUNT_BYTES, maxMessage: 'Le mot de passe est trop long.'),
        ];
        if ($email !== null && $email !== '') {
            $rules[] = new Regex(pattern: '/' . preg_quote($email, '/') . '/i', match: false, message: 'Le mot de passe ne doit pas contenir votre adresse e-mail.');
        }
        $rules[] = new PasswordStrength(minScore: PasswordStrength::STRENGTH_MEDIUM, message: 'Mot de passe trop prévisible : allongez-le (une phrase de plusieurs mots convient très bien).');
        $rules[] = new NotCompromisedPassword(message: 'Ce mot de passe figure dans des fuites de données connues : choisissez-en un autre.', skipOnError: true);

        $violations = $this->validator->validate($password, new Sequentially($rules));

        return count($violations) === 0 ? null : (string) $violations->get(0)->getMessage();
    }
}
