<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

use Patrimeter\Domain\Budget\PrecautionCoverage;
use Patrimeter\Domain\Budget\PrecautionStatus;

/**
 * Recommendations drawn from the month's pyramid (pure, no database), on top of the emergency savings relative to
 * spending (PrecautionCoverage):
 * - the pyramid is built from the base: no shares nor alternative investments while the emergency savings have not
 *   reached their target (or, without a budget, while they are empty);
 * - a negative level signals loans higher than the value of the properties they finance;
 * - the widest gap with the allocation benchmark (RecommendedAllocation), from 15 points.
 * Without remark, a favourable finding. Indicative benchmarks, never investment advice.
 */
final class PyramidAdvice
{
    /** Gap with the allocation benchmark, in percentage points, from which it is reported. */
    public const int ALLOCATION_GAP_POINTS = 15;

    /** Levels at the top of the pyramid, the riskiest. */
    private const array RISKY_LEVELS = [Level::Actions, Level::Exotique];

    /** @return list<Advice> from the most to the least severe; empty if the month has no data */
    public static function of(NetWorthSnapshot $snapshot, PrecautionCoverage $precaution): array
    {
        if (!$snapshot->hasData()) {
            return [];
        }
        $advice = [];
        $risky = 0;
        foreach (self::RISKY_LEVELS as $level) {
            $risky += max(0, $snapshot->level($level)->net());
        }
        $status = $precaution->status();
        $baseMissing = $status->isAlert() || ($status === PrecautionStatus::Unknown && $precaution->amount <= 0);
        if ($baseMissing && $risky > 0) {
            $advice[] = new Advice('base_first', AdviceSeverity::Warning, ['risky' => $risky]);
        }
        foreach (Level::ordered() as $level) {
            $total = $snapshot->level($level);
            if (!$total->isEmpty() && $total->net() < 0) {
                $advice[] = new Advice('negative_level', AdviceSeverity::Warning, ['level' => $level, 'amount' => $total->net()]);
            }
        }
        $gap = self::largestAllocationGap($snapshot);
        if ($gap !== null) {
            $advice[] = $gap;
        }
        if ($advice === []) {
            $advice[] = new Advice('balanced', AdviceSeverity::Ok);
        }
        usort($advice, static fn(Advice $a, Advice $b): int => $a->severity->rank() <=> $b->severity->rank());

        return $advice;
    }

    /** Widest gap with the allocation benchmark, if it reaches the threshold; on a tie, the first level. */
    private static function largestAllocationGap(NetWorthSnapshot $snapshot): ?Advice
    {
        $largest = null;
        foreach (RecommendedAllocation::compare($snapshot) ?? [] as $row) {
            $points = abs($row['actual'] - (float) $row['recommended']);
            if ($points >= self::ALLOCATION_GAP_POINTS && ($largest === null || $points > $largest[0])) {
                $largest = [$points, $row];
            }
        }
        if ($largest === null) {
            return null;
        }
        $row = $largest[1];

        return new Advice($row['actual'] > $row['recommended'] ? 'allocation_above' : 'allocation_below', AdviceSeverity::Info, [
            'level' => $row['level'],
            'actual' => $row['actual'],
            'recommended' => $row['recommended'],
        ]);
    }
}
