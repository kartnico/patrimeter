<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Twig;

use Doctrine\DBAL\DriverManager;
use Patrimeter\Application\Calendar;
use Patrimeter\Domain\AccountValue;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\WebAuthnCredentialRepository;
use Patrimeter\Security\CurrentUser;
use Patrimeter\Twig\AppExtension;
use Patrimeter\Twig\BudgetSankeySvg;
use Patrimeter\Twig\PrecautionGaugeSvg;
use Patrimeter\Twig\PyramidSvg;
use Patrimeter\Twig\QrCodeSvg;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppExtensionTest extends TestCase
{
    public function testSignClass(): void
    {
        self::assertSame('negative', AppExtension::signClass(-1));
        self::assertSame('positive', AppExtension::signClass(1));
        self::assertSame('', AppExtension::signClass(0));
    }

    public function testFrenchDate(): void
    {
        self::assertSame('31/10/2026', AppExtension::dateFr('2026-10-31'));
        self::assertSame('', AppExtension::dateFr(null));
        self::assertSame('', AppExtension::dateFr('31/10/2026'));
        self::assertSame('1 Oct 2026', AppExtension::dateFr('2026-10-01', 'en'));
    }

    public function testDateTimeInTheApplicationTimezone(): void
    {
        $paris = new \DateTimeZone('Europe/Paris');
        self::assertSame('15/11/2026 à 13:00', self::extension('fr')->datetimeFr('2026-11-15T12:00:00Z', $paris));
        self::assertSame('15 Nov 2026, 13:00', self::extension('en')->datetimeFr('2026-11-15T12:00:00Z', $paris));
        self::assertSame('', self::extension('fr')->datetimeFr('hier', $paris));
    }

    public function testValueBadge(): void
    {
        $extension = self::extension('fr');
        self::assertSame('<span class="badge badge-empty">aucun solde</span>', $extension->valueBadge(null));
        self::assertSame('<span class="badge badge-entered">saisi</span>', $extension->valueBadge(new AccountValue(1, Month::fromString('2026-11'), false)));
        self::assertStringContainsString('reporté · oct. 2026', $extension->valueBadge(new AccountValue(1, Month::fromString('2026-10'), true)));
        self::assertStringContainsString('carried over · Oct 2026', self::extension('en')->valueBadge(new AccountValue(1, Month::fromString('2026-10'), true)));
    }

    public function testRegistersFiltersAndFunctions(): void
    {
        $extension = self::extension('fr');

        self::assertSame(
            ['money', 'money_rounded', 'money_input', 'percent', 'sign_class', 'date_fr', 'datetime_fr', 'month_label', 'month_of', 'month_short', 'months_count'],
            array_map(static fn(TwigFilter $f): string => $f->getName(), $extension->getFilters()),
        );
        self::assertSame(
            ['levels', 'household_child_rows', 'value_badge', 'pyramid', 'budget_sankey', 'precaution_gauge', 'budget_groups', 'month_picker', 'theme_preference', 'locales', 'month_names', 'current_month_value', 'current_month', 'qr_code', 'has_security_key', 'share_percent', 'share_percent_label', 'account_ready'],
            array_map(static fn(TwigFunction $f): string => $f->getName(), $extension->getFunctions()),
        );
    }

    private static function extension(string $locale): AppExtension
    {
        $translator = new Translator($locale);
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['reporté · %month%' => 'carried over · %month%'], 'en');

        return new AppExtension(
            new PyramidSvg($translator),
            new BudgetSankeySvg($translator),
            new PrecautionGaugeSvg($translator),
            new QrCodeSvg(),
            new Calendar(new MockClock(), 'Europe/Paris'),
            new CurrentUser(new TokenStorage()),
            new WebAuthnCredentialRepository(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])),
            $translator,
        );
    }
}
