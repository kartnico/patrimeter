<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Where a page belongs, from its path: entry of the main navigation (wealth, budget, monthly entry) and tab of
 * My account. Pages keep their address; settings pages (accounts, properties, budget household, Carbure) are shown
 * as tabs of My account.
 */
final class NavigationExtension extends AbstractExtension
{
    public const string WEALTH = 'wealth';
    public const string BUDGET = 'budget';
    public const string GOALS = 'goals';
    public const string CHALLENGES = 'challenges';
    public const string ENTRY = 'entry';
    public const string ACCOUNT = 'account';

    /** Pages that show a month chosen by ?mois=. */
    private const array MONTH_PAGES = ['/', '/rapport', '/saisie', '/budget', '/budget/saisie'];

    /** Tab of My account => path prefixes (first match wins, most specific first). */
    private const array ACCOUNT_TABS = [
        'sign_in' => ['/securite/connexion', '/securite/application', '/securite/journal'],
        'agents' => ['/securite/agents-ia'],
        'profile' => ['/securite', '/donnees', '/famille'],
        'accounts' => ['/comptes'],
        'properties' => ['/biens'],
        'budget' => ['/budget/foyer', '/budget/categories', '/budget/postes'],
        'carbure' => ['/budget/carbure'],
    ];

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nav_section', self::section(...)),
            new TwigFunction('account_tab', self::accountTab(...)),
            new TwigFunction('current_month_path', self::currentMonthPath(...)),
        ];
    }

    /**
     * Address of the current month from this page (link of the month of the top bar): the same page for those that
     * show a month (wealth, report, monthly entry, budget, budget entry), otherwise the wealth page.
     */
    public static function currentMonthPath(string $path, string $month): string
    {
        return (in_array($path, self::MONTH_PAGES, true) ? $path : '/') . '?mois=' . $month;
    }

    /** Entry of the main navigation for this path, or null (sign-in pages…). */
    public static function section(string $path): ?string
    {
        if (self::accountTab($path) !== null) {
            return self::ACCOUNT;
        }
        if (self::under($path, '/saisie') || self::under($path, '/budget/saisie')) {
            return self::ENTRY;
        }
        if (self::under($path, '/budget')) {
            return self::BUDGET;
        }
        if (self::under($path, '/objectifs')) {
            return self::GOALS;
        }
        if (self::under($path, '/defis')) {
            return self::CHALLENGES;
        }
        if ($path === '/' || self::under($path, '/bienvenue') || self::under($path, '/historique') || self::under($path, '/rapport')) {
            return self::WEALTH;
        }

        return null;
    }

    /** Tab of My account for this path, or null. */
    public static function accountTab(string $path): ?string
    {
        foreach (self::ACCOUNT_TABS as $tab => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (self::under($path, $prefix)) {
                    return $tab;
                }
            }
        }

        return null;
    }

    /** The path is the prefix itself or one of its sub-paths ("/biens" matches "/biens/nouveau", not "/biensx"). */
    private static function under(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }
}
