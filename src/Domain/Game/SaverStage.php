<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Game;

/**
 * Stage of the saver profile, computed each month: the highest rung whose condition is met along with those of every
 * rung below. Built on habits and safety only, never on the split between the levels nor on risk; it describes where
 * the user stands and is not a suitability test.
 */
enum SaverStage: string
{
    case Discovery = 'discovery';
    case Organized = 'organized';
    case Protected = 'protected';
    case Serene = 'serene';
    case Builder = 'builder';

    public function label(): string
    {
        return match ($this) {
            self::Discovery => 'Découverte',
            self::Organized => 'Organisé',
            self::Protected => 'Protégé',
            self::Serene => 'Serein',
            self::Builder => 'Bâtisseur',
        };
    }

    public function requirement(): string
    {
        return match ($this) {
            self::Discovery => 'Un premier mois saisi.',
            self::Organized => 'Trois mois où tous les comptes ont leur solde saisi.',
            self::Protected => 'Une épargne de précaution qui atteint votre cible, en mois de dépenses du budget.',
            self::Serene => 'Aucun crédit à la consommation à rembourser.',
            self::Builder => 'Un objectif d\'épargne atteint et douze mois de saisie de suite.',
        };
    }

    public function isMet(GameFacts $facts): bool
    {
        return match ($this) {
            self::Discovery => $facts->enteredMonths !== [],
            self::Organized => $facts->completeMonths >= 3,
            self::Protected => $facts->precautionMonths !== null && $facts->precautionMonths >= max(1, $facts->precautionTarget),
            self::Serene => $facts->consumerCreditFreeMonths >= 1,
            self::Builder => $facts->goalReached && $facts->streak()->length >= 12,
        };
    }

    /** The stage reached, or null before the first month entered. */
    public static function of(GameFacts $facts): ?self
    {
        $reached = null;
        foreach (self::cases() as $stage) {
            if (!$stage->isMet($facts)) {
                break;
            }
            $reached = $stage;
        }

        return $reached;
    }

    /** The stage after this one, or null for the last one. */
    public function next(): ?self
    {
        return match ($this) {
            self::Discovery => self::Organized,
            self::Organized => self::Protected,
            self::Protected => self::Serene,
            self::Serene => self::Builder,
            self::Builder => null,
        };
    }
}
