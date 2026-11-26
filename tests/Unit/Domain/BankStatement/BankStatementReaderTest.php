<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain\BankStatement;

use Patrimeter\Domain\BankStatement\BankStatementReader;
use Patrimeter\Domain\BankStatement\UnreadableBankStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Closing balance read from the files exported by the banks: OFX 1 (SGML) and 2 (XML), CAMT.053; the rest refused. */
final class BankStatementReaderTest extends TestCase
{
    public function testOfxVersion1WithUnclosedTags(): void
    {
        $balance = BankStatementReader::read(<<<'OFX'
            OFXHEADER:100
            DATA:OFXSGML
            VERSION:102

            <OFX>
            <BANKMSGSRSV1><STMTTRNRS><STMTRS>
            <CURDEF>EUR
            <BANKACCTFROM><BANKID>30004<ACCTID>00012345678</BANKACCTFROM>
            <BANKTRANLIST><STMTTRN><TRNAMT>-42.10<DTPOSTED>20261105</STMTTRN></BANKTRANLIST>
            <LEDGERBAL><BALAMT>1234.56<DTASOF>20261130120000[+1:CET]</LEDGERBAL>
            <AVAILBAL><BALAMT>999.99<DTASOF>20261130</AVAILBAL>
            </STMTRS></STMTTRNRS></BANKMSGSRSV1>
            </OFX>
            OFX);

        self::assertSame(123456, $balance->cents, 'the ledger balance, not the available one');
        self::assertSame('2026-11-30', $balance->date);
    }

    public function testOfxVersion2InXmlOverdrawn(): void
    {
        $balance = BankStatementReader::read(<<<'OFX'
            <?xml version="1.0" encoding="UTF-8"?>
            <?OFX OFXHEADER="200" VERSION="220"?>
            <OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS>
              <CURDEF>EUR</CURDEF>
              <LEDGERBAL><BALAMT>-80,5</BALAMT><DTASOF>20261031</DTASOF></LEDGERBAL>
            </STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>
            OFX);

        self::assertSame(-8050, $balance->cents);
        self::assertSame('2026-10-31', $balance->date);
    }

    public function testCamt053ClosingBookedBalance(): void
    {
        $balance = BankStatementReader::read(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08">
              <BkToCstmrStmt><Stmt>
                <Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp><Amt Ccy="EUR">500.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-11-01</Dt></Dt></Bal>
                <Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp><Amt Ccy="EUR">12.345</Amt><CdtDbtInd>DBIT</CdtDbtInd><Dt><DtTm>2026-11-30T23:59:59</DtTm></Dt></Bal>
              </Stmt></BkToCstmrStmt>
            </Document>
            XML);

        self::assertSame(-1235, $balance->cents, 'closing balance, debit, rounded half away from zero');
        self::assertSame('2026-11-30', $balance->date);
    }

    /** @return iterable<string, array{string, string}> file => expected message */
    public static function refusedFiles(): iterable
    {
        yield 'QIF' => ["!Type:Bank\nD30/11/2026\nT-42.10\n^\n", 'Les fichiers QIF ne donnent pas le solde du compte'];
        yield 'CSV' => ["Date;Libellé;Montant\n30/11/2026;Courses;-42,10\n", 'Fichier illisible'];
        yield 'OFX without a balance' => ["OFXHEADER:100\n<OFX><STMTRS><CURDEF>EUR</STMTRS></OFX>", 'pas de solde de fin de période'];
        yield 'OFX with two accounts' => ['<OFX><LEDGERBAL><BALAMT>1<DTASOF>20261130</LEDGERBAL><LEDGERBAL><BALAMT>2<DTASOF>20261130</LEDGERBAL></OFX>', 'plusieurs comptes'];
        yield 'OFX in dollars' => ['<OFX><CURDEF>USD<LEDGERBAL><BALAMT>1<DTASOF>20261130</LEDGERBAL></OFX>', 'pas en euros'];
        yield 'impossible date' => ['<OFX><LEDGERBAL><BALAMT>1<DTASOF>20261131</LEDGERBAL></OFX>', 'date du solde'];
        yield 'unreadable amount' => ['<OFX><LEDGERBAL><BALAMT>1e9<DTASOF>20261130</LEDGERBAL></OFX>', 'montant du solde'];
        yield 'CAMT.053 with an entity' => ['<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt>&x;</Stmt></BkToCstmrStmt></Document>', 'Fichier illisible'];
        yield 'CAMT.053 in pounds' => ['<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt><Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp><Amt Ccy="GBP">1</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-11-30</Dt></Dt></Bal></Stmt></BkToCstmrStmt></Document>', 'pas en euros'];
        yield 'CAMT.053 with two accounts' => ['<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt/><Stmt/></BkToCstmrStmt></Document>', 'plusieurs comptes'];
    }

    #[DataProvider('refusedFiles')]
    public function testRefusedFilesSayWhatToDo(string $file, string $message): void
    {
        $this->expectException(UnreadableBankStatement::class);
        $this->expectExceptionMessage($message);
        BankStatementReader::read($file);
    }
}
