<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Pipeline of a vX.Y.Z tag: the commit already passed the full pipeline of main, so only what publishes the version
 * runs (image and its scans, security scans and their gate, archive, publication). Every other job excludes itself
 * through the .not-tag rule, and a new job must choose its side here.
 */
final class TagPipelineTest extends TestCase
{
    /** Jobs of the tag pipeline. */
    private const array ON_TAG = [
        'ci-php-image', 'composer', 'container-image', 'container-smoke', 'trivy-image', 'npm-audit', 'semgrep',
        'semgrep-sast', 'secret_detection', 'trivy', 'zap', 'nuclei', 'security-gate', 'pipeline-report', 'release',
        'container-publish', 'github-publish', 'captures', 'npm',
    ];

    /** Jobs that never run on a tag by their own rules (main only, or Mondays only). */
    private const array NEVER_ON_TAG = ['scorecard', 'renovate', 'nuclei-production'];

    public function testOnlyThePublicationJobsRunOnATag(): void
    {
        $ci = Yaml::parseFile(__DIR__ . '/../../.gitlab-ci.yml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($ci);
        $jobs = array_filter($ci, static fn(mixed $job, int|string $name): bool => is_array($job) && is_string($name) && !str_starts_with($name, '.')
            && (isset($job['stage']) || isset($job['extends']) || isset($job['script'])), ARRAY_FILTER_USE_BOTH);
        self::assertGreaterThan(30, count($jobs));

        foreach ($jobs as $name => $job) {
            $excluded = self::excludedOnTag($job);
            if (in_array($name, self::ON_TAG, true)) {
                if (in_array($name, ['captures', 'npm'], true)) {
                    $rules = $job['rules'] ?? null;
                    self::assertIsArray($rules);
                    self::assertEquals(new TaggedValue('reference', ['.public-tag', 'rules']), $rules[0] ?? null);
                    continue;
                }
                self::assertFalse($excluded, "$name publishes the version: it runs on a tag");
            } elseif (!in_array($name, self::NEVER_ON_TAG, true)) {
                self::assertTrue($excluded, "$name: add « - !reference [.not-tag, rules] » to its rules, or list it in ON_TAG");
            }
        }
    }

    /** @param array<mixed> $job */
    private static function excludedOnTag(array $job): bool
    {
        foreach ((array) ($job['rules'] ?? []) as $rule) {
            if ($rule instanceof TaggedValue && $rule->getValue() === ['.not-tag', 'rules']) {
                return true;
            }
        }

        return false;
    }
}
