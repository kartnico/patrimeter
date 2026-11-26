<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * k6 measures alone on the runner: it waits for every job of the test, e2e and security stages, and public documentation
 * runs only on a release tag, never alongside k6. A job running at the same time (Nuclei scanning the application, in 3.0.0) slows
 * the pages down and makes the p95 threshold fail without any error.
 */
final class PerformancePipelineTest extends TestCase
{
    /** Stages whose jobs k6 waits for. */
    private const array BEFORE_K6 = ['test', 'e2e', 'security'];

    /** Jobs never in the same pipeline as k6 (Monday scan of the deployed instance, where k6 does not run). */
    private const array NEVER_WITH_K6 = ['nuclei-production'];

    public function testK6WaitsForEveryJobThatLoadsTheRunner(): void
    {
        $ci = self::pipeline();
        $needs = self::needs($ci['k6'] ?? null);

        foreach ($ci as $name => $job) {
            if (!is_string($name) || !is_array($job) || !in_array($job['stage'] ?? null, self::BEFORE_K6, true) || in_array($name, self::NEVER_WITH_K6, true)) {
                continue;
            }
            self::assertContains($name, $needs, "k6 must wait for $name (needs, optional: true if it does not always run)");
        }
    }

    public function testThePublicDocumentationWaitsForItsScreenshots(): void
    {
        self::assertContains('captures', self::needs(self::pipeline()['github-publish'] ?? null));
    }

    /** @return array<mixed> */
    private static function pipeline(): array
    {
        $ci = Yaml::parseFile(__DIR__ . '/../../.gitlab-ci.yml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($ci);

        return $ci;
    }

    /** @return list<string> names of the jobs a job needs */
    private static function needs(mixed $job): array
    {
        self::assertIsArray($job);
        $names = [];
        foreach ((array) ($job['needs'] ?? []) as $need) {
            $name = is_array($need) ? ($need['job'] ?? null) : $need;
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
