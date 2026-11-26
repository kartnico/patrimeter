<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * docker/compose.yaml reads its env_file relative to its own folder: the "compose-config" CI job must copy the
 * template there (regression of 2.15.0: the template was copied to the repository root after the move to docker/,
 * and docker compose did not find patrimeter.env).
 */
final class ComposeConfigTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    public function testTheCiCopiesTheConfigurationTemplateWhereComposeReadsIt(): void
    {
        $compose = Yaml::parseFile(self::ROOT . '/docker/compose.yaml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($compose);
        $envFiles = [];
        foreach ($this->services($compose) as $service) {
            foreach ((array) ($service['env_file'] ?? []) as $file) {
                self::assertIsString($file);
                $envFiles[] = 'docker/' . $file;
            }
        }
        self::assertNotSame([], $envFiles, 'compose.yaml declares its configuration file');

        $ci = (string) file_get_contents(self::ROOT . '/.gitlab-ci.yml');
        foreach (array_unique($envFiles) as $file) {
            self::assertStringContainsString("cp docker/patrimeter.env.example $file\n", $ci, "compose-config creates $file");
        }
    }

    /**
     * Services of the compose file, including those defined through an extension (x-…) anchor.
     *
     * @param array<mixed> $compose
     *
     * @return list<array<mixed>>
     */
    private function services(array $compose): array
    {
        $services = [];
        foreach ($compose as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($key === 'services') {
                foreach ($value as $service) {
                    if (is_array($service)) {
                        $services[] = $service;
                    }
                }
            } elseif (is_string($key) && str_starts_with($key, 'x-')) {
                $services[] = $value;
            }
        }

        return $services;
    }
}
