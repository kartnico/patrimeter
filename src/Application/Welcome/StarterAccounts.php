<?php

declare(strict_types=1);

namespace Patrimeter\Application\Welcome;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Calendar;
use Patrimeter\Application\Payload\AccountPayload;
use Patrimeter\Application\PortfolioService;
use Patrimeter\Domain\Account;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\AccountTypeRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;

/**
 * First accounts of a new user (welcome pages): the common account types of each level of the pyramid, and their
 * creation together, with this month's balance, in one transaction. These first balances are often rough figures: they
 * are flagged "to refine" until the monthly entry saves them again. A type describes what the user already holds,
 * never what they should open. The account types themselves are the reference data of the `account_types` table.
 *
 * @phpstan-import-type AccountType from AccountTypeRepository
 */
final class StarterAccounts
{
    /** Account types offered on the welcome pages, in the order of the reference data. */
    private const array CODES = [
        'residence_principale', 'credit_immo_rp',
        'compte_courant', 'livret_a', 'ldds', 'lep', 'livret_bancaire', 'pel_cel', 'credit_conso',
        'fonds_euros',
        'av_uc', 'pea', 'cto', 'per', 'epargne_salariale',
        'scpi', 'location_nue', 'residence_secondaire', 'credit_immo_locatif',
        'crypto', 'or', 'crowdfunding',
    ];

    public function __construct(
        private readonly AccountTypeRepository $types,
        private readonly PortfolioService $portfolio,
        private readonly Connection $connection,
        private readonly Calendar $calendar,
        private readonly StatementRepository $statements,
    ) {}

    /**
     * Types offered, by level (from the base of the pyramid), for one level or for all of them.
     *
     * @return list<array{level: Level, types: list<AccountType>}>
     */
    public function byLevel(?Level $only = null): array
    {
        $types = array_filter($this->types->all(), static fn(array $type): bool => in_array($type['code'], self::CODES, true));
        $groups = [];
        foreach (Level::ordered() as $level) {
            if ($only === null || $only === $level) {
                $groups[] = ['level' => $level, 'types' => array_values(array_filter($types, static fn(array $type): bool => $type['default_level'] === $level->value))];
            }
        }

        return $groups;
    }

    /**
     * Creates an account for each row with a balance, this month, all together or none. Rows without a balance are
     * skipped; a loan's balance is its outstanding principal, whatever its sign.
     *
     * @param array<array-key, mixed> $rows type code => ['name' => …, 'amount' => …]
     *
     * @return array{created: int, errors: array<string, string>} errors by type code (nothing created then)
     */
    public function create(UserId $owner, array $rows, string $locale): array
    {
        $types = [];
        foreach ($this->types->all() as $type) {
            if (in_array($type['code'], self::CODES, true)) {
                $types[$type['code']] = $type;
            }
        }
        $payloads = [];
        $errors = [];
        $openedOn = $this->calendar->currentMonth()->toString() . '-01';
        foreach ($rows as $code => $row) {
            $type = $types[$code] ?? null;
            if ($type === null || !is_array($row)) {
                continue;
            }
            $amount = self::field($row, 'amount');
            if ($amount === '') {
                continue;
            }
            $name = self::field($row, 'name');
            if (preg_match(Account::NAME_PATTERN, $name) !== 1) {
                $errors[$type['code']] = 'Indiquez un nom de 1 à 80 caractères.';
                continue;
            }
            try {
                $cents = Money::parse($amount, $locale);
            } catch (\InvalidArgumentException $e) {
                $errors[$type['code']] = $e->getMessage();
                continue;
            }
            $payloads[] = new AccountPayload($name, $type['id'], null, null, $openedOn, $type['nature'] === 'liability' ? abs($cents) : $cents);
        }
        if ($errors !== []) {
            return ['created' => 0, 'errors' => $errors];
        }
        $month = $this->calendar->currentMonth();
        $this->connection->transactional(function () use ($owner, $payloads, $month): void {
            foreach ($payloads as $payload) {
                $created = $this->portfolio->createAccount($owner, $payload);
                if (isset($created['id']) && is_int($created['id'])) {
                    $this->statements->markApproximate($owner, $created['id'], $month);
                }
            }
        });

        return ['created' => count($payloads), 'errors' => []];
    }

    /**
     * A text field of a row of the form, trimmed; empty if missing or not a text.
     *
     * @param array<array-key, mixed> $row
     */
    private static function field(array $row, string $key): string
    {
        return array_key_exists($key, $row) && is_string($row[$key]) ? trim($row[$key]) : '';
    }
}
