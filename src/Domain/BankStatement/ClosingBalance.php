<?php

declare(strict_types=1);

namespace Patrimeter\Domain\BankStatement;

/** Balance at the end of a bank statement: amount in cents (negative when overdrawn) and its date (YYYY-MM-DD). */
final readonly class ClosingBalance
{
    public function __construct(
        public int $cents,
        public string $date,
    ) {}
}
