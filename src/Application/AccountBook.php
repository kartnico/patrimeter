<?php

declare(strict_types=1);

namespace Patrimeter\Application;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Form\AccountForm;
use Patrimeter\Domain\Account;
use Patrimeter\Domain\AccountValue;
use Patrimeter\Domain\BankStatement\BankStatementReader;
use Patrimeter\Domain\BankStatement\UnreadableBankStatement;
use Patrimeter\Domain\DateInput;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\AccountTypeRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;

use function Symfony\Component\Translation\t;

/**
 * The accounts and loans of an owner, and their statements: the rules of the account pages (AccountController), which
 * only reads the request and shows the outcome. A property is created on its own screen, with its share and its loans
 * (PropertyPortfolio). Every method takes the owner, given by the controller from the signed-in user.
 */
final class AccountBook
{
    /** Properties are created on their own screen (PropertyController), with their share and their loans. */
    public const string PROPERTY_ELSEWHERE = 'Un bien immobilier (résidence, location, SCPI…) se crée depuis Biens immobiliers, « Nouveau bien ».';
    private const string UNREADABLE_FILE = 'Fichier illisible : choisissez un relevé OFX ou CAMT.053 exporté par votre banque.';

    public function __construct(
        private readonly Connection $connection,
        private readonly Calendar $calendar,
        private readonly AccountRepository $accounts,
        private readonly AccountTypeRepository $types,
        private readonly StatementRepository $statements,
        private readonly OwnershipShareRepository $shares,
        private readonly PropertyPortfolio $portfolio,
    ) {}

    /**
     * The owner's accounts with their value this month: the open ones by level, then the archived ones.
     *
     * @return array{active: array<string, list<array{account: Account, value: ?AccountValue}>>, archived: list<array{account: Account, value: ?AccountValue}>}
     */
    public function overview(UserId $owner, Month $month): array
    {
        $valuation = $this->statements->latestValuation($owner, $month);
        $overview = ['active' => [], 'archived' => []];
        foreach ($this->accounts->all($owner) as $account) {
            $line = ['account' => $account, 'value' => $valuation->valueAt($account->id, $month)];
            if ($account->isArchived()) {
                $overview['archived'][] = $line;
            } else {
                $overview['active'][$account->level->value][] = $line;
            }
        }

        return $overview;
    }

    /**
     * Creates the account, with its initial balance in the current month, or in its opening month if later.
     *
     * @throws ValidationFailed a property, created on its own screen
     */
    public function create(UserId $owner, AccountForm $form): int
    {
        if (self::makesAProperty($form->data)) {
            throw new ValidationFailed(['type_id' => self::PROPERTY_ELSEWHERE]);
        }

        return $this->connection->transactional(function () use ($owner, $form): int {
            $id = $this->accounts->create($owner, $form->data);
            if ($form->initialCents !== null) {
                $month = $this->calendar->currentMonth();
                $opened = Month::fromDate($form->data['opened_on']);
                $this->statements->save($owner, $id, $opened->compare($month) > 0 ? $opened : $month, $form->initialCents);
            }

            return $id;
        });
    }

    /**
     * Changes the account, its dates kept consistent with its closing, its statements and its property links.
     *
     * @throws ValidationFailed by field
     */
    public function update(UserId $owner, Account $account, AccountForm $form): void
    {
        $data = $form->data;
        if ($account->closedOn !== null && $data['opened_on'] > $account->closedOn) {
            throw new ValidationFailed(['opened_on' => 'La date d\'ouverture doit précéder la date de clôture.']);
        }
        $conflicts = $this->portfolio->conflicts($owner, $account, $data);
        if ($conflicts !== []) {
            throw new ValidationFailed($conflicts);
        }
        // After the conflicts, more precise (a loan linked to a property must first be unlinked from it).
        if (!PropertyPortfolio::isProperty($account) && self::makesAProperty($data)) {
            throw new ValidationFailed(['type_id' => self::PROPERTY_ELSEWHERE]);
        }
        $this->accounts->update($owner, $account->id, $data);
    }

    /** @throws Refused a date that is not one, or before the opening */
    public function archive(UserId $owner, Account $account, string $closedOn): void
    {
        $closedOn = trim($closedOn);
        if (!DateInput::isValid($closedOn) || $closedOn < $account->openedOn) {
            throw new Refused(t('Date de clôture invalide (elle doit suivre la date d\'ouverture).'));
        }
        $this->accounts->archive($owner, $account->id, $closedOn);
    }

    public function unarchive(UserId $owner, Account $account): void
    {
        $this->accounts->unarchive($owner, $account->id);
    }

