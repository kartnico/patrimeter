<?php

declare(strict_types=1);

namespace Patrimeter\Application\Form;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\Nature;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Form of an account (pages and API), validated by Symfony constraints. Empty level and nature:
 * those of the chosen type.
 */
final class AccountForm
{
    /** @var array<string, string> errors by field (translated on display by the template) */
    public array $errors;

    /** @var array{name:string,type_id:int,level_code:string,nature:string,opened_on:string} */
    public array $data;

    /** Optional initial balance (creation only). */
    public ?int $initialCents = null;

    /**
     * @param array<string, string>                                                            $raw   values entered, to show the form again
     * @param list<array{id:int,code:string,label:string,default_level:string,nature:string}> $types
     */
    private function __construct(public array $raw, array $types, bool $withInitialAmount, ValidatorInterface $validator, string $locale)
    {
        $type = null;
        foreach ($types as $candidate) {
            if ((string) $candidate['id'] === $raw['type_id']) {
                $type = $candidate;
            }
        }
        $level = $raw['level_code'] !== '' ? $raw['level_code'] : ($type['default_level'] ?? '');
        $nature = $raw['nature'] !== '' ? $raw['nature'] : ($type['nature'] ?? '');
        $values = ['level_code' => $level, 'nature' => $nature] + $raw;

        $this->errors = Violations::byField($validator->validate($values, new Assert\Collection(fields: [
            'name' => self::name('Indiquez un nom de 1 à 80 caractères.'),
            'type_id' => new Assert\Choice(choices: array_map(static fn(array $t): string => (string) $t['id'], $types), message: 'Choisissez un type de compte.'),
            'level_code' => new Assert\Choice(choices: array_map(static fn(Level $l): string => $l->value, Level::cases()), message: 'Choisissez un niveau de pyramide.'),
            'nature' => new Assert\Choice(choices: Nature::values(), message: 'Choisissez actif ou passif.'),
            'opened_on' => new Assert\Sequentially([
                new Assert\NotBlank(message: 'Date d\'ouverture invalide (AAAA-MM-JJ).'),
                new Assert\Date(message: 'Date d\'ouverture invalide (AAAA-MM-JJ).'),
            ]),
            'initial_amount' => $withInitialAmount
                ? new Assert\Callback(static function (mixed $value, ExecutionContextInterface $context) use ($locale): void {
                    self::validateAmount($value, $context, $locale);
                })
                : new Assert\Blank(),
        ])));
        if ($withInitialAmount && $raw['initial_amount'] !== '' && !isset($this->errors['initial_amount'])) {
            $this->initialCents = Money::parse($raw['initial_amount'], $locale);
        }
        $this->data = [
            'name' => $raw['name'],
            'type_id' => (int) $raw['type_id'],
            'level_code' => $level,
            'nature' => $nature,
            'opened_on' => $raw['opened_on'],
        ];
    }

    /**
     * @param array<string, string>                                                            $input
     * @param list<array{id:int,code:string,label:string,default_level:string,nature:string}> $types
     * @param string                                                                           $locale language of the page, to read the initial balance
     */
    public static function validate(ValidatorInterface $validator, array $input, array $types, bool $withInitialAmount = false, string $locale = 'fr'): self
    {
        return new self([
            'name' => trim($input['name'] ?? ''),
            'type_id' => $input['type_id'] ?? '',
            'level_code' => $input['level_code'] ?? '',
            'nature' => $input['nature'] ?? '',
            'opened_on' => trim($input['opened_on'] ?? ''),
            'initial_amount' => trim($input['initial_amount'] ?? ''),
        ], $types, $withInitialAmount, $validator, $locale);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** Name required, valid UTF-8, no control character, 80 characters at most (Regex ignores the empty value). */
    public static function name(string $message): Assert\Sequentially
    {
        return new Assert\Sequentially([new Assert\NotBlank(message: $message), new Assert\Regex(pattern: Account::NAME_PATTERN, message: $message)]);
    }

    /** Optional amount, in the format of the page's language. */
    public static function validateAmount(mixed $value, ExecutionContextInterface $context, string $locale = 'fr'): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }
        try {
            Money::parse($value, $locale);
        } catch (\InvalidArgumentException $e) {
            $context->addViolation($e->getMessage());
        }
    }
}
