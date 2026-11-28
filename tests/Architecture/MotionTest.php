<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Micro-animations of app.css only for those who have not asked their system to reduce motion (WCAG 2.3.3): every
 * transition and animation sits in a prefers-reduced-motion: no-preference block (app.js does the same with
 * matchMedia for its scrolling and charts).
 */
final class MotionTest extends TestCase
{
    private const string CSS = __DIR__ . '/../../assets/styles/app.css';
    private const string MOTION_ALLOWED = '@media (prefers-reduced-motion: no-preference) {';

    public function testEveryTransitionAndAnimationWaitsForMotionToBeAllowed(): void
    {
        $css = self::withoutComments((string) file_get_contents(self::CSS));
        $allowed = self::blocks($css, self::MOTION_ALLOWED);
        self::assertNotSame([], $allowed, self::MOTION_ALLOWED . ' absent de app.css');

        preg_match_all('/(?<=[{;\s])(?:transition|animation)(?:-[a-z]+)*\s*:/', $css, $matches, PREG_OFFSET_CAPTURE);
        self::assertNotSame([], $matches[0], 'aucune animation trouvée : le motif ne lit plus app.css');
        foreach ($matches[0] as [$declaration, $offset]) {
            $inside = array_filter($allowed, static fn(array $block): bool => $offset > $block[0] && $offset < $block[1]);
            self::assertNotSame([], $inside, sprintf('« %s » (ligne %d) hors de %s', $declaration, substr_count($css, "\n", 0, $offset) + 1, self::MOTION_ALLOWED));
        }
    }

    /** Comments blanked out, line breaks kept: the line numbers of the messages stay those of the file. */
    private static function withoutComments(string $css): string
    {
        return (string) preg_replace_callback('~/\*.*?\*/~s', static fn(array $comment): string => (string) preg_replace('/[^\n]/', ' ', $comment[0]), $css);
    }

    /** @return list<array{int, int}> offsets of the start and end of each block opened by $opening */
    private static function blocks(string $css, string $opening): array
    {
        $blocks = [];
        for ($start = strpos($css, $opening); $start !== false; $start = strpos($css, $opening, $start + 1)) {
            $depth = 0;
            $end = $start + strlen($opening) - 1;
            for ($length = strlen($css); $end < $length; ++$end) {
                $depth += match ($css[$end]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
                if ($depth === 0) {
                    break;
                }
            }
            $blocks[] = [$start, $end];
        }

        return $blocks;
    }
}
