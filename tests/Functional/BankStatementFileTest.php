<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bank statement read on an account page: its closing balance fills in the statement form (month and amount), to check
 * and save as any statement; nothing is saved before; an unreadable file says what to export instead.
 */
final class BankStatementFileTest extends FunctionalTestCase
{
    private const string OFX = "OFXHEADER:100\n<OFX><STMTRS><CURDEF>EUR\n<LEDGERBAL><BALAMT>1234.56<DTASOF>20261031\n</LEDGERBAL></STMTRS></OFX>\n";

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logIn();
    }

    public function testTheBalanceFillsInTheStatementFormWithoutSavingIt(): void
    {
        $id = self::createAccount('Compte courant', 'livret_a');
        $crawler = $this->upload($id, self::OFX);

        self::assertResponseIsSuccessful();
        // Amounts are written with non-breaking spaces: compared with plain ones.
        self::assertStringContainsString('Relevé lu : solde de 1 234,56 € au 31/10/2026.', self::plain($crawler->filter('p[role="status"]')->text()));
        self::assertSame('2026-10', $crawler->filter('input[name="mois"]')->attr('value'));
        self::assertSame('1234,56', $crawler->filter('#st-montant')->attr('value'), 'as typed in the entry fields');
        self::assertSame([], self::service(StatementRepository::class)->forAccount(self::owner(), $id), 'saved only once confirmed');
    }

    public function testALoanKeepsTheCapitalOwedPositive(): void
    {
        $loan = self::createAccount('Crédit auto', 'credit_immo_rp');
        $crawler = $this->upload($loan, str_replace('1234.56', '-8000.00', self::OFX));

        self::assertResponseIsSuccessful();
        self::assertSame('8000,00', $crawler->filter('#st-montant')->attr('value'));
    }

    public function testAnUnreadableFileSaysWhatToExport(): void
    {
        $id = self::createAccount('Compte courant', 'livret_a');
        $this->upload($id, "!Type:Bank\nD31/10/2026\nT-12.00\n^\n");

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('#st-fichier-error', 'Les fichiers QIF ne donnent pas le solde du compte');
        self::assertSelectorExists('#st-fichier[aria-invalid="true"]');
    }

    public function testABalanceBeforeTheOpeningOfTheAccountIsRefused(): void
    {
        $id = self::createAccount('Compte courant', 'livret_a', '2026-11-01');
        $this->upload($id, self::OFX);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('#st-fichier-error', 'Le compte n\'est pas ouvert en');
    }

    private static function plain(string $text): string
    {
        return str_replace(["\u{202F}", "\u{00A0}"], ' ', $text);
    }

    private function upload(int $account, string $content): Crawler
    {
        $file = tempnam(sys_get_temp_dir(), 'patrimeter-ofx');
        self::assertIsString($file);
        file_put_contents($file, $content);

        return $this->client->request('POST', "/comptes/$account/releves/fichier", ['_csrf' => $this->csrfToken("/comptes/$account")], ['releve' => new UploadedFile($file, 'releve.ofx', 'application/x-ofx', null, true)]);
    }
}
