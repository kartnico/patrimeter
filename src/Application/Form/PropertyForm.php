<?php

declare(strict_types=1);

namespace Patrimeter\Application\Form;

use Patrimeter\Domain\LoanMode;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\OwnershipShares;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Form of a property and its loans, validated by Symfony constraints. A completely empty loan line
 * is ignored.
 *
 * @phpstan-type LoanInput array{name: string, amount: string, mode: string}
 */
final class PropertyForm
{
    /** @var array<string, string> errors by field (loans.N for a loan line), translated on display */
    public array $errors;
    public int $valueCents = 0;
    public int $share = OwnershipShares::FULL;
    /** @var list<array{name: string, cents: int, mode: LoanMode}> */
    public array $loans = [];
    public string $name;
    public string $openedOn;

    /** @param list<LoanInput> $loanInputs */
    private function __construct(
        string $name,
        public readonly string $typeId,
        string $openedOn,
        public readonly string $value,
        public readonly string $sharePercent,
        public readonly array $loanInputs,
        ValidatorInterface $validator,
        string $locale,
    ) {
        $this->name = trim($name);
        $this->openedOn = trim($openedOn);
        $loans = [];
        foreach ($loanInputs as $index => $loan) {
            $loanName = trim($loan['name']);
            if ($loanName !== '' || trim($loan['amount']) !== '') {
                $loans[$index] = ['name' => $loanName, 'amount' => $loan['amount']];
            }
        }
        $positiveAmount = static function (mixed $amount, ExecutionContextInterface $context) use ($locale): void {
            self::validatePositiveAmount($amount, $context, $locale);
        };
        $this->errors = Violations::byField($validator->validate([
            'name' => $this->name,
            'opened_on' => $this->openedOn,
            'value' => $value,
            'share' => $sharePercent === '' ? '100' : $sharePercent,
            'loans' => $loans,
        ], new Assert\Collection(fields: [
            'name' => AccountForm::name('Donnez un nom au bien (80 caractères au plus).'),
            'opened_on' => new Assert\Sequentially([new Assert\NotBlank(message: 'Date invalide.'), new Assert\Date(message: 'Date invalide.')]),
            'value' => new Assert\Callback($positiveAmount),
            'share' => new Assert\Callback(self::validateShare(...)),
            'loans' => new Assert\All(new Assert\Collection(fields: [
                'name' => AccountForm::name('Donnez un nom au crédit (80 caractères au plus).'),
                'amount' => new Assert\Callback($positiveAmount),
            ])),
        ])));
        if (!isset($this->errors['value'])) {
            $this->valueCents = Money::parse($value, $locale);
        }
        $this->share = OwnershipShares::parsePercent($sharePercent === '' ? '100' : $sharePercent) ?? OwnershipShares::FULL;
        foreach ($loans as $index => $loan) {
            if (!isset($this->errors["loans.$index"])) {
                $this->loans[] = ['name' => $loan['name'], 'cents' => Money::parse($loan['amount'], $locale), 'mode' => LoanMode::tryFrom($loanInputs[$index]['mode']) ?? LoanMode::Prorata];
            }
        }
    }

    /**
     * @param list<LoanInput> $loanInputs
     * @param string          $locale     language of the page, to read the amounts
     */
    public static function validate(ValidatorInterface $validator, string $name, string $typeId, string $openedOn, string $value, string $sharePercent, array $loanInputs, string $locale = 'fr'): self
    {
        return new self($name, $typeId, $openedOn, $value, $sharePercent, $loanInputs, $validator, $locale);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public static function validatePositiveAmount(mixed $value, ExecutionContextInterface $context, string $locale = 'fr'): void
    {
        try {
            if (Money::parse(is_string($value) ? $value : '', $locale) < 0) {
                $context->addViolation('Le montant ne peut pas être négatif.');
            }
        } catch (\InvalidArgumentException $e) {
            $context->addViolation($e->getMessage());
        }
    }

    public static function validateShare(mixed $value, ExecutionContextInterface $context): void
    {
        if (OwnershipShares::parsePercent(is_string($value) ? $value : '') === null) {
            $context->addViolation('Part invalide : un pourcentage supérieur à 0 et au plus 100.');
        }
    }
}
