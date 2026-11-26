<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/**
 * Account (asset) or loan (liability) tracked month by month.
 *
 * The owner is always set for an account read from the database; it stays empty for pure
 * calculations (tests, reference datasets), and an account without an owner is never authorised
 * by the access control.
 */
final class Account
{
    /** Name of an account: valid UTF-8, no control character, 80 characters at most. */
    public const string NAME_PATTERN = '/\A[^\p{Cc}]{1,80}\z/u';

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $typeId,
        public readonly string $typeLabel,
        public readonly Level $level,
        public readonly Nature $nature,
        public readonly string $openedOn,
        public readonly ?string $closedOn = null,
        public readonly ?UserId $ownerId = null,
        public readonly ?int $linkedAssetId = null,
        public readonly LoanMode $loanMode = LoanMode::Prorata,
    ) {}

    public function isLiability(): bool
    {
        return $this->nature === Nature::Liability;
    }

    public function isArchived(): bool
    {
        return $this->closedOn !== null;
    }

    /**
     * The account counts for month M if it was opened in M at the latest
     * and was not closed in M or before.
     */
    public function isActiveIn(Month $month): bool
    {
        if (Month::fromDate($this->openedOn)->compare($month) > 0) {
            return false;
        }

        return $this->closedOn === null || Month::fromDate($this->closedOn)->compare($month) > 0;
    }

    /** Signed amount contributing to the net worth (liabilities are subtracted). */
    public function signed(int $cents): int
    {
        return $this->isLiability() ? -$cents : $cents;
    }
}
