<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Infrastructure;

use Patrimeter\Infrastructure\Persistence\Row;
use PHPUnit\Framework\TestCase;

final class RowTest extends TestCase
{
    public function testConvertsSqliteValues(): void
    {
        $row = ['id' => '42', 'count' => 7, 'negative' => '-3', 'name' => 'Livret', 'code' => 12, 'closed' => null];

        self::assertSame(42, Row::int($row, 'id'));
        self::assertSame(7, Row::int($row, 'count'));
        self::assertSame(-3, Row::int($row, 'negative'));
        self::assertSame('Livret', Row::string($row, 'name'));
        self::assertSame('12', Row::string($row, 'code'));
        self::assertNull(Row::nullableString($row, 'closed'));
        self::assertSame('Livret', Row::nullableString($row, 'name'));
    }

    public function testRejectsMissingColumns(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('absente');

        Row::int([], 'id');
    }

    public function testRejectsNonIntegerText(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        Row::int(['id' => '4.2'], 'id');
    }

    public function testRejectsNonTextValues(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        Row::string(['amount' => 1.5], 'amount');
    }
}
