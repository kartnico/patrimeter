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

    /** Short name of a threshold, under its star on the rail of the card: %count% (the threshold). */
    public function stepLabel(): string
    {
        return match ($this) {
            self::FirstSteps => '%count% étape|%count% étapes',
            self::Goal => '%count% %',
            self::Security => '%count% clé|%count% clés',
            default => '%count% mois|%count% mois',
        };
    }

    /**
     * What is still missing for the next star: %count% (difference between the next threshold and the value
     * measured), %next% (that threshold), %rate% (savings rate aimed at), %name% (goal).
     */
    public function missingLabel(): string
    {
        return match ($this) {
            self::FirstSteps => 'Encore %count% étape, parmi :|Encore %count% étapes, parmi :',
            self::Streak => 'Encore %count% mois de saisie de suite.|Encore %count% mois de saisie de suite.',
            self::UpToDate => 'Encore %count% mois où tous les comptes ont leur solde saisi. Un solde reporté ne compte pas.|Encore %count% mois où tous les comptes ont leur solde saisi. Un solde reporté ne compte pas.',
            self::Precaution => 'Encore l\'équivalent de %count% mois de dépenses à mettre de côté.|Encore l\'équivalent de %count% mois de dépenses à mettre de côté.',
            self::NoConsumerCredit => 'Encore %count% mois de suite sans crédit à la consommation à rembourser.|Encore %count% mois de suite sans crédit à la consommation à rembourser.',
            self::Goal => 'Objectif « %name% » à porter à %next% % : encore %count% point.|Objectif « %name% » à porter à %next% % : encore %count% points.',
            self::Budget => 'Encore %count% mois de budget à saisir, pas forcément de suite.|Encore %count% mois de budget à saisir, pas forcément de suite.',
            self::Security => 'Encore %count% clé d\'accès à enregistrer.|Encore %count% clés d\'accès à enregistrer.',
            self::SavingsRate => 'Encore %count% mois de suite dont le budget prévoit au moins %rate% % d\'épargne.|Encore %count% mois de suite dont le budget prévoit au moins %rate% % d\'épargne.',
        };
    }

    public function family(): ChallengeFamily
    {
        return match ($this) {
            self::FirstSteps => ChallengeFamily::FirstSteps,
            self::Streak, self::UpToDate => ChallengeFamily::Entries,
            self::Goal, self::Budget, self::SavingsRate => ChallengeFamily::BudgetAndGoals,
            self::Precaution, self::NoConsumerCredit => ChallengeFamily::CushionAndCredit,
            self::Security => ChallengeFamily::Security,
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

    /** Icon of the challenge, a name of the interface's icon set (Patrimeter\Twig\Icons). */
    public function icon(): string
    {
        return match ($this) {
            self::FirstSteps => 'check',
            self::Streak => 'flame',
            self::UpToDate => 'calendar-check',
            self::Precaution => 'shield',
            self::NoConsumerCredit => 'card',
            self::Goal => 'goal',
            self::Budget => 'budget',
            self::Security => 'lock',
            self::SavingsRate => 'percent',
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
