<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;

/** Trends over 3, 6 and 12 months: net worth on the wealth page, spending and savings on the budget page. */
final class TrendsTest extends FunctionalTestCase
{
    public function testTheWealthPageShowsTheChangeOverEachPeriod(): void
    {
        $livret = self::createAccount('Livret A', 'livret_a', '2024-01-01');
        $statements = self::service(StatementRepository::class);
        foreach (Month::range(Month::fromString('2025-05'), Month::fromString('2026-11')) as $index => $month) {
            $statements->save(self::owner(), $livret, $month, 1_000_000 + $index * 10_000);
        }
        $this->logIn();

        $rows = $this->client->request('GET', '/')->filter('.trends-card tbody tr');

        self::assertCount(3, $rows);
        self::assertStringContainsString('3 mois', $rows->eq(0)->text());
        self::assertStringContainsString('+300,00', self::plain($rows->eq(0)->text()));
        self::assertStringContainsString('soit +100,00 € par mois', self::plain($rows->eq(0)->text()));
        self::assertStringContainsString('+1 200,00', self::plain($rows->eq(2)->text()));
        self::assertStringContainsString('—', $rows->eq(2)->filter('td')->eq(1)->text(), 'no data two years ago');
    }

    public function testTheBudgetPageShowsTheAveragesOverEachPeriod(): void
    {
        $owner = self::owner();
        $ids = self::createBudget($owner, 'Camille', 'Logement', 'Loyer', 100_000, '2025-01');
        self::service(BudgetRepository::class)->saveBudget($owner, new MonthlyBudget(Month::fromString('2026-09'), [Household::FIRST_ADULT => 300_000], [$ids['line'] => 130_000]));
        $this->logIn();

        $rows = $this->client->request('GET', '/budget?mois=2026-11')->filter('.budget-trends tbody tr');

        self::assertCount(3, $rows);
        self::assertStringContainsString('1 300,00', self::plain($rows->eq(0)->text()));
        self::assertStringContainsString('avant : 1 000,00', self::plain($rows->eq(0)->text()));
        self::assertStringContainsString('1 150,00', self::plain($rows->eq(1)->text()), 'six months: three at 1 000, three at 1 300');
    }

    private static function plain(string $text): string
    {
        return str_replace(["\u{202F}", "\u{A0}"], ' ', $text);
    }
}
