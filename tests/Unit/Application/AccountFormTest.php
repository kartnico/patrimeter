<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Application;

use Patrimeter\Application\Form\AccountForm;
use PHPUnit\Framework\TestCase;

final class AccountFormTest extends TestCase
{
    private const array TYPES = [
        ['id' => 1, 'code' => 'livret_a', 'label' => 'Livret A', 'default_level' => 'precaution', 'nature' => 'asset'],
        ['id' => 2, 'code' => 'credit_immo_rp', 'label' => 'Crédit RP', 'default_level' => 'residence', 'nature' => 'liability'],
    ];

    public function testDefaultsFromType(): void
    {
        $form = AccountForm::validate(self::validator(), ['name' => ' Prêt maison ', 'type_id' => '2', 'opened_on' => '2020-05-01'], self::TYPES);
        self::assertTrue($form->isValid(), implode(', ', $form->errors));
        self::assertSame('Prêt maison', $form->data['name']);
        self::assertSame('residence', $form->data['level_code']);
        self::assertSame('liability', $form->data['nature']);
    }

    public function testOverrides(): void
    {
        $form = AccountForm::validate(self::validator(), [
            'name' => 'Crédit locatif', 'type_id' => '2', 'level_code' => 'immobilier',
            'nature' => 'liability', 'opened_on' => '2021-01-01',
        ], self::TYPES);
        self::assertTrue($form->isValid());
        self::assertSame('immobilier', $form->data['level_code']);
    }

    public function testErrors(): void
    {
        $form = AccountForm::validate(self::validator(), [
            'name' => '', 'type_id' => '99', 'level_code' => 'lune', 'nature' => 'x', 'opened_on' => '2021-02-30',
            'initial_amount' => 'abc',
        ], self::TYPES, true);
        self::assertFalse($form->isValid());
        self::assertSame(['name', 'type_id', 'level_code', 'nature', 'opened_on', 'initial_amount'], array_keys($form->errors));
    }

    public function testInitialAmount(): void
    {
        $form = AccountForm::validate(self::validator(), ['name' => 'LA', 'type_id' => '1', 'opened_on' => '2021-01-01', 'initial_amount' => '1 500,25'], self::TYPES, true);
        self::assertTrue($form->isValid());
        self::assertSame(150025, $form->initialCents);
    }

    public function testInitialAmountIsReadInTheLanguageOfThePage(): void
    {
        $input = ['name' => 'LA', 'type_id' => '1', 'opened_on' => '2021-01-01', 'initial_amount' => '1,500'];

        self::assertSame(150000, AccountForm::validate(self::validator(), $input, self::TYPES, true, 'en')->initialCents);
        self::assertArrayHasKey('initial_amount', AccountForm::validate(self::validator(), $input, self::TYPES, true, 'fr')->errors);
    }

    public function testNameTooLong(): void
    {
        $form = AccountForm::validate(self::validator(), ['name' => str_repeat('é', 81), 'type_id' => '1', 'opened_on' => '2021-01-01'], self::TYPES);
        self::assertArrayHasKey('name', $form->errors);
        $form = AccountForm::validate(self::validator(), ['name' => str_repeat('é', 80), 'type_id' => '1', 'opened_on' => '2021-01-01'], self::TYPES);
        self::assertTrue($form->isValid());
    }

    private static function validator(): \Symfony\Component\Validator\Validator\ValidatorInterface
    {
        return \Symfony\Component\Validator\Validation::createValidator();
    }
}