    /** Deletes the account with its statements and shares; its linked loans are unlinked. */
    public function delete(UserId $owner, Account $account): void
    {
        $this->accounts->delete($owner, $account->id);
    }

    /**
     * Adds or corrects the statement of any month in which the account is open (filling in the history).
     *
     * @throws Refused a month or an amount that cannot be read, or a month outside the account's life
     */
    public function saveStatement(UserId $owner, Account $account, string $month, string $amount, string $locale): Month
    {
        $statementMonth = Month::tryFromString(trim($month)) ?? throw new Refused(t('Mois invalide (AAAA-MM).'));
        $this->assertOpenIn($account, $statementMonth, $locale);
        try {
            $cents = Money::parse($amount, $locale);
        } catch (\InvalidArgumentException $e) {
            throw new Refused(t($e->getMessage()));
        }
        $this->statements->save($owner, $account->id, $statementMonth, $cents);

        return $statementMonth;
    }

    public function deleteStatement(UserId $owner, Account $account, Month $month): void
    {
        $this->statements->delete($owner, $account->id, $month);
    }

    /**
     * Closing balance of a bank statement exported by the bank (OFX, CAMT.053), to check and save as any statement:
     * nothing is saved here, nor kept anywhere. A loan's statement shows the debt negative; Patrimeter keeps the
     * capital owed, positive.
     *
     * @param string|null $content the file, or null if it could not be received
     *
     * @return array{month: Month, date: string, cents: int}
     *
     * @throws Refused a file that is not a statement, or a month outside the account's life
     */
    public function readStatement(Account $account, ?string $content, string $locale): array
    {
        try {
            $balance = BankStatementReader::read($content ?? throw new UnreadableBankStatement(self::UNREADABLE_FILE));
        } catch (UnreadableBankStatement $e) {
            throw new Refused(t($e->getMessage()));
        }
        $month = Month::fromString(substr($balance->date, 0, 7));
        $this->assertOpenIn($account, $month, $locale);

        return ['month' => $month, 'date' => $balance->date, 'cents' => $account->isLiability() ? -$balance->cents : $balance->cents];
    }

    /**
     * What the account form shows besides the fields: the types offered (an existing property keeps them all,
     * otherwise none of the property types), the statements and shares of the account, the properties a loan can be
     * linked to.
     *
     * @return array<string, mixed>
     */
    public function formView(UserId $owner, ?Account $account): array
    {
        $isProperty = $account !== null && PropertyPortfolio::isProperty($account);

        return [
            'account' => $account,
            'types' => $isProperty ? $this->types->all() : array_values(array_filter(
                $this->types->all(),
                static fn(array $type): bool => !PropertyPortfolio::isPropertyKind(Level::from($type['default_level']), Nature::from($type['nature'])),
            )),
            'levels' => Level::ordered(),
            'statements' => $account === null ? [] : $this->statementLines($owner, $account),
            'is_property' => $isProperty,
            'share_lines' => $account === null ? [] : $this->shareLines($owner, $account),
            'properties' => array_values(array_filter($this->accounts->all($owner), PropertyPortfolio::isProperty(...))),
            'today' => $this->calendar->today(),
            'current_month' => $this->calendar->currentMonth(),
        ];
    }

    /** @throws Refused */
    private function assertOpenIn(Account $account, Month $month, string $locale): void
    {
        if (!$account->isActiveIn($month)) {
            throw new Refused(t('Le compte n\'est pas ouvert en %month%.', ['%month%' => $month->label($locale)]));
        }
    }

    /** @return list<array{month: Month, share: int}> shares of the property, from the most recent to the oldest */
    private function shareLines(UserId $owner, Account $account): array
    {
        $lines = [];
        foreach (array_reverse($this->shares->all($owner)[$account->id] ?? [], true) as $month => $share) {
            $lines[] = ['month' => Month::fromString($month), 'share' => $share];
        }

        return $lines;
    }

    /** @return list<array{month: Month, cents: int}> statements of the account, from the most recent to the oldest */
    private function statementLines(UserId $owner, Account $account): array
    {
        $lines = [];
        foreach ($this->statements->forAccount($owner, $account->id) as $month => $cents) {
            $lines[] = ['month' => Month::fromString($month), 'cents' => $cents];
        }

        return $lines;
    }

    /** @param array{level_code: string, nature: string, ...} $data */
    private static function makesAProperty(array $data): bool
    {
        return PropertyPortfolio::isPropertyKind(Level::from($data['level_code']), Nature::from($data['nature']));
    }
}
