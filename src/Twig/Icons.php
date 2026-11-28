<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

/**
 * The interface's icon set: one drawing per name, all on the same 24-unit grid and drawn with the same rounded
 * 2-unit stroke (the .icon rule of app.css), whatever the page. Written as SVG paths in the page (no font, no request,
 * nothing inline for the CSP). Decorative: the text next to an icon says what it means.
 */
final class Icons
{
    /** Name => path of the drawing. */
    public const array PATHS = [
        // Main navigation and its parts.
        'home' => 'M3 11 12 3l9 8v10h-6v-6H9v6H3z',
        'budget' => 'M21.2 15.9A10 10 0 1 1 8 2.8M22 12A10 10 0 0 0 12 2v10z',
        'goal' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM12 12h.01',
        'entry' => 'M4 20h4L19 9l-4-4L4 16zM14 6l4 4',
        'star' => 'M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.8l-5.2 2.8 1-5.8L3.5 9.7l5.9-.9z',
        // Header and My account.
        'moon' => 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z',
        'sun' => 'M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10zM12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4',
        'globe' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z',
        'user' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0',
        'lock' => 'M7 11V8a5 5 0 0 1 10 0v3M5 11h14v10H5z',
        'accounts' => 'M3 6h18M3 12h18M3 18h18',
        'property' => 'M4 21V8l8-5 8 5v13M4 21h16M9 21v-6h6v6',
        'carbure' => 'M4 7h16v10H4zM8 7V5h8v2M8 12h8',
        'agents' => 'M5 9h14v11H5zM12 9V6M12 3v1M9 14v1M15 14v1M2 13v3M22 13v3',
        'documentation' => 'M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2zM4 21V5M8 7h7M8 11h7',
        'logout' => 'M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10',
        'calendar' => 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4',
        // Messages and advice.
        'alert' => 'M12 3 2 21h20zM12 10v5M12 18v.5',
        'warning' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7v6M12 16v.5',
        'good' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM8 12.5l3 3 5-6',
        'info' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 11v6M12 7.5V8',
        // Ways to start and family.
        'question' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01',
        'pyramid' => 'M12 3 22 20H2zM6.5 13h11M9.3 8h5.4',
        'lines' => 'M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01',
        'upload' => 'M12 16V4M7 9l5-5 5 5M5 20h14',
        'plus' => 'M12 5v14M5 12h14',
        'family' => 'M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM17 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM3 20a6 6 0 0 1 12 0M13 20a6 6 0 0 1 8-5.6',
        // Challenges.
        'check' => 'M5 12.5l4.5 4.5L19 7.5',
        'flame' => 'M12 3c1 3 5 5 5 10a5 5 0 0 1-10 0c0-2 1-3.5 2-4.5 0 2 1 3 2 3 0-3-1-5 1-8.5z',
        'calendar-check' => 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4M8.5 15l2.5 2.5 4.5-4.5',
        'shield' => 'M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z',
        'card' => 'M3 7h18v11H3zM3 11h18M7 15h4',
        'percent' => 'M19 5 5 19M6.5 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM17.5 20a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
    ];

    /**
     * The icon's SVG, with the .icon class and any other given (e.g. "icon-moon").
     *
     * @throws \InvalidArgumentException an unknown name: a typing error, caught by the tests that render the page
     */
    public static function svg(string $name, string $class = ''): string
    {
        $path = self::PATHS[$name] ?? throw new \InvalidArgumentException(sprintf('Unknown icon "%s".', $name));
        $classes = trim('icon ' . $class);

        return sprintf(
            '<svg class="%s" viewBox="0 0 24 24" aria-hidden="true"><path d="%s"/></svg>',
            htmlspecialchars($classes, ENT_QUOTES | ENT_HTML5),
            $path,
        );
    }
}
