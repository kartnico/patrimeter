<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Game;

/**
 * Families of challenges, for the summary "where do my stars come from" of the Défis page. Each challenge belongs to
 * exactly one family (Challenge::family()); texts are the French source strings, translated by the templates.
 */
enum ChallengeFamily: string
{
    case FirstSteps = 'first_steps';
    case Entries = 'entries';
    case BudgetAndGoals = 'budget_and_goals';
    case CushionAndCredit = 'cushion_and_credit';
    case Security = 'security';

    public function label(): string
    {
        return match ($this) {
            self::FirstSteps => 'Premiers pas',
            self::Entries => 'Saisie des soldes',
            self::BudgetAndGoals => 'Budget et objectifs',
            self::CushionAndCredit => 'Coussin et crédit',
            self::Security => 'Sécurité du compte',
        };
    }
}
