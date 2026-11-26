<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Twig;

use Patrimeter\Twig\NavigationExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Entry of the main navigation and tab of My account, from the path of the page. */
final class NavigationExtensionTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, ?string}> path, section, tab of My account */
    public static function paths(): iterable
    {
        yield 'wealth' => ['/', 'wealth', null];
        yield 'former history' => ['/historique', 'wealth', null];
        yield 'report' => ['/rapport', 'wealth', null];
        yield 'budget' => ['/budget', 'budget', null];
        yield 'monthly entry' => ['/saisie', 'entry', null];
        yield 'budget entry' => ['/budget/saisie', 'entry', null];
        yield 'profile' => ['/securite', 'account', 'profile'];
        yield 'data' => ['/donnees', 'account', 'profile'];
        yield 'sign-in' => ['/securite/connexion', 'account', 'sign_in'];
        yield 'authenticator app' => ['/securite/application', 'account', 'sign_in'];
        yield 'agents' => ['/securite/agents-ia', 'account', 'agents'];
        yield 'former journal' => ['/securite/journal', 'account', 'sign_in'];
        yield 'accounts' => ['/comptes', 'account', 'accounts'];
        yield 'one account' => ['/comptes/12', 'account', 'accounts'];
        yield 'properties' => ['/biens/nouveau', 'account', 'properties'];
        yield 'household' => ['/budget/foyer', 'account', 'budget'];
        yield 'category' => ['/budget/categories/3', 'account', 'budget'];
        yield 'budget line' => ['/budget/postes/4', 'account', 'budget'];
        yield 'carbure' => ['/budget/carbure', 'account', 'carbure'];
        yield 'sign-in page' => ['/connexion', null, null];
        yield 'look-alike prefix' => ['/comptesx', null, null];
    }

    #[DataProvider('paths')]
    public function testSectionAndTab(string $path, ?string $section, ?string $tab): void
    {
        self::assertSame($section, NavigationExtension::section($path));
        self::assertSame($tab, NavigationExtension::accountTab($path));
    }

    public function testTheCurrentMonthStaysOnAPageThatShowsAMonth(): void
    {
        self::assertSame('/budget/saisie?mois=2026-11', NavigationExtension::currentMonthPath('/budget/saisie', '2026-11'));
        self::assertSame('/saisie?mois=2026-11', NavigationExtension::currentMonthPath('/saisie', '2026-11'));
        self::assertSame('/?mois=2026-11', NavigationExtension::currentMonthPath('/comptes', '2026-11'), 'elsewhere: the wealth page');
    }
}
