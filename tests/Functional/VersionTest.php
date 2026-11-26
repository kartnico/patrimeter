<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Application\Calendar;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Twig\VersionExtension;
use Symfony\Component\Clock\MockClock;

/**
 * Footer of every page: copyright, MIT license, link to the project; Patrimeter version (VERSION file, matching the
 * CHANGELOG), unless the administrator hides it (SHOW_VERSION=false).
 */
final class VersionTest extends FunctionalTestCase
{
    public function testVersionFileMatchesTheLatestReleaseOfTheChangelog(): void
    {
        $root = dirname(__DIR__, 2);
        $version = trim((string) file_get_contents("$root/VERSION"));
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);

        foreach (['CHANGELOG.md', 'CHANGELOG.en.md'] as $changelog) {
            self::assertSame(1, preg_match('/^## \[(\d+\.\d+\.\d+)\] - \d{4}-\d{2}-\d{2}$/m', (string) file_get_contents("$root/$changelog"), $match));
            self::assertSame($match[1], $version, "VERSION suit la dernière version de $changelog (docs/ci.md, « Publier une version »)");
        }
    }

    public function testTheFooterShowsCopyrightLicenseProjectAndVersion(): void
    {
        $version = trim((string) file_get_contents(dirname(__DIR__, 2) . '/VERSION'));

        $crawler = $this->client->request('GET', '/connexion');
        self::assertSelectorTextSame('footer.app-footer', "Patrimeter © 2026 · Logiciel libre sous licence MIT · version $version");
        $links = $crawler->filter('footer.app-footer a');
        self::assertSame([VersionExtension::PROJECT_URL, VersionExtension::LICENSE_URL], $links->each(static fn($link): ?string => $link->attr('href')));
        self::assertSame(['Patrimeter', 'Logiciel libre sous licence MIT'], $links->each(static fn($link): string => $link->text()));

        $this->logIn();
        $this->client->request('GET', '/comptes');
        self::assertSelectorTextSame('footer.app-footer', "Patrimeter © 2026 · Logiciel libre sous licence MIT · version $version");

    }

    public function testTheLicenseIsMitAndTheCopyrightStartsIn2026(): void
    {
        $license = (string) file_get_contents(dirname(__DIR__, 2) . '/LICENSE');
        self::assertStringStartsWith('MIT License', $license);
        self::assertStringContainsString('Copyright (c) ' . VersionExtension::FIRST_YEAR . ' ', $license);
    }

    public function testCopyrightYearsFollowTheCalendar(): void
    {
        self::assertSame('2026', self::footer(true, '2026-11-15')->copyrightYears());
        self::assertSame('2026–2028', self::footer(true, '2028-01-01')->copyrightYears());
    }

    public function testTheVersionIsShownByDefault(): void
    {
        self::assertSame('true', $_SERVER['SHOW_VERSION'] ?? $_ENV['SHOW_VERSION'] ?? null, 'valeur par défaut de .env');
        self::assertTrue(self::service(VersionExtension::class)->isVisible());
    }

    public function testTheAdministratorCanHideTheVersion(): void
    {
        self::getContainer()->set(VersionExtension::class, self::footer(false, '2026-11-15'));

        $this->client->request('GET', '/connexion');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('footer.app-footer', 'Patrimeter © 2026 · Logiciel libre sous licence MIT');
        self::assertSelectorNotExists('.app-version');
        self::assertStringNotContainsString('version', $this->client->getCrawler()->filter('footer.app-footer')->text());

        $this->logIn();
        $this->client->request('GET', '/comptes');
        self::assertSelectorNotExists('.app-version');
    }

    private static function footer(bool $visible, string $today): VersionExtension
    {
        return new VersionExtension(dirname(__DIR__, 2), $visible, new Calendar(new MockClock($today . ' 12:00:00', 'Europe/Paris'), 'Europe/Paris'));
    }
}
