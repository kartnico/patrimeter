<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Typed class constants (PHP 8.3): `private const int MAX = 13;`, never `private const MAX = 13;`. A value changed
 * to another type becomes an error, and a redefined constant cannot change type.
 */
final class TypedConstantsTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    public function testEveryClassConstantHasAType(): void
    {
        $untyped = [];
        foreach (['src', 'tests', 'tools'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . "/$directory", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
                self::assertIsArray($lines);
                foreach ($lines as $number => $line) {
                    // Class constant (indented) whose name directly follows "const".
                    if (preg_match('/^\s+(?:final\s+)?(?:(?:public|protected|private)\s+)?(?:final\s+)?const\s+[A-Z][A-Z0-9_]*\s*=/', $line) === 1) {
                        $untyped[] = substr($file->getPathname(), strlen(self::ROOT) + 1) . ':' . ($number + 1);
                    }
                }
            }
        }

        self::assertSame([], $untyped, 'Constantes de classe sans type');
    }
}
