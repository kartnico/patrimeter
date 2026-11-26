<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * CI image of the PHP jobs (ci/php/Dockerfile): its tag is the start of the SHA-256 of what builds it, so that a change
 * of the Dockerfile, of the installation scripts or of the Debian image always gives a new image (built by the first
 * pipeline that needs it), never an old image under the same name.
 */
final class CiPhpImageTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../';

    public function testTheTagFollowsWhatBuildsTheImage(): void
    {
        $variables = self::ci()['variables'] ?? null;
        self::assertIsArray($variables);
        $debian = $variables['DEBIAN_IMAGE'] ?? null;
        self::assertIsString($debian);
        $content = '';
        foreach (['ci/php/Dockerfile', 'ci/install-php.sh', 'ci/install-composer.sh', 'ci/debsuryorg-archive-keyring.gpg'] as $file) {
            $content .= (string) file_get_contents(self::ROOT . $file);
        }
        $expected = substr(hash('sha256', $content . $debian), 0, 12);

        self::assertSame('$CI_REGISTRY_IMAGE/ci-php:' . $expected, $variables['CI_PHP_IMAGE'] ?? null, 'CI_PHP_IMAGE to update in .gitlab-ci.yml');
    }

    public function testThePhpJobsUseItOnceBuilt(): void
    {
        $ci = self::ci();
        $template = $ci['.php'] ?? null;
        self::assertIsArray($template);
        self::assertSame('$CI_PHP_IMAGE', $template['image'] ?? null);
        $build = $ci['ci-php-image'] ?? null;
        self::assertIsArray($build);
        self::assertSame('prepare', $build['stage'] ?? null);
        self::assertArrayNotHasKey('rules', $build, 'in every pipeline: the PHP jobs need it');
        foreach (['composer', 'php-lint'] as $job) {
            $definition = $ci[$job] ?? null;
            self::assertIsArray($definition);
            self::assertSame(['ci-php-image'], $definition['needs'] ?? null, $job);
        }
    }

    /**
     * Regression (pipeline 834): PHP 8.5 has no php8.5-opcache package (OPcache is part of PHP from 8.5 on), so asking
     * for it made apt fail and the image was never built. As in docker/Dockerfile, it is asked for 8.3 and 8.4 only.
     */
    public function testOpcacheIsAPackageUpToPhp84Only(): void
    {
        $dockerfile = (string) file_get_contents(self::ROOT . 'ci/php/Dockerfile');
        self::assertStringContainsString('for version in 8.3 8.4 8.5', $dockerfile);
        self::assertStringContainsString('case "$version" in 8.3|8.4) echo "php$version-opcache"', $dockerfile);
        self::assertStringNotContainsString('"php$version-opcache" unzip', $dockerfile, 'never asked for every version');
    }

    /**
     * Regression (pipeline 840): "buildah manifest inspect" fails on a single image, so the image was rebuilt in every
     * pipeline although its tag was in the registry. The check adds the remote image to a local manifest list.
     */
    public function testTheImageIsBuiltOnlyWhenItsTagIsMissing(): void
    {
        $job = self::ci()['ci-php-image'] ?? null;
        self::assertIsArray($job);
        $script = $job['script'] ?? null;
        self::assertIsList($script);
        $text = implode("\n", array_map(static fn(mixed $line): string => is_string($line) ? $line : '', $script));
        self::assertStringContainsString('buildah manifest add ci-php-check "docker://$CI_PHP_IMAGE"', $text);
        self::assertStringNotContainsString('manifest inspect', $text);
        self::assertLessThan(strpos($text, 'buildah build'), strpos($text, 'exit 0'), 'the build is skipped');
    }

    /** @return array<mixed> */
    private static function ci(): array
    {
        $ci = Yaml::parseFile(self::ROOT . '.gitlab-ci.yml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($ci);

        return $ci;
    }
}
