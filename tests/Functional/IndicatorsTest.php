<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\CustomIndicatorRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Custom indicators: created from /indicateurs, shown there and on the wealth page for the month, changed with their
 * history, deleted; stored encrypted (their name never in clear in the database).
 */
final class IndicatorsTest extends FunctionalTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logIn();
    }

    public function testAnIndicatorAddsUpItsLevelsAndAccountsMonthAfterMonth(): void
    {
        $livret = self::createAccount('Livret A', 'livret_a');
        $pea = self::createAccount('PEA', 'pea');
        $statements = self::service(StatementRepository::class);
        foreach (['2026-10' => [1_000_000, 2_000_000], '2026-11' => [1_100_000, 2_500_000]] as $month => [$a, $b]) {
            $statements->save(self::owner(), $livret, Month::fromString($month), $a);
            $statements->save(self::owner(), $pea, Month::fromString($month), $b);
        }

        $this->submit('/indicateurs', ['name' => 'Retraite', 'levels' => ['actions'], 'accounts' => [(string) $livret], 'share' => '1'], '/indicateurs');
        self::assertResponseRedirects('/indicateurs', Response::HTTP_SEE_OTHER);
        $crawler = $this->client->followRedirect();
        $row = $crawler->filter('table.ledger tbody tr')->first();
        self::assertStringContainsString('Retraite', $row->text());
        self::assertStringContainsString('36 000,00', self::plain($row->text()), 'shares level 25 000 + Livret A 11 000');
        self::assertStringContainsString('+6 000,00', self::plain($row->text()), 'over a month: 30 000 then 36 000');
        self::assertStringContainsString('100,0 % du patrimoine net', self::plain($row->text()));

        $this->client->request('GET', '/');
        self::assertSelectorTextContains('#indicators-title', 'Mes indicateurs');
        self::assertSelectorTextContains('.indicators-card', 'Retraite');

        $stored = self::service(Connection::class)->fetchOne('SELECT ciphertext FROM custom_indicators');
        self::assertIsString($stored);
        self::assertStringNotContainsString('Retraite', $stored, 'encrypted');
    }

    public function testAnIndicatorIsChangedWithItsHistoryThenDeleted(): void
    {
        $livret = self::createAccount('Livret A', 'livret_a');
        self::service(StatementRepository::class)->save(self::owner(), $livret, Month::fromString('2026-11'), 1_000_000);
        $this->submit('/indicateurs', ['name' => 'Épargne', 'accounts' => [(string) $livret]], '/indicateurs');
        $id = self::queryInt('SELECT id FROM custom_indicators');

        $crawler = $this->client->request('GET', "/indicateurs/$id");
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Épargne');
        self::assertStringContainsString('Novembre 2026', $crawler->filter('#indicator-history-title + table')->text());

        $this->submit("/indicateurs/$id", ['name' => 'Précaution', 'levels' => ['precaution']], "/indicateurs/$id");
        self::assertResponseRedirects('/indicateurs', Response::HTTP_SEE_OTHER);
        $indicator = self::service(CustomIndicatorRepository::class)->find(self::owner(), $id);
        self::assertNotNull($indicator);
        self::assertSame('Précaution', $indicator->name);

        $this->submit("/indicateurs/$id/supprimer", [], "/indicateurs/$id");
        self::assertResponseRedirects('/indicateurs', Response::HTTP_SEE_OTHER);
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM custom_indicators'));
    }

    public function testAnIndicatorNeedsANameAndSomethingToAddUp(): void
    {
        $this->submit('/indicateurs', ['name' => ' '], '/indicateurs');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorExists('#err-name');
        self::assertSelectorTextContains('#err-perimeter', 'Choisissez au moins un niveau ou un compte.');
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM custom_indicators'));
    }

    private static function plain(string $text): string
    {
        return str_replace(["\u{202F}", "\u{00A0}"], ' ', $text);
    }
}
