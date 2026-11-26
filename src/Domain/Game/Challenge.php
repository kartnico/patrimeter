<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Game;

/**
 * Challenges and the thresholds of their stars. They reward habits (entering the balances, keeping the budget up to
 * date, a savings rate the user chose themselves) and safety (emergency savings, no consumer loan, a protected account, a
 * goal the user set themselves), never a
 * return, the share of a level, a product or more risk: tests/Architecture/ChallengeGuardRailsTest checks that no
 * challenge reads the split of the wealth between the levels.
 *
 * Texts are the French source strings, translated by the templates (domain "messages").
 */
enum Challenge: string
{
    case FirstSteps = 'first_steps';
    case Streak = 'streak';
    case UpToDate = 'up_to_date';
    case Precaution = 'precaution';
    case NoConsumerCredit = 'no_consumer_credit';
    case Goal = 'goal';
    case Budget = 'budget';
    case Security = 'security';
    case SavingsRate = 'savings_rate';

    public function title(): string
    {
        return match ($this) {
            self::FirstSteps => 'Premiers pas',
            self::Streak => 'Série de saisies',
            self::UpToDate => 'Patrimoine à jour',
            self::Precaution => 'Coussin de sécurité',
            self::NoConsumerCredit => 'Sans crédit à la consommation',
            self::Goal => 'Objectif en route',
            self::Budget => 'Budget tenu à jour',
            self::Security => 'Compte protégé',
            self::SavingsRate => 'Taux d\'épargne tenu',
        };
    }

    /** What the challenge rewards. */
    public function description(): string
    {
        return match ($this) {
            self::FirstSteps => 'Décrire votre foyer, créer vos comptes, saisir un premier mois, ajouter votre résidence et fixer un objectif.',
            self::Streak => 'Saisir vos soldes chaque mois : 3, 6 puis 12 mois de suite. Un mois oublié par an est pardonné.',
            self::UpToDate => 'Des mois où tous vos comptes ont leur solde saisi, et pas seulement reporté : 1, 6 puis 12 mois.',
            self::Precaution => 'Une épargne de précaution qui couvre 1 mois de dépenses, puis la moitié de votre cible, puis votre cible.',
            self::NoConsumerCredit => 'Des mois de suite sans crédit à la consommation à rembourser : 1, 6 puis 12 mois.',
            self::Goal => 'Faire avancer l\'objectif d\'épargne que vous vous êtes fixé : 25 %, 50 % puis 75 %.',
            self::Budget => 'Saisir le budget du foyer : 1, 3 puis 6 mois.',
            self::Security => 'Se connecter avec une clé d\'accès, puis en garder une deuxième en secours.',
            self::SavingsRate => 'Prévoir dans votre budget une épargne au moins égale au taux que vous avez choisi : 1, 3 puis 6 mois de suite.',
        };
    }

    /** Where the challenge stands: %count% (value measured), %name% (goal); see ChallengeResult::messageParameters(). */
    public function progressLabel(): string
    {
        return match ($this) {
            self::FirstSteps => 'Étapes faites : %count% sur 5',
            self::Streak => 'Mois de suite : %count%',
            self::UpToDate => 'Mois entièrement à jour : %count%',
            self::Precaution => 'Mois de dépenses de côté : %count%',
            self::NoConsumerCredit => 'Mois sans crédit à la consommation : %count%',
            self::Goal => 'Objectif « %name% » : %count% %',
            self::Budget => 'Mois de budget saisis : %count%',
            self::Security => 'Clés d\'accès : %count%',
            self::SavingsRate => 'Mois de suite à %rate% % ou plus : %count%',
        };
    }

