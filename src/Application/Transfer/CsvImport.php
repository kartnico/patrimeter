<?php

declare(strict_types=1);

namespace Patrimeter\Application\Transfer;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Payload\AccountPayload;
use Patrimeter\Application\Payload\ClosePayload;
use Patrimeter\Application\Payload\LoanLinkPayload;
use Patrimeter\Application\Payload\SharePayload;
use Patrimeter\Application\Payload\StatementPayload;
use Patrimeter\Application\PortfolioService;
use Patrimeter\Application\PropertyPortfolio;
use Patrimeter\Application\ValidationFailed;
use Patrimeter\Domain\DateInput;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Domain\OwnershipShares;
use Patrimeter\Domain\UserId;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

/**
 * Bulk import of accounts, loans and properties from a CSV file written by hand or with a spreadsheet (format:
 * documentation, "Import CSV"; model: self::model()). One line per account; the accounts are added to those that
 * already exist. Everything goes through PortfolioService, like the API and the JSON import: same validations, data
 * encrypted with the key of the importing user. All or nothing: the first refused line cancels the whole import.
 *
 * Tolerant reading, for the files of a spreadsheet: separator ";", "," or tab (that of the header), UTF-8 with or
 * without BOM or Windows-1252 (Excel in French), column names in French or English, with or without accents, dates
 * AAAA-MM-JJ or JJ/MM/AAAA, amounts in the language of the page ("1 234,56" or "1,234.56"), account type given by its
 * code or its label.
 */
final class CsvImport
{
    public const int MAX_BYTES = 1024 * 1024;
    public const int MAX_ROWS = 500;

    /** Columns: name in French => name in English (the key used below). */
    public const array COLUMNS = [
        'nom' => 'name',
        'type' => 'type',
        'ouverture' => 'opened_on',
        'solde' => 'balance',
        'mois' => 'month',
        'niveau' => 'level',
        'nature' => 'nature',
        'part' => 'share',
        'bien' => 'property',
        'mode_credit' => 'loan_mode',
        'cloture' => 'closed_on',
    ];
    private const array REQUIRED = ['name', 'type', 'opened_on'];
    private const array NATURES = ['actif' => 'asset', 'asset' => 'asset', 'passif' => 'liability', 'liability' => 'liability', 'credit' => 'liability'];
    private const array LOAN_MODES = ['prorata' => 'prorata', 'own' => 'own', 'propre' => 'own', 'montant_propre' => 'own'];

