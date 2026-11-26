<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

use Patrimeter\Application\Calendar;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Footer of every page: copyright, MIT license, link to the project, and Patrimeter version; address of the online
 * user documentation.
 *
 * The version comes from the VERSION file at the root of the project, the only source, present in every installation
 * (git, release archive, container image). Updated with the CHANGELOG at each release (docs/ci.md, "Publier une
 * version"); VersionTest checks that they match. The administrator can hide it (SHOW_VERSION=false) so as not to reveal
 * it to visitors; shown by default.
 */
final class VersionExtension extends AbstractExtension
{
    public const string PROJECT_URL = 'https://github.com/kartnico/patrimeter';
    public const string LICENSE_URL = self::PROJECT_URL . '/blob/main/LICENSE';
    /** Online user documentation (GitHub Pages), followed by the language: fr/ or en/. */
    public const string DOCUMENTATION_URL = 'https://kartnico.github.io/patrimeter/';
    /** Year of the first version (LICENSE file). */
    public const int FIRST_YEAR = 2026;

    private ?string $version = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(bool:SHOW_VERSION)%')]
        private readonly bool $visible,
        private readonly Calendar $calendar,
    ) {}

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('app_version', $this->version(...)),
            new TwigFunction('app_version_visible', $this->isVisible(...)),
            new TwigFunction('app_copyright_years', $this->copyrightYears(...)),
            new TwigFunction('app_project_url', static fn(): string => self::PROJECT_URL),
            new TwigFunction('app_license_url', static fn(): string => self::LICENSE_URL),
            new TwigFunction('app_documentation_url', static fn(string $locale, string $page): string => self::DOCUMENTATION_URL . ($locale === 'en' ? 'en' : 'fr') . '/' . $page),
        ];
    }

    public function version(): string
    {
        if ($this->version === null) {
            $file = $this->projectDir . '/VERSION';
            $content = is_file($file) ? trim((string) file_get_contents($file)) : '';
            $this->version = $content !== '' ? $content : '?';
        }

        return $this->version;
    }

    /** True if the version is shown at the bottom of the pages (SHOW_VERSION, 1 by default). */
    public function isVisible(): bool
    {
        return $this->visible;
    }

    /** Copyright years: "2026", then "2026–2027" in the following years. */
    public function copyrightYears(): string
    {
        $year = (int) $this->calendar->now()->format('Y');

        return $year > self::FIRST_YEAR ? self::FIRST_YEAR . '–' . $year : (string) self::FIRST_YEAR;
    }
}
