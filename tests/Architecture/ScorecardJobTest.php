<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * OpenSSF Scorecard: the job runs in every pipeline of main (the README badge is its latest artifact there), with a
 * release pinned by its version and its SHA-256, never blocks, and the private CI documentation links to the badge.
 */
final class ScorecardJobTest extends TestCase
{
    public function testTheJobRunsOnMainWithAPinnedReleaseAndProducesTheBadge(): void
    {
        $ci = Yaml::parseFile(__DIR__ . '/../../.gitlab-ci.yml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($ci);
        $job = $ci['scorecard'] ?? null;
        self::assertIsArray($job);
        self::assertEquals(
            [new TaggedValue('reference', ['.not-renovate', 'rules']), ['if' => '$CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH']],
            $job['rules'] ?? null,
            'every pipeline of main, the Monday one included, except the Renovate one',
        );
        self::assertTrue($job['allow_failure'] ?? false, 'informative: never blocks');
        self::assertSame(['python3 -I ci/scorecard.py'], $job['script'] ?? null);
        $artifacts = $job['artifacts'] ?? null;
        self::assertIsArray($artifacts);
        self::assertSame(['scorecard/'], $artifacts['paths'] ?? null);

        $variables = $ci['variables'] ?? null;
        self::assertIsArray($variables);
        $version = $variables['SCORECARD_VERSION'] ?? null;
        $digest = $variables['SCORECARD_SHA256'] ?? null;
        self::assertIsString($version);
        self::assertIsString($digest);
        self::assertMatchesRegularExpression('/\A\d+\.\d+\.\d+\z/', $version);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $digest);
    }

    public function testTheCiGuideLinksToTheBadgeOfTheLatestScorecardOfMain(): void
    {
        self::assertStringContainsString(
            'https://gitlab.example.org/kartzone/patrimeter/-/jobs/artifacts/main/raw/scorecard/badge.svg?job=scorecard',
            (string) file_get_contents(__DIR__ . '/../../docs/ci.md'),
        );
    }
}
