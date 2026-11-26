<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Application\PortfolioService;
use Patrimeter\Application\Transfer\CsvImport;
use Patrimeter\Http\LocalePreference;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/** Bulk creation of accounts, loans and properties from a CSV file (documentation: CSV import). */
final class CsvImportTest extends FunctionalTestCase
{
    public function testAccountsPropertiesAndLoansAreCreatedFromTheFile(): void
    {
        self::createAccount('Déjà là', 'compte_courant');
        $this->logIn();
        // The loan comes before its property; amounts, dates and month written as a spreadsheet in French would.
        $this->import(<<<'CSV'
            Nom;Type;Ouverture;Solde;Mois;Part;Bien;Mode crédit;Clôture
            Crédit appartement;credit_immo_locatif;01/06/2018;180 000;;;Appartement Lyon;own;
            Appartement Lyon;Location nue;2018-06-01;250000;10/2026;50;;;
            Livret A;livret_a;2020-01-15;12 345,67;;;;;
            Vieux livret;LDDS;2015-03-01;100;2016-01;;;;2019-12-31
            CSV);

        self::assertResponseRedirects('/comptes', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('4 comptes créés depuis le fichier CSV.', $this->flashes());

        $accounts = $this->accountsByName();
        self::assertCount(4, $accounts);
        self::assertSame(5, self::queryInt('SELECT COUNT(*) FROM accounts'), 'added to the existing account');
        $portfolio = self::service(PortfolioService::class);
        $flat = $accounts['Appartement Lyon'];
        $loan = $accounts['Crédit appartement'];
        self::assertSame($flat['id'], $loan['linked_asset_id']);
        self::assertSame('own', $loan['loan_mode']);
        self::assertSame([['month' => '2018-06', 'share_millionths' => 500_000]], $portfolio->shares(self::owner(), self::id($flat)));
        self::assertSame([['month' => '2026-10', 'amount_cents' => 25_000_000]], $portfolio->statements(self::owner(), self::id($flat)));
        self::assertSame([['month' => '2026-11', 'amount_cents' => 18_000_000]], $portfolio->statements(self::owner(), self::id($loan)), 'no month: the current one');
        self::assertSame([['month' => '2026-11', 'amount_cents' => 1_234_567]], $portfolio->statements(self::owner(), self::id($accounts['Livret A'])));
        self::assertSame('2019-12-31', $accounts['Vieux livret']['closed_on']);
        self::assertSame('2018-06-01', $loan['opened_on']);
    }

    public function testAnEnglishFileWithCommasAndAWindowsEncodingIsRead(): void
    {
        $this->logIn();
        $csv = "name,type,opened_on,balance\r\n\"Savings, joint\",Livret A,2020-01-15,\"1,234.50\"\r\nÉpargne,pel_cel,2021-02-01,10\r\n";
        $this->import(mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'), 'en');

        self::assertResponseRedirects('/comptes', Response::HTTP_SEE_OTHER);
        $accounts = $this->accountsByName();
        self::assertSame(['Savings, joint', 'Épargne'], array_keys($accounts));
        self::assertSame([['month' => '2026-11', 'amount_cents' => 123_450]], self::service(PortfolioService::class)->statements(self::owner(), self::id($accounts['Savings, joint'])));
    }

    public function testARefusedLineImportsNothingAndIsNamed(): void
    {
        $this->logIn();
        $cases = [
            "nom;type;ouverture\nBon;livret_a;2020-01-01\nMauvais;type_inconnu;2020-01-01\n" => 'Ligne 3 : Type de compte « type_inconnu » inconnu',
            "nom;type;ouverture;part\nLivret;livret_a;2020-01-01;50\n" => 'Ligne 2 : Part réservée aux biens immobiliers',
            "nom;type;ouverture;bien\nCrédit;credit_conso;2020-01-01;Nulle part\n" => 'Ligne 2 : Bien « Nulle part » introuvable',
            "nom;type;ouverture\nA;livret_a;2020-01-01\na;ldds;2020-01-01\n" => 'Ligne 3 : Nom « a » déjà utilisé à la ligne 2',
            "nom;type;ouverture;solde\nA;livret_a;2020-13-01;10\n" => 'Ligne 2 : Date d\'ouverture invalide',
            "nom;type;ouverture;solde\nA;livret_a;2020-01-01;douze\n" => 'Ligne 2 : Solde invalide',
            "nom;type;couleur\nA;livret_a;bleu\n" => 'Ligne 1 : Colonne « couleur » inconnue',
            "nom;type\nA;livret_a\n" => 'Ligne 1 : Colonnes nom, type et ouverture obligatoires.',
            "nom;type;ouverture\n" => 'Aucun compte dans ce fichier',
        ];
        foreach ($cases as $csv => $message) {
            $this->import($csv);
            self::assertResponseRedirects('/donnees#csv', Response::HTTP_SEE_OTHER);
            $this->client->followRedirect();
            self::assertStringContainsString('Rien n\'a été importé. ' . $message, $this->flashes(), $csv);
            self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM accounts'), 'all or nothing: ' . $csv);
        }
    }

    public function testTheModelCanBeImportedAsIs(): void
    {
        $this->logIn();
        $this->client->request('GET', '/donnees/modele.csv');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=utf-8');
        self::assertStringContainsString('filename=patrimeter-comptes.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith("\u{FEFF}nom;type;ouverture;solde", $this->body());

        $this->import(CsvImport::model('fr'));
        self::assertResponseRedirects('/comptes', Response::HTTP_SEE_OTHER);
        self::assertCount(3, $this->accountsByName());
        $this->import(CsvImport::model('en'), 'en');
        self::assertCount(6, $this->accountsByName());
    }

    public function testTheExamplesOfTheDocumentationAreImported(): void
    {
        $this->logIn();
        foreach (['fr' => 4, 'en' => 8] as $locale => $total) {
            $page = (string) file_get_contents(__DIR__ . "/../../documentation/$locale/utilisation/import-csv.md");
            self::assertSame(1, preg_match('/```text\n(.+?)\n```/s', $page, $example), $locale);
            $this->import($example[1], $locale);
            self::assertResponseRedirects('/comptes', Response::HTTP_SEE_OTHER, $locale);
            self::assertSame($total, self::queryInt('SELECT COUNT(*) FROM accounts'), $locale);
        }
    }

    public function testTheDataPageOffersTheImport(): void
    {
        $this->logIn();
        $this->client->request('GET', '/donnees');
        self::assertSelectorExists('#csv form[action="/donnees/import-csv"] input[type=file][name=fichier_csv]');
        self::assertSelectorExists('#csv a[href="/donnees/modele.csv"]');
    }

    private function import(string $content, string $locale = 'fr'): void
    {
        $file = tempnam(sys_get_temp_dir(), 'patrimeter-csv');
        self::assertIsString($file);
        file_put_contents($file, $content);
        if ($locale === 'en') {
            $this->client->getCookieJar()->set(new Cookie(LocalePreference::COOKIE, 'en'));
        }
        $this->client->request('POST', '/donnees/import-csv', ['_csrf' => $this->csrfToken('/donnees')], ['fichier_csv' => new UploadedFile($file, 'comptes.csv', 'text/csv', null, true)]);
    }

    /** @return array<string, array<string, mixed>> */
    private function accountsByName(): array
    {
        $accounts = [];
        foreach (self::service(PortfolioService::class)->accountList(self::owner()) as $account) {
            if ($account['name'] !== 'Déjà là') {
                self::assertIsString($account['name']);
                $accounts[$account['name']] = $account;
            }
        }

        return $accounts;
    }

    /** @param array<string, mixed> $account */
    private static function id(array $account): int
    {
        self::assertIsInt($account['id']);

        return $account['id'];
    }
}
