<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Game;

/** The five first steps of a new user (challenge "Premiers pas"), one star each; passkeys belong to "Compte protégé". */
enum FirstStep: string
{
    case Household = 'household';
    case Accounts = 'accounts';
    case MonthEntered = 'month_entered';
    case Property = 'property';
    case Goal = 'goal';

    public function label(): string
    {
        return match ($this) {
            self::Household => 'Décrire votre foyer',
            self::Accounts => 'Créer vos comptes',
            self::MonthEntered => 'Saisir les soldes du mois',
            self::Property => 'Ajouter votre résidence ou un bien immobilier',
            self::Goal => 'Fixer un premier objectif d\'épargne',
        };
    }

    public function isDone(GameFacts $facts): bool
    {
        return match ($this) {
            self::Household => $facts->hasHousehold,
            self::Accounts => $facts->accountCount > 0,
            self::MonthEntered => $facts->hasEntered($facts->month),
            self::Property => $facts->hasProperty,
            self::Goal => $facts->goalCount > 0,
        };
    }
}
