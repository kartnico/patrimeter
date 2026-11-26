<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Daily Renovate pipeline (schedule with RENOVATE=1, docs/ci.md): only Renovate runs, with the two light jobs that
 * every pipeline has (the CI image check and the space check of "composer"); and Renovate runs nowhere else. The rules
 * of .gitlab-ci.yml are evaluated for the variables of each pipeline (the subset of expressions the file uses).
 */
final class RenovatePipelineTest extends TestCase
{
    private const array ALWAYS = ['ci-php-image', 'composer'];

    public function testOnlyRenovateRunsInTheDailyPipeline(): void
    {
        $running = self::runningJobs(['CI_PIPELINE_SOURCE' => 'schedule', 'CI_COMMIT_BRANCH' => 'main', 'RENOVATE' => '1']);

        self::assertSame([...self::ALWAYS, 'renovate'], $running);
    }

    /** A PHP update of Renovate also writes the license notices again (MR !101 failed on ThirdPartyLicensesTest). */
    public function testAPhpUpdateWritesTheLicenseNoticesAgain(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../ci/renovate-post-upgrade.sh');
        self::assertMatchesRegularExpression('/git diff --quiet -- composer\.lock;\s+then\s+php tools\/third-party-licenses\.php/', $script);
        $config = json_decode((string) file_get_contents(__DIR__ . '/../../renovate.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($config);
        $tasks = $config['postUpgradeTasks'] ?? null;
        self::assertIsArray($tasks);
        $filters = $tasks['fileFilters'] ?? null;
        self::assertIsArray($filters);
        self::assertContains('THIRD-PARTY-LICENSES.md', $filters, 'the file written is committed');
    }

    public function testRenovateRunsInNoOtherPipeline(): void
    {
        $pipelines = [
            'push on main' => ['CI_PIPELINE_SOURCE' => 'push', 'CI_COMMIT_BRANCH' => 'main'],
            'branch' => ['CI_PIPELINE_SOURCE' => 'push', 'CI_COMMIT_BRANCH' => 'feat/x'],
            'merge request' => ['CI_PIPELINE_SOURCE' => 'merge_request_event'],
            'tag' => ['CI_PIPELINE_SOURCE' => 'push', 'CI_COMMIT_TAG' => 'v9.9.9'],
            'Monday' => ['CI_PIPELINE_SOURCE' => 'schedule', 'CI_COMMIT_BRANCH' => 'main', 'SECURITY_SCAN' => '1'],
        ];
        foreach ($pipelines as $name => $variables) {
            $running = self::runningJobs($variables);
            self::assertNotContains('renovate', $running, $name);
            self::assertContains('composer', $running, "$name: the rules are evaluated");
        }
        self::assertContains('nuclei-production', self::runningJobs($pipelines['Monday']));
        self::assertContains('k6', self::runningJobs($pipelines['push on main']));
    }

    public function testPublicDestinationsRequireProtectedReleaseRefsAndExplicitOptIn(): void
    {
        $settings = ['PUBLIC_RELEASE_ENABLED' => '1', 'CI_COMMIT_REF_PROTECTED' => 'true'];
        $tag = ['CI_PIPELINE_SOURCE' => 'push', 'CI_COMMIT_TAG' => 'v3.5.1'];
        $daily = ['CI_PIPELINE_SOURCE' => 'schedule', 'CI_COMMIT_BRANCH' => 'main', 'SECURITY_SCAN' => '1'];
        foreach (['container-publish', 'github-publish'] as $job) {
            self::assertContains($job, self::runningJobs($tag + $settings));
            self::assertNotContains($job, self::runningJobs($tag));
            self::assertNotContains($job, self::runningJobs(['CI_COMMIT_REF_PROTECTED' => 'false'] + $tag + $settings));
            self::assertNotContains($job, self::runningJobs(['CI_COMMIT_TAG' => 'preview'] + $tag + $settings));
            self::assertNotContains($job, self::runningJobs(['RENOVATE' => '1'] + $tag + $settings));
        }
        self::assertContains('container-publish', self::runningJobs($daily + $settings));
        self::assertNotContains('github-publish', self::runningJobs($daily + $settings));
        self::assertNotContains('container-publish', self::runningJobs(['CI_COMMIT_BRANCH' => 'stable'] + $daily + $settings));
    }

    /**
     * Jobs of .gitlab-ci.yml that a pipeline with these variables starts, sorted.
     *
     * @param array<string, string> $variables
     *
     * @return list<string>
     */
    private static function runningJobs(array $variables): array
    {
        $ci = Yaml::parseFile(__DIR__ . '/../../.gitlab-ci.yml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($ci);
        $variables += ['CI_DEFAULT_BRANCH' => 'main'];
        foreach (is_array($ci['variables'] ?? null) ? $ci['variables'] : [] as $name => $value) {
            if (is_string($name) && is_string($value) && !isset($variables[$name])) {
                $variables[$name] = $value;
            }
        }
        $running = [];
        foreach ($ci as $name => $job) {
            if (!is_string($name) || str_starts_with($name, '.') || !is_array($job) || !isset($job['stage']) && !isset($job['script']) && !isset($job['extends'])
                || in_array($name, ['workflow', 'include', 'stages', 'variables', 'default'], true)) {
                continue;
            }
            $rules = self::rules($ci, $job);
            if ($rules === null || self::runs($rules, $variables)) {
                $running[] = $name;
            }
        }
        sort($running);

        return $running;
    }

    /**
     * Rules of a job, references resolved, inherited through "extends" when it has none; null: no rules (always runs).
     *
     * @param array<mixed> $ci
     * @param array<mixed> $job
     *
     * @return list<array<mixed>>|null
     */
    private static function rules(array $ci, array $job): ?array
    {
        if (array_key_exists('rules', $job)) {
            return self::expand($ci, $job['rules']);
        }
        $parents = is_string($job['extends'] ?? null) ? [$job['extends']] : (is_array($job['extends'] ?? null) ? $job['extends'] : []);
        foreach (array_reverse($parents) as $parent) {
            if (is_string($parent) && is_array($ci[$parent] ?? null)) {
                $rules = self::rules($ci, $ci[$parent]);
                if ($rules !== null) {
                    return $rules;
                }
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $ci
     *
     * @return list<array<mixed>>
     */
    private static function expand(array $ci, mixed $rules): array
    {
        if ($rules instanceof TaggedValue) {
            $rules = [$rules];
        }
        $expanded = [];
        foreach (is_array($rules) ? $rules : [] as $rule) {
            if ($rule instanceof TaggedValue && $rule->getTag() === 'reference') {
                $path = $rule->getValue();
                self::assertIsArray($path);
                $target = $ci;
                foreach ($path as $key) {
                    self::assertIsArray($target);
                    if (!is_string($key) && !is_int($key)) {
                        self::fail('!reference path of names expected');
                    }
                    $target = $target[$key] ?? null;
                }
                $expanded = [...$expanded, ...self::expand($ci, $target)];
            } elseif (is_array($rule)) {
                $expanded[] = $rule;
            }
        }

        return $expanded;
    }

    /**
     * First matching rule decides: "never" excludes; no rule matching: the job does not run.
     *
     * @param list<array<mixed>>    $rules
     * @param array<string, string> $variables
     */
    private static function runs(array $rules, array $variables): bool
    {
        foreach ($rules as $rule) {
            $if = $rule['if'] ?? null;
            // On a pipeline without push (schedule), GitLab evaluates "changes" as true.
            $matches = is_string($if) ? self::condition($if, $variables) : true;
            if ($matches) {
                return ($rule['when'] ?? 'on_success') !== 'never';
            }
        }

        return false;
    }

    /** @param array<string, string> $variables */
    private static function condition(string $expression, array $variables): bool
    {
        foreach (explode('||', $expression) as $alternative) {
            $all = true;
            foreach (explode('&&', $alternative) as $atom) {
                $all = $all && self::atom(trim($atom), $variables);
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $variables */
    private static function atom(string $atom, array $variables): bool
    {
        $value = static fn(string $name): ?string => $variables[ltrim($name, '$')] ?? null;
        if (preg_match('/^(\$\w+) =~ (\/.+\/)$/', $atom, $regex) === 1) {
            return preg_match($regex[2], $value($regex[1]) ?? '') === 1;
        }
        if (preg_match('/^(\$\w+)\s*(==|!=)\s*(.+)$/', $atom, $match) === 1) {
            $left = $value($match[1]);
            $right = trim($match[3]);
            $right = $right === 'null' ? null : (str_starts_with($right, '$') ? $value($right) : trim($right, '"\''));
            $equal = $right === null ? ($left === null) : ($left === $right);

            return $match[2] === '==' ? $equal : !$equal;
        }
        self::assertMatchesRegularExpression('/^\$\w+$/', $atom, "expression not understood by the test: $atom");

        return ($value($atom) ?? '') !== '';
    }
}
