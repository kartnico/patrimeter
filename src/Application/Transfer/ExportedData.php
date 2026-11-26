<?php

declare(strict_types=1);

namespace Patrimeter\Application\Transfer;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Budget\BudgetBook;

/** Content of an export file read in memory (ExportReader): accounts, statements, shares and household budget. */
final class ExportedData
{
    /**
     * @param list<Account>                     $accounts
     * @param array<int, array<string, int>>    $statements account => (YYYY-MM => cents)
     * @param array<int, array<string, int>>    $shares     account => (YYYY-MM => millionths)
     */
    public function __construct(
        public readonly array $accounts,
        public readonly array $statements,
        public readonly array $shares,
        public readonly ?BudgetBook $budget,
    ) {}
}
