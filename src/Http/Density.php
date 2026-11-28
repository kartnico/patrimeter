<?php

declare(strict_types=1);

namespace Patrimeter\Http;

/** Display density of the interface (Appearance): the space around and between the blocks, the height of the controls. */
enum Density: string
{
    case Comfortable = 'comfortable';
    case Compact = 'compact';

    public const self DEFAULT = self::Comfortable;

    public function label(): string
    {
        return match ($this) {
            self::Comfortable => 'Confortable',
            self::Compact => 'Compacte',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Comfortable => 'Des espaces aérés et de grandes cibles, faciles à toucher sur un téléphone.',
            self::Compact => 'Plus de lignes à l\'écran, surtout utile sur un ordinateur.',
        };
    }
}
