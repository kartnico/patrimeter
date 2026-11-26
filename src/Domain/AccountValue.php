<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/** Value of an account for a month: entered for that month, or carried over from an earlier month. */
final class AccountValue
{
    public function __construct(
        public readonly int $cents,
        public readonly Month $sourceMonth,
        public readonly bool $carried,
    ) {}
}
