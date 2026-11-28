<?php

declare(strict_types=1);

namespace Patrimeter\Http;

/**
 * Accent colour of the interface (Appearance), the colour of the buttons, links and current items. Each one has its
 * light and dark shades in app.css (--accent-{value}, -text and -soft), checked by ColorAccessibilityTest.
 */
enum Accent: string
{
    case Forest = 'forest';
    case Ocean = 'ocean';
    case Aurora = 'aurora';
    case Slate = 'slate';

    public const self DEFAULT = self::Forest;

    public function label(): string
    {
        return match ($this) {
            self::Forest => 'Forêt',
            self::Ocean => 'Océan',
            self::Aurora => 'Aurore',
            self::Slate => 'Ardoise',
        };
    }

    /** Colour of the browser's bar on a phone (meta theme-color): the accent in the light theme, as in app.css. */
    public function themeColor(): string
    {
        return match ($this) {
            self::Forest => '#2f6f5e',
            self::Ocean => '#1f5f8b',
            self::Aurora => '#a4462b',
            self::Slate => '#3d4a5c',
        };
    }
}
