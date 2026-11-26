<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/** Levels of the pyramid (mirror of the `levels` table). */
enum Level: string
{
    case Residence = 'residence';
    case Precaution = 'precaution';
    case FondsEuros = 'fonds_euros';
    case Actions = 'actions';
    case Immobilier = 'immobilier';
    case Exotique = 'exotique';

    public function label(): string
    {
        return match ($this) {
            self::Residence => 'Résidence principale',
            self::Precaution => 'Épargne de précaution',
            self::FondsEuros => 'Fonds euros',
            self::Actions => 'Actions',
            self::Immobilier => 'Immobilier',
            self::Exotique => 'Exotique',
        };
    }

    /** Short label for small spaces (pyramid). */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Residence => 'Résidence principale',
            self::Precaution => 'Précaution',
            self::FondsEuros => 'Fonds euros',
            default => $this->label(),
        };
    }

    /** Rank in the display order. */
    public function position(): int
    {
        return (int) array_search($this, self::ordered(), true);
    }

    /**
     * Display order (from the base to the top).
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Residence, self::Precaution, self::FondsEuros, self::Actions, self::Immobilier, self::Exotique];
    }
}
