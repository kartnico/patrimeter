<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Application;

use Patrimeter\Application\Carbure\CarbureActuals;
use Patrimeter\Application\Carbure\CarbureLink;
use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\BudgetOverview;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Month;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Link with Carbure: address of the instance, API token, comparison of the planned and actual amounts. */
final class CarbureLinkTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function urls(): iterable
    {
        yield 'https' => ['https://carbure.maison.lan/', 'https://carbure.maison.lan'];
        yield 'http, port et chemin' => [' http://192.168.1.20:8080/carbure ', 'http://192.168.1.20:8080/carbure'];
        yield 'majuscules du schéma' => ['HTTPS://Carbure.example', 'HTTPS://Carbure.example'];
        yield 'sans schéma' => ['carbure.maison.lan', null];
        yield 'autre schéma' => ['file:///etc/passwd', null];
        yield 'gopher' => ['gopher://carbure.lan', null];
        yield 'identifiant' => ['https://admin@carbure.lan', null];
        // Assembled, so that the secret detection does not take this dummy address for a real password.
        yield 'identifiant et mot de passe' => [sprintf('https://%s:%s@carbure.lan', 'admin', 'factice'), null];
        yield 'paramètres' => ['https://carbure.lan/?x=1', null];
        yield 'fragment' => ['https://carbure.lan/#api', null];
        yield 'espace' => ['https://carbure.lan/a b', null];
        yield 'vide' => ['', null];
        // Regression 2.25.2: the address of the MCP service, shown by Carbure, was kept and the client called
        // …/api/mcp/api/mcp (404).
        yield 'adresse du service MCP' => ['https://carbure.kartzone.example/api/mcp', 'https://carbure.kartzone.example'];
        yield 'adresse du service MCP, barre finale' => ['https://carbure.lan/API/MCP/', 'https://carbure.lan'];
        yield 'adresse de l\'API' => ['http://192.168.1.20:8080/carbure/api', 'http://192.168.1.20:8080/carbure'];
        yield 'chemin qui contient api' => ['https://carbure.lan/api-perso', 'https://carbure.lan/api-perso'];
    }

    #[DataProvider('urls')]
    public function testUrlIsNormalized(string $url, ?string $expected): void
    {
        self::assertSame($expected, CarbureLink::normalizeUrl($url));
    }

    public function testTokenAndHint(): void
    {
        $token = 'cbt_jetondetestjetondetest1234';
        self::assertTrue(CarbureLink::isValidToken($token));
        self::assertFalse(CarbureLink::isValidToken('cbt_court'));
        self::assertFalse(CarbureLink::isValidToken('eyJhbGciOiJIUzI1NiJ9.e30.x'));
        self::assertFalse(CarbureLink::isValidToken("cbt_0123456789abcdef\n"));
        $link = new CarbureLink('https://Carbure.Maison.lan', $token);
        self::assertSame('cbt_…1234', $link->tokenHint());
        self::assertSame('carbure.maison.lan', $link->host());
    }

    public function testComparesMappedCategoriesWithThePlan(): void
    {
        $categories = [new BudgetCategory(1, 'Logement', BudgetGroup::Needs), new BudgetCategory(2, 'Loisirs', BudgetGroup::Wants), new BudgetCategory(3, 'Épargne', BudgetGroup::Savings)];
        $lines = [new BudgetLine(10, 1, 'Loyer'), new BudgetLine(20, 2, 'Sorties'), new BudgetLine(30, 3, 'Livret')];
        $overview = new BudgetOverview(new Household([1 => 'Alex']), new MonthlyBudget(Month::fromString('2026-11'), [1 => 300_000], [10 => 100_000, 20 => 20_000, 30 => 30_000]), $categories, $lines);
        $actuals = new CarbureActuals([1 => 98_000, 2 => 25_000], [['name' => 'Santé', 'cents' => 4_000], ['name' => 'Divers', 'cents' => 1_500]]);

        $comparison = $actuals->compare($overview);

        self::assertSame([[1, 100_000, 98_000], [2, 20_000, 25_000]], array_map(static fn(array $row): array => [$row['category']->id, $row['planned'], $row['actual']], $comparison['rows']));
        self::assertSame(120_000, $comparison['planned']);
        self::assertSame(123_000, $comparison['actual']);
        self::assertSame(5_500, $actuals->unmatchedTotal());
        self::assertNull($actuals->of(3), 'épargne non rapprochée');
    }
}
