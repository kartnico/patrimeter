<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guard rails of the challenges, checked on the code: they reward habits and safety, never the split of the wealth
 * between the levels, a return, the allocation benchmark or a riskier level. Patrimeter is a tracking tool, not
 * investment advice: a challenge that would read one of these would fail here.
 */
final class ChallengeGuardRailsTest extends TestCase
{
    private const array DIRECTORIES = [__DIR__ . '/../../src/Domain/Game', __DIR__ . '/../../src/Application/Game'];

    /** What a challenge must never read. */
    private const array FORBIDDEN = [
        '->share(' => 'part d\'un niveau dans le patrimoine',
        'RecommendedAllocation' => 'répartition recommandée',
        'Level::Actions' => 'niveau Actions',
        'Level::Immobilier' => 'niveau Immobilier',
        'Level::Exotique' => 'niveau Exotique',
        'Level::FondsEuros' => 'niveau Fonds euros',
        'WealthDistribution' => 'comparaison avec les autres',
    ];

    /** @return iterable<string, array{string}> */
    public static function files(): iterable
    {
        foreach (self::DIRECTORIES as $directory) {
            $files = glob($directory . '/*.php');
            foreach ($files === false ? [] : $files as $file) {
                yield basename($file) => [$file];
            }
        }
    }

    #[DataProvider('files')]
    public function testNoChallengeReadsTheSplitOfTheWealthNorARiskierLevel(string $file): void
    {
        $code = (string) file_get_contents($file);
        foreach (self::FORBIDDEN as $needle => $what) {
            self::assertStringNotContainsString($needle, $code, basename($file) . " lit : $what");
        }
    }
}
