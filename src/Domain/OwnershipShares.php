<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/**
 * Ownership shares of properties, in millionths (33.3333 % = 333,333), by month of effect: the share of a month
 * is that of the last month of effect reached, 100 % if there is none.
 *
 * Rounding: to the nearest cent, half a cent away from zero. The calculation splits off the
 * millions of cents so as never to exceed the capacity of an integer.
 */
final class OwnershipShares
{
    public const int FULL = 1_000_000;

    /** @param array<int, array<string, int>> $byAccount account => (YYYY-MM => millionths) */
    public function __construct(private readonly array $byAccount = []) {}

    public function at(int $accountId, Month $month): int
    {
        $share = self::FULL;
        $current = '';
        foreach ($this->byAccount[$accountId] ?? [] as $effective => $value) {
            if ($effective <= $month->toString() && $effective >= $current) {
                $current = $effective;
                $share = $value;
            }
        }

        return $share;
    }

    /**
     * Share applied to an account's statement for the month: an asset according to its own share; a loan linked pro
     * rata according to its property's share; a loan in its own amount or not linked, in full.
     */
    public function retainedShare(Account $account, Month $month): int
    {
        return match (true) {
            !$account->isLiability() => $this->at($account->id, $month),
            $account->linkedAssetId !== null && $account->loanMode === LoanMode::Prorata => $this->at($account->linkedAssetId, $month),
            default => self::FULL,
        };
    }

    public static function apply(int $cents, int $share): int
    {
        $sign = $cents < 0 ? -1 : 1;
        $absolute = abs($cents);

        return $sign * (intdiv($absolute, self::FULL) * $share + intdiv($absolute % self::FULL * $share + intdiv(self::FULL, 2), self::FULL));
    }

    /** Percentage entered ("33,3333" or "50") in millionths; null if invalid or outside ]0; 100]. */
    public static function parsePercent(string $input): ?int
    {
        $normalized = str_replace(',', '.', trim(str_replace('%', '', $input)));
        if (preg_match('/\A(\d{1,3})(?:\.(\d{1,4}))?\z/', $normalized, $parts) !== 1) {
            return null;
        }
        $share = (int) $parts[1] * 10_000 + (int) str_pad($parts[2] ?? '', 4, '0');

        return $share > 0 && $share <= self::FULL ? $share : null;
    }

    /** Millionths as a percentage, without useless zeros: "33,3333", "50" ("33.3333" in English). */
    public static function formatPercent(int $share, string $locale = 'fr'): string
    {
        $decimals = rtrim(str_pad((string) ($share % 10_000), 4, '0', STR_PAD_LEFT), '0');

        return intdiv($share, 10_000) . ($decimals === '' ? '' : ($locale === 'en' ? '.' : ',') . $decimals);
    }
}
