<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * On Mondays, the "container-image" job rebuilds the latest published version from its own code, with main's CI
 * configuration: it must find that version's Dockerfile wherever it is (regression of 2026-11-16: the Monday pipeline
 * checked out v2.14.0, whose Dockerfile is at the root, and looked for docker/Dockerfile).
 */
final class ContainerImageJobTest extends TestCase
{
    public function testTheMondayRebuildUsesTheDockerfileOfTheCheckedOutVersion(): void
    {
        $script = implode("\n", $this->script());

        $checkout = $this->position($script, 'git -c advice.detachedHead=false checkout');
        $fallback = $this->position($script, '[ -f "$dockerfile" ] || dockerfile=Dockerfile');
        $build = $this->position($script, '--file "$context/tree/$dockerfile"');
        self::assertGreaterThan($checkout, $fallback, 'the Dockerfile is looked for after the checkout');
        self::assertGreaterThan($fallback, $build, 'the build uses the Dockerfile found');
        self::assertFileExists(__DIR__ . '/../../docker/Dockerfile', 'the current versions keep it in docker/');
    }

    /** @return list<string> commands of the job, as written in .gitlab-ci.yml */
    private function script(): array
    {
        $ci = Yaml::parseFile(__DIR__ . '/../../.gitlab-ci.yml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($ci);
        $job = $ci['container-image'] ?? null;
        self::assertIsArray($job);
        $script = [];
        foreach ((array) ($job['script'] ?? []) as $command) {
            if (is_string($command)) {
                $script[] = $command;
            } elseif (!$command instanceof TaggedValue) {
                self::fail('unexpected command in the container-image job');
            }
        }

        return $script;
    }

    private function position(string $script, string $needle): int
    {
        $position = strpos($script, $needle);
        self::assertNotFalse($position, "container-image runs $needle");

        return $position;
    }
}