    /**
     * What a threshold asks, with %count% (the threshold) and %rate% (savings rate aimed at): the steps of the ladder
     * of the challenge's stars.
     */
    public function thresholdLabel(): string
    {
        return match ($this) {
            self::FirstSteps => '%count% étape faite|%count% étapes faites',
            self::Streak => '%count% mois de suite saisis|%count% mois de suite saisis',
            self::UpToDate => '%count% mois où tous les comptes sont saisis|%count% mois où tous les comptes sont saisis',
            self::Precaution => '%count% mois de dépenses de côté|%count% mois de dépenses de côté',
            self::NoConsumerCredit => '%count% mois de suite sans crédit à la consommation|%count% mois de suite sans crédit à la consommation',
            self::Goal => 'Objectif atteint à %count% %|Objectif atteint à %count% %',
            self::Budget => '%count% mois de budget saisi|%count% mois de budget saisis',
            self::Security => '%count% clé d\'accès enregistrée|%count% clés d\'accès enregistrées',
            self::SavingsRate => '%count% mois de suite à %rate% % d\'épargne ou plus|%count% mois de suite à %rate% % d\'épargne ou plus',
        };
    }

    /** What to do first when the data needed is missing; null if the challenge can always be measured. */
    public function unavailableLabel(): ?string
    {
        return match ($this) {
            self::Precaution => 'Renseignez votre foyer et votre budget pour mesurer ce coussin.',
            self::Goal => 'Fixez un objectif d\'épargne pour relever ce défi.',
            self::SavingsRate => 'Choisissez le taux d\'épargne que vous visez, et saisissez votre budget, pour relever ce défi.',
            default => null,
        };
    }

    /** Line icon (SVG path, 24 × 24). */
    public function icon(): string
    {
        return match ($this) {
            self::FirstSteps => 'M5 12.5l4.5 4.5L19 7.5',
            self::Streak => 'M12 3c1 3 5 5 5 10a5 5 0 0 1-10 0c0-2 1-3.5 2-4.5 0 2 1 3 2 3 0-3-1-5 1-8.5z',
            self::UpToDate => 'M4 5h16v15H4zM4 10h16M9 3v4M15 3v4M8.5 15l2.5 2.5 4.5-4.5',
            self::Precaution => 'M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z',
            self::NoConsumerCredit => 'M3 7h18v11H3zM3 11h18M7 15h4',
            self::Goal => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM12 12h.01',
            self::Budget => 'M21.2 15.9A10 10 0 1 1 8 2.8M22 12A10 10 0 0 0 12 2v10z',
            self::Security => 'M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4',
            self::SavingsRate => 'M19 5 5 19M6.5 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM17.5 20a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
        };
    }

    public function evaluate(GameFacts $facts): ChallengeResult
    {
        return match ($this) {
            self::FirstSteps => new ChallengeResult($this, count(array_filter(FirstStep::cases(), static fn(FirstStep $step): bool => $step->isDone($facts))), [1, 2, 3, 4, 5]),
            self::Streak => new ChallengeResult($this, $facts->streak()->length, [3, 6, 12]),
            self::UpToDate => new ChallengeResult($this, $facts->completeMonths, [1, 6, 12]),
            self::Precaution => new ChallengeResult(
                $this,
                $facts->precautionMonths ?? 0,
                [1, max(1, intdiv($facts->precautionTarget + 1, 2)), max(1, $facts->precautionTarget)],
                $facts->precautionMonths !== null,
            ),
            self::NoConsumerCredit => new ChallengeResult($this, $facts->consumerCreditFreeMonths, [1, 6, 12]),
            self::Goal => new ChallengeResult($this, $facts->goalPercent ?? 0, [25, 50, 75], $facts->goalPercent !== null, ['%name%' => $facts->goalName ?? '']),
            self::Budget => new ChallengeResult($this, $facts->budgetMonths, [1, 3, 6]),
            self::Security => new ChallengeResult($this, $facts->passkeys, [1, 2]),
            self::SavingsRate => new ChallengeResult(
                $this,
                $facts->savingsRateMonths,
                [1, 3, 6],
                $facts->savingsRateTarget !== null && $facts->budgetMonths > 0,
                ['%rate%' => $facts->savingsRateTarget ?? 0],
            ),
        };
    }
}
