<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * THIRD-PARTY-LICENSES.md lists the third-party code shipped (Composer dependencies without the development ones,
 * Chart.js) as composer.lock and assets/vendor/ describe it: a dependency added or updated without regenerating the
 * file fails here. Regenerate with: php tools/third-party-licenses.php
 */
final class ThirdPartyLicensesTest extends TestCase
{
    public function testTheShippedLicensesFileIsUpToDate(): void
    {
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../tools/third-party-licenses.php') . ' --check 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
    }

    public function testOnlyLicensesCompatibleWithMitAreShipped(): void
    {
        $content = (string) file_get_contents(__DIR__ . '/../../THIRD-PARTY-LICENSES.md');
        preg_match_all('/^\| [^|]+ \| [^|]+ \| ([^|]+) \|/m', $content, $matches);
        $licenses = array_values(array_unique(array_map('trim', array_slice($matches[1], 1))));
        sort($licenses);

        self::assertSame([], array_values(array_diff($licenses, ['Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'ISC', 'MIT'])), 'a license to review before shipping');
    }
}
