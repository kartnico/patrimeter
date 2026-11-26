<?php

declare(strict_types=1);

namespace Patrimeter\Domain\BankStatement;

/**
 * Closing balance of a bank statement exported by the bank, for one account:
 * - OFX (and QFX), version 1 (SGML, tags not always closed) or 2 (XML): LEDGERBAL, its BALAMT and DTASOF;
 * - CAMT.053 (ISO 20022 XML): the balance of type CLBD ("closing booked"), its amount, direction and date.
 * QIF and CSV exports give transactions without the balance: they are refused with a message that says so. Amounts in
 * euros only; a file with several accounts is refused (one account at a time, the one whose page is open).
 */
final class BankStatementReader
{
    /** Euros at most before the decimal point, as in the monthly entry (assets/js/app.js). */
    private const int MAX_EURO_DIGITS = 13;

    public static function read(string $content): ClosingBalance
    {
        $head = substr(ltrim($content, "\xEF\xBB\xBF \t\r\n"), 0, 4096);
        if (str_starts_with($head, '!Type:') || str_starts_with($head, '!Account')) {
            throw new UnreadableBankStatement('Les fichiers QIF ne donnent pas le solde du compte : exportez plutôt un relevé OFX ou CAMT.053.');
        }
        if (stripos($content, 'camt.053') !== false || stripos($content, '<BkToCstmrStmt') !== false) {
            return self::camt053($content);
        }
        if (stripos($head, 'OFXHEADER') !== false || stripos($content, '<OFX>') !== false) {
            return self::ofx($content);
        }
        throw new UnreadableBankStatement('Fichier illisible : choisissez un relevé OFX ou CAMT.053 exporté par votre banque.');
    }

    private static function ofx(string $content): ClosingBalance
    {
        $balances = preg_split('/<LEDGERBAL>/i', $content);
        if ($balances === false || count($balances) < 2) {
            throw new UnreadableBankStatement('Ce relevé ne contient pas de solde de fin de période.');
        }
        if (count($balances) > 2) {
            throw new UnreadableBankStatement('Ce fichier contient plusieurs comptes : exportez le relevé d\'un seul compte.');
        }
        if (preg_match('/<CURDEF>\s*([A-Z]{3})/i', $content, $currency) === 1 && strtoupper($currency[1]) !== 'EUR') {
            throw new UnreadableBankStatement('Le solde de ce relevé n\'est pas en euros.');
        }
        // SGML or XML alike: the value follows the opening tag, up to the next tag or the end of the line.
        $balance = $balances[1];
        if (preg_match('/<BALAMT>\s*([^<\s]+)/i', $balance, $amount) !== 1 || preg_match('/<DTASOF>\s*(\d{4})(\d{2})(\d{2})/i', $balance, $date) !== 1) {
            throw new UnreadableBankStatement('Ce relevé ne contient pas de solde de fin de période.');
        }

        return new ClosingBalance(self::cents($amount[1]), self::date($date[1], $date[2], $date[3]));
    }

    private static function camt053(string $content): ClosingBalance
    {
        // No document type: neither external entities nor expansion of internal ones.
        if (stripos($content, '<!DOCTYPE') !== false) {
            throw new UnreadableBankStatement('Fichier illisible : choisissez un relevé OFX ou CAMT.053 exporté par votre banque.');
        }
        $document = new \DOMDocument();
        if ($content === '' || !@$document->loadXML($content, \LIBXML_NONET)) {
            throw new UnreadableBankStatement('Fichier illisible : choisissez un relevé OFX ou CAMT.053 exporté par votre banque.');
        }
        // Element names whatever the version of the namespace (camt.053.001.02 to .13).
        $xpath = new \DOMXPath($document);
        $statements = $xpath->query("//*[local-name()='Stmt']");
        $count = $statements === false ? 0 : $statements->length;
        if ($count > 1) {
            throw new UnreadableBankStatement('Ce fichier contient plusieurs comptes : exportez le relevé d\'un seul compte.');
        }
        $balance = $count === 1 ? self::first($xpath, "//*[local-name()='Bal'][*[local-name()='Tp']/*[local-name()='CdOrPrtry']/*[local-name()='Cd']='CLBD']") : null;
        $amount = $balance === null ? null : self::first($xpath, "*[local-name()='Amt']", $balance);
        $date = $balance === null ? null : self::first($xpath, "*[local-name()='Dt']/*[local-name()='Dt' or local-name()='DtTm']", $balance);
        if ($balance === null || $amount === null || $date === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($date->textContent), $day) !== 1) {
            throw new UnreadableBankStatement('Ce relevé ne contient pas de solde de fin de période.');
        }
        if ($amount->getAttribute('Ccy') !== 'EUR') {
            throw new UnreadableBankStatement('Le solde de ce relevé n\'est pas en euros.');
        }
        $debit = trim((string) self::first($xpath, "*[local-name()='CdtDbtInd']", $balance)?->textContent) === 'DBIT';
        $cents = self::cents(trim($amount->textContent));

        return new ClosingBalance($debit ? -$cents : $cents, self::date($day[1], $day[2], $day[3]));
    }

    /** First element that the expression finds (from $context, or from the whole document), or null. */
    private static function first(\DOMXPath $xpath, string $expression, ?\DOMElement $context = null): ?\DOMElement
    {
        $found = $xpath->query($expression, $context);
        $node = $found === false ? null : $found->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    /** A decimal amount of the file ("1234.56", "-12,5", "+0.005") in cents, rounded half away from zero. */
    private static function cents(string $amount): int
    {
        if (preg_match('/^([+-]?)(\d{1,' . self::MAX_EURO_DIGITS . '})(?:[.,](\d+))?$/', $amount, $parts) !== 1) {
            throw new UnreadableBankStatement('Le montant du solde de ce relevé est illisible.');
        }
        $thousandths = str_pad(substr($parts[3] ?? '', 0, 3), 3, '0');
        $cents = (int) $parts[2] * 100 + intdiv((int) $thousandths + 5, 10);

        return $parts[1] === '-' ? -$cents : $cents;
    }

    private static function date(string $year, string $month, string $day): string
    {
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            throw new UnreadableBankStatement('La date du solde de ce relevé est illisible.');
        }

        return "$year-$month-$day";
    }
}
