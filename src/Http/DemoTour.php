<?php

declare(strict_types=1);

namespace Patrimeter\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;

use function Symfony\Component\Translation\t;

/**
 * Guided tour of the demonstration accounts: steps across the wealth, entry, accounts, properties, budget, goals and report
 * pages. Rendered by the server (a card on the step's page, buttons in forms): it works without JavaScript, which only
 * highlights the element the step talks about. The current step is kept in the session; stopping removes it, the
 * banner of the demonstration starts the tour again or resumes it.
 */
final class DemoTour
{
    private const string SESSION_KEY = '_patrimeter_demo_tour';

    public function __construct(private readonly UrlGeneratorInterface $urls) {}

    /**
     * Steps: page (route), element to highlight (CSS selector, null: none), title and text.
     *
     * @return list<array{route: string, target: ?string, title: TranslatableMessage, text: TranslatableMessage}>
     */
    public static function steps(): array
    {
        return [
            ['route' => 'app_dashboard', 'target' => '.summary:not(.history-summary)', 'title' => t('Votre patrimoine net'), 'text' => t('Tout ce que le foyer possède, moins ce qu\'il doit encore, pour le mois affiché ; son évolution sur un mois et sur un an. Les flèches et le calendrier changent de mois.')],
            ['route' => 'app_dashboard', 'target' => '.pyramid-card', 'title' => t('La pyramide'), 'text' => t('Chaque étage est un niveau de placement, du plus sûr au plus risqué : la résidence principale à la base, puis l\'épargne de précaution, les fonds euros, les actions et l\'immobilier, l\'exotique au sommet. Une base large avant de monter.')],
            ['route' => 'app_dashboard', 'target' => '.levels-card', 'title' => t('Le détail par niveau'), 'text' => t('Chaque niveau se déplie sur ses comptes. Un solde « reporté » est le dernier connu : il compte jusqu\'à la saisie du mois.')],
            ['route' => 'app_dashboard', 'target' => '#historique', 'title' => t('L\'historique'), 'text' => t('L\'évolution du patrimoine net, en graphique et mois par mois, sur la période de votre choix.')],
            ['route' => 'app_entry', 'target' => '.entry-form', 'title' => t('La saisie du mois'), 'text' => t('Une fois par mois, le solde de chaque compte en un seul formulaire, classé par niveau. Les totaux se recalculent pendant la frappe ; on peut aussi reprendre les soldes d\'un autre mois.')],
            ['route' => 'app_accounts', 'target' => 'main .sheet', 'title' => t('Les comptes et crédits'), 'text' => t('Livrets, assurance-vie, PEA, crédits… 38 types prédéfinis, rangés dans leur niveau. Un compte fermé s\'archive et garde son historique.')],
            ['route' => 'app_properties', 'target' => '.property', 'title' => t('Les biens immobiliers'), 'text' => t('Un bien et ses crédits : valeur, capital restant dû et valeur nette. Ici, la résidence principale est détenue à moitié, et le crédit compte au prorata.')],
            ['route' => 'app_budget', 'target' => '.budget-summary', 'title' => t('Le budget du foyer'), 'text' => t('Les revenus de chaque adulte, le budget commun et ce qui reste à chacun ; le budget se saisit chaque mois, comme les soldes.')],
            ['route' => 'app_budget', 'target' => '.budget-flows', 'title' => t('Les flux et la règle 50/30/20'), 'text' => t('Où vont les revenus : besoins, envies et épargne, comparés à la règle 50/30/20.')],
            ['route' => 'app_budget', 'target' => '.budget-detail', 'title' => t('Le détail des budgets'), 'text' => t('Le budget commun et celui de chaque adulte se déplient sur leurs catégories et leurs postes : touchez la flèche.')],
            ['route' => 'app_goals', 'target' => '.goal', 'title' => t('Les objectifs d\'épargne'), 'text' => t('Un montant à atteindre à une date : chaque mois, Patrimeter compare votre rythme à l\'effort encore nécessaire et vous dit si vous êtes sur la bonne voie. Ici, la précaution est en retard, l\'apport en avance.')],
            ['route' => 'app_report', 'target' => '.report-card', 'title' => t('Le rapport à partager'), 'text' => t('Le patrimoine du mois sur une carte, avec ou sans les montants, à garder en image ou à partager.')],
            ['route' => 'app_dashboard', 'target' => null, 'title' => t('À vous de jouer'), 'text' => t('Modifiez les soldes, ajoutez un compte, changez le budget : tout sera supprimé à la fin de la démonstration. La visite se relance depuis le bandeau du haut.')],
        ];
    }

    /** Starts the tour at its first step; returns the address of that step's page. */
    public function start(Request $request): string
    {
        return $this->go($request, 0);
    }

    /** Goes to the next (+1) or previous (-1) step, from the current one (the first if none); returns its address. */
    public function move(Request $request, int $offset): string
    {
        return $this->go($request, ($this->current($request) ?? 0) + $offset);
    }

    /** Goes back to the page of the current step (the first if the tour is stopped); returns its address. */
    public function resume(Request $request): string
    {
        return $this->go($request, $this->current($request) ?? 0);
    }

    /** Stops the tour (the banner can start it again); returns the address of the page of the step left. */
    public function stop(Request $request): string
    {
        $step = $this->current($request) ?? 0;
        $request->getSession()->remove(self::SESSION_KEY);

        return $this->url($step);
    }

    /** Index of the current step, or null if the tour is stopped. */
    public function current(Request $request): ?int
    {
        if (!$request->hasSession()) {
            return null;
        }
        return self::validStep($request->getSession()->get(self::SESSION_KEY));
    }

    /**
     * State of the tour for the templates.
     *
     * @return array{active: bool, number: int, total: int, here: bool, first: bool, last: bool, target: ?string,
     *     title: TranslatableMessage, text: TranslatableMessage}
     */
    public function view(Request $request): array
    {
        $steps = self::steps();
        $current = $this->current($request);
        $step = $steps[$current ?? 0];

        return [
            'active' => $current !== null,
            'number' => ($current ?? 0) + 1,
            'total' => count($steps),
            'here' => $current !== null && $request->attributes->get('_route') === $step['route'],
            'first' => ($current ?? 0) === 0,
            'last' => ($current ?? 0) === count($steps) - 1,
            'target' => $step['target'],
            'title' => $step['title'],
            'text' => $step['text'],
        ];
    }

    private static function validStep(mixed $step): ?int
    {
        return is_int($step) && $step >= 0 && $step < count(self::steps()) ? $step : null;
    }

    private function go(Request $request, int $step): string
    {
        $step = max(0, min(count(self::steps()) - 1, $step));
        $request->getSession()->set(self::SESSION_KEY, $step);

        return $this->url($step);
    }

    private function url(int $step): string
    {
        return $this->urls->generate(self::steps()[$step]['route']);
    }
}
