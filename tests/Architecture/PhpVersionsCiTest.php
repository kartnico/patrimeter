<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every supported PHP version (8.3, 8.4 and 8.5) is checked in every pipeline of main: syntax of all the files
 * ("php-lint") and the full PHPUnit suites ("phpunit" on 8.3 and 8.5, "coverage" on 8.4). Dropping one of them from
 * .gitlab-ci.yml makes this test fail.
 */
final class PhpVersionsCiTest extends TestCase
{
    private const array SUPPORTED = ['8.3', '8.4', '8.5'];

    public function testEverySupportedVersionIsLinted(): void
    {
        self::assertSame(self::SUPPORTED, self::matrix('php-lint'));
    }

    public function testTheFullSuitesRunOnEverySupportedVersion(): void
    {
        $versions = self::matrix('phpunit');
        $coverage = self::job('coverage');
        $variables = $coverage['variables'] ?? null;
        self::assertIsArray($variables);
        $version = $variables['PHP_VERSION'] ?? null;
        self::assertIsString($version, 'coverage states its PHP version');
        $versions[] = $version;
        sort($versions);
        self::assertSame(self::SUPPORTED, $versions);
        foreach (['phpunit', 'coverage'] as $name) {
            self::assertIsList(self::job($name)['rules'] ?? null, $name);
            self::assertStringContainsString('vendor/bin/phpunit', implode("\n", self::script($name)), $name);
        }
    }

    /** @return list<string> */
    private static function matrix(string $name): array
    {
        $parallel = self::job($name)['parallel'] ?? null;
        self::assertIsArray($parallel, $name);
        $matrix = $parallel['matrix'] ?? null;
        self::assertIsArray($matrix, $name);
        $first = $matrix[0] ?? null;
        self::assertIsArray($first, $name);
        $versions = $first['PHP_VERSION'] ?? null;
        self::assertIsList($versions, $name);
        $strings = [];
        foreach ($versions as $version) {
            self::assertIsString($version);
            $strings[] = $version;
        }
        sort($strings);

        return $strings;
    }

    /** @return list<string> */
    private static function script(string $name): array
    {
        $script = self::job($name)['script'] ?? null;
        self::assertIsList($script, $name);
        $lines = [];
        foreach ($script as $line) {
            self::assertIsString($line);
            $lines[] = $line;
        }

        return $lines;
    }

    /** @return array<mixed> */
    private static function job(string $name): array
    {
        $ci = Yaml::parseFile(__DIR__ . '/../../.gitlab-ci.yml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($ci);
        $job = $ci[$name] ?? null;
        self::assertIsArray($job, $name);

        return $job;
    }
}
