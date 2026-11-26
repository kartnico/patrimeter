<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\DateInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DateInputTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function dates(): iterable
    {
        yield 'date valide' => ['2026-11-15', true];
        yield '29 février bissextile' => ['2024-02-29', true];
        yield '29 février non bissextile' => ['2026-02-29', false];
        yield 'mois 13' => ['2026-13-01', false];
        yield 'jour 0' => ['2026-11-00', false];
        yield 'format français' => ['15/11/2026', false];
        yield 'saut de ligne final' => ["2026-11-15\n", false];
        yield 'année trop ancienne' => ['1899-12-31', false];
        yield 'année trop lointaine' => ['3000-01-01', false];
        yield 'vide' => ['', false];
    }

    #[DataProvider('dates')]
    public function testIsValid(string $date, bool $valid): void
    {
        self::assertSame($valid, DateInput::isValid($date));
    }
}
