<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Integration\Transfer;

use Patrimeter\Application\Transfer\ExportReader;
use Patrimeter\Application\ValidationFailed;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\LoanMode;
use Patrimeter\Domain\Month;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * In-memory reading of an export file (family account): same rules as the import into an empty account, without
 * saving anything; identifiers derived from the member's rank.
 */
final class ExportReaderTest extends KernelTestCase
{
    public function testReadsAccountsLinksSharesAndBudget(): void
    {
        $data = self::reader()->read(self::accounts(), self::budget(), 2);

        self::assertSame([-2_000_001, -2_000_002], array_map(static fn($account): int => $account->id, $data->accounts));
        [$house, $loan] = $data->accounts;
        self::assertSame(Level::Residence, $house->level);
        self::assertSame('Résidence principale (valeur du bien)', $house->typeLabel);
        self::assertSame(-2_000_001, $loan->linkedAssetId, 'le crédit précède son bien dans le fichier');
        self::assertSame(LoanMode::Own, $loan->loanMode);
        self::assertSame([-2_000_001 => ['2026-10' => 30_000_000], -2_000_002 => ['2026-10' => 12_000_000]], $data->statements);
        self::assertSame([-2_000_001 => ['2025-01' => 500_000]], $data->shares);
        self::assertNotNull($data->budget);
        self::assertSame([2_000_001], array_map(static fn($category): int => $category->id, $data->budget->categories));
        self::assertSame(-2_000_001, $data->budget->lines[0]->accountId, 'poste d\'épargne vers le bien du fichier');
        self::assertSame(100_000, $data->budget->budgetAt(Month::fromString('2026-11'))?->amount(2_000_001));
    }

    public function testDecodeChecksTheFormat(): void
    {
        $decoded = ExportReader::decode((string) json_encode(['format' => 'patrimeter', 'version' => 1, 'exported_on' => '2026-11-02', 'accounts' => []]));
        self::assertSame(['accounts' => [], 'budget' => null, 'goals' => [], 'indicators' => [], 'exported_on' => '2026-11-02'], $decoded, 'version 1, sans budget');

        foreach (['{', '{"format":"autre","version":2,"accounts":[]}', '{"format":"patrimeter","version":5,"accounts":[]}', '{"format":"patrimeter","version":2,"accounts":[],"budget":"x"}', '{"format":"patrimeter","version":3,"accounts":[],"goals":{"a":1}}', '{"format":"patrimeter","version":4,"accounts":[],"indicators":{"a":1}}'] as $json) {
            try {
                ExportReader::decode($json);
                self::fail("Fichier accepté : $json");
            } catch (ValidationFailed) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{?int, string, mixed, string}> account changed (null: added), field, value, message */
    public static function invalidAccounts(): iterable
    {
        yield 'type inconnu' => [0, 'type', 'inconnu', 'type de compte inconnu'];
        yield 'nom vide' => [0, 'name', ' ', 'nom, niveau, nature ou dates invalides'];
        yield 'clôture avant ouverture' => [0, 'closed_on', '2019-12-31', 'nom, niveau, nature ou dates invalides'];
        yield 'relevé avant ouverture' => [0, 'statements', [['month' => '2019-01', 'amount_cents' => 1]], 'relevé invalide'];
        yield 'relevé décimal' => [0, 'statements', [['month' => '2026-01', 'amount_cents' => 1.5]], 'relevé invalide'];
        yield 'part d\'un crédit' => [1, 'shares', [['month' => '2026-01', 'share_millionths' => 500_000]], 'part invalide'];
        yield 'part nulle' => [0, 'shares', [['month' => '2026-01', 'share_millionths' => 0]], 'part invalide'];
        yield 'crédit lié à un crédit' => [1, 'linked_asset_ref', 'pret', 'Choisissez un bien'];
        yield 'bien lié à un bien' => [0, 'linked_asset_ref', 'maison', 'Choisissez un bien'];
        yield 'compte illisible' => [null, '', 'x', 'Compte n° 3 illisible'];
    }

    #[DataProvider('invalidAccounts')]
    public function testRefusesInvalidAccounts(?int $index, string $field, mixed $value, string $message): void
    {
        $accounts = $index === null
            ? [...self::accounts(), $value]
            : array_map(static fn(int $i, array $account): array => $i === $index ? [$field => $value] + $account : $account, array_keys(self::accounts()), self::accounts());

        try {
            self::reader()->read($accounts, null, 1);
            self::fail('Fichier accepté');
        } catch (ValidationFailed $failure) {
            self::assertStringContainsString($message, implode(' ', $failure->errors));
        }
    }

    public function testRefusesAnInvalidBudget(): void
    {
        $this->expectException(ValidationFailed::class);
        self::reader()->read(self::accounts(), self::budget(2), 1);
    }

    private static function reader(): ExportReader
    {
        self::bootKernel();

        return self::getContainer()->get(ExportReader::class);
    }

    /** @return list<array<string, mixed>> the house, then the loan that finances it, in the export format */
    private static function accounts(): array
    {
        return [
            ['ref' => 'maison', 'name' => 'Maison', 'type' => 'residence_principale', 'level' => 'residence', 'nature' => 'asset', 'opened_on' => '2020-01-01', 'closed_on' => null, 'linked_asset_ref' => null, 'loan_mode' => 'prorata',
                'statements' => [['month' => '2026-10', 'amount_cents' => 30_000_000]], 'shares' => [['month' => '2025-01', 'share_millionths' => 500_000]]],
            ['ref' => 'pret', 'name' => 'Prêt', 'type' => 'credit_immo_rp', 'level' => 'residence', 'nature' => 'liability', 'opened_on' => '2020-01-01', 'closed_on' => null, 'linked_asset_ref' => 'maison', 'loan_mode' => 'own',
                'statements' => [['month' => '2026-10', 'amount_cents' => 12_000_000]], 'shares' => []],
        ];
    }

    /**
     * Budget of one adult, a savings item towards the house; incomes given to the adult $incomeAdult.
     *
     * @return array<string, mixed>
     */
    private static function budget(int $incomeAdult = 1): array
    {
        return [
            'adults' => ['Alex'], 'children' => [], 'precaution_months' => 3,
            'categories' => [['name' => 'Épargne', 'group' => 'savings', 'adult' => null, 'lines' => [['ref' => 7, 'name' => 'Travaux', 'child_ref' => null, 'account_ref' => 'maison']]]],
            'months' => [['month' => '2026-10', 'incomes' => [['adult' => $incomeAdult, 'amount_cents' => 300_000]], 'split_rule' => 'income', 'first_adult_share_millionths' => 500_000, 'amounts' => [['line_ref' => 7, 'amount_cents' => 100_000]]]],
        ];
    }
}
