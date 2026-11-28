<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Twig;

use Patrimeter\Domain\Game\Challenge;
use Patrimeter\Twig\Icons;
use PHPUnit\Framework\TestCase;

/** The icon set: one drawing per name, the same markup everywhere, and every name used by the domain drawn. */
final class IconsTest extends TestCase
{
    public function testAnIconIsADecorativeSvgOnTheCommonGrid(): void
    {
        self::assertSame(
            '<svg class="icon icon-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="' . Icons::PATHS['moon'] . '"/></svg>',
            Icons::svg('moon', 'icon-moon'),
        );
    }

    public function testAnUnknownNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Icons::svg('rocket');
    }

    public function testEveryChallengeHasADrawnIcon(): void
    {
        foreach (Challenge::cases() as $challenge) {
            self::assertArrayHasKey($challenge->icon(), Icons::PATHS, $challenge->value);
        }
    }

    public function testEachDrawingAppearsOnce(): void
    {
        self::assertSame(array_values(array_unique(Icons::PATHS)), array_values(Icons::PATHS), 'one name per drawing: no duplicate under two names');
    }
}
