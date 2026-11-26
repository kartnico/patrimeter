<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/**
 * Recommendation drawn from the month's pyramid (PyramidAdvice): a code, which the template turns into a sentence, its
 * severity and the values it quotes (amounts in cents, percentages, level).
 */
final class Advice
{
    /** @param array<string, int|float|Level> $values */
    public function __construct(
        public readonly string $code,
        public readonly AdviceSeverity $severity,
        public readonly array $values = [],
    ) {}
}
