<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/**
 * Benchmark for splitting investments: the "balanced" allocation of Avenue des Investisseurs (Nicolas Decaudain,
 * "Allocation patrimoniale : répartition idéale des actifs", update of 7 February 2026), which inspires the wealth
 * pyramid. It covers invested money: the main residence (base of the pyramid) and the emergency savings (about three
 * months of spending) have no percentage in it.
 *
 * It is an indicative benchmark, not advice: the right split depends on the horizon and the risk profile.
 */
final class RecommendedAllocation
{
    public const string SOURCE = 'Avenue des Investisseurs, allocation équilibrée (Nicolas Decaudain, mise à jour du 7 février 2026)';
    public const string SOURCE_URL = 'https://avenuedesinvestisseurs.fr/allocation-patrimoniale-repartition-allocation-ideale-actifs/';

    /** Recommended share of each level in the investments, in percent (total 100). */
    public const array SHARES = [
        'fonds_euros' => 45,
        'actions' => 25,
        'immobilier' => 25,
        'exotique' => 5,
    ];

    /**
     * Share of each level in the month's investments, next to the benchmark. Null if the investments (sum of the
     * levels concerned) are not strictly positive: the comparison would make no sense.
     *
     * @return list<array{level: Level, actual: float, recommended: int}>|null
     */
    public static function compare(NetWorthSnapshot $snapshot): ?array
    {
        $total = 0;
        foreach (array_keys(self::SHARES) as $code) {
            $total += $snapshot->level(Level::from($code))->net();
        }
        if ($total <= 0) {
            return null;
        }
        $rows = [];
        foreach (self::SHARES as $code => $recommended) {
            $level = Level::from($code);
            $rows[] = [
                'level' => $level,
                'actual' => (float) $snapshot->level($level)->net() * 100.0 / (float) $total,
                'recommended' => $recommended,
            ];
        }

        return $rows;
    }
}
