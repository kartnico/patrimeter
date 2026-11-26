<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LevelTest extends TestCase
{
    /** @return iterable<string, array{Level, string, string}> */
    public static function labels(): iterable
    {
        yield 'résidence' => [Level::Residence, 'Résidence principale', 'Résidence principale'];
        yield 'précaution' => [Level::Precaution, 'Épargne de précaution', 'Précaution'];
        yield 'fonds euros' => [Level::FondsEuros, 'Fonds euros', 'Fonds euros'];
        yield 'actions' => [Level::Actions, 'Actions', 'Actions'];
        yield 'immobilier' => [Level::Immobilier, 'Immobilier', 'Immobilier'];
        yield 'exotique' => [Level::Exotique, 'Exotique', 'Exotique'];
    }

    #[DataProvider('labels')]
    public function testLabels(Level $level, string $label, string $shortLabel): void
    {
        self::assertSame($label, $level->label());
        self::assertSame($shortLabel, $level->shortLabel());
    }

    public function testOrderGoesFromTheBaseToTheTop(): void
    {
        self::assertSame(
            ['residence', 'precaution', 'fonds_euros', 'actions', 'immobilier', 'exotique'],
            array_map(static fn(Level $level): string => $level->value, Level::ordered()),
        );
    }

    public function testPositionFollowsTheDisplayOrder(): void
    {
        self::assertSame(0, Level::Residence->position());
        self::assertSame(5, Level::Exotique->position());
    }
}
