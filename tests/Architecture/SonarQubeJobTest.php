<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * SonarQube: the analysis blocks on the quality gate, reads the PHP coverage of the "coverage" job, runs on main and in
 * the full pipelines (Community keeps a single branch, never fed by a merge request), with a scanner pinned by its
 * version and its SHA-256, whose server and token come from the CI variables only.
 */
final class SonarQubeJobTest extends TestCase
{
    public function testTheJobBlocksOnTheGateWithTheCoverageOfThePipeline(): void
    {
        $ci = Yaml::parseFile(__DIR__ . '/../../.gitlab-ci.yml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($ci);
        $job = $ci['sonarqube'] ?? null;
        self::assertIsArray($job);
        self::assertFalse($job['allow_failure'] ?? false, 'blocking, as every job of the project');
        self::assertEquals(
            [
                new TaggedValue('reference', ['.not-tag', 'rules']),
                new TaggedValue('reference', ['.not-security-scan', 'rules']),
                new TaggedValue('reference', ['.full-pipeline', 'rules']),
                ['if' => '$SONARQUBE == "1"'],
            ],
            $job['rules'] ?? null,
            'main, full pipelines and by hand; never a merge request pipeline of its own',
        );
        self::assertSame([['job' => 'coverage']], $job['needs'] ?? null, 'the clover report of the coverage job');
        self::assertSame(
            ['sh ci/install-sonar-scanner.sh', 'sonar-scanner -Dsonar.host.url="$SONAR_HOST_URL" -Dsonar.projectVersion="$(cat VERSION)"'],
            $job['script'] ?? null,
            'the token is read by the scanner from SONAR_TOKEN, never written in a command',
        );

        $properties = (string) file_get_contents(__DIR__ . '/../../sonar-project.properties');
        self::assertStringContainsString("\nsonar.qualitygate.wait=true\n", $properties);
        self::assertStringContainsString("\nsonar.php.coverage.reportPaths=var/coverage/clover.xml\n", $properties);
        self::assertStringNotContainsString('sonar.token', $properties);
        self::assertStringNotContainsString('sonar.host.url', $properties);
    }

    public function testTheScannerIsPinnedByVersionAndChecksum(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../ci/install-sonar-scanner.sh');
        self::assertMatchesRegularExpression('/\nversion=\d+\.\d+\.\d+\.\d+\n/', $script);
        self::assertMatchesRegularExpression('/\nsha256=[0-9a-f]{64}\n/', $script);
        self::assertStringContainsString('sha256sum -c -', $script);
    }
}
