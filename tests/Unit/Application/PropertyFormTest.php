<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Application;

use Patrimeter\Application\Form\PropertyForm;
use Patrimeter\Domain\LoanMode;
use PHPUnit\Framework\TestCase;

final class PropertyFormTest extends TestCase
{
    public function testValidPropertyWithLoansIgnoringEmptyRows(): void
    {
        $form = PropertyForm::validate(self::validator(), ' Maison ', '1', '2024-01-15', '300 000', '50', [
            ['name' => 'Prêt', 'amount' => '100 000,50', 'mode' => 'own'],
            ['name' => '', 'amount' => '', 'mode' => 'prorata'],
            ['name' => 'Second', 'amount' => '10', 'mode' => 'autre'],
        ]);

        self::assertTrue($form->isValid());
        self::assertSame('Maison', $form->name);
        self::assertSame(30_000_000, $form->valueCents);
        self::assertSame(500_000, $form->share);
        self::assertSame([
            ['name' => 'Prêt', 'cents' => 10_000_050, 'mode' => LoanMode::Own],
            ['name' => 'Second', 'cents' => 1_000, 'mode' => LoanMode::Prorata],
        ], $form->loans);
    }

    public function testAmountsAreReadInTheLanguageOfThePage(): void
    {
        $english = PropertyForm::validate(self::validator(), 'House', '1', '2024-01-15', '300,000', '50', [
            ['name' => 'Loan', 'amount' => '100,000.50', 'mode' => 'own'],
        ], 'en');
        self::assertTrue($english->isValid());
        self::assertSame(30_000_000, $english->valueCents);
        self::assertSame(10_000_050, $english->loans[0]['cents']);

        // In French, "300,000" reads with three decimals: refused, never counted as €300.
        self::assertArrayHasKey('value', PropertyForm::validate(self::validator(), 'Maison', '1', '2024-01-15', '300,000', '50', [])->errors);
    }

    public function testEmptyShareMeansFullOwnership(): void
    {
        self::assertSame(1_000_000, (PropertyForm::validate(self::validator(), 'M', '1', '2024-01-15', '1', '', []))->share);
    }

    public function testNegativeAmountsAreRefused(): void
    {
        $form = PropertyForm::validate(self::validator(), 'M', '1', '2024-01-15', '-1', '100', [['name' => 'Prêt', 'amount' => '-10', 'mode' => 'own']]);

        self::assertSame(['value', 'loans.0'], array_keys($form->errors));
    }

    public function testErrorsAreReportedByField(): void
    {
        $form = PropertyForm::validate(self::validator(), '', '1', '2024-13-45', 'abc', '150', [
            ['name' => '', 'amount' => '10', 'mode' => 'prorata'],
            ['name' => 'Prêt', 'amount' => 'abc', 'mode' => 'prorata'],
        ]);

        self::assertFalse($form->isValid());
        self::assertSame(['name', 'opened_on', 'value', 'share', 'loans.0', 'loans.1'], array_keys($form->errors));
    }

    public function testNamesMustBeValidTextWithoutControlCharacters(): void
    {
        self::assertArrayHasKey('name', (PropertyForm::validate(self::validator(), "\xFF", '1', '2024-01-15', '1', '100', []))->errors);
        self::assertArrayHasKey('name', (PropertyForm::validate(self::validator(), "A\x07B", '1', '2024-01-15', '1', '100', []))->errors);
        self::assertArrayHasKey('loans.0', (PropertyForm::validate(self::validator(), 'M', '1', '2024-01-15', '1', '100', [['name' => "\xFF", 'amount' => '1', 'mode' => 'own']]))->errors);
    }

    private static function validator(): \Symfony\Component\Validator\Validator\ValidatorInterface
    {
        return \Symfony\Component\Validator\Validation::createValidator();
    }
}
