<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Goal;

/** What a savings goal measures: the whole net worth, one pyramid level, or chosen accounts. */
enum GoalScope: string
{
    case NetWorth = 'net';
    case Level = 'level';
    case Accounts = 'accounts';

    public function label(): string
    {
        return match ($this) {
            self::NetWorth => 'Patrimoine net',
            self::Level => 'Un niveau de la pyramide',
            self::Accounts => 'Des comptes choisis',
        };
    }
}
