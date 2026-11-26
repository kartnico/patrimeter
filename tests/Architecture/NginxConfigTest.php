<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * nginx configuration shipped (docs/nginx/, docker/): a "location" regular expression that contains a brace
 * ("{1,9}") must be quoted, otherwise nginx sees the start of the block there and refuses to start (regression of
 * 2.9.0, detected by the container-image and deployment jobs).
 */
final class NginxConfigTest extends TestCase
{
    public function testRegexLocationsWithBracesAreQuoted(): void
    {
        $found = glob(__DIR__ . '/../../docs/nginx/*.conf');
        $files = [...($found === false ? [] : $found), __DIR__ . '/../../docker/nginx.conf'];
        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            self::assertIsArray($lines, $file);
            foreach ($lines as $number => $line) {
                if (preg_match('/^\s*location\s+~\*?\s+(\S.*)\{\s*$/', $line, $match) === 1) {
                    $pattern = trim($match[1]);
                    self::assertFalse(
                        str_contains($pattern, '{') && !str_starts_with($pattern, '"'),
                        basename($file) . ':' . ($number + 1) . ' : expression régulière à mettre entre guillemets',
                    );
                }
            }
        }
    }
}
