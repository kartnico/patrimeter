<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Operation scripts shipped in docs/ (update, backup, restore): runnable as they come out of git, so that
 * /srv/patrimeter/docs/update/patrimeter-update.sh starts without « Permission denied ». Bug of 2.13.0: the
 * update script was stored without the executable bit.
 */
final class OperationScriptsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function scripts(): iterable
    {
        $update = glob(__DIR__ . '/../../docs/update/*.sh');
        $backup = glob(__DIR__ . '/../../docs/backup/*.sh');
        foreach ([...($update === false ? [] : $update), ...($backup === false ? [] : $backup)] as $path) {
            yield basename($path) => [$path];
        }
    }

    #[DataProvider('scripts')]
    public function testScriptIsExecutableWithAPosixShell(string $path): void
    {
        self::assertTrue(is_executable($path), basename($path) . ' : mode exécutable attendu (git update-index --chmod=+x)');
        self::assertStringStartsWith("#!/bin/sh\n", (string) file_get_contents($path));
    }

    public function testTheThreeScriptsAreCovered(): void
    {
        self::assertSame(['patrimeter-update.sh', 'patrimeter-backup.sh', 'patrimeter-restore.sh'], array_keys(iterator_to_array(self::scripts())));
    }
}
