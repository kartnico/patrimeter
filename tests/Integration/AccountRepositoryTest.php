<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Integration;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Nature;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\AccountTypeRepository;
use Patrimeter\Tests\Support\IntegrationTestCase;

final class AccountRepositoryTest extends IntegrationTestCase
{
    private AccountRepository $accounts;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->accounts = self::service(AccountRepository::class);
    }

    public function testCreateFindUpdate(): void
    {
        $id = self::createAccount('Livret A', 'livret_a', '2024-03-01');
        $account = $this->accounts->find(self::owner(), $id);

        self::assertNotNull($account);
        self::assertSame('Livret A', $account->name);
        self::assertSame('Livret A', $account->typeLabel);
        self::assertSame(Level::Precaution, $account->level);
        self::assertSame(Nature::Asset, $account->nature);
        self::assertSame('2024-03-01', $account->openedOn);
        self::assertNull($account->closedOn);

        $this->accounts->update(self::owner(), $id, [
            'name' => 'Livret A (Banque)', 'type_id' => $account->typeId, 'level_code' => 'fonds_euros',
            'nature' => Nature::Asset->value, 'opened_on' => '2024-02-01',
        ]);
        $updated = $this->accounts->find(self::owner(), $id);
        self::assertNotNull($updated);
        self::assertSame('Livret A (Banque)', $updated->name);
        self::assertSame(Level::FondsEuros, $updated->level);
        self::assertSame('2024-02-01', $updated->openedOn);
    }

    public function testArchiveAndUnarchive(): void
    {
        $id = self::createAccount('CTO', 'cto');
        $this->accounts->archive(self::owner(), $id, '2026-06-30');
        self::assertSame('2026-06-30', $this->accounts->find(self::owner(), $id)?->closedOn);

        $this->accounts->unarchive(self::owner(), $id);
        self::assertNull($this->accounts->find(self::owner(), $id)?->closedOn);
    }

    public function testFindUnknownAccount(): void
    {
        self::assertNull($this->accounts->find(self::owner(), 424242));
    }

    public function testAllIsSortedByLevelThenNatureThenName(): void
    {
        self::createAccount('zèbre', 'livret_a');
        self::createAccount('Prêt', 'credit_immo_rp');
        self::createAccount('Maison', 'residence_principale');
        self::createAccount('Abeille', 'ldds');
        self::createAccount('Bitcoin', 'crypto');

        $names = array_map(static fn(Account $a): string => $a->name, $this->accounts->all(self::owner()));
        self::assertSame(['Maison', 'Prêt', 'Abeille', 'zèbre', 'Bitcoin'], $names);
    }

    public function testAccountTypesAreSortedByLevel(): void
    {
        $types = self::service(AccountTypeRepository::class)->all();

        self::assertCount(38, $types);
        self::assertSame('residence_principale', $types[0]['code']);
        self::assertSame('residence', $types[0]['default_level']);
        self::assertSame('exotique', $types[27]['default_level']);
    }
}