    public function __construct(
        private readonly PortfolioService $portfolio,
        private readonly Connection $connection,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * @return int number of accounts created
     *
     * @throws CsvImportFailed nothing is imported
     */
    public function import(UserId $owner, string $csv, string $locale): int
    {
        $rows = $this->rows($csv);
        $types = $this->types();

        return $this->connection->transactional(function () use ($owner, $rows, $types, $locale): int {
            $existing = $this->existingProperties($owner);
            // Accounts of the file, by normalized name: identifier and line.
            $ids = [];
            $lines = [];
            foreach ($rows as $line => $row) {
                $name = $row['name'];
                $type = $types[self::normalize($row['type'])] ?? throw new CsvImportFailed($line, [t('Type de compte « %type% » inconnu (codes : documentation, Import CSV).', ['%type%' => $row['type']])]);
                $key = self::normalize($name);
                if (isset($lines[$key])) {
                    throw new CsvImportFailed($line, [t('Nom « %name% » déjà utilisé à la ligne %other% : chaque compte du fichier a un nom distinct.', ['%name%' => $name, '%other%' => $lines[$key]])]);
                }
                $opened = self::date($row['opened_on']) ?? throw new CsvImportFailed($line, [t('Date d\'ouverture invalide (AAAA-MM-JJ ou JJ/MM/AAAA).')]);
                $balance = self::amount($row['balance'], $locale, $line);
                $month = $row['month'] === '' ? null : (self::month($row['month']) ?? throw new CsvImportFailed($line, [t('Mois du solde invalide (AAAA-MM ou MM/AAAA).')]));
                $account = $this->guard($line, fn(): array => $this->portfolio->createAccount($owner, new AccountPayload(
                    name: $name,
                    type_id: $type,
                    level: self::level($row['level'], $line),
                    nature: self::choice(self::NATURES, $row['nature'], $line, t('Nature : actif ou passif.')),
                    opened_on: $opened,
                    initial_amount_cents: $month === null ? $balance : null,
                )));
                $id = self::identifier($account);
                $ids[$key] = $id;
                $lines[$key] = $line;
                if ($month !== null && $balance !== null) {
                    $this->guard($line, fn() => $this->portfolio->saveStatement($owner, $id, $month, new StatementPayload($balance)));
                }
                if ($row['share'] !== '') {
                    $share = OwnershipShares::parsePercent($row['share']) ?? throw new CsvImportFailed($line, [t('Part invalide : un pourcentage de 0 à 100, par exemple 50 ou 33,33.')]);
                    $this->guard($line, fn() => $this->portfolio->saveShare($owner, $id, Month::fromDate($opened)->toString(), new SharePayload($share)));
                }
            }
            // Links and closings once all the accounts exist: a loan can come before its property in the file.
            foreach ($rows as $line => $row) {
                $id = $ids[self::normalize($row['name'])];
                if ($row['property'] !== '') {
                    $property = self::normalize($row['property']);
                    $asset = $ids[$property] ?? $existing[$property] ?? throw new CsvImportFailed($line, [t('Bien « %property% » introuvable : ni dans le fichier, ni parmi vos biens.', ['%property%' => $row['property']])]);
                    $mode = self::choice(self::LOAN_MODES, $row['loan_mode'], $line, t('Mode du crédit : prorata ou own.'));
                    $this->guard($line, fn() => $this->portfolio->linkLoan($owner, $id, new LoanLinkPayload($asset, $mode)));
                } elseif ($row['loan_mode'] !== '') {
                    throw new CsvImportFailed($line, [t('Mode du crédit sans bien : indiquez le bien dans la colonne « bien ».')]);
                }
                if ($row['closed_on'] !== '') {
                    $closed = self::date($row['closed_on']) ?? throw new CsvImportFailed($line, [t('Date de clôture invalide (AAAA-MM-JJ ou JJ/MM/AAAA).')]);
                    $this->guard($line, fn(): array => $this->portfolio->archiveAccount($owner, $id, new ClosePayload($closed)));
                }
            }

            return count($rows);
        });
    }

    /**
     * Model file in the language of the page: header and three example lines (a savings account, a property and its
     * loan).
     */
    public static function model(string $locale): string
    {
        $english = $locale === 'en';
        $header = $english ? array_values(self::COLUMNS) : array_keys(self::COLUMNS);
        $lines = $english
            ? [
                ['Savings account', 'livret_a', '2020-01-15', '12345.67', '', '', '', '', '', '', ''],
                ['Flat in Lyon', 'location_nue', '2018-06-01', '250000', '', '', '', '50', '', '', ''],
                ['Flat loan', 'credit_immo_locatif', '2018-06-01', '180000', '', '', '', '', 'Flat in Lyon', 'prorata', ''],
            ]
            : [
                ['Livret A', 'livret_a', '2020-01-15', '12345,67', '', '', '', '', '', '', ''],
                ['Appartement Lyon', 'location_nue', '2018-06-01', '250000', '', '', '', '50', '', '', ''],
                ['Crédit appartement', 'credit_immo_locatif', '2018-06-01', '180000', '', '', '', '', 'Appartement Lyon', 'prorata', ''],
            ];
        $separator = $english ? ',' : ';';
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Mémoire temporaire indisponible.');
        }
        foreach ([$header, ...$lines] as $line) {
            fputcsv($handle, $line, $separator, '"', '', "\r\n");
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return "\u{FEFF}" . (is_string($csv) ? $csv : '');
    }

    /**
     * Lines of the file, by line number (that of a spreadsheet: the header is line 1), with all the columns.
     *
     * @return array<int, array<string, string>>
     *
     * @throws CsvImportFailed
     */
    private function rows(string $csv): array
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw new CsvImportFailed(0, [t('Fichier trop volumineux (1 Mo au plus).')]);
        }
        if (str_starts_with($csv, "\u{FEFF}")) {
            $csv = substr($csv, 3);
        }
        if (!mb_check_encoding($csv, 'UTF-8')) {
            $csv = self::text(mb_convert_encoding($csv, 'UTF-8', 'Windows-1252'));
        }
        $first = strtok($csv, "\r\n");
        if (!is_string($first) || trim($first) === '') {
            throw new CsvImportFailed(0, [t('Fichier vide : la première ligne nomme les colonnes.')]);
        }
        $counts = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
        $separator = (string) array_search(max($counts), $counts, true);
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Mémoire temporaire indisponible.');
        }
        fwrite($handle, $csv);
        rewind($handle);
        try {
            $cells = fgetcsv($handle, null, $separator, '"', '');
            $header = $this->header(is_array($cells) ? $cells : []);
            $rows = [];
            $line = 1;
            while (is_array($cells = fgetcsv($handle, null, $separator, '"', ''))) {
                ++$line;
                $cells = array_map(static fn(?string $cell): string => trim($cell ?? ''), $cells);
                if (implode('', $cells) === '') {
                    continue;
                }
                if (count($rows) === self::MAX_ROWS) {
                    throw new CsvImportFailed(0, [t('Trop de lignes : %max% comptes au plus par fichier.', ['%max%' => self::MAX_ROWS])]);
                }
                if (count($cells) > count($header)) {
                    throw new CsvImportFailed($line, [t('Plus de valeurs que de colonnes : vérifiez le séparateur et les guillemets.')]);
                }
                $row = array_fill_keys(array_values(self::COLUMNS), '');
                foreach ($header as $index => $column) {
                    $row[$column] = $cells[$index] ?? '';
                }
                foreach (self::REQUIRED as $required) {
                    if ($row[$required] === '') {
                        throw new CsvImportFailed($line, [t('Colonnes nom, type et ouverture obligatoires.')]);
                    }
                }
                $rows[$line] = $row;
            }
        } finally {
            fclose($handle);
        }
        if ($rows === []) {
            throw new CsvImportFailed(0, [t('Aucun compte dans ce fichier : une ligne par compte, après celle des colonnes.')]);
        }

        return $rows;
    }

    /**
     * Columns of the header, by position, in their English name.
     *
     * @param array<int, string|null> $cells
     *
     * @return array<int, string>
     *
     * @throws CsvImportFailed
     */
    private function header(array $cells): array
    {
        $known = self::COLUMNS + array_combine(array_values(self::COLUMNS), array_values(self::COLUMNS));
        $header = [];
        foreach ($cells as $index => $cell) {
            $name = self::normalize($cell ?? '');
            if ($name === '') {
                continue;
            }
            $column = $known[$name] ?? throw new CsvImportFailed(1, [t('Colonne « %column% » inconnue (colonnes : documentation, Import CSV).', ['%column%' => trim($cell ?? '')])]);
            if (in_array($column, $header, true)) {
                throw new CsvImportFailed(1, [t('Colonne « %column% » en double.', ['%column%' => trim($cell ?? '')])]);
            }
            $header[$index] = $column;
        }
        if (array_diff(self::REQUIRED, $header) !== []) {
            throw new CsvImportFailed(1, [t('Colonnes nom, type et ouverture obligatoires.')]);
        }

        return $header;
    }

    /**
     * Identifier of each account type, by normalized code and label (French and English).
     *
     * @return array<string, int>
     */
    private function types(): array
    {
        $types = [];
        foreach ($this->portfolio->accountTypes() as $type) {
            $id = $type['id'];
            if (!is_int($id) || !is_string($type['code']) || !is_string($type['label'])) {
                continue;
            }
            $types[self::normalize($type['code'])] = $id;
            $types[self::normalize($type['label'])] = $id;
            $types[self::normalize($this->translator->trans($type['label'], [], 'account_types', 'en'))] = $id;
        }

        return $types;
    }

    /**
     * Properties already entered, by normalized name; a name shared by two properties is left out (ambiguous).
     *
     * @return array<string, int>
     */
    private function existingProperties(UserId $owner): array
    {
        $properties = [];
        $ambiguous = [];
        foreach ($this->portfolio->accountList($owner) as $account) {
            $level = Level::tryFrom(self::text($account['level'] ?? null));
            $nature = Nature::tryFrom(self::text($account['nature'] ?? null));
            if ($level === null || $nature === null || !PropertyPortfolio::isPropertyKind($level, $nature)) {
                continue;
            }
            $key = self::normalize(self::text($account['name'] ?? null));
            if (isset($properties[$key])) {
                $ambiguous[] = $key;
            }
            $properties[$key] = self::identifier($account);
        }

        return array_diff_key($properties, array_flip($ambiguous));
    }

    /** Text value, or '' if it is not one (values read from PortfolioService or converted). */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $account account created or listed by PortfolioService */
    private static function identifier(array $account): int
    {
        $id = $account['id'] ?? null;
        if (!is_int($id)) {
            throw new \UnexpectedValueException('Identifiant de compte attendu.');
        }

        return $id;
    }

    /**
     * PortfolioService operation; its refusal names the line concerned.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function guard(int $line, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationFailed $failure) {
            throw new CsvImportFailed($line, array_map(static fn(string $error): TranslatableMessage => t($error), array_values($failure->errors)));
        }
    }

    /** Lower case, without accents, words joined by "_": "Clôture" => "cloture", "Mode crédit" => "mode_credit". */
    private static function normalize(string $text): string
    {
        $text = strtr(mb_strtolower(trim($text)), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae',
        ]);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $text), '_');
    }

    /** AAAA-MM-JJ, or JJ/MM/AAAA (also with "-" or "."), as AAAA-MM-JJ; null if invalid. */
    private static function date(string $value): ?string
    {
        if (preg_match('/\A(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})\z/', $value, $parts) === 1) {
            $value = sprintf('%s-%02d-%02d', $parts[3], (int) $parts[2], (int) $parts[1]);
        }

        return DateInput::isValid($value) ? $value : null;
    }

    /** AAAA-MM, or MM/AAAA, as AAAA-MM; null if invalid. */
    private static function month(string $value): ?string
    {
        if (preg_match('/\A(\d{1,2})[\/.-](\d{4})\z/', $value, $parts) === 1) {
            $value = sprintf('%s-%02d', $parts[2], (int) $parts[1]);
        }

        return Month::tryFromString($value)?->toString();
    }

    /** @throws CsvImportFailed */
    private static function amount(string $value, string $locale, int $line): ?int
    {
        if ($value === '') {
            return null;
        }
        try {
            return Money::parse($value, $locale);
        } catch (\InvalidArgumentException) {
            throw new CsvImportFailed($line, [t('Solde invalide : un montant en euros, par exemple 1234,56.')]);
        }
    }

    /** @throws CsvImportFailed */
    private static function level(string $value, int $line): ?string
    {
        if ($value === '') {
            return null;
        }

        $level = Level::tryFrom(self::normalize($value))
            ?? throw new CsvImportFailed($line, [t('Niveau inconnu : residence, precaution, fonds_euros, actions, immobilier ou exotique.')]);

        return $level->value;
    }

    /**
     * Value of a column with a closed list, by normalized alias; null if empty.
     *
     * @param array<string, string> $choices
     *
     * @throws CsvImportFailed
     */
    private static function choice(array $choices, string $value, int $line, TranslatableMessage $error): ?string
    {
        if ($value === '') {
            return null;
        }

        return $choices[self::normalize($value)] ?? throw new CsvImportFailed($line, [$error]);
    }
}
